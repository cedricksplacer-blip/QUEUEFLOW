import 'dart:convert';
import 'dart:math';

import 'package:flutter/foundation.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../core/app_constants.dart';
import '../core/qf_value.dart';
import '../models/announcement.dart';
import '../models/app_user.dart';
import '../models/campus_catalog.dart';
import '../models/operations.dart';
import '../models/queue_ticket.dart';
import '../models/student_bootstrap.dart';
import 'campus_backend.dart';
import 'demo_campus.dart';

/// ============================================================
/// QUEUEFLOW TMC — LOCAL BACKEND
///
/// The on-device campus: everything the app shows comes out of a document
/// held in `SharedPreferences`, seeded once from [buildDemoCampus] and edited
/// in place from then on - a ticket taken on the phone is a row added to that
/// document, not a POST to Laravel.
///
/// It is a real transport rather than a stub, and it is the one `--dart-define
/// =USE_LOCAL_CAMPUS=true` selects. It implements the same [CampusBackend]
/// contract as [RemoteBackend] and answers in the same shapes, so the models
/// decode both identically and no screen knows which one it is talking to.
///
/// What it drops compared with the API is the transport, not the data: there
/// is no base URL, no bearer token, no timeout and no unreachable state,
/// because none of those can occur when the data is already on the device.
/// What it cannot do is revoke a session, which is why [signOut] is a no-op
/// here and a real call on the remote backend.
/// ============================================================

/// Historic name for [CampusBackendException].
///
/// Kept as an alias rather than removed because it is what every screen and
/// test already names, and the wording of a refusal has not changed - only
/// which transport produced it.
typedef LocalBackendException = CampusBackendException;

/// Historic name for [ResetCodeIssued].
///
/// On this transport the code really is returned and shown on screen, because
/// there is no mailbox to send it to. [ResetCodeIssued.code] is nullable for
/// the API, where the code is emailed instead.
typedef LocalResetCode = ResetCodeIssued;

class LocalBackend implements CampusBackend {
  LocalBackend._();

  static final LocalBackend instance = LocalBackend._();

  @override
  bool get isRemote => false;

  static const String _storeKey = 'qf_local_campus';
  static const int _schema = 1;

  /// Seconds an address has to wait between reset codes.
  static const int _resetCooldownSeconds = 60;

  static const int _resetLifetimeMinutes = 10;

  static const String _badCredentials =
      'These credentials do not match our records.';
  static const String _badCode = 'That code is not valid or has expired.';

  final Random _random = Random();

  Map<String, dynamic>? _db;
  Future<Map<String, dynamic>>? _loading;

  bool get isReady => _db != null;

  // ==========================================================
  // LIFECYCLE
  // ==========================================================

  /// Loads the persisted campus, seeding it on first launch.
  ///
  /// Safe to await more than once: the first caller starts the load and
  /// everyone else waits on the same future.
  Future<Map<String, dynamic>> ready() {
    if (_db != null) {
      return Future<Map<String, dynamic>>.value(_db);
    }

    return _loading ??= _load();
  }

  Future<Map<String, dynamic>> _load() async {
    Map<String, dynamic>? saved;

    try {
      final SharedPreferences prefs = await SharedPreferences.getInstance();
      final String? raw = prefs.getString(_storeKey);

      if (raw != null && raw.isNotEmpty) {
        final dynamic decoded = jsonDecode(raw);

        if (decoded is Map && decoded['schema'] == _schema) {
          saved = Map<String, dynamic>.from(decoded);
        }
      }
    } catch (_) {
      // A corrupt or unavailable store must never block startup, so fall
      // through to the seed rather than leaving the app with no campus.
      saved = null;
    }

    _db = saved ?? buildDemoCampus();

    if (saved == null) {
      await _persist();
    }

    return _db!;
  }

  /// Drops the in-memory campus so the next [ready] re-reads the store, or
  /// re-seeds it if the store is empty.
  ///
  /// Test-only. Nothing in the app calls this: the document is meant to
  /// outlive every screen, and a fresh launch is meant to find the campus
  /// exactly as the last one left it.
  @visibleForTesting
  void debugReset() {
    _db = null;
    _loading = null;
  }

  Map<String, dynamic> get _state {
    final Map<String, dynamic>? db = _db;

    if (db == null) {
      throw const LocalBackendException(
        'The offline campus is still loading. Pull to refresh in a moment.',
      );
    }

    return db;
  }

  /// Writes the campus back to the preference store.
  ///
  /// Best effort: a full disk must not turn a working sign-in into an
  /// error, so a write failure is swallowed and the in-memory document
  /// stays authoritative for this session.
  Future<void> _persist() async {
    final Map<String, dynamic>? db = _db;

    if (db == null) {
      return;
    }

    try {
      final SharedPreferences prefs = await SharedPreferences.getInstance();

      await prefs.setString(_storeKey, jsonEncode(db));
    } catch (_) {
      // Kept in memory only.
    }
  }

  // ==========================================================
  // RAW ROW ACCESS
  // ==========================================================

  /// The live rows under [key].
  ///
  /// A cast view rather than a copy: the list and the maps in it are the
  /// stored ones, so editing a row or appending a new one edits the
  /// campus. Copying here would silently drop every insert.
  List<Map<String, dynamic>> _rows(String key) {
    final dynamic raw = _state[key];

    if (raw is List<Map<String, dynamic>>) {
      return raw;
    }

    if (raw is List) {
      return raw.cast<Map<String, dynamic>>();
    }

    final List<Map<String, dynamic>> fresh = <Map<String, dynamic>>[];
    _state[key] = fresh;

    return fresh;
  }

  /// The rows of a list nested inside an office row.
  ///
  /// Services and stations belong to an office rather than standing on
  /// their own, so they are flattened out on demand.
  List<Map<String, dynamic>> _rowsOf(String containerKey, String listKey) {
    final List<Map<String, dynamic>> out = <Map<String, dynamic>>[];

    for (final Map<String, dynamic> row in _rows(containerKey)) {
      final dynamic nested = row[listKey];

      if (nested is List) {
        out.addAll(nested.whereType<Map<String, dynamic>>());
      }
    }

    return out;
  }

  List<Map<String, dynamic>> get _services => _rowsOf('offices', 'services');

  List<Map<String, dynamic>> get _stations => _rowsOf('offices', 'stations');

  Map<String, dynamic>? _findById(List<Map<String, dynamic>> rows, int id) {
    for (final Map<String, dynamic> row in rows) {
      if (QfValue.toInt(row['id']) == id) {
        return row;
      }
    }

    return null;
  }

  Map<String, dynamic>? _rowById(String key, int id) =>
      _findById(_rows(key), id);

  Map<String, dynamic> _requireService(int serviceId) {
    final Map<String, dynamic>? row = _findById(_services, serviceId);

    if (row == null) {
      throw const LocalBackendException('That service is no longer offered.');
    }

    return row;
  }

  Map<String, dynamic> _requireRow(String key, int id, String missing) {
    final Map<String, dynamic>? row = _rowById(key, id);

    if (row == null) {
      throw LocalBackendException(missing);
    }

    return row;
  }

  Map<String, dynamic> _officeRow(int officeId) =>
      _requireRow('offices', officeId, 'That office is no longer available.');

  Map<String, dynamic> _accountRowByEmail(String email) {
    final String needle = email.trim().toLowerCase();

    for (final Map<String, dynamic> row in _rows('accounts')) {
      if (QfValue.toStr(row['email']).toLowerCase() == needle) {
        return row;
      }
    }

    return <String, dynamic>{};
  }

  /// Reduces a college ID to its digits.
  ///
  /// A student will type an ID however they were issued it: `24-019083`,
  /// `24019083`, or with stray spaces. Comparing the digits means the stored
  /// format never has to be guessed.
  static String _idKey(String value) => value.replaceAll(RegExp(r'[^0-9]'), '');

