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
import '../screens/ticket_receipt_screen.dart';
import 'active_ticket_notice.dart';
import 'connection_status.dart';
import 'pwd_proof_picker.dart';

/// ============================================================
/// QUEUEFLOW TMC — GENERATE TICKET (POPUP FORM)
///
/// The student-facing way to take a queue number. It opens as a modal sheet
/// over whatever screen asked for it, collects the few things the server needs
/// that are not already on the account, and hands the result straight to the
/// receipt.
///
/// Student ID, name and Registrar course are read from the signed-in account
/// rather than typed: the server files the ticket against that account anyway,
/// so asking for them again would only be a chance to disagree with it stored
/// nowhere. Choosing Registrar auto-assigns the student's own course queue
/// (BSIT students enter the BSIT queue); the server re-verifies the queue
/// against the account and refuses a mismatched one.
///
/// The form talks to the same `POST /tickets` endpoint as every other transport
/// (see RemoteBackend.createTicket) through [AuthRepository.createTicket]. The
/// server remains the single source of truth: it re-checks the office switch,
/// the deployed batch's remaining slots, the course/station match and the
/// per-student active-ticket limit, and issues the number. Nothing here invents
/// a number, a position or a wait.
///
/// Submitting is guarded the same way the full-screen form guards it: the
/// button refuses a second tap while a create is in flight, and a create that
/// may have succeeded behind a lost or slow reply is reconciled once against
/// the live queue before it is reported as a failure - so a duplicated number
/// cannot be taken and a ticket the counter already holds is never denied.
/// ============================================================

/// Opens the generate-ticket popup and, on success, the official receipt.
Future<void> showGenerateTicketSheet(BuildContext context) async {
  final CreatedTicket? created = await showModalBottomSheet<CreatedTicket>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    barrierColor: Colors.black.withValues(alpha: 0.62),
    builder: (BuildContext context) => const GenerateTicketSheet(),
  );

  if (created == null || !context.mounted) {
    return;
  }

  await Navigator.of(context).push<void>(
    QfPageRoute<void>(
      page: TicketReceiptScreen(
        ticket: created.ticket,
        progress: created.queue,
        message: created.message,
        student: AuthRepository.instance.student,
      ),
    ),
  );
}

class GenerateTicketSheet extends StatefulWidget {
  const GenerateTicketSheet({super.key});

  @override
  State<GenerateTicketSheet> createState() => _GenerateTicketSheetState();
}

class _GenerateTicketSheetState extends State<GenerateTicketSheet> {
  final AuthRepository _auth = AuthRepository.instance;

  /// The Registrar is not one office id: it is seven, one per course. The
  /// dropdown carries a single sentinel row for it and the student's own
  /// registered course resolves the real office and service automatically —
  /// there is no manual course picker.
  static const int _registrarId = -1;

  int? _selectedId;

  /// The office a number will actually be taken at. For a Registrar choice this
  /// is the student's own `REG-<COURSE>` office, resolved from the account.
  Office? _office;

  /// The service the ticket is filed under, resolved from [_office] rather than
  /// chosen (the server requires one that belongs to the office).
  OfficeService? _service;

  RegistrarCourseOption? _course;

  String _priority = AppConstants.priorityRegular;
  String? _pwdProof;
  String? _selectedPurpose;

  bool _loadingCourses = false;
  bool _submitting = false;

  String? _error;
  String? _officeError;
  String? _courseError;
  String? _purposeError;
  String? _proofError;

  /// Parked across the connectivity gate, like the full-screen form, because
  /// the gate's callback must return the action's own result.
  String? _failure;
  CreatedTicket? _issued;
  bool _creationUncertain = false;

  bool get _registrarSelected => _selectedId == _registrarId;

  // Common purposes by office (mirrors typical campus flows).

