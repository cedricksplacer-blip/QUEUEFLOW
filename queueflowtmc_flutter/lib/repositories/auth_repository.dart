import 'dart:async';

import 'package:flutter/foundation.dart';

import '../core/app_constants.dart';
import '../models/announcement.dart';
import '../models/app_user.dart';
import '../models/campus_catalog.dart';
import '../models/queue_ticket.dart';
import '../models/student.dart';
import '../models/student_bootstrap.dart';
import '../core/api/api_client.dart';
import '../core/network/connectivity_service.dart';
import '../core/storage/offline_cache.dart';
import '../services/campus_backend.dart';
import '../services/campus_backend_factory.dart';
import '../services/session_store.dart';

/// Where the app is in the sign-in lifecycle.
enum AuthStatus {
  /// Still restoring a persisted session — show the splash.
  restoring,

  /// No usable session — show the login form.
  signedOut,

  /// A sign-in or refresh is in flight.
  busy,

  /// Signed in and hydrated — show the shell for the signed-in role.
  signedIn,
}

/// ============================================================
/// QUEUEFLOW TMC — AUTHENTICATION & SESSION STATE
///
/// Single source of truth for the signed-in account and everything the
/// student shell renders.
///
/// Every read and write goes to the [CampusBackend] this build was configured
/// with, so this file does not know whether it is talking to the LAN over
/// `artisan serve` or to a public host. It hydrates one payload — the student
/// bootstrap, which carries the profile, the offices, the services, the
/// announcements, the settings and the counters — plus the student's own
/// tickets.
///
/// STUDENT-ONLY, ENFORCED HERE RATHER THAN IN THE SCREENS
///
/// A staff or administrator token is refused at sign-in and at restore, with a
/// sentence the reader can act on. It is not simply hidden: those accounts are
/// issued by the same registrar and somebody will eventually type one in here,
/// and "this app is for students, use the staff console" is a far better answer
/// than a dashboard that fails every button. The roles are still parsed rather
/// than collapsed into [AppRole.unknown], because the users table stores all
/// three and an account refused as unrecognised reads like a bug.
///
/// What the repository owns, because it is the layer above the transport, is the
/// session lifecycle: the token is persisted alongside the account, a signed-out
/// call revokes it on the server, and a 401 ends the session wherever it was
/// noticed rather than leaving a shell full of "Sign in first" errors.
/// ============================================================

class AuthRepository extends ChangeNotifier {
  AuthRepository._();

  static final AuthRepository instance = AuthRepository._();

  /// The transport this build was configured with.
  ///
  /// Read through the factory on every call rather than captured once, so a
  /// test that swaps the transport mid-run is obeyed from the next call on.
  CampusBackend get _backend => CampusBackendFactory.instance.current;

  /// True while talking to the live API, false for the on-device campus.
  ///
  /// The login form and the About screen branch on this, because "the server
  /// is unreachable" is a sentence only a network transport can be honest
  /// about.
  bool get isRemote => _backend.isRemote;

  // ==========================================================
  // STATE
  // ==========================================================

  AuthStatus _status = AuthStatus.restoring;
  AppUser? _user;
  String? _error;

  /// True while a call/serve action is in flight, so a console can lock
  /// its buttons rather than letting a second tap queue a second call.
  bool _actionBusy = false;

  // Student payload.
  StudentBootstrap? _bootstrap;
  List<QueueTicket> _tickets = <QueueTicket>[];
  TicketCounts _counts = const TicketCounts();

  /// The Registrar course queues, read from their own endpoint.
  ///
  /// Held apart from [_bootstrap] because the catalogue inside it is narrowed
  /// to the student's own college, and the course picker needs all seven.
  RegistrarCourseList _registrar = RegistrarCourseList.empty;
  bool _registrarLoaded = false;

  AuthStatus get status => _status;
  AppUser? get user => _user;
  String? get error => _error;
  bool get actionBusy => _actionBusy;

  /// The role the server assigned. [AppRole.unknown] while signed out.
  AppRole get role => _user?.role ?? AppRole.unknown;

  bool get isStudent => role.isStudent;
  bool get isStaff => role.isStaff;
  bool get isAdmin => role.isAdmin;

  /// True for the roles that work a counter.
  ///
  /// Never true in this build — there is no counter console in a student app —
  /// but the flag stays so a staff token is refused on role rather than on a
  /// missing screen.
  bool get canOperateQueue => role.canOperateQueue;

  /// The student-shaped view of the signed-in account.
  Student? get student => _user?.asStudent;

  /// The student bootstrap payload, or null before it has loaded.
  ///
  /// The dashboard treats null as "first load" and shows a spinner.
  StudentBootstrap? get bootstrap => _bootstrap;

  /// A session is only usable when it carries a role this build serves.
  ///
  /// A token for a staff member or an administrator must land on the login
  /// screen rather than on a student dashboard that would immediately 403 on
  /// every action.
  bool get isSignedIn =>
      _status == AuthStatus.signedIn && _user != null && role.isStudent;