  /// The fewest digits an ID may have and still be looked up.
  ///
  /// A range rather than an exact count, because the length of a college ID is
  /// the registrar's business and not this app's: a build seeded with nine
  /// digit IDs must not lock out an eight digit one. Five or fewer is refused
  /// because no real ID is that short and the lookup would be scanning the whole
  /// campus on every keystroke-sized guess.
  static const int _idDigitsMin = 6;

  /// Finds an account by college ID or by email address.
  ///
  /// Mirrors `User::findByIdentifier()` on the server: a student signs in with
  /// their ID, staff and administrators with their email, and both work either
  /// way. Email is matched case-insensitively because that is what every mail
  /// provider preserves and what people retype differently.
  Map<String, dynamic> _accountRowByIdentifier(String identifier) {
    final String trimmed = identifier.trim();

    if (trimmed.isEmpty) {
      return <String, dynamic>{};
    }

    if (trimmed.contains('@')) {
      final String needle = trimmed.toLowerCase();

      for (final Map<String, dynamic> row in _rows('accounts')) {
        if (QfValue.toStr(row['email']).toLowerCase() == needle) {
          return row;
        }
      }

      return <String, dynamic>{};
    }

    final String needle = _idKey(trimmed);

    if (needle.length < _idDigitsMin) {
      return <String, dynamic>{};
    }

    for (final Map<String, dynamic> row in _rows('accounts')) {
      final String stored = _idKey(QfValue.toStr(row['student_id']));

      if (stored.isNotEmpty && stored == needle) {
        return row;
      }
    }

    return <String, dynamic>{};
  }

  Map<String, dynamic> _requireAccount(int userId) =>
      _requireRow('accounts', userId, 'That account no longer exists.');

  /// The public face of an account row, without its password.
  AppUser _asUser(Map<String, dynamic> row) =>
      AppUser.fromMap(Map<String, dynamic>.from(row));

  // ==========================================================
  // HYDRATION
  // ==========================================================

  Map<String, dynamic> _officeRef(int officeId) {
    final Map<String, dynamic> row =
        _rowById('offices', officeId) ?? <String, dynamic>{};

    return <String, dynamic>{
      'id': officeId,
      'name': QfValue.toStr(row['name'], fallback: 'Office'),
      'code': QfValue.toStr(row['code']),
    };
  }

  Map<String, dynamic> _serviceRef(int serviceId) {
    final Map<String, dynamic> row =
        _findById(_services, serviceId) ?? <String, dynamic>{};

    return <String, dynamic>{
      'id': serviceId,
      'name': QfValue.toStr(row['name']),
    };
  }

  Map<String, dynamic> _stationRef(int stationId) {
    final Map<String, dynamic> row =
        _findById(_stations, stationId) ?? <String, dynamic>{};

    return <String, dynamic>{
      'id': stationId,
      'name': QfValue.toStr(row['name']),
      'location': row['location'],
    };
  }

  Map<String, dynamic> _courseMap(int courseId) {
    final Map<String, dynamic>? row = _rowById('courses', courseId);

    return row == null ? <String, dynamic>{} : Map<String, dynamic>.from(row);
  }

  Map<String, dynamic> _staffRef(int userId) {
    final Map<String, dynamic> row =
        _rowById('accounts', userId) ?? <String, dynamic>{};

    return <String, dynamic>{'id': userId, 'name': QfValue.toStr(row['name'])};
  }

  Map<String, dynamic>? _batchMap(int batchId) {
    final Map<String, dynamic>? row = _rowById('batches', batchId);

    return row == null ? null : Map<String, dynamic>.from(row);
  }

  /// Expands a stored ticket row into the nested shape
  /// [QueueTicket.fromMap] expects, resolving every reference inline.
  Map<String, dynamic> _ticketView(Map<String, dynamic> row) {
    final int officeId = QfValue.toInt(row['office_id']);
    final int serviceId = QfValue.toInt(row['service_id']);
    final int stationId = QfValue.toInt(row['station_id']);
    final int courseId = QfValue.toInt(row['course_id']);
    final int? servedBy = QfValue.toIntOrNull(row['served_by']);

    return <String, dynamic>{
      'id': row['id'],
      'queue_number': row['queue_number'],
      'status': row['status'],
      'priority': row['priority'],
      'purpose': row['purpose'],
      'year_level': row['year_level'],
      'has_evidence': row['has_evidence'],
      'office': _officeRef(officeId),
      'service': _serviceRef(serviceId),
      'station': _stationRef(stationId),
      'course': courseId == 0 ? null : _courseMap(courseId),
      'deployment': _batchMap(QfValue.toInt(row['batch_id'])),
      'served_by': servedBy == null ? null : _staffRef(servedBy),
      'called_at': row['called_at'],
      'served_at': row['served_at'],
      'created_at': row['created_at'],
      'call_duration_minutes': row['call_duration_minutes'] ?? QueueTicket.defaultCallDurationMinutes,
      'expires_at': row['expires_at'],
      'remaining_seconds': 0,
      'is_expired': false,
      'arrived_at': row['arrived_at'],
      'is_arrived': row['arrived_at'] != null,
      'cancelled_at': row['cancelled_at'],
      'cancel_reason': row['cancel_reason'],
    };
  }

  List<Map<String, dynamic>> _ticketViews(List<Map<String, dynamic>> rows) =>
      rows.map(_ticketView).toList();

  /// Minutes past midnight for an `HH:MM` column, or [fallback].
  int _minutesOfDay(dynamic value, int fallback) {
    final String? text = QfValue.toStrOrNull(value);

    if (text == null || !text.contains(':')) {
      return fallback;
    }

    final List<String> parts = text.split(':');
    final int? hour = int.tryParse(parts.first);

    if (hour == null) {
      return fallback;
    }

    return hour * 60 + (parts.length > 1 ? int.tryParse(parts[1]) ?? 0 : 0);
  }

  /// Whether an office is inside its own operating hours right now.
  ///
  /// An inactive office is never open, which is what gives the guidance
  /// copy a second reason to read something other than "no batch yet".
  bool _withinHours(Map<String, dynamic> office) {
    if (office['is_active'] != true) {
      return false;
    }

    final DateTime now = DateTime.now();
    final int minutes = now.hour * 60 + now.minute;

    return minutes >= _minutesOfDay(office['opening_time'], 0) &&
        minutes <= _minutesOfDay(office['closing_time'], 24 * 60);
  }

  /// The batch currently governing an office, or null when none is open.
  QueueBatch? _activeBatch(int officeId) {
    QueueBatch? best;

    for (final Map<String, dynamic> row in _rows('batches')) {
      if (QfValue.toInt(row['office_id']) != officeId) {
        continue;
      }

      final QueueBatch batch = QueueBatch.fromMap(row);

      if (batch.isClosed || batch.isCompleted) {
        continue;
      }

      if (best == null || batch.id > best.id) {
        best = batch;
      }
    }

    return best;
  }

  /// An office row with its live counters filled in.
  Map<String, dynamic> _officeView(int officeId) {
    final Map<String, dynamic> view = Map<String, dynamic>.from(
      _officeRow(officeId),
    );

    final QueueBatch? batch = _activeBatch(officeId);
    final bool windowOpen = _withinHours(view);
    final bool usableBatch = batch != null && !batch.isClosed;

    // Mirrors ApiPresenter::slotState(): no deployed batch means nothing can be
    // issued, so it reports 0 available against the office limit rather than
    // reading as a full open queue.
    final int limit = QfValue.toInt(view['max_slots'], fallback: 100);
    final int total = batch == null ? limit : batch.maxSlots;
    final int available = batch == null ? 0 : (batch.maxSlots - batch.submittedCount).clamp(0, total);

    view['window_open'] = windowOpen;
    view['accepting'] =
        view['is_active'] == true && windowOpen && usableBatch && available > 0;
    view['slots_deployed'] = batch != null;
    view['total_slots'] = total;
    view['used_slots'] = batch?.submittedCount ?? 0;
    view['available_slots'] = available;
    view['queue_status'] = view['accepting'] == true ? 'open' : 'closed';
    view['is_registrar_office'] = AppConstants.isRegistrarOfficeCode(
      QfValue.toStrOrNull(view['code']),
    );
    view['open_batch'] = batch != null && batch.isOpen ? batch.toMap() : null;
    view['active_batch'] = batch?.toMap();
    view['waiting_count'] = _ticketsIn(
      officeId,
      AppConstants.statusWaiting,
    ).length;
    view['serving_count'] = _ticketsIn(
      officeId,
      AppConstants.statusServing,
    ).length;

    return view;
  }