  List<String> get _purposesForSelected {
    // If a Registrar course is selected, use its course-specific purposes.
    final RegistrarCourseOption? course = _course;

    if (course != null) {
      final String courseCode = course.code.trim().toUpperCase();

      final List<String>? coursePurposes = _registrarCoursePurposes[courseCode];

      if (coursePurposes != null && coursePurposes.isNotEmpty) {
        return List<String>.unmodifiable(coursePurposes);
      }

      // Fall back to the common Registrar purposes for unknown course codes.
      return List<String>.unmodifiable(
        _officePurposes['Registrar'] ?? const <String>[],
      );
    }

    // For other offices, retain their existing purpose lists.
    final Office? office = _office;

    if (office == null) {
      return const <String>[];
    }

    final List<String>? list = _officePurposes[office.name];

    if (list == null || list.isEmpty) {
      return const <String>[];
    }

    return List<String>.unmodifiable(list);
  }

  /// Common purposes by office (mirrors typical campus flows).

  static const Map<String, List<String>> _officePurposes =
      <String, List<String>>{
        'Registrar': <String>[
          'Enrollment / Registration',
          'New Student Enrollment',
          'Adding or Changing Subjects',
          'Study Load Request',
          'Grade Inquiry',
          'Certificate of Grades Request',
          'Transcript of Records (TOR) Request',
          'Certificate of Enrollment Request',
          'Certificate of Good Moral Character Request',
          'Student Records Update',
          'Transfer Credentials Request',
          'Academic Records Inquiry',
          'Other Registrar Concerns',
        ],
        'Cashier': <String>[
          'Tuition or School Fee Payment',
          'Assessment Payment',
          'Document Processing Fee',
          'Other Authorized Fee Payment',
          'Payment Receipt Request',
          'Payment Verification',
          'Refund Inquiry',
          'Other Cashier Concerns',
        ],
        'Admissions': <String>[
          'Admission Inquiry',
          'New Student Application',
          'Admission Requirements Submission',
          'Entrance Examination Inquiry',
          'Admission Status Inquiry',
          'Enrollment Requirements',
          'Transfer Student Application',
          'Student Information Correction',
          'Other Admissions Concerns',
        ],
        'SASO': <String>[
          'Student Assistance',
          'Student Concerns or Complaints',
          'Student Activities',
          'Student Organization Inquiry',
          'Student Welfare Assistance',
          'Student Conduct Inquiry',
          'Other SASO Concerns',
        ],
        'Scholarship Office': <String>[
          'Scholarship Application',
          'Scholarship Requirements Submission',
          'Scholarship Renewal',
          'Scholarship Status Inquiry',
          'Scholarship Eligibility Inquiry',
          'Scholarship Documents Verification',
          'Financial Assistance Inquiry',
          'Other Scholarship Concerns',
        ],
        'School Clinic': <String>[
          'General Consultation',
          'First Aid',
          'Medical Certificate Request',
          'Health Assessment',
          'Health Requirements Inquiry',
          'Medical Records Inquiry',
          'Other Clinic Concerns',
        ],
      };

  static const Map<String, List<String>> _registrarCoursePurposes =
      <String, List<String>>{
        'BACOMM': <String>[
          'Communication Subject Inquiry',
          'Communication Internship Inquiry',
          'Communication Research Consultation',
          'Academic Records Request',
          'Other Registrar Concerns',
        ],
        'BAPOLSCI': <String>[
          'Political Science Subject Inquiry',
          'Political Science Internship Inquiry',
          'Academic Records Request',
          'Certificate Request',
          'Other Registrar Concerns',
        ],
        'BEED': <String>[
          'Education Subject Inquiry',
          'Teaching Internship Inquiry',
          'Academic Records Request',
          'Certificate Request',
          'Other Registrar Concerns',
        ],
        'BSCRIM': <String>[
          'Criminology Subject Inquiry',
          'Criminology Internship Inquiry',
          'Academic Records Request',
          'Certificate Request',
          'Other Registrar Concerns',
        ],
        'BSED': <String>[
          'Education Subject Inquiry',
          'Teaching Internship Inquiry',
          'Academic Records Request',
          'Certificate Request',
          'Other Registrar Concerns',
        ],
        'BSIT': <String>[
          'IT Subject Inquiry',
          'IT Internship Inquiry',
          'Capstone or Project Records Inquiry',
          'Academic Records Request',
          'Other Registrar Concerns',
        ],
        'BSOA': <String>[
          'Office Administration Subject Inquiry',
          'Office Administration Internship Inquiry',
          'Academic Records Request',
          'Certificate Request',
          'Other Registrar Concerns',
        ],
      };