  /// The sentence shown when an account signed in successfully but is not one
  /// this app serves.
  static const String notAStudent =
      'This app is for students. Please use the staff or admin console with '
      'this account.';

  // ==========================================================
  // SHARED CATALOGUE
  // ==========================================================

  /// The offices a student may take a ticket at.
  ///
  /// The six official offices come first in campus order; any further active
  /// office the server sends (added later through the admin Offices screen)
  /// follows by name. Laravel only sends active rows, so everything listed
  /// here is a live counter — but a row the server retired stays out, because
  /// offering a student a window that will never call a number is worse than
  /// not listing it.
  ///
  /// Matching is on the name, case-insensitively, since that is what is painted
  /// above the window; the codes have moved between campus databases.
  List<Office> get offices {
    final List<Office> fromServer = _bootstrap?.offices ?? const <Office>[];

    final List<Office> known = fromServer
        .where((Office o) => AppConstants.isQueueOffice(o.name))
        .toList(growable: false)
      ..sort(
        (Office a, Office b) => AppConstants.queueOfficeRank(
          a.name,
        ).compareTo(AppConstants.queueOfficeRank(b.name)),
      );

    final List<Office> extra = fromServer
        .where((Office o) => o.isActive && !AppConstants.isQueueOffice(o.name))
        .toList(growable: false)
      ..sort((Office a, Office b) => a.name.compareTo(b.name));

    return <Office>[...known, ...extra];
  }

  /// The one card a student picks Registrar from.
  ///
  /// The Registrar is seven offices on the server (REG-BSIT ... REG-BSOA), each
  /// with its own deployed batch and its own assigned staff member, so the
  /// catalogue row a student gets is for their own college alone. Folding the
  /// rows back into a single Registrar entry is what makes the picker read as
  /// the six offices the campus advertises, and lets the slot count be the sum
  /// across all seven course queues rather than one college's.
  ///
  /// Returns null when the server sent no Registrar row at all, which is the
  /// honest answer: a course picker with nothing to choose from is a bug to
  /// surface, not something to paper over with a hardcoded list.
  Office? get registrarOffice {
    for (final Office office in offices) {
      if (isRegistrarRow(office)) {
        return office;
      }
    }

    return null;
  }

  /// The offices a student picks directly, with the Registrar's course
  /// sub-offices removed so they cannot appear twice.
  List<Office> get selectableOffices {
    final List<Office> all = offices;

    final bool hasRegistrar = all.any(isRegistrarRow);

    return all
        .where((Office o) => hasRegistrar ? !isRegistrarRow(o) : true)
        .toList(growable: false);
  }

  /// Whether an office row is one of the Registrar's per-course desks.
  ///
  /// Matched on the code prefix as well as the display name, because a campus
  /// database may carry a single undivided `REG` office alongside the per-course
  /// ones and both are the Registrar as far as a student is concerned.
  ///
  /// Public so a screen that folds the Registrar's course rows into one card
  /// can exclude them all from the plain office list without duplicating the
  /// match. Only Registrar-named rows match: an "is this one of the campus
  /// offices" test would be the job of [AppConstants.isQueueOffice], and a
  /// Cashier row folding into the Registrar card is precisely the bug this is
  /// meant to prevent.
  static bool isRegistrarRow(Office office) =>
      AppConstants.isRegistrarOfficeCode(office.code) ||
      AppConstants.isRegistrarName(office.name);

  /// Offices that will actually issue a number right now.
  List<Office> get openOffices =>
      offices.where((Office o) => o.isOpen).toList(growable: false);

  /// The current catalogue row for an office, so a screen can re-check the
  /// online/offline switch right before acting on a choice made earlier.
  ///
  /// The switch lives on the server, so a screen that builds an office from a
  /// user tap keeps a stale status in it; this reads today's copy.
  Office? officeById(int id) {
    for (final Office office in offices) {
      if (office.id == id) {
        return office;
      }
    }

    return null;
  }

  /// Offices that are closed, with the reason resolved server-side.
  List<Office> get closedOffices =>
      offices.where((Office o) => !o.isOpen).toList(growable: false);

  /// The student's own enrolled course code, upper-cased.
  String? get enrolledCourseCode {
    final String? code = _bootstrap?.student.course;

    if (code == null || code.trim().isEmpty) {
      return null;
    }

    return code.trim().toUpperCase();
  }

  // ==========================================================
  // REGISTRAR COURSE QUEUES
  // ==========================================================

  /// Every Registrar course queue with its live slot counters.
  ///
  /// Empty until [refreshRegistrarCourses] has succeeded once, which
  /// [_registrarLoaded] distinguishes from a read that genuinely found none:
  /// the first means "not asked yet" and the second means "the server says
  /// there are no course queues", and the screen says different things.
  RegistrarCourseList get registrarCourses => _registrar;

  bool get registrarCoursesLoaded => _registrarLoaded;