  /// Every office, hydrated. Pass `includeInactive: false` to drop the
  /// ones an administrator has switched off.
  Future<List<Office>> offices({bool includeInactive = true}) async {
    await ready();

    final List<Map<String, dynamic>> views = _rows('offices')
        .where(
          (Map<String, dynamic> row) =>
              includeInactive || row['is_active'] == true,
        )
        .map(
          (Map<String, dynamic> row) => _officeView(QfValue.toInt(row['id'])),
        )
        .toList();

    return views.map(Office.fromMap).toList(growable: false);
  }

  /// The status re-read the office screens ask for, satisfied here from the
  /// same rows the catalogue is built from.
  ///
  /// The local campus has no network and no staff toggling switches behind
  /// it, so the answer cannot change between two calls - but the method still
  /// exists, because a screen that was written to re-read status before
  /// selling a ticket must not have to care which transport is running.
  @override
  Future<List<Office>> officeCatalogue(AppUser user) => offices();

  @override
  Future<RegistrarCourseList> registrarCourses(AppUser user) async {
    await ready();

    final String? enrolled = user.asStudent.course?.trim().toUpperCase();

    final List<RegistrarCourseOption> options = <RegistrarCourseOption>[];

    for (final Map<String, dynamic> office in _rows('offices')) {
      final String code = QfValue.toStr(office['code']);
      final int officeId = QfValue.toInt(office['id']);

      final Map<String, dynamic> view = _officeView(officeId);

      // Two shapes, both of them the Registrar.
      //
      // A per-course desk (`REG-BSIT`) is one course queue of its own. The
      // undivided `REG` office of the demo campus is a single counter that
      // serves every course attached to it, so each of those courses becomes a
      // row over the same batch. Reading only the `REG-` prefix would leave the
      // demo's Registrar card with an empty picker.
      List<Map<String, dynamic>> courses;

      if (AppConstants.isRegistrarOfficeCode(code)) {
        final Map<String, dynamic>? course = _courseRowFor(
          code.substring(AppConstants.registrarOfficePrefix.length),
        );

        courses = <Map<String, dynamic>>[?course];
      } else if (AppConstants.isQueueOffice(QfValue.toStr(office['name']))) {
        courses = _rows('courses')
            .where(
              (Map<String, dynamic> c) => QfValue.toInt(c['office_id']) == officeId,
            )
            .toList();
      } else {
        continue;
      }

      for (final Map<String, dynamic> course in courses) {
        final String courseCode = QfValue.toStr(course['code']).toUpperCase();

        options.add(
          RegistrarCourseOption(
            courseId: QfValue.toInt(course['id']),
            code: courseCode,
            name: QfValue.toStr(course['name'], fallback: courseCode),
            officeId: officeId,
            officeName: QfValue.toStr(office['name']),
            slotsDeployed: QfValue.toBool(view['slots_deployed']),
            availableSlots: QfValue.toInt(view['available_slots']),
            totalSlots: QfValue.toInt(view['total_slots'], fallback: 100),
            queueStatus: QfValue.toStrOrNull(view['queue_status']) ?? 'closed',
            accepting: QfValue.toBool(view['accepting']),
            // Live queue counters for this course queue alone, with the same
            // status rules as the per-office board: cancelled and no-show
            // rows are excluded from all three.
            waitingCount: _ticketsIn(officeId, AppConstants.statusWaiting).length,
            servingCount: _ticketsIn(officeId, AppConstants.statusServing).length,
            servedCount: _ticketsIn(officeId, AppConstants.statusServed).length,
            // The switch rides along with the row so a course queue in the
            // picker is refused for the same reason its office is: the local
            // campus keeps one status per office, and a course row that
            // claimed online over a shut office would open a second door the
            // server never unlocked.
            status:
                QfValue.toStrOrNull(view['status']) ??
                AppConstants.officeStatusOffline,
            isEnrolledCourse: courseCode == enrolled,
            services: <OfficeService>[
              for (final Map<String, dynamic> service in _servicesFor(officeId))
                OfficeService.fromMap(service),
            ],
          ),
        );
      }
    }

    // The campus order first, then anything else the database happens to carry,
    // rather than leaving the unknown codes to sort wherever they land.
    options.sort(
      (RegistrarCourseOption a, RegistrarCourseOption b) =>
          AppConstants.registrarCourses.indexOf(a.code).compareTo(
            AppConstants.registrarCourses.indexOf(b.code),
          ),
    );

    return RegistrarCourseList(
      courses: options,
      totalAvailableSlots: options.fold<int>(
        0,
        (int sum, RegistrarCourseOption c) => sum + c.availableSlots,
      ),
      totalSlots: options.fold<int>(
        0,
        (int sum, RegistrarCourseOption c) => sum + c.totalSlots,
      ),
    );
  }

  /// The course row whose code is [code], or null when the seed has none.
  Map<String, dynamic>? _courseRowFor(String code) {
    final String wanted = code.trim().toUpperCase();

    for (final Map<String, dynamic> course in _rows('courses')) {
      if (QfValue.toStr(course['code']).toUpperCase() == wanted) {
        return course;
      }
    }

    return null;
  }

  /// The id of the course row for [code], or null when there is none.
  ///
  /// Used by the Registrar guard so the stored ticket carries the
  /// server-resolved course rather than whatever id the caller posted.
  int? _courseIdFor(String code) {
    final Map<String, dynamic>? row = _courseRowFor(code);

    if (row == null) {
      return null;
    }

    final int id = QfValue.toInt(row['id']);

    return id == 0 ? null : id;
  }

  /// One office's active services, in the order the counter lists them.
  ///
  /// Services are nested inside the office rows rather than tabled on their
  /// own, the same shape `_officeView` reads them from.
  List<Map<String, dynamic>> _servicesFor(int officeId) {
    final List<Map<String, dynamic>> services = _services
        .where(
          (Map<String, dynamic> s) =>
              QfValue.toInt(s['office_id']) == officeId && s['is_active'] == true,
        )
        .toList();

    services.sort(
      (Map<String, dynamic> a, Map<String, dynamic> b) =>
          QfValue.toInt(a['id']).compareTo(QfValue.toInt(b['id'])),
    );

    return services;
  }

  List<Announcement> _publishedAnnouncements([String? audienceRole]) {
    final List<Announcement> all = _rows('announcements')
        .map(Announcement.fromMap)
        .where(
          (Announcement a) => audienceRole == null
              ? (a.isActive && a.status == 'published')
              : a.isLiveForRole(audienceRole),
        )
        .toList();

    all.sort((Announcement a, Announcement b) {
      final DateTime left = a.createdAt ?? DateTime(1970);
      final DateTime right = b.createdAt ?? DateTime(1970);

      return right.compareTo(left);
    });

    return all;
  }

  List<Map<String, dynamic>> _ticketsIn(int officeId, String status) =>
      _rows('tickets').where((Map<String, dynamic> row) {
        return QfValue.toInt(row['office_id']) == officeId &&
            QfValue.toStrOrNull(row['status']) == status;
      }).toList();

  SystemSettings get settings => SystemSettings.fromMap(_settingsMap());

  Map<String, dynamic> _settingsMap() {
    final dynamic raw = _state['settings'];

    return raw is Map
        ? Map<String, dynamic>.from(raw)
        : SystemSettings().toMap();
  }

  // ==========================================================
  // ACCOUNTS AND SESSIONS
  // ==========================================================