  @override
  void initState() {
    super.initState();
    // Repository changes (a ticket served, cancelled, or completed on
    // another screen or by the counter) rebuild this sheet, so the gate
    // below lifts the moment the active ticket finishes — no sign-out,
    // no restart.
    _auth.addListener(_onAuthChanged);

    // The popup is usually opened from a dashboard that already read the
    // catalogue, but a light re-read keeps the slot counts honest if it sat
    // open for a while first.
    unawaited(_auth.refresh());
  }

  @override
  void dispose() {
    _auth.removeListener(_onAuthChanged);
    super.dispose();
  }

  void _onAuthChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  /// The ticket currently occupying this student's queue slot, if any.
  ///
  /// One active ticket across all offices: holding a Registrar number locks
  /// Cashier, Admissions and every other office until it is served or
  /// cancelled. The server enforces the same rule, so this gate only decides
  /// what the student is told — never whether the request may go out.
  QueueTicket? get _activeTicket => _auth.activeTicket;

  static OfficeService? _defaultServiceFor(Office office) {
    for (final OfficeService service in office.services) {
      if (service.isActive) {
        return service;
      }
    }

    return null;
  }

  /// The student's own Registrar course queue, resolved from the account.
  ///
  /// Matched by the enrolled course code first (the account is the source of
  /// truth), falling back to the server-flagged enrolled row. Null when the
  /// profile has no course or the queue is not listed, both of which are
  /// reported as "contact the administrator" rather than queued elsewhere.
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

  /// Assigns the student's own Registrar queue after the course list loads.
  ///
  /// Runs on every Registrar read (open, refresh, pre-submit re-read) so a
  /// stale assignment cannot survive a catalogue change. A missing or
  /// unlisted course leaves [_course] null and the validation below explains
  /// it instead of queueing the student elsewhere.
  void _autoAssignRegistrarCourse() {
    final RegistrarCourseOption? mine = _enrolledCourse();

    if (mine == null) {
      _course = null;
      _office = null;
      _service = null;
      return;
    }

    _course = mine;
    _office = mine.office;
    _service = _defaultServiceFor(mine.office);
    _courseError = null;
    _error = null;
    _selectedPurpose = null;
  }

  void _onOfficeChanged(int? id) {
    if (id == null) {
      return;
    }

    setState(() {
      _selectedId = id;
      _officeError = null;
      _courseError = null;
      _error = null;

      if (id == _registrarId) {
        _office = null;
        _service = null;
        _course = null;
        _selectedPurpose = null;
      } else {
        _course = null;
        final Office? office = _auth.officeById(id);
        _office = office;
        _service = office == null ? null : _defaultServiceFor(office);
        _selectedPurpose = null;
      }
    });

    if (id == _registrarId) {
      unawaited(_loadCourses());
    }
  }

  Future<void> _loadCourses() async {
    setState(() => _loadingCourses = true);

    await _auth.refreshRegistrarCourses();

    if (!mounted) {
      return;
    }

    setState(() {
      _loadingCourses = false;
      _autoAssignRegistrarCourse();
      if (_registrarSelected && _course == null) {
        _courseError =
            'Your profile has no Registrar course queue assigned, so a '
            'number cannot be issued here. Please contact the administrator '
            'to update your account.';
      }
    });
  }

  void _selectPriority(String value) {
    setState(() {
      _priority = value;

      if (value != AppConstants.priorityPwd) {
        _pwdProof = null;
      }

      _proofError = null;
    });
  }

  /// The first thing wrong with the chosen office, in words the student can act
  /// on - or null when the server would take a number there.
  String? get _officeNotice {
    final Office? office = _office;

    if (office == null) {
      return null;
    }

    if (!office.isActive) {
      return 'This office is not available.';
    }

    if (!office.isOnline) {
      return 'This counter is currently closed and not accepting tickets.';
    }

    if (office.isBatchClosed) {
      return 'Batch Closed — the office is not accepting new queue tickets.';
    }

    if (office.isBatchFull) {
      return 'This queue is full (${office.slotsLabel}). Please try another office.';
    }

    return null;
  }