  /// Reads the Registrar course queues.
  ///
  /// Its own call rather than part of [refresh] because it is only needed by
  /// the course picker and the Registrar card, and folding it into every
  /// dashboard poll would add a round trip to the screen students look at
  /// most. Callers get the list back as well, so a button that pressed "get a
  /// number" can act on the same read it just displayed.
  ///
  /// A failure is recorded through the normal error path and the previous list
  /// is kept: stale slot counts on screen are better than a card that blanks
  /// every time the connection hiccups, and the numbers are re-read on the next
  /// entry anyway.
  Future<RegistrarCourseList> refreshRegistrarCourses() async {
    final AppUser? signedIn = _user;

    if (signedIn == null || !isStudent) {
      return _registrar;
    }

    try {
      _registrar = await _backend.registrarCourses(signedIn);
      _registrarLoaded = true;
      _error = null;
      notifyListeners();
    } catch (error) {
      _fail(error);
    }

    return _registrar;
  }

  /// Re-reads the office online/offline switches.
  ///
  /// Its own call rather than part of [refresh] because it is light: the
  /// office screen asks for it when it opens, while the reader pulls to
  /// refresh, on a timer while the page sits there, and once more immediately
  /// before a ticket is issued. Folding those into a full shell re-read would
  /// refetch announcements, tickets and settings to learn one field, and the
  /// pre-submit call in particular has to be cheap enough to happen on every
  /// attempt.
  ///
  /// Only the switch is adopted. The catalogue on screen carries slot counts,
  /// services and station rows that were fetched with the rest of the shell,
  /// and a payload that knew only about `status` would wipe them if it
  /// replaced the list - so the fresh rows correct [Office.status] on the rows
  /// already here, matched by office id.
  ///
  /// The previous catalogue is kept when the read fails, and the exception is
  /// rethrown for the caller to explain: a screen that promised to re-check
  /// "right before issuing a number" has to be able to say the check did not
  /// happen. Reporting the failure as a quiet stale `online` would be exactly
  /// the claim the status feature exists to prevent, so the error path never
  /// resolves to "the counter is fine".
  Future<List<Office>> refreshOfficeStatus() async {
    final AppUser? signedIn = _user;

    if (signedIn == null || !isStudent) {
      return offices;
    }

    try {
      final List<Office> fresh = await _backend.officeCatalogue(signedIn);

      _applyOfficeStatus(fresh);
      _error = null;
      notifyListeners();
    } catch (error) {
      // Only a dead session ends here. Any other failure is handed back to
      // the screen, which owns the wording for it - this repository's [error]
      // is the shell's message, and a screen that is about to tell the reader
      // "the server did not answer" must not race a second, differently
      // worded banner for the same event.
      if (_isRejectedSession(error)) {
        unawaited(_expire(error));
      }

      rethrow;
    }

    return offices;
  }

  /// Adopts fresh office rows from a re-read catalogue, by office id.
  ///
  /// The whole row is adopted — not just the online switch — so a batch
  /// staff just deployed or closed (number, slots, status, counters) shows
  /// up on the next light refresh as well as on a full shell re-read. Rows
  /// are matched by id and keep the current order; offices missing from the
  /// fresh read are kept as they were, and a payload that knew only about
  /// `status` must never wipe the services a row needs to sell a ticket —
  /// [officeCatalogue] always returns full rows, so this holds.
  void _applyOfficeStatus(List<Office> fresh) {
    final StudentBootstrap? current = _bootstrap;

    if (current == null) {
      // Nothing to correct yet: the shell payload that carries the offices
      // has not landed, and building a catalogue out of a partial read
      // would hand the screen rows with no services to sell a ticket with.
      // The next shell read brings those, carrying the same switch the
      // server has now.
      return;
    }

    final Map<int, Office> rows = <int, Office>{
      for (final Office office in fresh) office.id: office,
    };

    _bootstrap = StudentBootstrap(
      student: current.student,
      settings: current.settings,
      announcements: current.announcements,
      courses: current.courses,
      offices: <Office>[
        for (final Office office in current.offices)
          rows[office.id] ?? office,
      ],
    );
  }

  /// System settings from the student bootstrap payload.
  SystemSettings get settings => _bootstrap?.settings ?? const SystemSettings();

  /// Every published announcement, newest first.
  List<Announcement> get announcements =>
      _bootstrap?.announcements ?? const <Announcement>[];

  /// The most recent announcement, used as a shell banner.
  String? get latestAnnouncement {
    final List<Announcement> published = announcements;

    if (published.isEmpty) {
      return null;
    }

    return published.first.title;
  }

  /// The course id required when taking a ticket.
  int? get queueCourseId => _bootstrap?.queueCourseId;

  /// Campus-wide counters for this student, from the tickets payload.
  TicketCounts get counts => _counts;

  // ==========================================================
  // LIFECYCLE
  // ==========================================================