  /// Checks an identifier and a password against the local accounts.
  ///
  /// The identifier is a college ID or an email address, resolved exactly the
  /// way the server resolves it, so a demo build and a live build answer the
  /// same question the same way.
  ///
  /// An unknown identifier and a wrong password give the same sentence on
  /// purpose: there is no server response here to leak, but there is no reason
  /// to help someone enumerate accounts either.
  @override
  Future<AppUser> signIn({
    required String identifier,
    required String password,
  }) async {
    await ready();

    final Map<String, dynamic> row = _accountRowByIdentifier(identifier);

    if (row.isEmpty || QfValue.toStr(row['password']) != password) {
      throw const LocalBackendException(_badCredentials);
    }

    if (row['is_active'] != true) {
      throw const LocalBackendException(
        'This account has been deactivated. Please see the registrar.',
      );
    }

    return _asUser(row);
  }

  /// Nothing to revoke.
  ///
  /// A local session lives on the device and is ended by signing out of the
  /// app; it cannot be withdrawn from anywhere else, which is also why
  /// `SessionStore` holds no token for this transport.
  @override
  Future<void> signOut() async {}

  /// The current state of an account, which is how a cached session is
  /// re-checked on launch: an administrator may have deactivated it.
  @override
  Future<AppUser> account(AppUser user) async {
    await ready();

    final Map<String, dynamic> row = _requireAccount(user.id);
    final AppUser fresh = _asUser(row);

    if (!fresh.isActive) {
      throw const LocalBackendException(
        'This account has been deactivated. Please see the registrar.',
      );
    }

    return fresh;
  }

  // ==========================================================
  // STUDENT
  // ==========================================================

  @override
  Future<StudentBootstrap> studentBootstrap(AppUser user) async {
    await ready();

    final Map<String, dynamic> row = _requireAccount(user.id);

    return StudentBootstrap.fromMap(<String, dynamic>{
      'student': _asUser(row).asStudent.toMap(),
      'settings': _settingsMap(),
      'announcements': _publishedAnnouncements(Announcement.audienceUser)
          .map((Announcement a) => a.toMap())
          .toList(),
      'courses': _rows(
        'courses',
      ).map((Map<String, dynamic> c) => Map<String, dynamic>.from(c)).toList(),
      // Every office, including the inactive ones: the dashboard lists
      // closed offices alongside open ones, so filtering the inactive out
      // here would leave a student with nothing to explain.
      'offices': _rows('offices')
          .map((Map<String, dynamic> o) => _officeView(QfValue.toInt(o['id'])))
          .toList(),
    });
  }

  List<Map<String, dynamic>> _ticketsForStudent(int userId) =>
      _rows('tickets').where((Map<String, dynamic> row) {
        return QfValue.toInt(row['student_id']) == userId;
      }).toList();

  @override
  Future<({List<QueueTicket> tickets, TicketCounts counts})> studentTickets(
    AppUser user,
  ) async {
    await ready();

    final List<QueueTicket> tickets = _ticketsForStudent(user.id)
        .map(
          (Map<String, dynamic> row) => QueueTicket.fromMap(_ticketView(row)),
        )
        .toList();

    return (tickets: tickets, counts: TicketCounts.fromTickets(tickets));
  }

  /// The live position of one of this student's tickets.
  @override
  Future<({QueueTicket ticket, QueueProgress queue})> studentTicket(
    AppUser user,
    int ticketId,
  ) async {
    await ready();

    final List<Map<String, dynamic>> mine = _ticketsForStudent(user.id);

    final Map<String, dynamic> row = mine.firstWhere(
      (Map<String, dynamic> t) => QfValue.toInt(t['id']) == ticketId,
      orElse: () => throw const LocalBackendException(
        'That ticket is not on your account.',
      ),
    );

    final QueueTicket ticket = QueueTicket.fromMap(_ticketView(row));

    return (ticket: ticket, queue: _progressFor(row, ticket));
  }

  /// Where a ticket sits in its office's line, and how long that is.
  ///
  /// The position comes from the same priority-then-oldest order the
  /// counter console calls numbers in, so a student and the member of
  /// staff at the window always agree on whose turn it is.
  QueueProgress _progressFor(Map<String, dynamic> row, QueueTicket ticket) {
    final int officeId = QfValue.toInt(row['office_id']);
    final List<Map<String, dynamic>> line = _callOrder(
      _ticketsIn(officeId, AppConstants.statusWaiting),
    );

    int ahead = line.length;

    for (int i = 0; i < line.length; i++) {
      if (QfValue.toInt(line[i]['id']) == ticket.id) {
        ahead = i;
        break;
      }
    }
    final int perTicket = QfValue.toInt(
      _settingsMap()['default_waiting_time'],
      fallback: 5,
    );

    final QueueTicket? nowServing = _nowServing(officeId);

    return QueueProgress(
      position: ahead + 1,
      peopleAhead: ahead,
      waitingCount: line.length,
      estimatedWaitMinutes: ticket.isPwd ? 0 : ahead * perTicket,
      minutesPerTicket: perTicket,
      nowServing: nowServing?.queueNumber,
      nowServingCalledAt: nowServing?.calledAt,
    );
  }

  /// Takes a queue ticket for [user].
  @override
  Future<CreatedTicket> createTicket({
    required AppUser user,
    required int officeId,
    required int serviceId,
    required int courseId,
    String priority = AppConstants.priorityRegular,
    String? purpose,
    String? yearLevel,
    String? evidencePath,
  }) async {
    await ready();

    if (!user.role.isStudent) {
      throw const LocalBackendException(
        'Only student accounts can take a queue ticket.',
      );
    }

    final SystemSettings rules = settings;
    final int active = _ticketsForStudent(user.id).where((
      Map<String, dynamic> t,
    ) {
      final String status = QfValue.toStrOrNull(t['status']) ?? '';

      return status == AppConstants.statusWaiting ||
          status == AppConstants.statusServing;
    }).length;

    if (rules.maxActiveTicketsPerStudent > 0 &&
        active >= rules.maxActiveTicketsPerStudent) {
      throw const LocalBackendException(
        'You already have an active queue ticket. Cancel it before taking another.',
      );
    }

    final Map<String, dynamic> office = _officeView(officeId);

    // Registrar tickets are queue-assigned from the account, not the request:
    // a student registered under BSIT belongs in the BSIT queue even if the
    // caller posts another course's ids. Mirrors the API guard so demo mode
    // and production refuse a cross-course queue the same way.
    int resolvedCourseId = courseId;
    final String officeCode = QfValue.toStr(office['code']).toUpperCase();
    if (officeCode.startsWith(AppConstants.registrarOfficePrefix)) {
      final String registered =
          (user.asStudent.course ?? '').trim().toUpperCase();
      if (registered.isEmpty || !AppUser.courses.contains(registered)) {
        throw const LocalBackendException(
          'Your profile has no course set, so the Registrar cannot place you '
          'in a queue. Please contact the administrator to update your account.',
        );
      }
      if (officeCode != '${AppConstants.registrarOfficePrefix}$registered') {
        throw LocalBackendException(
          'Your account is registered under $registered, so you can only take '
          'a ticket in the $registered Registrar queue.',
        );
      }
      resolvedCourseId = _courseIdFor(registered) ?? courseId;
    }

    // Every office the student can see stays queue-able, whether or not it
    // is inside its operating hours and whether or not staff have deployed a
    // batch. Outside those hours the number simply waits, and a ticket raised
    // without a batch is still served normally because the counter works from
    // the ticket's own office. Only an office an administrator switched off
    // is refused here.
    if (office['is_active'] != true) {
      throw const LocalBackendException('That office is closed.');
    }

    final Map<String, dynamic> service = _requireService(serviceId);

    if (QfValue.toInt(service['office_id']) != officeId) {
      throw const LocalBackendException(
        'That service does not belong to this office.',
      );
    }

    final QueueBatch? batch = _activeBatch(officeId);

    if (batch != null && batch.slotsRemaining <= 0) {
      throw const LocalBackendException(
        'This batch is full. Please wait for the next one to open.',
      );
    }

    final Map<String, dynamic> row = <String, dynamic>{
      'id': _nextTicketId(),
      'queue_number': _nextQueueNumber(officeId),
      'status': AppConstants.statusWaiting,
      'priority': priority,
      'purpose': QfValue.toStrOrNull(purpose),
      'year_level': QfValue.toStrOrNull(yearLevel),
      'has_evidence': evidencePath != null && evidencePath.isNotEmpty,
      'student_id': user.id,
      'office_id': officeId,
      'service_id': serviceId,
      'station_id': QfValue.toInt(service['station_id']),
      'course_id': resolvedCourseId,
      'batch_id': batch?.id ?? 0,
      'served_by': null,
      'called_at': null,
      'served_at': null,
      'created_at': DateTime.now().toIso8601String(),
      'call_duration_minutes': QueueTicket.defaultCallDurationMinutes,
      'expires_at': null,
      'arrived_at': null,
      'cancelled_at': null,
      'cancel_reason': null,
    };

    _rows('tickets').add(row);

    if (batch != null) {
      final Map<String, dynamic>? batchRow = _rowById('batches', batch.id);

      if (batchRow != null) {
        batchRow['submitted_count'] =
            QfValue.toInt(batchRow['submitted_count']) + 1;

        if (QfValue.toInt(batchRow['submitted_count']) >=
            QfValue.toInt(batchRow['max_slots'], fallback: 100)) {
          batchRow['status'] = 'full';
        }
      }
    }

    await _persist();

    final QueueTicket ticket = QueueTicket.fromMap(_ticketView(row));

    return CreatedTicket(
      ticket: ticket,
      queue: _progressFor(row, ticket),
      message: 'Queue ${ticket.queueNumber} generated successfully.',
    );
  }

