import 'dart:async';

import 'package:flutter/material.dart';

import '../core/app_colors.dart';
import '../core/app_constants.dart';
import '../core/glass.dart';
import '../core/motion.dart';
import '../core/network/connection_messages.dart';
import '../core/network/connectivity_service.dart';
import '../models/campus_catalog.dart';
import '../models/queue_ticket.dart';
import '../repositories/auth_repository.dart';
import '../services/campus_backend.dart';
import '../widgets/active_ticket_notice.dart';
import '../widgets/connection_status.dart';
import '../widgets/pwd_proof_picker.dart';
import '../widgets/qf_page.dart';
import 'ticket_receipt_screen.dart';

/// ============================================================
/// QUEUEFLOW TMC — NEW QUEUE TICKET
///
/// Two steps in one scrolling page rather than a wizard, so a student
/// can see the office list and their choice together instead of hunting
/// for a Next button:
///
///   1. pick an office      (every office a student may queue at)
///   2. confirm             (priority, then the number)
///
/// The service a ticket is filed under is resolved automatically from the
/// chosen office (its first active service), so the student is never asked
/// to pick one.
///
/// Priority is a claim, not a fact. Choosing Person with Disability opens the
/// PWD Proof section (see widgets/pwd_proof_picker.dart), and the ticket will
/// not be issued without a photo from the gallery - the same rule the Laravel
/// side enforces on the multipart upload, stated here so the student is told
/// before the request rather than by a 422 after it. Regular carries no proof
/// and never asks for one.
///
/// The Registrar is the exception to step 1. It is seven offices on the
/// server - one per college, each with its own deployed batch and its own
/// assigned staff member - so it is listed once here, and opening it
/// auto-assigns the student's own registered course queue. There is no
/// manual course picker: a BSIT student enters the BSIT queue, and the
/// server re-verifies the queue against the account before issuing. The
/// card's own numbers are the sum across all seven, because that is what
/// "the Registrar has numbers available" means; the assigned row then
/// reports its own batch.
///
/// Every office is listed, but the online/offline switch gates the choice.
/// An office inside its hours with no batch deployed yet is still selectable:
/// the ticket waits and the counter picks it up from the queue as normal, and
/// the row says so. An office a staff member switched offline is shown but
/// locked, because numbers taken behind a counter that is not accepting them
/// would queue for nothing.
///
/// The switch is read from the server, never kept: on entry, on return to the
/// foreground, on a pull-to-refresh, on a timer while the page sits open, and
/// once more immediately before a ticket is issued. A page that only checked
/// the copy it opened with would be telling the student a counter is
/// listening when the last thing it knows is minutes old. Until the first
/// read lands the page says it is loading rather than offering offices as
/// online, and a read that fails says which of the three things went wrong -
/// the server is offline, it timed out, or its answer could not be loaded -
/// with the retry that fixes each of them.
///
/// Changing the office re-resolves the service, because a service belongs to
/// exactly one office and the ids would otherwise be submitted mismatched.
/// ============================================================

class NewTicketScreen extends StatefulWidget {
  const NewTicketScreen({super.key});

  @override
  State<NewTicketScreen> createState() => _NewTicketScreenState();
}