  /// Restores a persisted session on launch.
  ///
  /// The cached profile is shown immediately so the app never flashes the
  /// login form. It is then re-read from the backend, which is what catches
  /// the three ways a cached session can be stale: an administrator
  /// deactivated the account, the role was changed, or the token was revoked
  /// (on the API by a sign-out from another device). Each of those lands back
  /// on the login screen instead of opening a shell it no longer has access to.
  Future<void> restore() async {
    _status = AuthStatus.restoring;
    _error = null;
    notifyListeners();

    await SessionStore.load();

    // A cached account with no token cannot be re-read over the network, and
    // a token with no account has nothing to show. Either half alone is not a
    // session.
    if (!SessionStore.hasSession || (isRemote && SessionStore.token == null)) {
      await _clearSession();
      _status = AuthStatus.signedOut;
      notifyListeners();
      return;
    }

    _user = SessionStore.user;
    notifyListeners();

    final AppUser? cached = _user;

    if (cached == null) {
      _status = AuthStatus.signedOut;
      notifyListeners();
      return;
    }

    // Refused before the network is touched: there is nothing on the server a
    // staff account could do in this app, so a round trip would only delay the
    // explanation.
    if (!cached.role.isStudent) {
      await _clearSession();
      _status = AuthStatus.signedOut;
      _error = notAStudent;
      notifyListeners();
      return;
    }

    try {
      _user = await _backend.account(cached);
      await SessionStore.updateUser(_user!);
    } on CampusBackendException catch (error) {
      // A refusal and an unreachable server are not the same event, and the
      // difference decides whether the reader keeps their session.
      //
      // 401/403 means the server answered and rejected this token. Nothing
      // later will work, so the cached row is dropped and the login form is
      // correct.
      //
      // Anything else means nothing usable came back - the phone is in a lift,
      // or `artisan serve` is stopped. The token may well still be perfectly
      // valid; we simply cannot ask. Signing out here would mean a student who
      // opens the app on the bus loses their session to a server that was
      // never asked, and then has to remember a password on a captive portal.
      // So the cached session is kept and the shell opens on cached data.
      if (error.isUnauthenticated) {
        await _expire(error);
        return;
      }

      ConnectivityService.instance.reportUnreachable();

      // Show the shell on whatever was last fetched rather than on nothing.
      // The connection banner explains why the numbers may be stale, which is
      // the honest pairing: stale-but-labelled beats empty-and-silent.
      _hydrateFromCache();
      _hydrateTicketsFromCache();

      _status = AuthStatus.signedIn;
      _error = ConnectivityService.instance.message.body;
      notifyListeners();

      return;
    }

    if (!role.isStudent) {
      // The server reassigned the role, or the cached row named one this build
      // does not serve. Either way there is no student shell to open.
      await _clearSession();
      _status = AuthStatus.signedOut;
      _error = notAStudent;
      notifyListeners();
      return;
    }

    _status = AuthStatus.signedIn;
    notifyListeners();

    await refresh();
  }

  /// Signs in against the configured backend.
  ///
  /// [identifier] is the student's ID Number. The role comes from the account
  /// the server returned rather than from anything the caller supplied, so the
  /// app never asks which role to use and never trusts a role handed to it by
  /// the device.
  Future<bool> signIn({
    required String identifier,
    required String password,
  }) async {
    _status = AuthStatus.busy;
    _error = null;
    notifyListeners();

    try {
      final AppUser user = await _backend.signIn(
        identifier: identifier,
        password: password,
      );

      // A staff or administrator token is valid; it just is not for this app.
      // The session is dropped rather than kept "just in case", because a token
      // with no shell behind it is a credential on the device with no way to
      // use it and no way to see it.
      if (!user.role.isStudent) {
        await _clearSession();
        _status = AuthStatus.signedOut;
        _error = user.role == AppRole.unknown
            ? 'This account has no portal access on this app.'
            : notAStudent;
        notifyListeners();
        return false;
      }

      await SessionStore.save(user: user, token: SessionStore.token);

      _user = user;
      _status = AuthStatus.signedIn;
      notifyListeners();

      await refresh();

      return true;
    } on CampusBackendException catch (error) {
      _error = error.message;
      _status = AuthStatus.signedOut;
      notifyListeners();

      return false;
    }
  }

  /// Ends the session.
  ///
  /// On the API this revokes the token, so a device that is lost with the app
  /// signed in is signed out from here rather than staying usable until the
  /// session is otherwise cleaned up. The local token is cleared either way, and
  /// a failure to revoke is deliberately not reported: the reader asked to be
  /// signed out, and being signed out locally is the part that protects this
  /// device.
  Future<void> signOut() async {
    _status = AuthStatus.busy;
    notifyListeners();

    try {
      await _backend.signOut();
    } on CampusBackendException {
      // Deliberately swallowed; see above.
    }

    await _clearSession();

    _status = AuthStatus.signedOut;
    notifyListeners();
  }

  /// Ends a session the server has already rejected.
  ///
  /// Takes the exception rather than a string because the two rejections a
  /// reader can meet are not the same: an expired token gets an instruction to
  /// sign in again, while a role the server no longer grants gets the server's
  /// own sentence, which is more specific than anything invented here.
  Future<void> _expire(Object error) async {
    final int? status = _statusOf(error);

    await _clearSession();

    _status = AuthStatus.signedOut;
    _error = status == 401
        ? 'Your session has expired. Please sign in again.'
        : _saidBy(error);
    notifyListeners();
  }