  /// Cancels a waiting ticket belonging to [user].
  @override
  Future<QueueTicket> cancelTicket(AppUser user, int ticketId) async {
    await ready();

    final Map<String, dynamic> row = _ticketsForStudent(user.id).firstWhere(
      (Map<String, dynamic> t) => QfValue.toInt(t['id']) == ticketId,
      orElse: () => throw const LocalBackendException(
        'That ticket is not on your account.',
      ),
    );

    return _cancel(row, 'Only a waiting ticket can be cancelled.');
  }

  Future<QueueTicket> _cancel(
    Map<String, dynamic> row,
    String refuseMessage,
  ) async {
    if (QfValue.toStrOrNull(row['status']) != AppConstants.statusWaiting) {
      throw LocalBackendException(refuseMessage);
    }

    row['status'] = AppConstants.statusCancelled;

    final int officeId = QfValue.toInt(row['office_id']);
    final Map<String, dynamic>? batch = _rowById(
      'batches',
      QfValue.toInt(row['batch_id']),
    );

    if (batch != null) {
      final int submitted = QfValue.toInt(batch['submitted_count']);

      if (submitted > 0) {
        batch['submitted_count'] = submitted - 1;
      }

      if (batch['status'] == 'full' &&
          QfValue.toInt(batch['submitted_count']) <
              QfValue.toInt(batch['max_slots'], fallback: 100)) {
        batch['status'] = 'open';
      }
    }

    // A cancelled ticket frees the number back into the pool.
    final String code = QfValue.toStr(_officeRef(officeId)['code']);
    final int number = _numberFrom(row['queue_number']);

    if (number > 0) {
      final Map<String, dynamic> sequences = _sequences;
      final int highest = QfValue.toInt(sequences[code]);

      if (number == highest) {
        sequences[code] = number - 1;
        _state['sequences'] = sequences;
      }
    }

    await _persist();

    return QueueTicket.fromMap(_ticketView(row));
  }

  // ==========================================================
  // LIVE BOARDS AND COUNTERS
  // ==========================================================

  /// The "now serving" board for one office.
  @override
  Future<LiveOfficeBoard> liveBoard(int officeId) async {
    await ready();

    final Map<String, dynamic> office = _officeRow(officeId);
    final List<Map<String, dynamic>> served = _recentlyServed(officeId);
    final QueueTicket? nowServing = _nowServing(officeId);

    return LiveOfficeBoard.fromMap(<String, dynamic>{
      'office': _officeRef(officeId),
      'now_serving': nowServing == null
          ? null
          : <String, dynamic>{
              'queue_number': nowServing.queueNumber,
              'called_at': nowServing.calledAt?.toIso8601String(),
            },
      'waiting_count': _ticketsIn(officeId, AppConstants.statusWaiting).length,
      'serving_count': _ticketsIn(officeId, AppConstants.statusServing).length,
      'served_count': served.length,
      'recent_served': served.map((Map<String, dynamic> row) {
        final QueueTicket ticket = QueueTicket.fromMap(_ticketView(row));

        return <String, dynamic>{
          'queue_number': ticket.queueNumber,
          'served_at': ticket.servedAt?.toIso8601String(),
        };
      }).toList(),
      'window_open': _withinHours(office),
    });
  }

  /// The working set for one office: who is at the counter, who is
  /// waiting, and what the counters say.
  @override
  Future<OfficeQueue> officeQueue(int officeId) async {
    await ready();

    final Map<String, dynamic> office = _officeRow(officeId);
    final List<Map<String, dynamic>> waiting = _callOrder(
      _ticketsIn(officeId, AppConstants.statusWaiting),
    );
    final List<Map<String, dynamic>> served = _recentlyServed(officeId);

    return OfficeQueue.fromMap(<String, dynamic>{
      'office': _officeRef(officeId),
      'now_serving': _nowServing(officeId)?.toMap(),
      'waiting': _ticketViews(waiting),
      'recently_served': _ticketViews(served),
      'batch': _activeBatch(officeId)?.toMap(),
      'window_open': _withinHours(office),
      'waiting_count': waiting.length,
      'serving_count': _ticketsIn(officeId, AppConstants.statusServing).length,
      'served_count': served.length,
    });
  }

  /// The ticket at the counter: the most recently called one still
  /// being served.
  QueueTicket? _nowServing(int officeId) {
    final List<QueueTicket> serving =
        _ticketsIn(officeId, AppConstants.statusServing)
            .map(
              (Map<String, dynamic> row) =>
                  QueueTicket.fromMap(_ticketView(row)),
            )
            .toList();

    if (serving.isEmpty) {
      return null;
    }

    serving.sort((QueueTicket a, QueueTicket b) {
      final DateTime left = a.calledAt ?? DateTime(1970);
      final DateTime right = b.calledAt ?? DateTime(1970);

      return right.compareTo(left);
    });

    return serving.first;
  }

  /// Priority tickets first, then oldest first.
  ///
  /// This is the order a counter works through a queue, and the order the
  /// student's live position is counted in.
  List<Map<String, dynamic>> _callOrder(List<Map<String, dynamic>> rows) {
    final List<QueueTicket> ordered = rows
        .map(
          (Map<String, dynamic> row) => QueueTicket.fromMap(_ticketView(row)),
        )
        .toList();

    ordered.sort((QueueTicket a, QueueTicket b) {
      if (a.isPwd != b.isPwd) {
        return a.isPwd ? -1 : 1;
      }

      final DateTime left = a.createdAt ?? DateTime(1970);
      final DateTime right = b.createdAt ?? DateTime(1970);

      return left.compareTo(right);
    });

    final List<Map<String, dynamic>> out = <Map<String, dynamic>>[];

    for (final QueueTicket ticket in ordered) {
      for (final Map<String, dynamic> row in rows) {
        if (QfValue.toInt(row['id']) == ticket.id) {
          out.add(row);
          break;
        }
      }
    }

    return out;
  }

  /// The tickets most recently served at an office, newest first.
  List<Map<String, dynamic>> _recentlyServed(int officeId, {int limit = 5}) {
    final List<Map<String, dynamic>> served = _rows('tickets').where((
      Map<String, dynamic> row,
    ) {
      return QfValue.toInt(row['office_id']) == officeId &&
          QfValue.toStrOrNull(row['status']) == AppConstants.statusServed;
    }).toList();

    served.sort((Map<String, dynamic> a, Map<String, dynamic> b) {
      final DateTime left = QfValue.toDate(a['served_at']) ?? DateTime(1970);
      final DateTime right = QfValue.toDate(b['served_at']) ?? DateTime(1970);

      return right.compareTo(left);
    });

    return served.take(limit).toList();
  }