class _NewTicketScreenState extends State<NewTicketScreen>
    with WidgetsBindingObserver {
  final AuthRepository _auth = AuthRepository.instance;

  // Purpose field removed as requested

  Office? _office;

  /// The service the ticket is filed under, resolved from [_office] rather
  /// than picked by the student.
  ///
  /// The server still requires a `service_id` that belongs to the office, so
  /// the first active service of the chosen office is carried here. It is
  /// never shown in the form.
  OfficeService? _service;
  String _priority = AppConstants.priorityRegular;

  /// The PWD proof photo from the gallery, while [AppConstants.priorityPwd] is
  /// selected. Null means nothing has been picked, and the submit is refused
  /// until something has been.
  String? _pwdProof;

  /// Why the proof is still missing, shown under the picker when a submit was
  /// attempted without one.
  String? _proofError;

  /// The Registrar course auto-assigned from the account, when the chosen
  /// office is the Registrar queue.
  ///
  /// Held separately from [_office] because it is the only thing that decides
  /// which `course_id` the request carries. Everywhere else the course comes
  /// from the student's own profile.
  RegistrarCourseOption? _registrarCourse;

  /// Why the current pick cannot take a number, if it cannot.
  ///
  /// A Registrar course pick reads its own row — the office rebuilt from it
  /// carries no batch object to judge — while a plain office pick reads the
  /// catalogue row. Null while the pick is issuable (or while nothing is
  /// picked, where the button's other gates already explain themselves).
  String? get _choiceBlockedReason {
    final RegistrarCourseOption? course = _registrarCourse;

    if (course != null) {
      final String? reason = course.closedReason;

      return reason == null ? null : '${course.code}: $reason';
    }

    final Office? office = _office;

    if (office == null) {
      return null;
    }

    final String? reason = office.closedReason;

    return reason == null ? null : '${office.name}: $reason';
  }

  /// Whether the Registrar's course queues are on screen.
  bool _coursesOpen = false;

  bool _submitting = false;
  String? _error;

  /// Why the last attempt failed, parked here across the connectivity gate.
  ///
  /// A local rather than a field because the write happens inside the callback
  /// `requireServer` hands back, which must return the action's own result and
  /// not reach into widget state. Read back immediately after, before anything
  /// else can run.
  String? _failure;

  /// The ticket the last attempt issued, parked here across the connectivity
  /// gate the same way [_failure] is.
  ///
  /// The receipt is rendered from this rather than from a re-read, so the
  /// number, the position and the server's sentence all match what the server
  /// actually answered at the moment of issue.
  CreatedTicket? _issued;

  /// True when the last create failed in a way that may still have created a
  /// ticket (a lost reply or a 5xx). Read back after the gate to decide whether
  /// one re-read is warranted before reporting a failure.
  bool _creationUncertain = false;

  /// True while this screen's own status read is in flight.
  ///
  /// Starts true because the first thing the page does is ask: until that
  /// answer lands it must not draw an empty catalogue as if the campus had
  /// no offices, and it must not draw a cached one as if it were current.
  bool _reading = true;

  /// The most recent status read's failure, split into the heading and the
  /// advice, or null while the last read succeeded.
  ///
  /// Kept separately from [_error] because the two are not the same report:
  /// [_error] is what the student's own attempt did, and this is what the
  /// screen's attempt to read the campus did.
  String? _statusFailureTitle;
  String? _statusFailureBody;

  /// Set when a pull-to-refresh got an answer, so the gesture does not end
  /// in silence. The reader pulled to be told the list is current, and a
  /// spinner that stops with no other change reads as nothing having
  /// happened.
  bool _statusUpdated = false;

  /// The periodic re-read, cancelled with the screen.
  ///
  /// A timer that outlived its page would keep calling a repository nothing
  /// is listening to, and would hold the test binding open after the widget
  /// is gone.
  Timer? _statusPoll;

  @override
  void initState() {
    super.initState();
    _auth.addListener(_onAuthChanged);
    WidgetsBinding.instance.addObserver(this);

    // The online/offline switch lives on the server, so the screen re-reads
    // the campus on entry rather than trusting what a previous screen cached.
    // The shell first, because the office rows live inside it, then the
    // lighter switch read that keeps this page honest while it is open. Both
    // are awaited in order rather than fired blind: the second one corrects
    // the first, and running them together would let a slower shell payload
    // land on top of the fresher switch. Failures are reported by
    // [_refreshStatus] and leave whatever catalogue is already on screen.
    unawaited(_boot());

    // A counter can be switched off while this page sits open and nothing
    // else here would notice: the list only changes when something asks the
    // server. The tick is the difference between a page that is drawn and
    // one that is still true.
    _statusPoll = Timer.periodic(const Duration(seconds: 15), (Timer timer) {
      unawaited(_refreshStatus());
    });
  }

  Future<void> _boot() async {
    await _auth.refresh();

    if (!mounted) {
      return;
    }

    await _refreshStatus();
  }

  @override
  void dispose() {
    _statusPoll?.cancel();
    WidgetsBinding.instance.removeObserver(this);
    _auth.removeListener(_onAuthChanged);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Returning from the background is the one moment this page has been
    // away from the server for as long as the phone was, which is exactly
    // when a copy cached on entry is most likely to be wrong.
    if (state == AppLifecycleState.resumed && mounted) {
      unawaited(_refreshStatus());
    }
  }

  /// Re-reads the office switches and reports what came back.
  ///
  /// A failure is never swallowed: the screen states which of the three went
  /// wrong and offers the retry, because silence after a pull is how a reader
  /// concludes the app is broken rather than the server. [pulled] marks the
  /// gesture so only it gets the confirmation - a background re-read nobody
  /// asked for has nothing to confirm.
  Future<void> _refreshStatus({bool pulled = false}) async {
    if (!mounted) {
      return;
    }

    setState(() {
      _reading = true;
      _statusUpdated = false;
    });

    try {
      await _auth.refreshOfficeStatus();
    } catch (error) {
      if (!mounted) {
        return;
      }

      final ({String title, String body}) failure = _statusFailureFor(error);

      setState(() {
        _reading = false;
        _statusFailureTitle = failure.title;
        _statusFailureBody = failure.body;
      });

      return;
    }

    if (!mounted) {
      return;
    }

    setState(() {
      _reading = false;
      _statusFailureTitle = null;
      _statusFailureBody = null;
      _statusUpdated = pulled;
    });
  }

  /// Which of the three failures a status read ended in.
  ///
  /// The wording is fixed rather than passed through from the exception: a
  /// reader has to be able to tell "the server is not there" from "the
  /// server did not answer in time" from "we asked and could not use the
  /// answer", and only the transport knows which of the first two happened.
  /// Taking the exception's sentence instead would make the distinction
  /// depend on whatever wording two transports happen to carry today.
  static ({String title, String body}) _statusFailureFor(Object error) {
    if (error is CampusBackendException) {
      if (error.isTimeout) {
        return (
          title: 'Connection Timeout',
          body:
              'The QueueFlow TMC server is taking too long to respond.\n'
              'Please try again.',
        );
      }

      // Status code 0 is the transport's own: nothing answered, so there was
      // no server to refuse the request. A null code means the failure was
      // decided on the device, and a real code means the server did answer -
      // neither of those is "the server is offline".
      if (error.statusCode == 0) {
        return (
          title: 'Server Offline',
          body:
              'Unable to connect to QueueFlow TMC.\n'
              'Please check your Wi-Fi/mobile data connection and make sure '
              'the QueueFlow TMC server is running.',
        );
      }
    }

    return (title: 'Unable to Load Office Status', body: 'Please try again.');
  }

  void _onAuthChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  void _selectOffice(Office office) {
    // A full or staff-closed batch is not a dead counter, but there is
    // nothing to take: the tile is disabled, and this second gate covers a
    // row that filled or closed between renders and was already selected
    // when it did.
    if (office.isBatchFull || office.isBatchClosed) {
      return;
    }

    setState(() {
      _office = office;
      // A service id is only valid for the office that owns it, so the one
      // carried for the previous office is replaced with this office's own.
      _service = _defaultServiceFor(office);
      _error = null;
      // Picking a non-Registrar office means the course comes from the profile
      // again, so a course chosen under the Registrar does not silently follow
      // the student to the Cashier.
      _registrarCourse = null;
    });
  }

  /// The service a ticket is filed under for [office].
  ///
  /// The form no longer asks the student to choose, so the first active service
  /// stands in. The server validates that the id belongs to the office, which
  /// is why this must come from the chosen office's own list.
  static OfficeService? _defaultServiceFor(Office office) {
    for (final OfficeService service in office.services) {
      if (service.isActive) {
        return service;
      }
    }

    return null;
  }

  /// Switches priority, keeping the two claims straight.
  ///
  /// The proof belongs to the PWD claim alone. Switching back to Regular drops
  /// it, so a photo picked for one ticket can never travel with a later
  /// regular one - the request only carries evidence for the claim that needs
  /// it.
  void _selectPriority(String value) {
    setState(() {
      _priority = value;

      if (value != AppConstants.priorityPwd) {
        _pwdProof = null;
      }

      _proofError = null;
    });
  }

  /// Opens the Registrar's assigned course queue, reading it the first time.
  ///
  /// Read on demand rather than with the rest of the shell because the numbers
  /// go stale: a student can sit on this page for a while, and a queue that was
  /// open when the screen loaded may be full by the time they choose. Opening
  /// auto-assigns the student's own registered course queue — there is no
  /// manual picker — and the server re-verifies the assignment on submit.
  Future<void> _toggleCourses() async {
    if (_coursesOpen) {
      setState(() => _coursesOpen = false);
      return;
    }

    setState(() => _coursesOpen = true);

    await _auth.refreshRegistrarCourses();

    if (!mounted) {
      return;
    }

    setState(() => _autoAssignRegistrarCourse());
  }

  /// The student's own Registrar course queue, resolved from the account.
  ///
  /// Matched by the enrolled course code first (the account is the source of
  /// truth), falling back to the server-flagged enrolled row. Null when the
  /// profile has no course or the queue is not listed — both reported as
  /// "contact the administrator" rather than queued elsewhere.
  RegistrarCourseOption? _enrolledCourse() {
    final List<RegistrarCourseOption> courses =
        _auth.registrarCourses.courses;

    if (courses.isEmpty) {
      return null;
    }

    final String? enrolled = _auth.enrolledCourseCode;

    if (enrolled != null && enrolled.isNotEmpty) {
      for (final RegistrarCourseOption option in courses) {
        if (option.code.trim().toUpperCase() == enrolled) {
          return option;
        }
      }
    }

    return _auth.registrarCourses.enrolledOption();
  }

  /// Assigns the student's own Registrar queue.
  ///
  /// Runs whenever the course list is (re-)read so a stale assignment cannot
  /// survive a catalogue change. A missing or unlisted course clears the pick
  /// and the submit below explains it instead of queueing elsewhere.
  void _autoAssignRegistrarCourse() {
    final RegistrarCourseOption? mine = _enrolledCourse();

    if (mine == null) {
      _registrarCourse = null;
      _office = null;
      _service = null;
      _error = null;
      return;
    }

    _registrarCourse = mine;
    // The office is rebuilt from the course row so the slot numbers on
    // screen are the ones this queue actually reported, and the service is
    // resolved from it.
    _office = mine.office;
    _service = _defaultServiceFor(mine.office);
    _error = null;
  }

  /// Re-reads the Registrar queues before a number is issued.
  ///
  /// The counts on screen were true when they were drawn. Between then and the
  /// tap another student may have taken the last one, and finding that out from
  /// a 422 is a worse answer than being told the queue is full before pressing
  /// anything. The server re-checks regardless - this only decides what the
  /// student is told.
  Future<RegistrarCourseOption?> _rereadSelectedCourse(
    RegistrarCourseOption selected,
  ) async {
    final RegistrarCourseList fresh = await _auth.refreshRegistrarCourses();

    return fresh.byCode(selected.code);
  }

  /// The ticket currently occupying this student's queue slot, if any.
  ///
  /// One active ticket across all offices: holding a Registrar number locks
  /// Cashier, Admissions and every other office until it is served or
  /// cancelled. The server enforces the same rule, so this gate only decides
  /// what the student is told — never whether the request may go out.
  QueueTicket? get _activeTicket => _auth.activeTicket;

  Future<void> _submit() async {
    final Office? office = _office;
    final OfficeService? service = _service;
    final RegistrarCourseOption? course = _registrarCourse;

    // A Registrar course carries the id the student chose; every other office
    // queues the student under the course on their profile.
    final int? courseId = course?.courseId ?? _auth.queueCourseId;

    // One active ticket across all offices. Said before anything else: no
    // office, course or purpose choice on this page can make the request
    // acceptable while another number is live.
    final QueueTicket? active = _activeTicket;

    if (active != null) {
      setState(() => _error = activeTicketMessage(active));
      return;
    }

    if (office == null) {
      setState(() => _error = 'Choose an office first.');
      return;
    }

    // The service is resolved from the office rather than chosen, but an
    // office with nothing active cannot file a ticket.
    if (service == null) {
      setState(
        () => _error = '${office.name} has no active service to take a number '
            'for right now.',
      );
      return;
    }

    if (courseId == null) {
      setState(
        () => _error =
            'Your profile has no course set, so the office cannot accept a '
            'queue request. Ask the registrar to assign your course.',
      );
      return;
    }

    // The PWD claim is only as good as its proof. The server refuses an
    // evidence-less PWD ticket too, so saying it here - next to the picker,
    // in words the student can act on - beats a 422 they never see the field
    // for. The proof error lives beside the control instead of in the page
    // banner because that is where the fix is.
    if (_priority == AppConstants.priorityPwd &&
        (_pwdProof == null || _pwdProof!.isEmpty)) {
      setState(
        () => _proofError = 'Please select a photo as proof for PWD priority.',
      );
      return;
    }

    setState(() {
      _submitting = true;
      _error = null;
      _proofError = null;
      // Cleared per attempt. Left over from a previous failure it would make a
      // *successful* second attempt report the first attempt's error, because
      // the check below is "is this still set?" and nothing had unset it.
      _failure = null;
    });

    // Set inside the gate when the counter closed while the form was open,
    // and read back after it for the same reason [_failure] is: the callback
    // must return the action's own result, and the dialog belongs on the
    // screen, not inside a transport gate.
    Office? wentOffline;

    // Last gate before the request leaves the device.
    //
    // A ticket number is issued by the server and means nothing until the
    // counter can see it, so there is no offline version of this action and no
    // queue for it to join locally. Refusing here - after one cheap re-probe -
    // is the difference between "you're offline, reconnect to continue" and a
    // number that the registrar will never call.
    final bool?
    allowed = await ConnectivityService.instance.requireServer<bool>(() async {
      // Inside the gate, so an offline student is told to reconnect instead of
      // being handed a stale "that queue is full".
      //
      // The switch is re-read from the server rather than looked up in the
      // copy this screen loaded with. That copy can only be as new as the
      // last time somebody asked, and this is the last moment the student can
      // still be told: a stale "online" here becomes a number for a counter a
      // staff member switched off ten minutes ago, which is the one outcome
      // the whole feature exists to prevent.
      try {
        await _auth.refreshOfficeStatus();
      } catch (error) {
        // The check could not happen, so the ticket must not either. Said in
        // words about the status rather than about the connection, because
        // that is what did not get confirmed - the reader is not being told
        // their network is down, they are being told this was not verified.
        _failure =
            'Your number was not issued because the office status could not '
            'be re-checked. Please try again.';
        return false;
      }

      final Office? freshOffice = _auth.officeById(office.id);

      if (freshOffice != null && !freshOffice.isOnline) {
        wentOffline = freshOffice;
        return false;
      }

      if (course != null) {
        final RegistrarCourseOption? fresh = await _rereadSelectedCourse(
          course,
        );

        if (fresh == null) {
          _failure =
              'That Registrar course queue is no longer listed. '
              'Pull to refresh and pick again.';
          return false;
        }

        if (!fresh.isOpen) {
          _failure =
              '${fresh.code} has no open queue right now. '
              '${fresh.slotsDeployed ? 'Its batch is full or closed.' : 'No batch has been deployed yet.'}';
          return false;
        }
      }

      final ({CreatedTicket? created, String? error, bool uncertain}) result =
          await _auth.createTicket(
            officeId: office.id,
            serviceId: service.id,
            courseId: courseId,
            priority: _priority,
            purpose: null,
            yearLevel: _auth.student?.yearLevel,
            // Only the PWD claim carries a file, and only the file the student
            // is looking at on screen - a regular ticket arrives with no
            // evidence part at all, which is what the server stores as NULL.
            evidencePath:
                _priority == AppConstants.priorityPwd ? _pwdProof : null,
          );

      _issued = result.created;
      _failure = result.error;
      _creationUncertain = result.uncertain;

      return _failure == null;
    }, onBlocked: (_) {});

    if (!mounted) {
      return;
    }

    setState(() => _submitting = false);

    // The counter closed while the form was open. Reported before anything
    // else: the sentence below would send the reader to their Wi-Fi settings
    // for a problem that is a staff member's switch, and the list has to be
    // redrawn so the tile they picked reads as it now is.
    if (wentOffline != null) {
      final Office closed = wentOffline!;

      if (_error != null) {
        setState(() => _error = null);
      }

      await _showOfficeOffline(closed);

      return;
    }

    // A refused create - or a pre-submit re-check that could not be made - puts
    // the server's own sentence in [_failure] and makes the gate's action
    // return false. So [_failure] has to be read before the connection wording
    // below: the gate returns `false` on a failed action, not only `null` when
    // it blocked one, and testing `allowed != true` first reported every
    // refusal as the connection state ("Connected") instead of the reason.
    if (_failure != null) {
      // The request may have succeeded behind a lost or late reply. Re-read
      // once and adopt the ticket if the queue now holds one for this office,
      // rather than tell the student their number failed when the counter is
      // already holding it.
      if (_creationUncertain) {
        final CreatedTicket? reconciled = await _reconcileUncertainCreate(
          office,
        );

        if (reconciled != null) {
          await _openReceipt(reconciled);

          if (mounted) {
            unawaited(_refreshStatus());
          }

          return;
        }
      }

      setState(() => _error = _failure);
      return;
    }

    // A null means the gate refused the action outright, so no request was sent
    // and there is nothing from the server to report; the connection message is
    // then the honest thing to say. (A `false` never reaches here - the action
    // that ran set [_failure], handled above.)
    //
    // One guard: the message is read after the gate settles, and a retry probe
    // can flip the state back to online in between. Reading the online state's
    // body ("Connected") here would present a link description as the action's
    // result - a fake success. When the phone reads as online at this point,
    // nothing was decided, and that is what is said.
    if (allowed != true) {
      final ConnectivityService connectivity = ConnectivityService.instance;
      setState(
        () => _error = ConnectionMessages.blockedActionBody(
          canReachServer: connectivity.canReachServer,
          liveBody: connectivity.message.body,
        ),
      );
      return;
    }

    final CreatedTicket? issued = _issued;

    if (issued == null) {
      return;
    }

    // The receipt opens the instant the server confirms the number. There used
    // to be a "View receipt" dialog in between, but the number the student
    // asked for *is* the receipt, so an extra tap to see the thing they just
    // requested earned nothing. The status re-read waits until they leave the
    // receipt: the counter's live counters have moved on by then anyway.
    await _openReceipt(issued);

    if (mounted) {
      unawaited(_refreshStatus());
    }
  }

  /// Re-reads the queue after a transport failure that may have hidden a
  /// successful create, adopting the ticket if one appeared for [office].
  ///
  /// A timeout is the case this exists for: the POST reached the server, the
  /// row was written, and the reply was lost. A second live ticket for the
  /// same office is exactly what that leaves behind, so one re-read settles
  /// it; anything else stays a failure and is reported as one.
  Future<CreatedTicket?> _reconcileUncertainCreate(Office office) async {
    try {
      await _auth.refresh();
    } catch (_) {
      return null;
    }

    final QueueTicket? found = _auth.activeTicket;

    if (found == null || found.office?.id != office.id) {
      return null;
    }

    final QueueProgress? progress = await _auth.activeProgress();

    return CreatedTicket(
      ticket: found,
      queue: progress ?? const QueueProgress(),
      message: 'Your number was issued, but the confirmation took too long to '
          'reach your phone. Here is the ticket the counter has.',
    );
  }

  /// Says the counter closed, then catches the list up with it.
  ///
  /// The dialog comes first because it is the answer to what the student just
  /// tried, and the refresh after because a page that explained why the office
  /// is shut must not go on showing it as open - the lock on the tile is the
  /// proof that the message was about this list and not a generic warning.
  Future<void> _showOfficeOffline(Office office) async {
    if (!mounted) {
      return;
    }

    await showDialog<void>(
      context: context,
      builder: (BuildContext context) => _OfficeOfflineDialog(office: office),
    );

    if (mounted) {
      unawaited(_refreshStatus());
    }
  }

  /// Pushes the digital ticket receipt for [created].
  ///
  /// Built from the issue-time response rather than from a re-read, so the
  /// number, the queue position and the server's sentence are exactly what
  /// the student was handed a moment ago.
  Future<void> _openReceipt(CreatedTicket created) async {
    await Navigator.of(context).push<void>(
      QfPageRoute<void>(
        page: TicketReceiptScreen(
          ticket: created.ticket,
          progress: created.queue,
          message: created.message,
          student: _auth.student,
        ),
      ),
    );
  }

  /// Pull-to-refresh for the office list.
  ///
  /// The gesture's whole promise is that the numbers under the finger are
  /// now current, so the read runs to completion before the spinner goes and
  /// the confirmation it earns is the one thing the reader asked for.
  Future<void> _pullToRefresh() => _refreshStatus(pulled: true);

  /// What to show where the catalogue is still empty.
  ///
  /// Three states, in the order they can actually happen: the first read is
  /// running, it failed, or it genuinely found no offices. Collapsing the
  /// first two into the third would report a campus with no counters, which
  /// is a different statement from a phone that has not heard back - and the
  /// only one of the three the reader can do nothing about.
  Widget _catalogueMissing() {
    if (_reading) {
      return const _StatusLoading();
    }

    if (_statusFailureTitle != null) {
      return _StatusFailure(
        title: _statusFailureTitle!,
        body: _statusFailureBody!,
        onRetry: () => _refreshStatus(),
      );
    }

    return QfEmptyState(
      icon: Icons.storefront_outlined,
      title: 'No offices available',
      message: 'The campus has not listed any offices yet. Pull to refresh.',
    );
  }

  @override
  Widget build(BuildContext context) {
    final List<Office> all = _auth.offices;
    final Office? registrar = _auth.registrarOffice;

    // `selectableOffices` already drops every registrar-named row - the
    // summary "Registrar" entry and the per-course desks alike - so the source
    // of this list is the offices the student picks directly.
    final List<Office> catalogue = _auth.selectableOffices;

    // Every office in the catalogue is listed, each with its own live
    // "ISSUED / TOTAL" reading (e.g. `0/100` rising as numbers go out) and no
    // online/offline wording beside its name. The counter's real switch still
    // decides which rows can be tapped - the server's rule is preserved - but
    // it is not spelled out as a label.

    // The pick gates above keep a full or staff-closed queue from being
    // chosen; this catches the row that filled or closed after it was
    // chosen, so a submit that can only fail is disabled rather than sent
    // to the server.
    final bool selectedFull =
        (_office?.isBatchFull ?? false) ||
        (_office?.isBatchClosed ?? false) ||
        (_registrarCourse?.isBatchFull ?? false) ||
        (_registrarCourse?.isBatchClosed ?? false);

    return QfPage(
      title: 'New ticket',
      subtitle: 'Take a queue number',
      onBack: () => Navigator.of(context).maybePop(),
      onRefresh: _pullToRefresh,
      children: <Widget>[
        if (_error != null) ...<Widget>[
          QfBanner(message: _error!),
          const SizedBox(height: 18),
        ],

        // The one-active-ticket gate, stated with the ticket that causes it.
        // The repository listener rebuilds this page the moment the ticket is
        // served or cancelled, which re-enables generation with no sign-out
        // or restart.
        if (_activeTicket case final QueueTicket active) ...<Widget>[
          ActiveTicketNotice(ticket: active),
          const SizedBox(height: 18),
        ],

        // A failed status read is its own report: it is not what the student
        // did, it is why this page cannot answer the question it exists to
        // answer, and it carries the retry that fixes it. Held back while a
        // retry is running, or the reader watches the warning sit there next
        // to the note saying the very same check is in flight.
        if (_statusFailureTitle != null && !_reading && all.isNotEmpty) ...<Widget>[
          _StatusFailure(
            title: _statusFailureTitle!,
            body: _statusFailureBody!,
            onRetry: () => _refreshStatus(),
          ),
          const SizedBox(height: 18),
        ],

        if (all.isEmpty)
          _catalogueMissing()
        else ...<Widget>[
          // The page says what it is doing with the switch, because the
          // reader cannot see the round trip: without this, a pull that
          // succeeded looks identical to one that did nothing, and a list
          // still loading reads as a campus with no offices.
          if (_reading || _statusUpdated) ...<Widget>[
            QfBanner(
              message: _reading
                  ? 'Refreshing office status...'
                  : 'Office status updated.',
              tone: _reading ? QfBannerTone.info : QfBannerTone.success,
              icon: _reading
                  ? Icons.sync_rounded
                  : Icons.check_circle_outline_rounded,
            ),
            const SizedBox(height: 14),
          ],

          const SectionTitle(text: 'Select campus office'),
          if (registrar != null)
            _RegistrarCard(
              office: registrar,
              courses: _auth.registrarCourses,
              open: _coursesOpen,
              selected: _registrarCourse,
              enrolledCode: _auth.enrolledCourseCode,
              onToggle: _toggleCourses,
              onRetry: _auth.refreshRegistrarCourses,
            ),

          for (final Office office in catalogue)
            QfSelectTile(
              title: office.name,
              subtitle: _officeSubtitle(office),
              selected: _office?.id == office.id,
              leading: _OfficeBadge(office: office),
              trailing: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.end,
                children: <Widget>[
                  _SlotsPill(label: office.slotsLabel),
                  const SizedBox(height: 6),
                  QfPill(
                    text: office.isOnline ? 'ONLINE' : 'OFFLINE',
                    color: office.isOnline
                        ? AppColors.success
                        : AppColors.danger,
                  ),
                ],
              ),
              // The counter's real switch and remaining capacity still decide
              // whether a number can be taken, so the row is shown for every
              // office but only pickable when the server would accept one.
              // A staff-closed batch reads as Batch Closed on the row.
              enabled:
                  office.isActive && office.isOnline && !office.isBatchFull && !office.isBatchClosed,
              onTap: () => _selectOffice(office),
            ),

          if (_office != null) ...<Widget>[
            const SizedBox(height: 22),

            const SizedBox(height: 22),

            const SizedBox(height: 20),
            const SectionTitle(text: 'Priority'),
            _PriorityOption(
              value: AppConstants.priorityRegular,
              group: _priority,
              title: 'Regular',
              message: 'Joins the normal queue order.',
              icon: Icons.people_alt_rounded,
              onChanged: _selectPriority,
            ),
            _PriorityOption(
              value: AppConstants.priorityPwd,
              group: _priority,
              title: 'Person with disability',
              message:
                  'Served ahead of the regular queue. Requires a photo of your PWD ID as proof.',
              icon: Icons.accessible_rounded,
              onChanged: _selectPriority,
            ),

            // The proof is asked for only under the claim that needs it: a
            // regular ticket never shows this section, and the submit refuses
            // a PWD claim that reaches it empty.
            if (_priority == AppConstants.priorityPwd) ...<Widget>[
              const SizedBox(height: 22),
              const SectionTitle(text: 'PWD Proof'),
              PwdProofPicker(
                imagePath: _pwdProof,
                onChanged: (String? path) => setState(() {
                  _pwdProof = path;
                  _proofError = null;
                }),
                onError: (String message) =>
                    setState(() => _proofError = message),
              ),
              if (_proofError != null) ...<Widget>[
                const SizedBox(height: 10),
                Text(
                  _proofError!,
                  style: const TextStyle(
                    color: AppColors.danger,
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                    height: 1.4,
                  ),
                ),
              ],
            ],

            const SizedBox(height: 26),
            // The chosen office may have gone offline or closed its batch
            // after it was picked: the button dims and the reason is said
            // here rather than left to the server's refusal. A Registrar
            // course pick reads its own row — the office rebuilt from it
            // carries no batch object to judge.
            if (_choiceBlockedReason != null) ...<Widget>[
              QfBanner(
                message: _choiceBlockedReason!,
                icon: Icons.info_outline_rounded,
                tone: QfBannerTone.info,
              ),
              const SizedBox(height: 12),
            ],
            // Wrapped rather than conditionally hidden: a button that
            // disappears when the connection drops leaves the reader
            // wondering whether they broke the app. This keeps it in place,
            // dimmed, with the reason beside it.
            ServerActionButton(
              child: PrimaryButton(
                label: _submitting
                    ? 'Issuing your number...'
                    : 'Generate ticket number',
                icon: Icons.confirmation_number_rounded,
                busy: _submitting,
                onPressed:
                    (_office != null &&
                            _service != null &&
                            !_submitting &&
                            !selectedFull &&
                            _choiceBlockedReason == null &&
                            _activeTicket == null)
                        ? _submit
                        : null,
              ),
            ),
          ],
        ],
      ],
    );
  }
}