  /// The HTTP status behind [error], whichever layer raised it.
  ///
  /// The remote transport translates [ApiException] into
  /// [CampusBackendException] on the way out, so a given call site can be
  /// handed either depending on who threw. Reading the code through one helper
  /// is what keeps "is this session dead?" answerable at every site, rather
  /// than only at the sites that happened to be written first.
  static int? _statusOf(Object error) => switch (error) {
    ApiException(:final statusCode) => statusCode,
    CampusBackendException(:final statusCode) => statusCode,
    _ => null,
  };

  /// The sentence the server wrote for [error].
  static String _saidBy(Object error) => switch (error) {
    ApiException(:final message) => message,
    CampusBackendException(:final message) => message,
    _ => 'The QueueFlow server refused the request.',
  };

  /// Whether [error] means the server rejected this session outright.
  ///
  /// Only 401 counts. A 403 is a refusal of one action, not of the session, and
  /// treating it as an expiry would sign an operator out for pressing a button
  /// they were not allowed to press.
  static bool _isRejectedSession(Object error) => _statusOf(error) == 401;

  /// Clears the local session and every cached payload.
  Future<void> _clearSession() async {
    await SessionStore.clear();

    // Cached payloads belong to the account that fetched them. Leaving them
    // behind would show the previous user's announcements to whoever signs in
    // next on a shared phone at the registrar's counter.
    await OfflineCache.clear();

    _user = null;
    _bootstrap = null;
    _tickets = <QueueTicket>[];
    _counts = const TicketCounts();
    _selfCancelledTicketIds.clear();

    // Slot counts belong to the account that read them. Carried over to the
    // next sign-in they would report another student's queues.
    _registrar = RegistrarCourseList.empty;
    _registrarLoaded = false;
  }

  // ==========================================================
  // DASHBOARD DATA
  // ==========================================================

  /// Hydrates the shell.
  ///
  /// The two reads are independent, so a failure in one still leaves the other
  /// on screen instead of blanking the page.
  Future<void> refresh() async {
    if (!isStudent) {
      return;
    }

    await _refreshStudent();
  }

  /// Records a failed read or write without tearing the session down.
  ///
  /// The one exception is a 401: that is the server saying this session no
  /// longer exists, and every later call would fail the same way. So the
  /// session ends here, wherever the expired call was noticed, rather than
  /// leaving the reader on a shell that can only produce errors.
  void _fail(Object error) {
    if (_isRejectedSession(error)) {
      unawaited(_expire(error));
      return;
    }

    _error = _messageOf(error);

    notifyListeners();
  }

  Future<void> _refreshStudent() async {
    final AppUser? signedIn = _user;

    if (signedIn == null) {
      return;
    }

    try {
      final StudentBootstrap data = await _backend.studentBootstrap(signedIn);
      _bootstrap = data;
      _user = AppUser.fromStudent(data.student);
      await SessionStore.updateStudent(data.student);
      _error = null;
      notifyListeners();

      // Cache on the way through, not lazily. The whole point is to have the
      // last good payload on disk BEFORE the connection drops, so this has to
      // happen on the success path rather than when somebody needs it.
      unawaited(
        OfflineCache.write(OfflineCacheKeys.studentBootstrap, data.toMap()),
      );
    } catch (error) {
      // Prefer the server's own refusal. Only when the server could not be
      // reached at all does the cache get a say - a 403 or a 422 is a real
      // answer about this account, and answering it with yesterday's payload
      // would be both wrong and confusing.
      if (error is ApiException && error.isUnreachable) {
        _hydrateFromCache();
      }

      _fail(error);
    }

    try {
      final ({List<QueueTicket> tickets, TicketCounts counts}) result =
          await _backend.studentTickets(signedIn);
      _tickets = result.tickets;
      _counts = result.counts;
      notifyListeners();

      unawaited(
        OfflineCache.write(OfflineCacheKeys.studentTickets, <String, dynamic>{
          'tickets': _tickets.map((QueueTicket t) => t.toMap()).toList(),
          'counts': _counts.toMap(),
        }),
      );
    } catch (error) {
      if (error is ApiException && error.isUnreachable) {
        _hydrateTicketsFromCache();
      }

      _fail(error);
    }
  }

  /// Fills the shell from the last successful read, when the server cannot be
  /// reached.
  ///
  /// Every field is only adopted when the live value is still empty, so this can
  /// never overwrite something the server just told us. A payload that fails to
  /// decode is simply not adopted - the screen renders its empty state, which is
  /// honest, rather than a half-populated dashboard.
  ///
  /// Deliberately silent about what it did: the caller reports the connection
  /// problem through the normal error path, and a second message about caching
  /// would be noise on a screen that already says the server is unreachable.
  void _hydrateFromCache() {
    if (_bootstrap != null) {
      return;
    }

    final Map<String, dynamic>? raw = OfflineCache.read(
      OfflineCacheKeys.studentBootstrap,
    );

    if (raw == null) {
      return;
    }

    try {
      _bootstrap = StudentBootstrap.fromMap(raw);
    } catch (error) {
      // A shape this build does not understand. Leave the empty state in place.
      _bootstrap = null;
    }
  }

