import '../core/app_constants.dart';
import '../core/qf_value.dart';

/// ============================================================
/// QUEUEFLOW TMC — CAMPUS CATALOGUE
///
/// Mirrors the `offices`, `office_stations`, `office_services` and
/// `courses` tables, including the Registrar per-course offices
/// (code `REG-<COURSE>`) introduced by the Laravel migrations.
///
/// THE STATUS IS READ, NEVER GUESSED
///
/// `status` is the daily online/offline switch Laravel keeps in
/// `offices.status`, and it is the only thing that decides [Office.isOnline].
/// A payload that does not carry it - a truncated read, a server build that
/// predates the column, a hand-built map in a test - decodes as offline, not
/// as online: a counter whose state the client does not know must not be
/// offered a student. The Laravel side is the source of truth for this field
/// and there is no local fallback for it anywhere in the app.
/// ============================================================

class Course {
  final int id;
  final String code;
  final String name;
  final int? officeId;
  final bool isActive;

  const Course({
    required this.id,
    required this.code,
    required this.name,
    this.officeId,
    this.isActive = true,
  });

  factory Course.fromMap(Map<String, dynamic> map) => Course(
    id: QfValue.toInt(map['id']),
    code: QfValue.toStr(map['code']),
    name: QfValue.toStr(map['name']),
    officeId: QfValue.toIntOrNull(map['office_id']),
    isActive: QfValue.toBool(map['is_active'], fallback: true),
  );

  Map<String, dynamic> toMap() => <String, dynamic>{
    'id': id,
    'code': code,
    'name': name,
    'office_id': officeId,
    'is_active': isActive,
  };
}

class OfficeStation {
  final int id;
  final int officeId;
  final String name;
  final String? location;
  final List<String> courseCodes;
  final int minDailyStudents;
  final int targetDailyStudents;
  final int maxDailySlots;
  final String? openingTime;
  final String? closingTime;
  final bool isActive;

  /// Resolved server-side against the signed-in student's course.
  /// Null means the transport did not supply it, so fall back to computing it.
  final bool? acceptsCourseForStudent;

  const OfficeStation({
    required this.id,
    required this.officeId,
    required this.name,
    this.location,
    this.courseCodes = const <String>[],
    this.minDailyStudents = 0,
    this.targetDailyStudents = 0,
    this.maxDailySlots = 0,
    this.openingTime,
    this.closingTime,
    this.isActive = true,
    this.acceptsCourseForStudent,
  });

  /// Mirrors OfficeStation::acceptsCourse() on the Laravel side.
  ///
  /// An empty `course_codes` list means the station serves everyone.
  bool acceptsCourse(String? courseCode) {
    if (acceptsCourseForStudent != null) {
      return acceptsCourseForStudent!;
    }

    if (courseCode == null || courseCode.isEmpty || courseCodes.isEmpty) {
      return true;
    }

    final String needle = courseCode.toUpperCase();

    return courseCodes.any((String code) => code.toUpperCase() == needle);
  }

  factory OfficeStation.fromMap(Map<String, dynamic> map) => OfficeStation(
    id: QfValue.toInt(map['id']),
    officeId: QfValue.toInt(map['office_id']),
    name: QfValue.toStr(map['name']),
    location: QfValue.toStrOrNull(map['location']),
    courseCodes: QfValue.toStringList(map['course_codes']),
    minDailyStudents: QfValue.toInt(map['min_daily_students']),
    targetDailyStudents: QfValue.toInt(map['target_daily_students']),
    maxDailySlots: QfValue.toInt(map['max_daily_slots']),
    openingTime: QfValue.toStrOrNull(map['opening_time']),
    closingTime: QfValue.toStrOrNull(map['closing_time']),
    isActive: QfValue.toBool(map['is_active'], fallback: true),
    // Absent and explicitly null both mean "not resolved against a signed-in
    // student", which is what lets [acceptsCourse] fall back to
    // [courseCodes]. Resolving to true here would silently widen every
    // station back to serving everyone on the next round trip.
    acceptsCourseForStudent: map['accepts_course'] == null
        ? null
        : QfValue.toBool(map['accepts_course'], fallback: true),
  );

  Map<String, dynamic> toMap() => <String, dynamic>{
    'id': id,
    'office_id': officeId,
    'name': name,
    'location': location,
    'course_codes': courseCodes,
    'min_daily_students': minDailyStudents,
    'target_daily_students': targetDailyStudents,
    'max_daily_slots': maxDailySlots,
    'opening_time': openingTime,
    'closing_time': closingTime,
    'is_active': isActive,
    'accepts_course': acceptsCourseForStudent,
  };
}