/// Monogram tile for an office.
class _OfficeBadge extends StatelessWidget {
  const _OfficeBadge({required this.office});

  final Office office;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 42,
      height: 42,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(13),
        color: Colors.white.withValues(alpha: 0.08),
        border: Border.all(color: Colors.white.withValues(alpha: 0.12)),
      ),
      alignment: Alignment.center,
      child: Text(
        office.code.length > 4 ? office.code.substring(0, 4) : office.code,
        style: const TextStyle(
          color: Colors.white,
          fontSize: 12,
          fontWeight: FontWeight.w800,
          letterSpacing: 0.4,
        ),
      ),
    );
  }
}

/// The "ISSUED / TOTAL" slot reading shown beside an office or course queue.
///
/// The figure is the backend's own count for that deployed batch - `0/100`
/// before any number goes out, rising as tickets are issued, and `100/100`
/// once it fills. It is never invented here; the model reads it from the
/// office or course payload (`used_slots`/`total_slots`, or the embedded
/// batch's `submitted_count`).
class _SlotsPill extends StatelessWidget {
  const _SlotsPill({required this.label});

  final String label;

  @override
  Widget build(BuildContext context) {
    return QfPill(
      text: label,
      color: AppColors.accent,
      icon: Icons.confirmation_number_outlined,
    );
  }
}