  /// The ticket-list half of [_hydrateFromCache], kept separate so a failure to
  /// decode the bootstrap cannot cost the reader their existing ticket.
  void _hydrateTicketsFromCache() {
    if (_tickets.isNotEmpty) {
      return;
    }

    final Map<String, dynamic>? raw = OfflineCache.read(
      OfflineCacheKeys.studentTickets,
    );

    if (raw == null) {
      return;
    }

    final Object? rows = raw['tickets'];

    if (rows is! List) {
      return;
    }

    final List<QueueTicket> cached = rows
        .whereType<Map>()
        .map((Map row) => QueueTicket.fromMap(Map<String, dynamic>.from(row)))
        .toList(growable: false);

    if (cached.isNotEmpty) {
      _tickets = cached;
    }

    final Object? counts = raw['counts'];

    if (counts is Map) {
      _counts = TicketCounts.fromMap(Map<String, dynamic>.from(counts));
    }
  }

  // ==========================================================
  // STUDENT TICKET ACTIONS
  // ==========================================================

  /// Every ticket this student has, newest activity first of all.
  ///
  /// [history] is the finished subset and [activeTicket] the one occupying a
  /// slot; this is the raw list both are derived from, exposed for the
  /// notification feed, which needs a cancelled row and a served row from the
  /// same ticket.
  List<QueueTicket> get tickets => _tickets;

  /// Tickets that are finished: served or cancelled.
  ///
  /// Served newest first, which is the order a student reads them in.
  List<QueueTicket> get history {
    final List<QueueTicket> past = _tickets
        .where((QueueTicket t) => !t.isActive)
        .toList();

    past.sort((QueueTicket a, QueueTicket b) {
      final DateTime left =
          a.servedAt ?? a.calledAt ?? a.createdAt ?? DateTime(1970);
      final DateTime right =
          b.servedAt ?? b.calledAt ?? b.createdAt ?? DateTime(1970);

      return right.compareTo(left);
    });

    return past;
  }

  /// The ticket currently occupying a queue slot, if any. Student only.
  QueueTicket? get activeTicket {
    for (final QueueTicket ticket in _tickets) {
      if (ticket.isActive) {
        return ticket;
      }
    }

    return null;
  }

  /// The newest ticket on this account, if any.
  ///
  /// Used by My Ticket to explain a just-cancelled number: a cancelled
  /// ticket is no longer active, so [activeTicket] goes null the moment
  /// staff cancel it, and without this the student would only see an empty
  /// "No active ticket" state with no trace of the cancellation or the
  /// reason staff gave.
  QueueTicket? get latestTicket {
    if (_tickets.isEmpty) {
      return null;
    }

    QueueTicket newest = _tickets.first;

    for (final QueueTicket ticket in _tickets) {
      if (ticket.id > newest.id) {
        newest = ticket;
      }
    }

    return newest;
  }

  /// Live position of the student's active ticket inside its office queue.
  ///
  /// Returns null when there is no active ticket, or when the read fails —
  /// the caller renders the ticket card either way.
  ///
  /// The fresh ticket row is adopted into [_tickets], not just the progress:
  /// this is the read that carries a staff call (waiting → serving with the
  /// server-stamped `expires_at`), a Stop-Timer arrival (`arrived_at`), a
  /// cancellation, or a lazy server-side expiry. Without adopting it, the
  /// countdown card and the arrival/cancelled states would wait for a full
  /// [refresh] instead of appearing on the next poll.
  Future<QueueProgress?> activeProgress() async {
    final QueueTicket? ticket = activeTicket;
    final AppUser? signedIn = _user;

    if (ticket == null || signedIn == null) {
      return null;
    }

    try {
      final ({QueueTicket ticket, QueueProgress queue}) result = await _backend
          .studentTicket(signedIn, ticket.id);

      _lastProgressFor = (ticketId: result.ticket.id, progress: result.queue);

      final int index = _tickets.indexWhere(
        (QueueTicket t) => t.id == result.ticket.id,
      );

      if (index >= 0) {
        _tickets[index] = result.ticket;
        notifyListeners();

        unawaited(
          OfflineCache.write(OfflineCacheKeys.studentTickets, <String, dynamic>{
            'tickets': _tickets.map((QueueTicket t) => t.toMap()).toList(),
            'counts': _counts.toMap(),
          }),
        );
      }

      return result.queue;
    } catch (error) {
      _fail(error);
      return null;
    }
  }

  /// The progress last read for the current active ticket, or null when no
  /// ticket is active or no read has succeeded yet.
  ///
  /// Stored here rather than by each caller so the notification service can
  /// judge "your turn is close" from its own poll without owning a second
  /// copy of the read. Tagged with the ticket id it was read for: progress
  /// from a finished ticket must never be used to judge the next one, which
  /// is why a ticket switch invalidates it even before a fresh read lands.
  ({int ticketId, QueueProgress progress})? _lastProgressFor;

  QueueProgress? get lastProgress {
    final ({int ticketId, QueueProgress progress})? cached = _lastProgressFor;
    final QueueTicket? ticket = activeTicket;

    if (cached == null || ticket == null || cached.ticketId != ticket.id) {
      return null;
    }

    return cached.progress;
  }