class OfficeService {
  final int id;
  final int officeId;
  final int? stationId;
  final String name;
  final String? description;
  final bool requiresCourse;
  final bool isActive;
  final OfficeStation? station;

  const OfficeService({
    required this.id,
    required this.officeId,
    required this.name,
    this.stationId,
    this.description,
    this.requiresCourse = false,
    this.isActive = true,
    this.station,
  });

  String get locationLabel => station?.location ?? '';

  factory OfficeService.fromMap(Map<String, dynamic> map) => OfficeService(
    id: QfValue.toInt(map['id']),
    officeId: QfValue.toInt(map['office_id']),
    stationId: QfValue.toIntOrNull(map['station_id']),
    name: QfValue.toStr(map['name']),
    description: QfValue.toStrOrNull(map['description']),
    requiresCourse: QfValue.toBool(map['requires_course']),
    isActive: QfValue.toBool(map['is_active'], fallback: true),
    station: map['station'] is Map
        ? OfficeStation.fromMap(
            Map<String, dynamic>.from(map['station'] as Map),
          )
        : null,
  );

  Map<String, dynamic> toMap() => <String, dynamic>{
    'id': id,
    'office_id': officeId,
    'station_id': stationId,
    'name': name,
    'description': description,
    'requires_course': requiresCourse,
    'is_active': isActive,
    'station': station?.toMap(),
  };
}

/// One batch ("deployment") of queue slots for an office on a given day.
class QueueBatch {
  final int id;
  final int officeId;
  final int batchNo;
  final String status;
  final int maxSlots;
  final int submittedCount;
  final int servedCount;
  final DateTime? openedAt;
  final DateTime? closedAt;
  final DateTime? completedAt;

  const QueueBatch({
    required this.id,
    required this.officeId,
    required this.batchNo,
    required this.status,
    this.maxSlots = 100,
    this.submittedCount = 0,
    this.servedCount = 0,
    this.openedAt,
    this.closedAt,
    this.completedAt,
  });

  bool get isOpen => status == 'open';
  bool get isFull => status == 'full';
  bool get isClosed => status == 'closed';
  bool get isCompleted => status == 'completed';

  int get slotsRemaining => (maxSlots - submittedCount).clamp(0, maxSlots);

  double get fillRatio => maxSlots <= 0
      ? 0
      : (submittedCount / maxSlots).clamp(0.0, 1.0).toDouble();

  factory QueueBatch.fromMap(Map<String, dynamic> map) => QueueBatch(
    id: QfValue.toInt(map['id']),
    officeId: QfValue.toInt(map['office_id']),
    batchNo: QfValue.toInt(map['batch_no']),
    status: QfValue.toStr(map['status']),
    maxSlots: QfValue.toInt(map['max_slots'], fallback: 100),
    submittedCount: QfValue.toInt(map['submitted_count']),
    servedCount: QfValue.toInt(map['served_count']),
    openedAt: QfValue.toDate(map['opened_at']),
    closedAt: QfValue.toDate(map['closed_at']),
    completedAt: QfValue.toDate(map['completed_at']),
  );

  Map<String, dynamic> toMap() => <String, dynamic>{
    'id': id,
    'office_id': officeId,
    'batch_no': batchNo,
    'status': status,
    'max_slots': maxSlots,
    'submitted_count': submittedCount,
    'served_count': servedCount,
    'opened_at': openedAt?.toIso8601String(),
    'closed_at': closedAt?.toIso8601String(),
    'completed_at': completedAt?.toIso8601String(),
  };
}

/// A campus office a student may queue at, with its services resolved
/// for that student's course.
class Office {
  final int id;
  final String name;
  final String code;
  final String? description;
  final String? officeType;
  final String? location;
  final bool isActive;
  final String? openingTime;
  final String? closingTime;
  final int maxSlots;

  /// The daily online/offline switch, kept in sync with `offices.status`.
  ///
  /// Defaults to `offline` rather than `online`: an unknown switch is an
  /// unavailable counter, and the only value this app should ever see come
  /// back as `online` is one Laravel actually wrote. See the library comment
  /// above for why the guess is deliberately the pessimistic one.
  final String status;

  /// The course that owns this office, for `REG-<COURSE>` offices.
  final Course? course;
  final String? courseCode;