/// A two-line description for an office tile.
///
/// The first line always names the live switch and batch the staff console
/// reports (`ONLINE • BATCH 2 • 50 Slots • ACTIVE`); the second line names
/// the services while numbers can be taken, or the reason they cannot.
String _officeSubtitle(Office office) {
  final String services = office.services
      .where((OfficeService service) => service.isActive)
      .map((OfficeService service) => service.name)
      .toList(growable: false)
      .join('  •  ');

  final String second = office.closedReason ??
      (services.isEmpty ? 'Tap to take a queue number' : services);

  return '${office.statusLine}\n$second';
}

/// The single Registrar entry, with the student's own course queue inside it.
///
/// The slot count on the card is the sum across every course, which is the only
/// total that answers "can I get a number from the Registrar today" - any one
/// course's batch could be full while another has plenty. Opening the card
/// shows the queue auto-assigned from the account's registered course; there
/// is no manual picker, and the server re-verifies the assignment on submit.
class _RegistrarCard extends StatelessWidget {
  const _RegistrarCard({
    required this.office,
    required this.courses,
    required this.open,
    required this.selected,
    required this.enrolledCode,
    required this.onToggle,
    required this.onRetry,
  });

  final Office office;
  final RegistrarCourseList courses;
  final bool open;
  final RegistrarCourseOption? selected;
  final String? enrolledCode;
  final VoidCallback onToggle;
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) {
    final RegistrarCourseOption? chosen = selected;

    return Container(
      margin: const EdgeInsets.only(bottom: 10),
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
        color: chosen != null
            ? AppColors.accent.withValues(alpha: 0.13)
            : Colors.white.withValues(alpha: 0.06),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(
          color: chosen != null
              ? AppColors.accent.withValues(alpha: 0.45)
              : Colors.white.withValues(alpha: 0.10),
          width: chosen != null ? 1.3 : 1,
        ),
      ),
      child: Column(
        children: <Widget>[
          PressScale(
            pressedScale: 0.985,
            onTap: onToggle,
            child: Row(
              children: <Widget>[
                _OfficeBadge(office: office),
                const SizedBox(width: 13),
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(
                        'Registrar',
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 14,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        chosen != null
                            ? '${chosen.code}  •  ${chosen.name}'
                            : 'Select your course queue',
                        style: TextStyle(
                          color: Colors.white.withValues(alpha: 0.48),
                          fontSize: 11.5,
                          height: 1.35,
                        ),
                      ),
                      const SizedBox(height: 3),
                      Text(
                        office.statusLine,
                        style: TextStyle(
                          color: Colors.white.withValues(alpha: 0.48),
                          fontSize: 10.5,
                          fontWeight: FontWeight.w700,
                          letterSpacing: 0.4,
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(width: 10),
                _SlotsPill(label: office.slotsLabel),
                const SizedBox(width: 6),
                QfPill(
                  text: office.isOnline ? 'ONLINE' : 'OFFLINE',
                  color: office.isOnline
                      ? AppColors.success
                      : AppColors.danger,
                ),
                const SizedBox(width: 6),
                AnimatedRotation(
                  turns: open ? 0.5 : 0,
                  duration: const Duration(milliseconds: 220),
                  child: const Icon(
                    Icons.keyboard_arrow_down_rounded,
                    color: Colors.white54,
                    size: 22,
                  ),
                ),
              ],
            ),
          ),

          if (open) ...<Widget>[
            const SizedBox(height: 14),
            const Divider(color: Colors.white12, height: 1),
            const SizedBox(height: 14),

            _AssignedRegistrarQueue(
              courses: courses,
              assigned: selected,
              enrolledCode: enrolledCode,
              onRetry: onRetry,
            ),
          ],
        ],
      ),
    );
  }
}