  /// Takes a queue ticket and folds the result into the cached state.
  ///
  /// The whole ticket list is refetched afterwards so the counters agree with
  /// the stored rows rather than being patched locally. Exactly one of
  /// [created] and [error] is set: success returns the [CreatedTicket] the
  /// transport issued - the row, its position at issue time and the server's
  /// sentence - so a screen can render the digital receipt from the moment of
  /// issue rather than re-reading it; a refusal returns the sentence to show.
  ///
  /// [uncertain] is true only when the failure may have hidden a successful
  /// create - nothing answered (status 0), or the server errored after
  /// receiving the request (5xx). The caller should then re-read the queue and
  /// adopt the ticket if one appeared, instead of reporting a failed number
  /// the counter is already holding. A 4xx is a definite refusal and never
  /// sets it.
  Future<({CreatedTicket? created, String? error, bool uncertain})> createTicket({
    required int officeId,
    required int serviceId,
    required int courseId,
    String priority = AppConstants.priorityRegular,
    String? purpose,
    String? yearLevel,
    String? evidencePath,
  }) async {
    final AppUser? signedIn = _user;

    if (signedIn == null) {
      return (
        created: null,
        error: 'Sign in before taking a queue ticket.',
        uncertain: false,
      );
    }

    try {
      final CreatedTicket created = await _backend.createTicket(
        user: signedIn,
        officeId: officeId,
        serviceId: serviceId,
        courseId: courseId,
        priority: priority,
        purpose: purpose,
        yearLevel: yearLevel,
        evidencePath: evidencePath,
      );

      await refresh();

      return (created: created, error: null, uncertain: false);
    } catch (error) {
      return (
        created: null,
        error: _messageOf(error),
        uncertain: _creationOutcomeUncertain(error),
      );
    }
  }

  /// Whether a failed create may still have created a ticket.
  ///
  /// Only two failures leave that open: one where nothing answered ([_statusOf]
  /// is 0 - the request may have reached the server and its reply been lost),
  /// and a 5xx (the server received it and broke partway). Both are worth one
  /// re-read. Anything the server refused outright (4xx) is a definite "no",
  /// so it never counts.
  static bool _creationOutcomeUncertain(Object error) {
    final int? status = _statusOf(error);

    return status == null || status == 0 || status >= 500;
  }

  /// Tickets the student cancelled themselves this session.
  ///
  /// A self-cancel needs no "Your Ticket Has Been Cancelled" popup: the
  /// student just asked for it and watched it happen. The cancellation
  /// dialog watcher skips these ids so only staff-side cancels pop up.
  /// Cleared with the session, so a later sign-in starts unmarked.
  final Set<int> _selfCancelledTicketIds = <int>{};

  /// Whether [ticketId] was cancelled from this device this session.
  bool isSelfCancelled(int ticketId) =>
      _selfCancelledTicketIds.contains(ticketId);

  /// Records a self-cancel so the cancellation dialog stays silent for it.
  void markSelfCancelled(int ticketId) {
    _selfCancelledTicketIds.add(ticketId);
  }

  /// Cancels a waiting ticket. Returns null on success, else the message.
  Future<String?> cancelTicket(int ticketId) async {
    final AppUser? signedIn = _user;

    if (signedIn == null) {
      return 'Sign in before cancelling a ticket.';
    }

    try {
      await _backend.cancelTicket(signedIn, ticketId);
      markSelfCancelled(ticketId);
      await refresh();
      return null;
    } catch (error) {
      return _messageOf(error);
    }
  }

  // ==========================================================
  // LIVE BOARDS
  // ==========================================================

  /// Live "now serving" board for one office, or null when the read failed.
  /// A null is not fatal: the stats screen still lists the office and
  /// simply shows it as unreachable.
  Future<LiveOfficeBoard?> liveBoard(int officeId) async {
    try {
      return await _backend.liveBoard(officeId);
    } catch (error) {
      _fail(error);
      return null;
    }
  }

  // ==========================================================
  // PROFILE ACTIONS
  // ==========================================================

  /// Saves profile edits and refreshes the cached copy.
  ///
  /// Name and student number are intentionally absent: students cannot
  /// change them (only an administrator can, and the server ignores them
  /// on the self-service endpoint), so there is nothing to send.
  ///
  /// Returns null on success, else the message to show against the
  /// offending field.
  Future<String?> updateProfile({
    String? email,
    String? block,
    String? yearLevel,
    String? phone,
  }) async {
    final AppUser? signedIn = _user;

    if (signedIn == null) {
      return 'Sign in before editing your profile.';
    }

    try {
      final AppUser updated = await _backend.updateProfile(
        actor: signedIn,
        email: email,
        block: block,
        yearLevel: yearLevel,
        phone: phone,
      );

      _user = updated;
      await SessionStore.updateUser(updated);

      // The payload holds a second copy of the account, so it has to be
      // refreshed too or the next shell build would show stale values.
      await refresh();
      return null;
    } catch (error) {
      return _messageOf(error);
    }
  }