  final bool windowOpen;
  final bool accepting;
  final QueueBatch? openBatch;
  final QueueBatch? activeBatch;
  final int waitingCount;
  final int servingCount;

  /// Whether an administrator has deployed a batch here at all.
  ///
  /// False means no number can be issued yet, which is what separates
  /// "closed because nobody deployed 100 slots" from "closed because all 100
  /// were taken".
  final bool slotsDeployed;

  /// Numbers still issuable in the deployed batch.
  final int availableSlots;

  /// Tickets already issued into the deployed batch.
  ///
  /// The numerator in "ISSUED / TOTAL": the counter renderers show how many
  /// numbers have gone out, so "0/100" reads as an empty batch and "100/100"
  /// as a full one, which is the ordering a student actually answers.
  final int usedSlots;

  /// Size of the deployed batch, i.e. the denominator in "ISSUED / TOTAL".
  final int totalSlots;

  /// The backend's own verdict on whether this office can take a number now.
  final String queueStatus;

  final List<OfficeService> services;
  final List<OfficeStation> stations;

  const Office({
    required this.id,
    required this.name,
    required this.code,
    this.description,
    this.officeType,
    this.location,
    this.isActive = true,
    this.openingTime,
    this.closingTime,
    this.maxSlots = 100,
    this.status = AppConstants.officeStatusOffline,
    this.course,
    this.courseCode,
    this.windowOpen = false,
    this.accepting = false,
    this.openBatch,
    this.activeBatch,
    this.waitingCount = 0,
    this.servingCount = 0,
    this.slotsDeployed = false,
    this.availableSlots = 0,
    this.usedSlots = 0,
    this.totalSlots = 100,
    this.queueStatus = 'closed',
    this.services = const <OfficeService>[],
    this.stations = const <OfficeStation>[],
  });

  bool get isRegistrarOffice => code.startsWith('REG-');

  /// True only for the one value Laravel switches a counter on with.
  ///
  /// Anything else - `offline`, an empty string, a value this build has never
  /// heard of - is not online, which is what makes a future server-side state
  /// fail closed here rather than silently offering the counter.
  bool get isOnline => status == AppConstants.officeStatusOnline;

  /// The same office with its switch replaced by a fresher read.
  ///
  /// Used by the status refresh: the catalogue on screen carries slot counts
  /// and services that were fetched with the rest of the shell, and the point
  /// of the status endpoint is to correct exactly one field of it rather than
  /// to replace the whole payload with one that knows less.
  Office withStatus(String status) => Office(
    id: id,
    name: name,
    code: code,
    description: description,
    officeType: officeType,
    location: location,
    isActive: isActive,
    openingTime: openingTime,
    closingTime: closingTime,
    maxSlots: maxSlots,
    status: status,
    course: course,
    courseCode: courseCode,
    windowOpen: windowOpen,
    accepting: accepting,
    openBatch: openBatch,
    activeBatch: activeBatch,
    waitingCount: waitingCount,
    servingCount: servingCount,
    slotsDeployed: slotsDeployed,
    availableSlots: availableSlots,
    usedSlots: usedSlots,
    totalSlots: totalSlots,
    queueStatus: queueStatus,
    services: services,
    stations: stations,
  );

  /// True when this office can take a number right now.
  ///
  /// Prefers the backend's `accepting`, which already folds in the office's
  /// opening hours and status, and falls back to the client's own reading so
  /// a payload from an older server build still behaves.
  bool get isOpen => isOnline && (accepting || queueStatus == 'open');

  /// The "ISSUED / TOTAL" pair, e.g. `25/100`.
  ///
  /// The first figure is how many numbers have gone out of this batch, which
  /// is the reading the counter screen, the registrar picker and the ticket
  /// console all show. Before any ticket is taken it reads `0/…`, and at
  /// capacity it reads `…/…` - both states are unambiguous.
  String get slotsLabel => '$usedSlots/$totalSlots';

  /// Whether any slot is left. A full batch and an undeployed office both read
  /// false, and both mean a number cannot be issued.
  bool get hasFreeSlots => availableSlots > 0;

  /// Whether any batch exists for today, open or full.
  ///
  /// `slots_deployed` alone cannot be trusted here: the payload resolves it
  /// against the *open* batch, so a filled batch reads `false` even though
  /// its `active_batch` row still exists. The batch rows are the ground
  /// truth for "something was deployed".
  bool get hasDeployedBatch =>
      openBatch != null || activeBatch != null || slotsDeployed;