  /// Calls the next number at [officeId], or [ticketId] when the console
  /// picked a specific row.
  @override
  Future<QueueTicket> callNext({int? officeId, int? ticketId}) async {
    await ready();

    Map<String, dynamic> row;

    if (ticketId != null) {
      row = _requireRow('tickets', ticketId, 'That ticket no longer exists.');

      final int ticketOffice = QfValue.toInt(row['office_id']);

      if (officeId != null && ticketOffice != officeId) {
        throw const LocalBackendException(
          'That ticket belongs to a different counter.',
        );
      }

      if (QfValue.toStrOrNull(row['status']) != AppConstants.statusWaiting) {
        throw const LocalBackendException(
          'That number is not waiting any more.',
        );
      }
    } else {
      final int counter =
          officeId ??
          (throw const LocalBackendException('Pick a counter first.'));

      final List<Map<String, dynamic>> line = _callOrder(
        _ticketsIn(counter, AppConstants.statusWaiting),
      );

      if (line.isEmpty) {
        throw const LocalBackendException('Nobody is waiting in this queue.');
      }

      row = line.first;
    }

    row['status'] = AppConstants.statusServing;
    row['called_at'] = DateTime.now().toIso8601String();
    row['call_duration_minutes'] = QueueTicket.defaultCallDurationMinutes;
    row['expires_at'] = DateTime.now()
        .add(const Duration(minutes: QueueTicket.defaultCallDurationMinutes))
        .toIso8601String();
    row['arrived_at'] = null;
    row['cancelled_at'] = null;
    row['cancel_reason'] = null;

    await _persist();

    return QueueTicket.fromMap(_ticketView(row));
  }

  /// Marks the number at the counter as served.
  ///
  /// [officeId] is accepted for symmetry with the shared contract and ignored:
  /// the ticket carries its own office, so there is nothing to check against.
  @override
  Future<QueueTicket> serveTicket(
    int ticketId,
    AppUser actor, {
    int? officeId,
  }) async {
    await ready();

    final Map<String, dynamic> row = _requireRow(
      'tickets',
      ticketId,
      'That ticket no longer exists.',
    );

    if (QfValue.toStrOrNull(row['status']) != AppConstants.statusServing) {
      throw const LocalBackendException(
        'That number is not being served right now.',
      );
    }

    row['status'] = AppConstants.statusServed;
    row['served_at'] = DateTime.now().toIso8601String();
    row['served_by'] = actor.id;
    row['expires_at'] = null;

    _countServed(QfValue.toInt(row['batch_id']));

    await _persist();

    return QueueTicket.fromMap(_ticketView(row));
  }

  /// Sends an unanswered number back to the waiting list.
  ///
  /// [officeId] is accepted for symmetry with the shared contract and
  /// ignored, for the same reason as in [serveTicket].
  @override
  Future<QueueTicket> markNoShow(int ticketId, {int? officeId}) async {
    await ready();

    final Map<String, dynamic> row = _requireRow(
      'tickets',
      ticketId,
      'That ticket no longer exists.',
    );

    if (QfValue.toStrOrNull(row['status']) != AppConstants.statusServing) {
      throw const LocalBackendException(
        'There is no number being served right now.',
      );
    }

    row['status'] = AppConstants.statusNoShow;
    row['no_show_at'] = DateTime.now().toIso8601String();
    row['arrived_at'] = null;

    await _persist();

    return QueueTicket.fromMap(_ticketView(row));
  }

  /// Administrator-only: cancels a ticket in any status.
  @override
  Future<QueueTicket> cancelAny(int ticketId) async {
    await ready();

    final Map<String, dynamic> row = _requireRow(
      'tickets',
      ticketId,
      'That ticket no longer exists.',
    );

    if (QfValue.toStrOrNull(row['status']) == AppConstants.statusCancelled) {
      throw const LocalBackendException('That ticket is already cancelled.');
    }

    if (QfValue.toStrOrNull(row['status']) == AppConstants.statusServed) {
      throw const LocalBackendException('A served ticket cannot be cancelled.');
    }

    return _cancel(row, 'That ticket can no longer be cancelled.');
  }

  void _countServed(int batchId) {
    final Map<String, dynamic>? batch = _rowById('batches', batchId);

    if (batch == null) {
      return;
    }

    batch['served_count'] = QfValue.toInt(batch['served_count']) + 1;
  }

  /// Opens a new batch of queue slots for an office.
  Future<QueueBatch> deployBatch({
    required int officeId,
    required int batchNo,
    int? maxSlots,
  }) async {
    await ready();

    final Map<String, dynamic> office = _officeRow(officeId);
    final int slots =
        maxSlots ?? QfValue.toInt(office['max_slots'], fallback: 100);

    // Only one batch may govern an office at a time, so deploying a new
    // one closes whatever was open before it.
    for (final Map<String, dynamic> row in _rows('batches')) {
      if (QfValue.toInt(row['office_id']) == officeId &&
          QfValue.toStrOrNull(row['status']) != 'closed') {
        row['status'] = 'closed';
        row['closed_at'] = DateTime.now().toIso8601String();
      }
    }

    int nextId = 1;

    for (final Map<String, dynamic> row in _rows('batches')) {
      nextId = max(nextId, QfValue.toInt(row['id']) + 1);
    }

    final Map<String, dynamic> row = <String, dynamic>{
      'id': nextId,
      'office_id': officeId,
      'batch_no': batchNo,
      'status': 'open',
      'max_slots': slots,
      'submitted_count': 0,
      'served_count': 0,
      'opened_at': DateTime.now().toIso8601String(),
      'closed_at': null,
      'completed_at': null,
    };

    _rows('batches').add(row);

    await _persist();

    return QueueBatch.fromMap(row);
  }

  /// Closes an open batch, which stops an office issuing numbers.
  Future<QueueBatch> closeBatch(int batchId) async {
    await ready();

    final Map<String, dynamic> row = _requireRow(
      'batches',
      batchId,
      'That batch no longer exists.',
    );

    if (QfValue.toStrOrNull(row['status']) == 'closed') {
      throw const LocalBackendException('That batch is already closed.');
    }

    row['status'] = 'closed';
    row['closed_at'] = DateTime.now().toIso8601String();

    await _persist();

    return QueueBatch.fromMap(row);
  }

  // ==========================================================
  // STAFF AND ADMIN PAYLOADS
  // ==========================================================

  @override
  Future<StaffWorkspace> staffWorkspace(AppUser user) async {
    await ready();

    return StaffWorkspace.fromMap(<String, dynamic>{
      'user': _asUser(_requireAccount(user.id)).toMap(),
      'offices': _rows('offices')
          .map((Map<String, dynamic> o) => _officeView(QfValue.toInt(o['id'])))
          .toList(),
      'announcements': _publishedAnnouncements(Announcement.audienceStaff)
          .map((Announcement a) => a.toMap())
          .toList(),
      'settings': _settingsMap(),
    });
  }

  @override
  Future<AdminOverview> adminOverview() async {
    await ready();

    final List<QueueTicket> everyTicket = _rows('tickets')
        .map(
          (Map<String, dynamic> row) => QueueTicket.fromMap(_ticketView(row)),
        )
        .toList();

    int countRole(String role) =>
        _rows('accounts').where((Map<String, dynamic> a) {
          return QfValue.toStr(a['role']) == role;
        }).length;

    return AdminOverview.fromMap(<String, dynamic>{
      'counts': TicketCounts.fromTickets(everyTicket).toMap(),
      'offices': _rows('offices')
          .map((Map<String, dynamic> o) => _officeView(QfValue.toInt(o['id'])))
          .toList(),
      'announcements': _publishedAnnouncements()
          .map((Announcement a) => a.toMap())
          .toList(),
      'settings': _settingsMap(),
      'student_count': countRole(AppConstants.roleStudent),
      'staff_count': countRole(AppConstants.roleStaff),
    });
  }