/// The Registrar queue auto-assigned from the student's account.
///
/// Read-only: the enrolled course decides the queue, so there is nothing to
/// pick. Shows the assigned course with its live batch reading, or why no
/// queue could be assigned (no course on the profile, or the queue not
/// listed) with the administrator contact guidance.
class _AssignedRegistrarQueue extends StatelessWidget {
  const _AssignedRegistrarQueue({
    required this.courses,
    required this.assigned,
    required this.enrolledCode,
    required this.onRetry,
  });

  final RegistrarCourseList courses;
  final RegistrarCourseOption? assigned;
  final String? enrolledCode;
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) {
    if (courses.isEmpty) {
      return _RegistrarRetry(onRetry: onRetry);
    }

    final RegistrarCourseOption? mine = assigned;

    if (mine == null) {
      final String? enrolled = enrolledCode;

      return Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              const Icon(
                Icons.lock_outline_rounded,
                size: 15,
                color: Colors.white38,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  enrolled == null || enrolled.isEmpty
                      ? 'Your profile has no course set, so no Registrar queue '
                            'can be assigned. Please contact the administrator '
                            'to update your account.'
                      : 'No Registrar queue matches your enrolled course '
                            '($enrolled). Please contact the administrator to '
                            'update your account.',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.55),
                    fontSize: 11.5,
                    height: 1.4,
                  ),
                ),
              ),
            ],
          ),
        ],
      );
    }

    return QfSelectTile(
      title: '${mine.code} — your Registrar queue',
      subtitle:
          '${mine.name.isEmpty ? 'Assigned from your account' : mine.name}\n'
          '${mine.statusLine}'
          '${mine.closedReason == null ? '' : '\n${mine.closedReason}'}',
      selected: true,
      enabled: false,
      trailing: mine.isOpen
          ? QfPill(
              text: 'Online',
              color: AppColors.success,
              icon: Icons.check_circle_outline_rounded,
            )
          : QfPill(
              text: 'Offline',
              color: AppColors.danger,
              icon: Icons.cloud_off_rounded,
            ),
      onTap: () {},
    );
  }
}