  /// Whether the deployed batch has no slots left.
  ///
  /// Distinct from "closed": filling is a capacity statement ("come back
  /// when numbers are served"), while closed is a staff switch or lapsed
  /// window. A full batch is disabled for ticketing either way, but the
  /// counter reads differently so the requirement can name it.
  bool get isBatchFull {
    final QueueBatch? batch = activeBatch ?? openBatch;

    if (batch == null) {
      return false;
    }

    return batch.isFull || batch.slotsRemaining <= 0;
  }

  /// Whether staff closed the deployed batch.
  ///
  /// Distinct from [isBatchFull]: a closed batch can still have free slots,
  /// but no number may be taken until staff deploy a new one.
  bool get isBatchClosed {
    final QueueBatch? batch = activeBatch ?? openBatch;

    return batch?.isClosed ?? false;
  }

  /// The deployed batch behind this office, if any.
  QueueBatch? get batch => activeBatch ?? openBatch;

  /// The current batch number as staff deployed it, e.g. `BATCH 2`.
  ///
  /// Null when no batch exists for today.
  String? get batchLabel {
    final QueueBatch? current = batch;

    if (current == null) {
      return null;
    }

    return 'BATCH ${current.batchNo}';
  }

  /// Total queue slots staff deployed for the current batch, e.g. `50 Slots`.
  ///
  /// Falls back to the office default when nothing is deployed yet.
  String get deployedSlotsLabel => '${batch?.maxSlots ?? totalSlots} Slots';

  /// Whether the current batch is taking numbers: `ACTIVE` for an open
  /// batch, `CLOSED` for a staff-closed one, otherwise the raw status.
  ///
  /// Null when no batch exists for today.
  String? get batchStateLabel {
    final QueueBatch? current = batch;

    if (current == null) {
      return null;
    }

    if (current.isOpen) {
      return 'ACTIVE';
    }

    if (current.isClosed) {
      return 'CLOSED';
    }

    return current.status.toUpperCase();
  }

  /// One-line status summary, e.g. `ONLINE • BATCH 2 • 50 Slots • ACTIVE`.
  ///
  /// Offices with no batch yet read `ONLINE • No batch deployed` (or
  /// `OFFLINE • …`), so a missing batch is never mistaken for a closed one.
  String get statusLine {
    final StringBuffer line = StringBuffer(isOnline ? 'ONLINE' : 'OFFLINE');

    final String? number = batchLabel;
    final String? state = batchStateLabel;

    if (number == null || state == null) {
      line.write(' • No batch deployed');
    } else {
      line
        ..write(' • ')
        ..write(number)
        ..write(' • ')
        ..write(deployedSlotsLabel)
        ..write(' • ')
        ..write(state);
    }

    return line.toString();
  }

  String get scheduleLabel {
    final String from = openingTime ?? '08:00';
    final String to = closingTime ?? '15:00';

    return '$from - $to';
  }

  /// Human-friendly reason the office is not currently taking tickets.
  String? get closedReason {
    if (accepting) {
      return null;
    }

    if (!windowOpen) {
      return 'Outside operating hours ($scheduleLabel).';
    }

    if (openBatch == null && activeBatch == null) {
      return 'No batch is open yet. Staff must deploy the queue first.';
    }

    if (isBatchClosed) {
      return 'Batch Closed — the office is not accepting new queue tickets.';
    }

    if (activeBatch != null && (activeBatch!.isFull || activeBatch!.isClosed)) {
      return 'This batch reached its $maxSlots-slot limit.';
    }

    return 'The queue is currently closed.';
  }