  // ==========================================================
  // ADMIN: ACCOUNTS
  // ==========================================================

  @override
  Future<List<AppUser>> accounts({String? role, String? search}) async {
    await ready();

    final String needle = QfValue.toStrOrNull(search)?.toLowerCase() ?? '';

    final List<Map<String, dynamic>> matched = _rows('accounts').where((
      Map<String, dynamic> row,
    ) {
      if (role != null &&
          role.isNotEmpty &&
          QfValue.toStr(row['role']) != role) {
        return false;
      }

      if (needle.isEmpty) {
        return true;
      }

      return QfValue.toStr(row['name']).toLowerCase().contains(needle) ||
          QfValue.toStr(row['email']).toLowerCase().contains(needle) ||
          QfValue.toStr(row['student_id']).toLowerCase().contains(needle);
    }).toList();

    matched.sort((Map<String, dynamic> a, Map<String, dynamic> b) {
      final int left = QfValue.toInt(a['id']);
      final int right = QfValue.toInt(b['id']);

      return right.compareTo(left);
    });

    return matched.map(_asUser).toList(growable: false);
  }

  @override
  Future<AppUser> createAccount({
    required String name,
    required String email,
    required AppRole role,
    required String password,
    String? phone,
    String? studentId,
    String? course,
    String? block,
    String? yearLevel,
    int? officeId,
  }) async {
    await ready();

    final String trimmedEmail = email.trim();

    if (name.trim().isEmpty) {
      throw const LocalBackendException('Enter a name for this account.');
    }

    if (trimmedEmail.isEmpty) {
      throw const LocalBackendException('Enter an email address.');
    }

    if (_accountRowByEmail(trimmedEmail).isNotEmpty) {
      throw const LocalBackendException(
        'That email address is already registered.',
      );
    }

    _checkPasswordStrength(password);

    int nextId = 1;

    for (final Map<String, dynamic> row in _rows('accounts')) {
      nextId = max(nextId, QfValue.toInt(row['id']) + 1);
    }

    // The academic columns only belong to a student account; sending them
    // for a staff login would leave a member of staff with a course.
    final bool student = role.isStudent;

    final Map<String, dynamic> row = <String, dynamic>{
      'id': nextId,
      'name': name.trim(),
      'email': trimmedEmail,
      'role': role.wire,
      'password': password,
      'student_id': student ? QfValue.toStrOrNull(studentId) : null,
      'course': student ? QfValue.toStrOrNull(course) : null,
      'block': student ? QfValue.toStrOrNull(block) : null,
      'year_level': student ? QfValue.toStrOrNull(yearLevel) : null,
      'phone': QfValue.toStrOrNull(phone),
      'office_id': role.isStaff ? officeId : null,
      'is_active': true,
      'created_at': DateTime.now().toIso8601String(),
    };

    _rows('accounts').add(row);

    await _persist();

    return _asUser(row);
  }

  /// Applies an administrator's edits to one account.
  ///
  /// [officeId] is recorded rather than checked here: unlike Laravel this
  /// transport has no operating-hours table to validate the office against,
  /// and the assignment is still kept on the row so the account screen and
  /// the exported document agree with what was asked for.
  @override
  Future<AppUser> updateAccount({
    required int userId,
    required AppRole role,
    required bool isActive,
    String? name,
    String? email,
    String? password,
    String? studentId,
    String? course,
    String? block,
    String? yearLevel,
    String? phone,
    int? officeId,
  }) async {
    await ready();

    final Map<String, dynamic> row = _requireAccount(userId);

    if (email != null && email.trim().isNotEmpty) {
      final String trimmed = email.trim();
      final Map<String, dynamic> clash = _accountRowByEmail(trimmed);

      if (clash.isNotEmpty && QfValue.toInt(clash['id']) != userId) {
        throw const LocalBackendException(
          'That email address is already registered.',
        );
      }

      row['email'] = trimmed;
    }

    if (name != null && name.trim().isNotEmpty) {
      row['name'] = name.trim();
    }

    if (role != AppRole.unknown) {
      row['role'] = role.wire;

      if (!role.isStudent) {
        row['student_id'] = null;
        row['course'] = null;
        row['block'] = null;
        row['year_level'] = null;
      }
    }

    row['is_active'] = isActive;

    if (officeId != null) {
      row['office_id'] = AppRole.fromWire(row['role']).isStaff
          ? officeId
          : null;
    }

    if (phone != null) {
      row['phone'] = QfValue.toStrOrNull(phone);
    }

    final bool stillStudent = AppRole.fromWire(row['role']).isStudent;

    if (stillStudent) {
      if (studentId != null) {
        row['student_id'] = QfValue.toStrOrNull(studentId);
      }

      if (course != null) {
        row['course'] = QfValue.toStrOrNull(course);
      }

      if (block != null) {
        row['block'] = QfValue.toStrOrNull(block);
      }

      if (yearLevel != null) {
        row['year_level'] = QfValue.toStrOrNull(yearLevel);
      }
    }

    if (password != null && password.isNotEmpty) {
      _checkPasswordStrength(password);
      row['password'] = password;
    }

    await _persist();

    return _asUser(row);
  }

  /// Removes an account outright, as opposed to deactivating it.
  ///
  /// Not part of [CampusBackend] and not called from anywhere, so this is not
  /// reachable - do not wire a screen to it without reading this first.
  ///
  /// The reason it is not part of the contract is that it does not mean what the
  /// server means. Here a row is deleted from the store. On the API,
  /// `DELETE /accounts/{id}` answers 200 and sets `is_active` to false, keeping
  /// the row for the audit trail - which is the behaviour the account screen
  /// already gets from deactivating, so the server has no hard delete to mirror.
  ///
  /// Calling this from the account screen would therefore make demo mode remove
  /// a person while production only stood them down. If a real delete is ever
  /// wanted, it needs an endpoint that genuinely deletes, and this method should
  /// follow that decision rather than go first.
  Future<void> deleteAccount(int userId) async {
    await ready();

    final Map<String, dynamic> row = _requireAccount(userId);

    if (QfValue.toInt(row['id']) == userId &&
        AppRole.fromWire(row['role']) == AppRole.admin) {
      // Deleting the last administrator would lock everyone out of the
      // account screen, so it is refused rather than allowed.
      int admins = 0;

      for (final Map<String, dynamic> other in _rows('accounts')) {
        if (AppRole.fromWire(other['role']) == AppRole.admin) {
          admins++;
        }
      }

      if (admins <= 1) {
        throw const LocalBackendException(
          'This is the last administrator account, so it cannot be deleted.',
        );
      }
    }

    _rows(
      'accounts',
    ).removeWhere((Map<String, dynamic> a) => QfValue.toInt(a['id']) == userId);

    await _persist();
  }

  // ==========================================================
  // PROFILE
  // ==========================================================

  /// Edits the signed-in account's own profile.
  ///
  /// Name and student number are not accepted here either: they belong to
  /// an administrator, matching the remote endpoint.
  @override
  Future<AppUser> updateProfile({
    required AppUser actor,
    String? email,
    String? block,
    String? yearLevel,
    String? phone,
    String? course,
  }) async {
    await ready();

    final Map<String, dynamic> row = _requireAccount(actor.id);

    if (email != null && email.trim().isNotEmpty) {
      final String trimmed = email.trim();
      final Map<String, dynamic> clash = _accountRowByEmail(trimmed);

      if (clash.isNotEmpty && QfValue.toInt(clash['id']) != actor.id) {
        throw const LocalBackendException(
          'That email address is already registered.',
        );
      }

      row['email'] = trimmed;
    }

    if (phone != null) {
      row['phone'] = QfValue.toStrOrNull(phone);
    }

    // The academic columns are a student's alone, so a staff member
    // editing their profile cannot set a course on their own account.
    // (Name and student number never reach this transport at all.)
    if (actor.role.isStudent) {
      if (course != null) {
        row['course'] = QfValue.toStrOrNull(course);
      }

      if (block != null) {
        row['block'] = QfValue.toStrOrNull(block);
      }

      if (yearLevel != null) {
        row['year_level'] = QfValue.toStrOrNull(yearLevel);
      }
    }

    await _persist();

    return _asUser(row);
  }