  bool _validate() {
    final Office? office = _office;
    // Registrar needs no manual pick: the student's own queue is assigned on
    // load. A null here means the profile has no course or the queue is not
    // listed, which is a contact-the-administrator problem, not a selection.
    final bool registrarMissingCourse = _registrarSelected && _course == null;
    final bool emptyPurpose =
        _selectedPurpose == null || _selectedPurpose!.trim().isEmpty;
    final bool pwdMissing =
        _priority == AppConstants.priorityPwd &&
        (_pwdProof == null || _pwdProof!.isEmpty);

    String? officeError;

    if (registrarMissingCourse) {
      officeError = null;
    } else if (office == null) {
      officeError = 'Please select a campus office.';
    } else {
      officeError = _officeNotice;

      if (officeError == null && _service == null) {
        officeError =
            '${office.name} has no active service to take a number for '
            'right now.';
      }
    }

    setState(() {
      _officeError = officeError;
      _courseError = registrarMissingCourse
          ? 'Your profile has no Registrar course queue assigned, so a '
              'number cannot be issued here. Please contact the administrator '
              'to update your account.'
          : null;
      _purposeError = emptyPurpose
          ? 'Please select a purpose of your visit.'
          : null;
      _proofError = pwdMissing
          ? 'Please select a photo as proof for PWD priority.'
          : null;
      _error = null;
    });

    return officeError == null &&
        !registrarMissingCourse &&
        !emptyPurpose &&
        !pwdMissing;
  }