  factory Office.fromMap(Map<String, dynamic> map) => Office(
    id: QfValue.toInt(map['id']),
    name: QfValue.toStr(map['name']),
    code: QfValue.toStr(map['code']),
    description: QfValue.toStrOrNull(map['description']),
    officeType: QfValue.toStrOrNull(map['office_type']),
    location: QfValue.toStrOrNull(map['location']),
    isActive: QfValue.toBool(map['is_active'], fallback: true),
    openingTime: QfValue.toStrOrNull(map['opening_time']),
    closingTime: QfValue.toStrOrNull(map['closing_time']),
    maxSlots: QfValue.toInt(map['max_slots'], fallback: 100),
    status: _statusOf(map['status']),
    course: map['course'] is Map
        ? Course.fromMap(Map<String, dynamic>.from(map['course'] as Map))
        : null,
    courseCode: QfValue.toStrOrNull(map['course_code']),
    windowOpen: QfValue.toBool(map['window_open']),
    accepting: QfValue.toBool(map['accepting']),
    openBatch: map['open_batch'] is Map
        ? QueueBatch.fromMap(
            Map<String, dynamic>.from(map['open_batch'] as Map),
          )
        : null,
    activeBatch: map['active_batch'] is Map
        ? QueueBatch.fromMap(
            Map<String, dynamic>.from(map['active_batch'] as Map),
          )
        : null,
    waitingCount: QfValue.toInt(map['waiting_count']),
    servingCount: QfValue.toInt(map['serving_count']),
    slotsDeployed: QfValue.toBool(map['slots_deployed']),
    // Derived from the batch when the server does not send the flat counters,
    // so the number a student reads always agrees with the batch behind it.
    availableSlots:
        QfValue.toIntOrNull(map['available_slots']) ?? _slotsLeftIn(map),
    usedSlots: _usedSlotsIn(map),
    totalSlots:
        QfValue.toIntOrNull(map['total_slots']) ??
        QfValue.toInt(map['max_slots'], fallback: 100),
    queueStatus: QfValue.toStrOrNull(map['queue_status']) ?? 'closed',
    services: _list(map['services'], OfficeService.fromMap),
    stations: _list(map['stations'], OfficeStation.fromMap),
  );

  Map<String, dynamic> toMap() => <String, dynamic>{
    'id': id,
    'name': name,
    'code': code,
    'description': description,
    'office_type': officeType,
    'location': location,
    'is_active': isActive,
    'opening_time': openingTime,
    'closing_time': closingTime,
    'max_slots': maxSlots,
    'status': status,
    'course': course?.toMap(),
    'course_code': courseCode,
    'window_open': windowOpen,
    'accepting': accepting,
    'open_batch': openBatch?.toMap(),
    'active_batch': activeBatch?.toMap(),
    'waiting_count': waitingCount,
    'serving_count': servingCount,
    'slots_deployed': slotsDeployed,
    'available_slots': availableSlots,
    'used_slots': usedSlots,
    'total_slots': totalSlots,
    'queue_status': queueStatus,
    'is_registrar_office': isRegistrarOffice,
    'services': services.map((OfficeService s) => s.toMap()).toList(),
    'stations': stations.map((OfficeStation s) => s.toMap()).toList(),
  };

  /// Builds an office from a payload that only carried the slot counters.
  ///
  /// Used for the per-course Registrar rows, which arrive from their own
  /// endpoint with everything the ticket form needs (id, name, services) but
  /// without the full office payload the catalogue sends.
  factory Office.fromSlotState(Map<String, dynamic> map) => Office.fromMap(map);
}

/// The office switch as read off a payload.
///
/// Absent, null and blank all mean "the server did not say", and a counter
/// the server has not vouched for is not one a student may queue at - so they
/// all resolve to offline. Only a value Laravel actually wrote can be online.
String _statusOf(dynamic raw) {
  final String? value = QfValue.toStrOrNull(raw)?.trim();

  if (value == null || value.isEmpty) {
    return AppConstants.officeStatusOffline;
  }

  return value;
}

/// Slots left in a deployed batch, for payloads that only carry the nested
/// batch rather than the flat counters.
///
/// Kept out of [Office] because a factory constructor cannot reach an instance
/// member, and it needs the raw map rather than a half-built object.
int _slotsLeftIn(Map<String, dynamic> map) {
  final dynamic batch = map['open_batch'];

  if (batch is! Map) {
    return 0;
  }

  final int limit = QfValue.toInt(map['max_slots'], fallback: 100);

  return (limit - QfValue.toInt(batch['submitted_count'])).clamp(0, limit);
}

/// Tickets issued so far, for the "ISSUED / TOTAL" label.
///
/// Prefers the embedded batch's own `submitted_count` over the flat
/// `used_slots`: the office payload resolves its counters against the *open*
/// batch only, so once a batch has filled the server writes `used_slots = 0`
/// while the (now full) `active_batch` still carries how many actually went
/// out. Trusting the batch keeps "100/100" honest for exactly the case the
/// requirement asks to be explicit about.
int _usedSlotsIn(Map<String, dynamic> map) {
  final int limit = QfValue.toInt(
    map['total_slots'],
    fallback: QfValue.toInt(map['max_slots'], fallback: 100),
  );

  final dynamic batch = map['open_batch'] ?? map['active_batch'];

  if (batch is Map) {
    return QfValue.toInt(batch['submitted_count']).clamp(0, limit);
  }

  final int? used = QfValue.toIntOrNull(map['used_slots']);

  if (used != null) {
    return used.clamp(0, limit);
  }

  final int available =
      QfValue.toIntOrNull(map['available_slots']) ?? _slotsLeftIn(map);

  return (limit - available).clamp(0, limit);
}