  /// Changes the signed-in account's password.
  @override
  Future<void> changePassword({
    required AppUser actor,
    required String currentPassword,
    required String password,
  }) async {
    await ready();

    final Map<String, dynamic> row = _requireAccount(actor.id);

    if (QfValue.toStr(row['password']) != currentPassword) {
      throw const LocalBackendException('Your current password is incorrect.');
    }

    _checkPasswordStrength(password);

    row['password'] = password;

    await _persist();
  }

  // ==========================================================
  // PASSWORD RESET
  // ==========================================================

  /// Issues a reset code for [identifier].
  ///
  /// The identifier is a college ID or an email address, resolved the same way
  /// sign-in resolves it. There is no mailbox to send the code to, so it is
  /// returned for the screen to show. It is still rate limited per account,
  /// and still expires, so the flow cannot be used to grind a password.
  @override
  Future<ResetCodeIssued> requestResetCode(String identifier) async {
    await ready();

    final Map<String, dynamic> account = _accountRowByIdentifier(identifier);

    if (account.isEmpty) {
      throw const LocalBackendException('No account uses that ID.');
    }

    // The code is held against the account rather than the text that was
    // typed, so a student who asks for it as `24-019083` and redeems it as
    // `24019083` is not told the code is wrong. The cool-down follows the
    // account for the same reason: two spellings are one person.
    final String key = _resetKey(account);

    final DateTime now = DateTime.now();
    final Map<String, dynamic> codes = _resetCodes();
    final Map<String, dynamic>? existing = codes[key];

    if (existing != null) {
      final DateTime issued = QfValue.toDate(existing['issued_at']) ?? now;
      final int wait = _resetCooldownSeconds - now.difference(issued).inSeconds;

      if (wait > 0) {
        return ResetCodeIssued(
          message: 'A code was requested very recently.',
          code: QfValue.toStr(existing['code']),
          expiresInMinutes: _resetLifetimeMinutes,
          retryAfterSeconds: wait,
        );
      }
    }

    final String code = _sixDigitCode();

    codes[key] = <String, dynamic>{
      'code': code,
      'issued_at': now.toIso8601String(),
      'expires_at': now
          .add(const Duration(minutes: _resetLifetimeMinutes))
          .toIso8601String(),
    };

    _state['reset_codes'] = codes;

    await _persist();

    return ResetCodeIssued(
      message: 'A reset code has been generated.',
      code: code,
      expiresInMinutes: _resetLifetimeMinutes,
    );
  }

  /// Verifies a reset [code] on this device and returns the single-use
  /// token that authorises the new-password step. Consumes the code, so a
  /// verified round cannot be replayed.
  @override
  Future<String> verifyCode({
    required String identifier,
    required String code,
  }) async {
    await ready();

    final Map<String, dynamic> codes = _resetCodes();

    final Map<String, dynamic> account = _accountRowByIdentifier(identifier);

    if (account.isEmpty) {
      throw const LocalBackendException(_badCode);
    }

    final String key = _resetKey(account);
    final Map<String, dynamic>? entry = codes[key];

    if (entry == null || QfValue.toStr(entry['code']) != code.trim()) {
      throw const LocalBackendException(_badCode);
    }

    final DateTime expires =
        QfValue.toDate(entry['expires_at']) ?? DateTime(1970);

    if (expires.isBefore(DateTime.now())) {
      codes.remove(key);
      _state['reset_codes'] = codes;
      await _persist();

      throw const LocalBackendException(_badCode);
    }

    codes.remove(key);

    final String token = 'local-reset-${QfValue.toInt(account['id'])}-'
        '${DateTime.now().microsecondsSinceEpoch}';

    codes['$key:verified'] = <String, dynamic>{
      'token': token,
      'expires_at': DateTime.now()
          .add(const Duration(minutes: _resetLifetimeMinutes))
          .toIso8601String(),
    };
    _state['reset_codes'] = codes;

    await _persist();

    return token;
  }

  /// Sets the new [password], gated by a verified [resetToken] or, for
  /// older callers, by the emailed [code] directly.
  @override
  Future<void> resetPassword({
    required String identifier,
    String? code,
    String? resetToken,
    required String password,
  }) async {
    await ready();

    final Map<String, dynamic> codes = _resetCodes();

    // Resolved before the code is read, so the same account is found however
    // its identifier was spelled on this step.
    final Map<String, dynamic> account = _accountRowByIdentifier(identifier);

    if (account.isEmpty) {
      throw const LocalBackendException(_badCode);
    }

    final String key = _resetKey(account);

    if (resetToken != null) {
      final Map<String, dynamic>? verified = codes['$key:verified'];

      if (verified == null || QfValue.toStr(verified['token']) != resetToken) {
        throw const LocalBackendException(_badCode);
      }

      final DateTime expires =
          QfValue.toDate(verified['expires_at']) ?? DateTime(1970);

      if (expires.isBefore(DateTime.now())) {
        codes.remove('$key:verified');
        _state['reset_codes'] = codes;
        await _persist();

        throw const LocalBackendException(_badCode);
      }

      codes.remove('$key:verified');
    } else {
      final Map<String, dynamic>? entry = codes[key];

      if (entry == null || QfValue.toStr(entry['code']) != (code ?? '').trim()) {
        throw const LocalBackendException(_badCode);
      }

      final DateTime expires =
          QfValue.toDate(entry['expires_at']) ?? DateTime(1970);

      if (expires.isBefore(DateTime.now())) {
        codes.remove(key);
        _state['reset_codes'] = codes;
        await _persist();

        throw const LocalBackendException(_badCode);
      }

      codes.remove(key);
    }

    _checkPasswordStrength(password);

    account['password'] = password;

    _state['reset_codes'] = codes;

    await _persist();
  }

  /// The key a pending reset is filed under.
  ///
  /// The account's own id, not the identifier that was typed: the two steps of
  /// the flow do not have to be given the same spelling of the same person.
  String _resetKey(Map<String, dynamic> account) =>
      'user:${QfValue.toInt(account['id'])}';

  Map<String, dynamic> _resetCodes() {
    final dynamic raw = _state['reset_codes'];

    return raw is Map ? Map<String, dynamic>.from(raw) : <String, dynamic>{};
  }

  String _sixDigitCode() => (100000 + _random.nextInt(900000)).toString();

  void _checkPasswordStrength(String password) {
    if (password.length < 8) {
      throw const LocalBackendException(
        'Choose a password of at least 8 characters.',
      );
    }
  }

  // ==========================================================
  // IDENTIFIERS
  // ==========================================================

  Map<String, dynamic> get _sequences {
    final dynamic raw = _state['sequences'];

    return raw is Map ? Map<String, dynamic>.from(raw) : <String, dynamic>{};
  }

  int _nextTicketId() {
    int next = 1;

    for (final Map<String, dynamic> row in _rows('tickets')) {
      next = max(next, QfValue.toInt(row['id']) + 1);
    }

    return next;
  }

  /// Takes the next number for an office, so a cancelled tail number is
  /// reused rather than leaving a gap in the day's sequence.
  String _nextQueueNumber(int officeId) {
    final String code = QfValue.toStr(_officeRow(officeId)['code']);
    final Map<String, dynamic> sequences = _sequences;
    final int next = QfValue.toInt(sequences[code]) + 1;

    sequences[code] = next;
    _state['sequences'] = sequences;

    return '$code-${next.toString().padLeft(3, '0')}';
  }

  /// The numeric tail of a `CODE-042` queue number, or 0.
  int _numberFrom(dynamic queueNumber) {
    final String text = QfValue.toStr(queueNumber);
    final int dash = text.lastIndexOf('-');

    if (dash < 0) {
      return 0;
    }

    return int.tryParse(text.substring(dash + 1)) ?? 0;
  }
}