  /// Changes the password. Null on success, else the message.
  Future<String?> changePassword({
    required String currentPassword,
    required String password,
    required String passwordConfirmation,
  }) async {
    final AppUser? signedIn = _user;

    if (signedIn == null) {
      return 'Sign in before changing your password.';
    }

    if (password != passwordConfirmation) {
      return 'The two passwords do not match.';
    }

    try {
      await _backend.changePassword(
        actor: signedIn,
        currentPassword: currentPassword,
        password: password,
      );

      // The session is dropped locally too, so the account is proven to
      // work with the new password rather than assumed to.
      await _clearSession();
      _status = AuthStatus.signedOut;
      _error = 'Password updated. Please sign in with your new password.';
      notifyListeners();

      return null;
    } catch (error) {
      return _messageOf(error);
    }
  }

  /// The sentence to show for a failed read or write.
  ///
  /// Routed through here so that a transport failure reads as a connection
  /// problem rather than as "something went wrong". That distinction is the
  /// whole reason the connectivity layer exists: a student who cannot reach the
  /// campus server needs to be told to check the network, not handed a generic
  /// apology that gives them nothing to act on.
  String _messageOf(Object error) {
    if (_isRejectedSession(error)) {
      // End the session in the background; the caller is about to show a
      // message, and the gate will swap the shell out on the next build.
      unawaited(_expire(error));

      return 'Your session has expired. Please sign in again.';
    }

    // A rejected token is the only failure that means "your session is over";
    // everything else is a problem with this request, the link or the server,
    // and none of those are the reader's password.
    if (error is ApiException && error.isUnreachable) {
      // Keep the shared state honest, so the banner and the status pill stop
      // claiming the server is fine at the same moment the action refuses.
      ConnectivityService.instance.reportUnreachable();

      return ConnectivityService.instance.message.body;
    }

    if (error is CampusBackendException) {
      // A translated transport failure arrives here with statusCode 0, having
      // lost its `isUnreachable` flag with the type. Route it back through the
      // same connectivity wording rather than reporting it as a plain refusal.
      if (error.statusCode == 0) {
        ConnectivityService.instance.reportUnreachable();

        return ConnectivityService.instance.message.body;
      }

      return error.message;
    }

    return 'Something went wrong. Please try again.';
  }

  void clearError() {
    if (_error == null) {
      return;
    }

    _error = null;
    notifyListeners();
  }

  /// Injects a signed-in state without touching the network.
  ///
  /// Only for widget tests that need to lay a screen out against a known
  /// profile; production code always goes through [signIn].
  @visibleForTesting
  void debugSeed({
    required Student student,
    TicketCounts counts = const TicketCounts(),
    QueueTicket? activeTicket,
    List<Announcement> announcements = const <Announcement>[],
    List<Office> offices = const <Office>[],
    List<Course> courses = const <Course>[],
    RegistrarCourseList registrar = RegistrarCourseList.empty,
  }) {
    _user = AppUser.fromStudent(student);
    _counts = counts;
    _tickets = activeTicket == null
        ? <QueueTicket>[]
        : <QueueTicket>[activeTicket];

    // The dashboard treats a null bootstrap as "first load", so seed one
    // to exercise the populated state. Courses matter as much as offices
    // here: without the student's own course row, queueCourseId is null and
    // the take-a-ticket submit refuses for a reason the test never meant to
    // arrange.
    _bootstrap = StudentBootstrap(
      student: student,
      announcements: announcements,
      courses: courses,
      offices: offices,
    );

    // Seeded rather than left unset: a test that lays out the course picker
    // should not have to make a real call, and a seeded empty list has to be
    // distinguishable from one that was never asked for.
    _registrar = registrar;
    _registrarLoaded = true;

    _status = AuthStatus.signedIn;
    _error = null;
    notifyListeners();
  }

  /// Injects a signed-in account that is NOT a student, for the tests that
  /// assert such a session is refused.
  ///
  /// [AuthStatus.signedIn] on purpose: the point is that a session the server
  /// accepted is still refused here, so the test cannot pass merely because
  /// the status never reached signed-in.
  @visibleForTesting
  void debugSeedOperator({
    required AppRole role,
    required String name,
    String email = 'operator@tmc.edu.ph',
  }) {
    _user = AppUser(id: 1, name: name, email: email, role: role);
    _bootstrap = null;
    _tickets = <QueueTicket>[];
    _registrar = RegistrarCourseList.empty;
    _registrarLoaded = false;

    _status = AuthStatus.signedIn;
    _error = null;
    notifyListeners();
  }

  /// Returns the repository to its signed-out state for the next test.
  @visibleForTesting
  void debugReset() {
    _user = null;
    _bootstrap = null;
    _tickets = <QueueTicket>[];
    _counts = const TicketCounts();
    _selfCancelledTicketIds.clear();
    _registrar = RegistrarCourseList.empty;
    _registrarLoaded = false;
    _actionBusy = false;
    _error = null;
    _status = AuthStatus.signedOut;
    notifyListeners();
  }
}