/// One college's Registrar queue, as offered in the course picker.
///
/// The Registrar is not one queue on the server but seven, each an office with
/// its own deployed batch and its own assigned staff member. This is the row a
/// student picks between, and it carries both halves of that: which course to
/// queue for, and how many numbers that course's queue has left.
class RegistrarCourseOption {
  /// The `courses.id` sent with the ticket, so the server stores the choice.
  final int courseId;

  final String code;
  final String name;

  /// The `REG-<COURSE>` office this queue is issued from.
  final int officeId;
  final String officeName;

  final bool slotsDeployed;
  final int availableSlots;
  final int usedSlots;
  final int totalSlots;
  final String queueStatus;
  final bool accepting;

  /// Today's live queue counters for this course queue alone.
  ///
  /// Counted per office (`REG-<COURSE>`) with the same status rules as the
  /// per-office live board: waiting = still in line, serving = at the
  /// counter, served = completed. Cancelled and no-show rows are excluded
  /// from all three, and each ticket belongs to exactly one office so
  /// summing courses never double-counts.
  final int waitingCount;
  final int servingCount;
  final int servedCount;

  /// The batch number staff deployed for this course queue, if any.
  final int? batchNo;

  /// Whether this course queue's office is switched online.
  ///
  /// Defaults to `offline` (unknown means unavailable), matching [Office].
  final String status;

  /// Whether this is the signed-in student's own enrolled course.
  final bool isEnrolledCourse;

  /// The services that course queue offers.
  ///
  /// Carried because the office catalogue a student signs in with holds only
  /// their own college's office, and taking a ticket needs a service id from
  /// whichever queue they picked.
  final List<OfficeService> services;

  const RegistrarCourseOption({
    required this.courseId,
    required this.code,
    required this.name,
    required this.officeId,
    required this.officeName,
    this.slotsDeployed = false,
    this.availableSlots = 0,
    this.usedSlots = 0,
    this.totalSlots = 100,
    this.queueStatus = 'closed',
    this.accepting = false,
    this.waitingCount = 0,
    this.servingCount = 0,
    this.servedCount = 0,
    this.batchNo,
    this.status = AppConstants.officeStatusOffline,
    this.isEnrolledCourse = false,
    this.services = const <OfficeService>[],
  });

  bool get isOnline => status == AppConstants.officeStatusOnline;

  bool get isOpen => isOnline && (accepting || queueStatus == 'open');

  bool get hasFreeSlots => availableSlots > 0;

  /// Whether this course row reports a deployed batch at all.
  bool get hasDeployedBatch => slotsDeployed;

  /// Whether the course's batch has nothing left in it.
  bool get isBatchFull => slotsDeployed && availableSlots <= 0;

  /// Whether staff closed this course's batch.
  ///
  /// The row carries no batch object, so a deployed queue with free slots
  /// that still refuses numbers reads as closed here. It doubles as the
  /// gate for queues outside operating hours, which the server refuses too.
  bool get isBatchClosed =>
      slotsDeployed && availableSlots > 0 && !accepting;

  /// Human-friendly reason this course queue is not taking tickets.
  String? get closedReason {
    if (accepting) {
      return null;
    }

    if (!isOnline) {
      return 'This Registrar course is currently offline. Please wait until the staff deploys a new batch.';
    }

    if (!slotsDeployed) {
      return 'No batch is open yet. Staff must deploy the queue first.';
    }

    if (isBatchClosed) {
      return 'Batch Closed — the office is not accepting new queue tickets.';
    }

    if (isBatchFull) {
      return 'This batch reached its $totalSlots-slot limit.';
    }

    return 'The queue is currently closed.';
  }

  /// "ISSUED / TOTAL", e.g. `25/100`.
  String get slotsLabel => '$usedSlots/$totalSlots';

  /// `BATCH 2`, or null when this course queue has no batch yet.
  String? get batchLabel => batchNo == null ? null : 'BATCH $batchNo';

  /// `ACTIVE` for an issuing batch, `CLOSED` for a staff-closed one.
  ///
  /// Null when this course queue has no batch yet.
  String? get batchStateLabel {
    if (!slotsDeployed) {
      return null;
    }

    if (queueStatus == 'open') {
      return 'ACTIVE';
    }

    if (isBatchClosed) {
      return 'CLOSED';
    }

    if (isBatchFull) {
      return 'FULL';
    }

    return queueStatus.toUpperCase();
  }