  Future<void> _submit() async {
    if (_submitting || !_validate()) {
      return;
    }

    // One active ticket across all offices. Said before any office,
    // course or purpose is even considered — because no choice on this form
    // can make the request acceptable while another number is live.
    final QueueTicket? active = _activeTicket;

    if (active != null) {
      setState(() => _error = activeTicketMessage(active));
      return;
    }

    final Office office = _office!;
    final OfficeService service = _service!;
    final RegistrarCourseOption? course = _course;
    final int? courseId = course?.courseId ?? _auth.queueCourseId;

    if (courseId == null) {
      setState(
        () => _error =
            'Your profile has no course set, so the office cannot accept a '
            'queue request. Ask the registrar to assign your course.',
      );
      return;
    }

    setState(() {
      _submitting = true;
      _error = null;
      _failure = null;
    });

    Office? wentOffline;

    final bool?
    allowed = await ConnectivityService.instance.requireServer<bool>(() async {
      // Re-read the switch from the server rather than trusting the copy on
      // screen: this is the last moment a stale "online" could become a
      // number for a counter that was switched off ten minutes ago.
      try {
        await _auth.refreshOfficeStatus();
      } catch (_) {
        _failure =
            'Your number was not issued because the office status could '
            'not be re-checked. Please try again.';
        return false;
      }

      final Office? freshOffice = _auth.officeById(office.id);

      if (freshOffice != null && !freshOffice.isOnline) {
        wentOffline = freshOffice;
        return false;
      }

      if (course != null) {
        final RegistrarCourseList fresh = await _auth.refreshRegistrarCourses();
        final RegistrarCourseOption? reread = fresh.byCode(course.code);

        if (reread == null) {
          _failure =
              'That Registrar course queue is no longer listed. '
              'Please pick again.';
          return false;
        }

        if (!reread.isOpen) {
          _failure =
              '${reread.code} has no open queue right now. '
              '${reread.slotsDeployed ? 'Its batch is full or closed.' : 'No batch has been deployed yet.'}';
          return false;
        }
      }

      final ({CreatedTicket? created, String? error, bool uncertain}) result =
          await _auth.createTicket(
            officeId: office.id,
            serviceId: service.id,
            courseId: courseId,
            priority: _priority,
            purpose: _selectedPurpose?.trim(),
            yearLevel: _auth.student?.yearLevel,
            evidencePath: _priority == AppConstants.priorityPwd
                ? _pwdProof
                : null,
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

    if (wentOffline != null) {
      final Office closed = wentOffline!;
      final String reason = closed.closedReason ?? '';

      setState(
        () => _error = reason.isEmpty
            ? '${closed.name} is not accepting tickets right now.'
            : '${closed.name} is not accepting tickets. $reason',
      );
      unawaited(_auth.refreshOfficeStatus().then((_) {}, onError: (_) {}));
      return;
    }

    // A refused create - or a pre-submit re-check that could not be made - puts
    // the server's own sentence in [_failure] and makes the gate's action
    // return false. So [_failure] has to be read before the connection wording
    // below: the gate returns `false` on a failed action, not only `null` when
    // it blocked one, and testing `allowed != true` first reported every
    // refusal as the connection state ("Connected") instead of the reason.
    if (_failure != null) {
      if (_creationUncertain) {
        final CreatedTicket? reconciled = await _reconcile(office);

        if (reconciled != null) {
          _close(reconciled);
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

    if (issued != null) {
      _close(issued);
    }
  }

  /// Re-reads the queue after a failure that may have hidden a successful
  /// create, adopting the ticket if one appeared for [office].
  Future<CreatedTicket?> _reconcile(Office office) async {
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
      message:
          'Your number was issued, but the confirmation took too long to '
          'reach your phone. Here is the ticket the counter has.',
    );
  }

  void _close(CreatedTicket created) {
    Navigator.of(context).pop(created);
  }

  @override
  Widget build(BuildContext context) {
    final MediaQueryData media = MediaQuery.of(context);

    return Padding(
      padding: EdgeInsets.only(bottom: media.viewInsets.bottom),
      child: ConstrainedBox(
        constraints: BoxConstraints(maxHeight: media.size.height * 0.92),
        child: Container(
          decoration: const BoxDecoration(
            gradient: LinearGradient(
              begin: Alignment.topCenter,
              end: Alignment.bottomCenter,
              colors: <Color>[AppColors.navy, AppColors.primary],
            ),
            borderRadius: BorderRadius.vertical(top: Radius.circular(28)),
            border: Border(top: BorderSide(color: Color(0x33FFFFFF))),
          ),
          child: SafeArea(
            top: false,
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: <Widget>[
                _grabber(),
                _header(),
                Flexible(
                  child: SingleChildScrollView(
                    padding: const EdgeInsets.fromLTRB(20, 4, 20, 8),
                    child: _form(),
                  ),
                ),
                _footer(),
              ],
            ),
          ),
        ),
      ),
    );
  }

  Widget _grabber() {
    return Padding(
      padding: const EdgeInsets.only(top: 10, bottom: 6),
      child: Container(
        width: 44,
        height: 4,
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: 0.22),
          borderRadius: BorderRadius.circular(999),
        ),
      ),
    );
  }

  Widget _header() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 6, 12, 8),
      child: Row(
        children: <Widget>[
          Container(
            width: 42,
            height: 42,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(14),
              gradient: AppColors.brandGradient,
            ),
            child: const Icon(
              Icons.confirmation_number_rounded,
              color: Colors.white,
              size: 20,
            ),
          ),
          const SizedBox(width: 13),
          const Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  'Generate ticket',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                SizedBox(height: 2),
                Text(
                  'Fill in the details to take a queue number',
                  style: TextStyle(color: Colors.white54, fontSize: 11.5),
                ),
              ],
            ),
          ),
          PressScale(
            pressedScale: 0.86,
            onTap: () => Navigator.of(context).maybePop(),
            child: Container(
              width: 38,
              height: 38,
              decoration: BoxDecoration(
                shape: BoxShape.circle,
                color: Colors.white.withValues(alpha: 0.08),
              ),
              child: const Icon(
                Icons.close_rounded,
                size: 19,
                color: Colors.white70,
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _form() {
    final student = _auth.student;
    final QueueTicket? active = _activeTicket;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        _identityCard(student?.name, student?.studentId, student?.course),
        const SizedBox(height: 20),

        if (active != null) ...<Widget>[
          ActiveTicketNotice(ticket: active),
          const SizedBox(height: 16),
        ],

        if (_error != null) ...<Widget>[
          _ErrorBanner(message: _error!),
          const SizedBox(height: 16),
        ],

        const SectionTitle(text: 'Campus office'),
        _officeDropdown(),
        if (_officeNotice != null) ...<Widget>[
          const SizedBox(height: 8),
          _Notice(text: _officeNotice!),
        ],
        if (_officeError != null) ...<Widget>[
          const SizedBox(height: 8),
          _FieldError(text: _officeError!),
        ],

        if (_registrarSelected) ...<Widget>[
          const SizedBox(height: 18),
          const SectionTitle(text: 'Your Registrar queue'),
          _registrarQueueDisplay(),
          if (_courseError != null) ...<Widget>[
            const SizedBox(height: 8),
            _FieldError(text: _courseError!),
          ],
        ],

        const SizedBox(height: 18),
        const SectionTitle(text: 'Purpose of visit'),
        _purposeField(),

        const SizedBox(height: 18),
        const SectionTitle(text: 'Priority'),
        _PriorityTile(
          value: AppConstants.priorityRegular,
          group: _priority,
          title: 'Regular',
          message: 'Joins the normal queue order.',
          icon: Icons.people_alt_rounded,
          onChanged: _selectPriority,
        ),
        _PriorityTile(
          value: AppConstants.priorityPwd,
          group: _priority,
          title: 'Person with disability (PWD)',
          message:
              'Served ahead of the regular queue. Requires a photo of your '
              'PWD ID as proof.',
          icon: Icons.accessible_rounded,
          onChanged: _selectPriority,
        ),

        if (_priority == AppConstants.priorityPwd) ...<Widget>[
          const SizedBox(height: 14),
          const SectionTitle(text: 'PWD proof'),
          PwdProofPicker(
            imagePath: _pwdProof,
            onChanged: (String? path) => setState(() {
              _pwdProof = path;
              _proofError = null;
            }),
            onError: (String message) => setState(() => _proofError = message),
          ),
          if (_proofError != null) ...<Widget>[
            const SizedBox(height: 8),
            _FieldError(text: _proofError!),
          ],
        ],

        const SizedBox(height: 8),
      ],
    );
  }

  Widget _identityCard(String? name, String? studentId, String? course) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(16),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.06),
        borderRadius: BorderRadius.circular(18),
        border: Border.all(color: Colors.white.withValues(alpha: 0.12)),
      ),
      child: Row(
        children: <Widget>[
          const Icon(Icons.badge_outlined, size: 20, color: AppColors.sky),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  name ?? 'Student',
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 14.5,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  <String?>[
                    if (studentId != null) 'ID $studentId',
                    if (course != null && course.isNotEmpty) course,
                  ].join('  •  '),
                  style: const TextStyle(color: Colors.white54, fontSize: 11.5),
                ),
              ],
            ),
          ),
          const Icon(
            Icons.lock_outline_rounded,
            size: 14,
            color: Colors.white30,
          ),
        ],
      ),
    );
  }

  Widget _officeDropdown() {
    final Office? registrar = _auth.registrarOffice;
    final List<Office> offices = _auth.selectableOffices;

    final List<DropdownMenuItem<int>> items = <DropdownMenuItem<int>>[
      if (registrar != null)
        const DropdownMenuItem<int>(
          value: _registrarId,
          child: Text('Registrar'),
        ),
      for (final Office office in offices)
        DropdownMenuItem<int>(
          value: office.id,
          child: Text(
            '${office.name} • ${office.isOnline ? 'ONLINE' : 'OFFLINE'}'
            '${office.isBatchClosed ? ' • Batch Closed' : ''}',
          ),
        ),
    ];

    return _FieldShell(
      child: DropdownButtonHideUnderline(
        child: DropdownButton<int>(
          value: _selectedId,
          isExpanded: true,
          hint: const Text(
            'Select a campus office',
            style: TextStyle(color: Colors.white38, fontSize: 14),
          ),
          dropdownColor: AppColors.navy,
          borderRadius: BorderRadius.circular(16),
          iconEnabledColor: Colors.white70,
          style: const TextStyle(
            color: Colors.white,
            fontSize: 14.5,
            fontWeight: FontWeight.w600,
          ),
          items: items,
          onChanged: _submitting ? null : _onOfficeChanged,
        ),
      ),
    );
  }

  /// The auto-assigned Registrar queue for the signed-in student.
  ///
  /// Read-only: the queue comes from the account's registered course, so
  /// there is nothing to pick. Shows the assigned course and its live slot
  /// reading, or why no queue could be assigned.
  Widget _registrarQueueDisplay() {
    if (_loadingCourses) {
      return _FieldShell(
        child: Row(
          children: <Widget>[
            const SizedBox(
              width: 15,
              height: 15,
              child: CircularProgressIndicator(
                strokeWidth: 2,
                color: AppColors.sky,
              ),
            ),
            const SizedBox(width: 12),
            Text(
              'Finding your Registrar queue...',
              style: TextStyle(
                color: Colors.white.withValues(alpha: 0.54),
                fontSize: 13,
              ),
            ),
          ],
        ),
      );
    }

    final RegistrarCourseOption? mine = _course;

    if (mine == null) {
      final List<RegistrarCourseOption> courses =
          _auth.registrarCourses.courses;

      if (courses.isEmpty) {
        return const _FieldShell(
          child: Text(
            'No course queues are listed right now.',
            style: TextStyle(color: Colors.white54, fontSize: 13),
          ),
        );
      }

      return _FieldShell(
        child: Row(
          children: <Widget>[
            const Icon(
              Icons.lock_outline_rounded,
              size: 16,
              color: Colors.white54,
            ),
            const SizedBox(width: 10),
            const Expanded(
              child: Text(
                'No Registrar queue matches your profile. Please contact the '
                'administrator to update your account.',
                style: TextStyle(
                  color: Colors.white70,
                  fontSize: 12.5,
                  height: 1.4,
                ),
              ),
            ),
          ],
        ),
      );
    }

    return _FieldShell(
      child: Row(
        children: <Widget>[
          Container(
            padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 6),
            decoration: BoxDecoration(
              color: AppColors.accent.withValues(alpha: 0.16),
              borderRadius: BorderRadius.circular(999),
              border: Border.all(
                color: AppColors.accent.withValues(alpha: 0.45),
              ),
            ),
            child: Text(
              mine.code,
              style: const TextStyle(
                color: AppColors.accent,
                fontSize: 13,
                fontWeight: FontWeight.w800,
                letterSpacing: 0.6,
              ),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  mine.officeName,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 13.5,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 2),
                Text(
                  'Assigned from your account • ${mine.slotsLabel}'
                  '${mine.isBatchClosed ? ' • Batch Closed' : ''}',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.50),
                    fontSize: 11,
                  ),
                ),
              ],
            ),
          ),
          const Icon(
            Icons.lock_outline_rounded,
            size: 14,
            color: Colors.white30,
          ),
        ],
      ),
    );
  }

  Widget _purposeField() {
    final List<String> purposes = _purposesForSelected;

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        _FieldShell(
          child: DropdownButtonHideUnderline(
            child: DropdownButton<String>(
              value: _selectedPurpose,
              isExpanded: true,
              hint: const Text(
                'Select purpose of visit',
                style: TextStyle(color: Colors.white38, fontSize: 14),
              ),
              dropdownColor: AppColors.navy,
              borderRadius: BorderRadius.circular(16),
              iconEnabledColor: Colors.white70,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 14.5,
                fontWeight: FontWeight.w600,
              ),
              items: <DropdownMenuItem<String>>[
                for (final String item in purposes)
                  DropdownMenuItem<String>(value: item, child: Text(item)),
              ],
              onChanged: _submitting || purposes.isEmpty
                  ? null
                  : (String? value) {
                      setState(() {
                        _selectedPurpose = value;
                        if (_purposeError != null) {
                          _purposeError = null;
                        }
                      });
                    },
            ),
          ),
        ),
        if (purposes.isEmpty && _office != null) ...<Widget>[
          const SizedBox(height: 8),
          const _Notice(text: 'No predefined purposes for this office yet.'),
        ],
        if (_purposeError != null) ...<Widget>[
          const SizedBox(height: 8),
          _FieldError(text: _purposeError!),
        ],
      ],
    );
  }

  Widget _footer() {
    return Padding(
      padding: const EdgeInsets.fromLTRB(20, 12, 20, 16),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: <Widget>[
          ServerActionButton(
            child: PrimaryButton(
              label: _submitting ? 'Issuing your number...' : 'Generate ticket',
              icon: Icons.confirmation_number_rounded,
              busy: _submitting,
              // An active ticket disables generation for every office: the
              // notice above says why, so the dimmed button needs no guess.
              onPressed: (_submitting || _activeTicket != null)
                  ? null
                  : _submit,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            'Your number is issued by the QueueFlow TMC server.',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.35),
              fontSize: 10.5,
            ),
          ),
        ],
      ),
    );
  }
}