/// The empty course list's retry, so a failed read is not a dead end.
class _RegistrarRetry extends StatelessWidget {
  const _RegistrarRetry({required this.onRetry});

  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Text(
          'The course queues have not loaded yet.',
          style: TextStyle(
            color: Colors.white.withValues(alpha: 0.48),
            fontSize: 11.5,
            height: 1.35,
          ),
        ),
        const SizedBox(height: 10),
        PressScale(
          pressedScale: 0.97,
          onTap: onRetry,
          child: Container(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.06),
              borderRadius: BorderRadius.circular(999),
              border: Border.all(color: Colors.white.withValues(alpha: 0.14)),
            ),
            child: const Row(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                Icon(Icons.refresh_rounded, size: 14, color: Colors.white70),
                SizedBox(width: 7),
                Text(
                  'Try again',
                  style: TextStyle(
                    color: Colors.white70,
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ],
            ),
          ),
        ),
      ],
    );
  }
}

/// The wait before the first status answer, said out loud.
///
/// Deliberately not the page's empty state: "the campus has no offices" and
/// "the phone has not heard back" are different claims, and only one of them
/// is true at this moment. The second gets a spinner instead of an
/// explanation because there is nothing to explain until the server answers.
class _StatusLoading extends StatelessWidget {
  const _StatusLoading();

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(top: 30, bottom: 12),
      child: Column(
        children: <Widget>[
          const SizedBox(
            width: 26,
            height: 26,
            child: CircularProgressIndicator(
              strokeWidth: 2.4,
              color: AppColors.accent,
            ),
          ),
          const SizedBox(height: 14),
          Text(
            'Loading office status...',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.55),
              fontSize: 12.5,
              height: 1.4,
            ),
          ),
        ],
      ),
    );
  }
}