  /// One-line status summary, e.g. `ONLINE • BATCH 2 • 100 Slots • ACTIVE`.
  String get statusLine {
    final StringBuffer line = StringBuffer(isOnline ? 'ONLINE' : 'OFFLINE');

    final String? number = batchLabel;
    final String? state = batchStateLabel;

    if (number == null || state == null) {
      line.write(' • No batch deployed');
    } else {
      line
        ..write(' • ')
        ..write(number)
        ..write(' • ')
        ..write('$totalSlots Slots')
        ..write(' • ')
        ..write(state);
    }

    return line.toString();
  }

  /// This course queue as an [Office], so the ticket form can treat a course
  /// selection and an office selection the same way.
  ///
  /// Built from this row rather than looked up in the catalogue on purpose: the
  /// catalogue is narrowed to the student's own college, which is exactly the
  /// row that is missing for the other six.
  Office get office => Office(
    id: officeId,
    name: officeName,
    code: 'REG-$code',
    isActive: true,
    maxSlots: totalSlots,
    status: status,
    courseCode: code,
    windowOpen: isOpen,
    accepting: isOpen,
    slotsDeployed: slotsDeployed,
    availableSlots: availableSlots,
    usedSlots: usedSlots,
    totalSlots: totalSlots,
    queueStatus: queueStatus,
    services: services,
  );

  factory RegistrarCourseOption.fromMap(Map<String, dynamic> map) {
    final Map<String, dynamic> course = map['course'] is Map
        ? Map<String, dynamic>.from(map['course'] as Map)
        : const <String, dynamic>{};

    return RegistrarCourseOption(
      courseId: QfValue.toInt(course['id']),
      code: QfValue.toStr(
        map['course_code'],
        fallback: QfValue.toStr(course['code']),
      ),
      name: QfValue.toStr(course['name']),
      officeId: QfValue.toInt(map['office_id']),
      officeName: QfValue.toStr(map['office_name']),
      slotsDeployed: QfValue.toBool(map['slots_deployed']),
      availableSlots: QfValue.toInt(map['available_slots']),
      usedSlots:
          QfValue.toIntOrNull(map['used_slots']) ??
          (() {
            final int total = QfValue.toInt(map['total_slots'], fallback: 100);
            final int available = QfValue.toInt(map['available_slots']);

            return (total - available).clamp(0, total);
          })(),
      totalSlots: QfValue.toInt(map['total_slots'], fallback: 100),
      queueStatus: QfValue.toStrOrNull(map['queue_status']) ?? 'closed',
      accepting: QfValue.toBool(map['accepting']),
      waitingCount: QfValue.toInt(map['waiting_count']),
      servingCount: QfValue.toInt(map['serving_count']),
      servedCount: QfValue.toInt(map['served_count']),
      batchNo: QfValue.toIntOrNull(map['batch_no']),
      status: _statusOf(map['status']),
      isEnrolledCourse: QfValue.toBool(map['is_enrolled_course']),
      services: _list(map['services'], OfficeService.fromMap),
    );
  }

  Map<String, dynamic> toMap() => <String, dynamic>{
    'course_id': courseId,
    'course_code': code,
    'course_name': name,
    'office_id': officeId,
    'office_name': officeName,
    'slots_deployed': slotsDeployed,
    'available_slots': availableSlots,
    'used_slots': usedSlots,
    'total_slots': totalSlots,
    'queue_status': queueStatus,
    'accepting': accepting,
    'waiting_count': waitingCount,
    'serving_count': servingCount,
    'served_count': servedCount,
    'batch_no': batchNo,
    'status': status,
    'is_enrolled_course': isEnrolledCourse,
    'services': services.map((OfficeService s) => s.toMap()).toList(),
  };
}

/// Every Registrar course queue plus the campus-wide totals for them.
class RegistrarCourseList {
  final List<RegistrarCourseOption> courses;

  /// Sum of every course queue, which is what the single Registrar card shows.
  final int totalAvailableSlots;
  final int totalSlots;

  /// Tickets issued across every course queue, the "ISSUED" side of the total.
  int get totalUsedSlots => (totalSlots - totalAvailableSlots).clamp(
    0,
    totalSlots,
  );

  const RegistrarCourseList({
    this.courses = const <RegistrarCourseOption>[],
    this.totalAvailableSlots = 0,
    this.totalSlots = 0,
  });