/// A rounded dark input surface.
class _FieldShell extends StatelessWidget {
  const _FieldShell({required this.child});

  final Widget child;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.06),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.white.withValues(alpha: 0.14)),
      ),
      child: child,
    );
  }
}

class _FieldError extends StatelessWidget {
  const _FieldError({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const Icon(
          Icons.error_outline_rounded,
          size: 14,
          color: AppColors.danger,
        ),
        const SizedBox(width: 7),
        Expanded(
          child: Text(
            text,
            style: const TextStyle(
              color: AppColors.danger,
              fontSize: 11.5,
              fontWeight: FontWeight.w700,
              height: 1.4,
            ),
          ),
        ),
      ],
    );
  }
}

class _Notice extends StatelessWidget {
  const _Notice({required this.text});

  final String text;

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        const Icon(
          Icons.info_outline_rounded,
          size: 14,
          color: AppColors.accent,
        ),
        const SizedBox(width: 7),
        Expanded(
          child: Text(
            text,
            style: const TextStyle(
              color: AppColors.accent,
              fontSize: 11.5,
              height: 1.4,
              fontWeight: FontWeight.w600,
            ),
          ),
        ),
      ],
    );
  }
}

class _ErrorBanner extends StatelessWidget {
  const _ErrorBanner({required this.message});