/// Why the switch could not be read, with the retry that reads it again.
///
/// Its own card rather than the page's error banner: the banner reports what
/// the student asked for and this reports what the screen asked for, and
/// merging them would leave one message about two different events, only one
/// of which the reader caused. The heading stays one of three fixed strings
/// so the cases remain distinguishable - server offline, timeout, load
/// failure - because that is what tells the reader which thing to fix.
class _StatusFailure extends StatelessWidget {
  const _StatusFailure({
    required this.title,
    required this.body,
    required this.onRetry,
  });

  final String title;
  final String body;
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(15),
      decoration: BoxDecoration(
        color: AppColors.danger.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: AppColors.danger.withValues(alpha: 0.30)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              const Icon(
                Icons.cloud_off_rounded,
                size: 17,
                color: AppColors.danger,
              ),
              const SizedBox(width: 9),
              Expanded(
                child: Text(
                  title,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 13.5,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            body,
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.60),
              fontSize: 12.5,
              height: 1.45,
            ),
          ),
          const SizedBox(height: 13),
          PressScale(
            pressedScale: 0.97,
            onTap: onRetry,
            child: Container(
              padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 9),
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: 0.06),
                borderRadius: BorderRadius.circular(999),
                border: Border.all(
                  color: Colors.white.withValues(alpha: 0.14),
                ),
              ),
              child: const Row(
                mainAxisSize: MainAxisSize.min,
                children: <Widget>[
                  Icon(Icons.refresh_rounded, size: 14, color: Colors.white70),
                  SizedBox(width: 7),
                  Text(
                    'Try again',
                    style: TextStyle(
                      color: Colors.white70,
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Says the counter closed while the form was open.
///
/// Shown before the list is redrawn because it is the direct answer to what
/// the student just tried. The alternative - a silent refusal followed by a
/// tile quietly changing state - reads as the tap having been ignored, which
/// sends the reader pressing the same button again.
class _OfficeOfflineDialog extends StatelessWidget {
  const _OfficeOfflineDialog({required this.office});

  final Office office;

  @override
  Widget build(BuildContext context) {
    return Dialog(
      backgroundColor: Colors.transparent,
      insetPadding: const EdgeInsets.all(26),
      child: GlassCard(
        radius: 28,
        glow: AppColors.danger,
        padding: const EdgeInsets.all(24),
        child: Column(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Container(
              width: 58,
              height: 58,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: AppColors.danger.withValues(alpha: 0.16),
              ),
              child: const Icon(
                Icons.power_settings_new_rounded,
                color: AppColors.danger,
                size: 30,
              ),
            ),
            const SizedBox(height: 16),
            const Text(
              'Office Offline',
              style: TextStyle(
                color: Colors.white,
                fontSize: 16,
                fontWeight: FontWeight.w800,
              ),
            ),
            const SizedBox(height: 12),
            Text(
              'The ${office.name} is no longer accepting queue tickets at '
              'this time.\n\n'
              'Please try again later.',
              textAlign: TextAlign.center,
              style: TextStyle(
                color: Colors.white.withValues(alpha: 0.65),
                fontSize: 13,
                height: 1.5,
              ),
            ),
            const SizedBox(height: 22),
            PrimaryButton(
              label: 'Got it',
              onPressed: () => Navigator.of(context).pop(),
            ),
          ],
        ),
      ),
    );
  }
}

/// One priority choice. PWD carries a warning because staff must verify it.
class _PriorityOption extends StatelessWidget {
  const _PriorityOption({
    required this.value,
    required this.group,
    required this.title,
    required this.message,
    required this.icon,
    required this.onChanged,
  });

  final String value;
  final String group;
  final String title;
  final String message;
  final IconData icon;
  final ValueChanged<String> onChanged;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: PressScale(
        pressedScale: 0.985,
        onTap: () => onChanged(value),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 220),
          padding: const EdgeInsets.all(15),
          decoration: BoxDecoration(
            color: value == group
                ? AppColors.accent.withValues(alpha: 0.13)
                : Colors.white.withValues(alpha: 0.06),
            borderRadius: BorderRadius.circular(18),
            border: Border.all(
              color: value == group
                  ? AppColors.accent.withValues(alpha: 0.45)
                  : Colors.white.withValues(alpha: 0.10),
              width: value == group ? 1.3 : 1,
            ),
          ),
          child: Row(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: <Widget>[
              Icon(
                icon,
                size: 19,
                color: value == group ? AppColors.accent : Colors.white54,
              ),
              const SizedBox(width: 12),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(
                      title,
                      style: const TextStyle(
                        color: Colors.white,
                        fontSize: 14,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    const SizedBox(height: 3),
                    Text(
                      message,
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: 0.48),
                        fontSize: 11.5,
                        height: 1.4,
                      ),
                    ),
                  ],
                ),
              ),
              if (value == group)
                const Icon(
                  Icons.check_circle_rounded,
                  color: AppColors.accent,
                  size: 19,
                ),
            ],
          ),
        ),
      ),
    );
  }
}