  static const RegistrarCourseList empty = RegistrarCourseList();

  bool get isEmpty => courses.isEmpty;

  /// OPEN while any single course queue can still issue a number, because a
  /// student only needs one of them to be available.
  bool get anyOpen => courses.any((RegistrarCourseOption c) => c.isOpen);

  /// The student's own course row, when the server flagged one.
  RegistrarCourseOption? enrolledOption() {
    for (final RegistrarCourseOption option in courses) {
      if (option.isEnrolledCourse) {
        return option;
      }
    }

    return null;
  }

  RegistrarCourseOption? byCode(String code) {
    final String needle = code.trim().toUpperCase();

    for (final RegistrarCourseOption option in courses) {
      if (option.code.toUpperCase() == needle) {
        return option;
      }
    }

    return null;
  }

  factory RegistrarCourseList.fromMap(Map<String, dynamic> map) {
    final Map<String, dynamic> totals = map['totals'] is Map
        ? Map<String, dynamic>.from(map['totals'] as Map)
        : const <String, dynamic>{};

    return RegistrarCourseList(
      courses: _list(map['registrar_courses'], RegistrarCourseOption.fromMap),
      // Falls back to summing the rows, so a payload without the aggregate
      // block still shows a truthful total rather than zero.
      totalAvailableSlots:
          QfValue.toIntOrNull(totals['available_slots']) ??
          _list(
            map['registrar_courses'],
            RegistrarCourseOption.fromMap,
          ).fold<int>(
            0,
            (int sum, RegistrarCourseOption c) => sum + c.availableSlots,
          ),
      totalSlots:
          QfValue.toIntOrNull(totals['total_slots']) ??
          _list(
            map['registrar_courses'],
            RegistrarCourseOption.fromMap,
          ).fold<int>(
            0,
            (int sum, RegistrarCourseOption c) => sum + c.totalSlots,
          ),
    );
  }
}

/// "Now serving" board for one office.
class LiveOfficeBoard {
  final int officeId;
  final String officeName;
  final String officeCode;
  final String? nowServing;
  final DateTime? nowServingCalledAt;
  final int waitingCount;
  final int servingCount;
  final int servedCount;
  final List<ServedNumber> recentServed;
  final bool windowOpen;

  const LiveOfficeBoard({
    required this.officeId,
    required this.officeName,
    required this.officeCode,
    this.nowServing,
    this.nowServingCalledAt,
    this.waitingCount = 0,
    this.servingCount = 0,
    this.servedCount = 0,
    this.recentServed = const <ServedNumber>[],
    this.windowOpen = false,
  });

  factory LiveOfficeBoard.fromMap(Map<String, dynamic> map) {
    final Map<String, dynamic> nowServing = map['now_serving'] is Map
        ? Map<String, dynamic>.from(map['now_serving'] as Map)
        : <String, dynamic>{};

    final Map<String, dynamic> office = map['office'] is Map
        ? Map<String, dynamic>.from(map['office'] as Map)
        : <String, dynamic>{};

    return LiveOfficeBoard(
      officeId: QfValue.toInt(office['id'] ?? map['office_id']),
      officeName: QfValue.toStr(office['name'] ?? map['office_name']),
      officeCode: QfValue.toStr(office['code'] ?? map['office_code']),
      nowServing: QfValue.toStrOrNull(nowServing['queue_number']),
      nowServingCalledAt: QfValue.toDate(
        nowServing['called_at'] ?? map['now_serving_called_at'],
      ),
      waitingCount: QfValue.toInt(map['waiting_count']),
      servingCount: QfValue.toInt(map['serving_count']),
      servedCount: QfValue.toInt(map['served_count']),
      recentServed: _list(map['recent_served'], ServedNumber.fromMap),
      windowOpen: QfValue.toBool(map['window_open']),
    );
  }
}

class ServedNumber {
  final String queueNumber;
  final DateTime? servedAt;

  const ServedNumber({required this.queueNumber, this.servedAt});

  factory ServedNumber.fromMap(Map<String, dynamic> map) => ServedNumber(
    queueNumber: QfValue.toStr(map['queue_number']),
    servedAt: QfValue.toDate(map['served_at']),
  );
}

List<T> _list<T>(dynamic raw, T Function(Map<String, dynamic>) parse) {
  if (raw is! List) {
    return <T>[];
  }

  return raw
      .whereType<Map>()
      .map((Map e) => parse(Map<String, dynamic>.from(e)))
      .toList();
}