  final String message;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.danger.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.danger.withValues(alpha: 0.4)),
      ),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          const Icon(
            Icons.error_outline_rounded,
            size: 18,
            color: AppColors.danger,
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              message,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 12.5,
                height: 1.45,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// One priority choice, matching the full-screen form's radio card.
class _PriorityTile extends StatelessWidget {
  const _PriorityTile({
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
    final bool selected = value == group;

    return PressScale(
      pressedScale: 0.985,
      onTap: () => onChanged(value),
      child: Container(
        width: double.infinity,
        margin: const EdgeInsets.only(bottom: 10),
        padding: const EdgeInsets.all(14),
        decoration: BoxDecoration(
          color: selected
              ? AppColors.accent.withValues(alpha: 0.14)
              : Colors.white.withValues(alpha: 0.05),
          borderRadius: BorderRadius.circular(18),
          border: Border.all(
            color: selected
                ? AppColors.accent.withValues(alpha: 0.5)
                : Colors.white.withValues(alpha: 0.12),
          ),
        ),
        child: Row(
          children: <Widget>[
            Icon(
              selected ? Icons.radio_button_checked : Icons.radio_button_off,
              size: 20,
              color: selected ? AppColors.accent : Colors.white38,
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
                      fontSize: 13.5,
                      fontWeight: FontWeight.w800,
                    ),
                  ),
                  const SizedBox(height: 3),
                  Text(
                    message,
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: 0.5),
                      fontSize: 11.5,
                      height: 1.35,
                    ),
                  ),
                ],
              ),
            ),
            Icon(icon, size: 18, color: Colors.white24),
          ],
        ),
      ),
    );
  }
}
