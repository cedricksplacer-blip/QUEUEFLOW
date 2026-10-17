import '../core/app_constants.dart';
import '../core/qf_value.dart';
import 'campus_catalog.dart';

/// ============================================================
/// QUEUEFLOW TMC — QUEUE TICKET
///
/// Maps to the `queue_tickets` table joined with its office,
/// service, station, course, batch and serving staff member.
/// ============================================================

class QueueTicket {
  final int id;
  final String queueNumber;
  final String status;
  final String priority;
  final String? purpose;
  final String? yearLevel;
  final bool hasEvidence;

  final OfficeRef? office;
  final ServiceRef? service;
  final StationRef? station;
  final Course? course;
  final QueueBatch? batch;
  final StaffRef? servedBy;

  final DateTime? calledAt;
  final DateTime? servedAt;
  final DateTime? createdAt;

  /// When staff confirmed arrival (Stop Timer). Keeps status=serving; only
  /// the deadline is cleared so the ticket stays workable for completion.
  final DateTime? arrivedAt;

  /// When the ticket left the queue as cancelled, if ever.
  final DateTime? cancelledAt;

  /// Optional staff note recorded with a cancel.
  final String? cancelReason;

  /// When staff marked the serving ticket as a terminal No-Show, if ever.
  /// Preserved in history: the same ticket keeps this status across
  /// refreshes, logins and new batches.
  final DateTime? noShowAt;

  /// Staff member who performed the No-Show action, when known.
  final StaffRef? noShowBy;

  /// How many times the same serving ticket was called again.
  final int recallCount;

  /// When the ticket was last called again, if ever.
  final DateTime? lastRecalledAt;

  /// Staff-picked call length for this ticket, in minutes. Defaults to 5
  /// when the server sent nothing (tickets issued before the countdown
  /// feature carry no duration).
  final int callDurationMinutes;

  /// Server-stamped deadline for the call. The countdown is always derived
  /// from this — never restarted locally — so refreshes, restarts and
  /// reconnects resume the same deadline.
  final DateTime? expiresAt;

  /// Seconds left at the moment the payload was built, when the server
  /// sent it. Used only as a fallback when [expiresAt] is absent.
  final int remainingSecondsAtSync;

  /// Whether the server already considers this call expired.
  final bool expired;

  static const int defaultCallDurationMinutes = 5;

  const QueueTicket({
    required this.id,
    required this.queueNumber,
    this.status = AppConstants.statusWaiting,
    this.priority = AppConstants.priorityRegular,
    this.purpose,
    this.yearLevel,
    this.hasEvidence = false,
    this.office,
    this.service,
    this.station,
    this.course,
    this.batch,
    this.servedBy,
    this.calledAt,
    this.servedAt,
    this.createdAt,
    this.arrivedAt,
    this.cancelledAt,
    this.cancelReason,
    this.noShowAt,
    this.noShowBy,
    this.recallCount = 0,
    this.lastRecalledAt,
    this.callDurationMinutes = defaultCallDurationMinutes,
    this.expiresAt,
    this.remainingSecondsAtSync = 0,
    this.expired = false,
  });

  bool get isPwd => priority == AppConstants.priorityPwd;
  bool get isWaiting => status == AppConstants.statusWaiting;
  bool get isServing => status == AppConstants.statusServing;
  bool get isServed => status == AppConstants.statusServed;
  bool get isCancelled => status == AppConstants.statusCancelled;
  bool get isNoShow => status == AppConstants.statusNoShow;

  /// Staff confirmed the student is at the window. Status stays serving;
  /// only the deadline was cleared, so the ticket remains workable.
  bool get isArrived => isServing && arrivedAt != null;

  /// waiting + serving: the tickets that still occupy a queue slot today.
  bool get isActive => isWaiting || isServing;

  bool get canCancel => isWaiting;

  /// Seconds left until the staff-set deadline, from the server-stamped
  /// [expiresAt]. Zero when expired, arrived, when not serving, or when the
  /// ticket predates the countdown feature. Falls back to the server's
  /// remaining_seconds snapshot when expiresAt is absent so an older row
  /// still shows something truthful until the next sync.
  int get remainingSeconds {
    if (!isServing || isArrived) {
      return 0;
    }

    if (expiresAt != null) {
      final int left = expiresAt!.difference(DateTime.now()).inSeconds;
      return left > 0 ? left : 0;
    }

    return remainingSecondsAtSync > 0 ? remainingSecondsAtSync : 0;
  }

  /// True when the serving deadline has passed without arrival.
  bool get isCountdownExpired {
    if (expired) {
      return true;
    }

    if (isArrived) {
      return false;
    }

    if (!isServing || expiresAt == null) {
      return false;
    }

    return !expiresAt!.isAfter(DateTime.now());
  }

  /// True when one minute or less remains and arrival is still pending.
  bool get isCountdownWarning =>
      isServing && !isArrived && !isCountdownExpired && remainingSeconds <= 60 && expiresAt != null;

  /// MM:SS for the live countdown, ARRIVED once staff stops the timer, or
  /// TICKET EXPIRED once the deadline passes.
  String get countdownLabel {
    if (isArrived) {
      return 'ARRIVED';
    }

    if (isCountdownExpired) {
      return 'TICKET EXPIRED';
    }

    if (!isServing || expiresAt == null) {
      return '--:--';
    }

    final int left = remainingSeconds;
    final String mm = (left ~/ 60).toString().padLeft(2, '0');
    final String ss = (left % 60).toString().padLeft(2, '0');

    return '$mm:$ss';
  }

  String get officeName => office?.name ?? 'Unknown office';
  String get serviceName => service?.name ?? '—';
  String get courseCode => course?.code ?? office?.code ?? '—';

  /// Student-facing queue state derived from the authoritative server row:
  /// Waiting / Your Turn / Time Almost Up / Time Expired / No-Show / Completed.
  String get displayStatus {
    if (isNoShow) {
      return 'No-Show';
    }
    if (isServed) {
      return 'Completed';
    }
    if (isCancelled) {
      return 'Cancelled';
    }
    if (isServing) {
      if (isArrived) {
        return 'Your Turn';
      }
      if (isCountdownExpired) {
        return 'Time Expired';
      }
      if (isCountdownWarning) {
        return 'Time Almost Up';
      }
      return 'Your Turn';
    }
    return 'Waiting';
  }

  String get statusLabel {
    switch (status) {
      case AppConstants.statusWaiting:
        return 'Waiting';
      case AppConstants.statusServing:
        return isArrived ? 'Arrived' : 'Now Serving';
      case AppConstants.statusServed:
        return 'Served';
      case AppConstants.statusCancelled:
        return 'Cancelled';
      case AppConstants.statusNoShow:
        return 'No-Show';
      default:
        return status;
    }
  }

  /// The single sentence shown on the ticket card and detail header.
  String get statusMessage {
    if (isNoShow) {
      return 'You missed your call at $officeName. This ticket is recorded as No-Show and kept in your history.';
    }

    if (isWaiting) {
      return 'Please stay near the $officeName counter.';
    }

    if (isServing) {
      if (isArrived) {
        return 'You have arrived at $officeName and are being attended to.';
      }

      if (isCountdownExpired) {
        return 'Your call time at $officeName has expired. Please follow the office rules for another queue number.';
      }

      if (isCountdownWarning) {
        return 'Your number has been called at $officeName. Time is almost up — please hurry to the counter.';
      }

      return 'Your number has been called. Please proceed to the $officeName.';
    }

    if (isServed) {
      return servedBy == null
          ? 'You have been served. Thank you!'
          : 'You have been served by ${servedBy!.name}.';
    }

    return 'This ticket is no longer active.';
  }

  factory QueueTicket.fromMap(Map<String, dynamic> map) => QueueTicket(
    id: QfValue.toInt(map['id']),
    queueNumber: QfValue.toStr(map['queue_number']),
    status: QfValue.toStrOrNull(map['status']) ?? AppConstants.statusWaiting,
    priority:
        QfValue.toStrOrNull(map['priority']) ?? AppConstants.priorityRegular,
    purpose: QfValue.toStrOrNull(map['purpose']),
    yearLevel: QfValue.toStrOrNull(map['year_level']),
    hasEvidence: QfValue.toBool(map['has_evidence']),
    office: map['office'] is Map
        ? OfficeRef.fromMap(Map<String, dynamic>.from(map['office'] as Map))
        : null,
    service: map['service'] is Map
        ? ServiceRef.fromMap(Map<String, dynamic>.from(map['service'] as Map))
        : null,
    station: map['station'] is Map
        ? StationRef.fromMap(Map<String, dynamic>.from(map['station'] as Map))
        : null,
    course: map['course'] is Map
        ? Course.fromMap(Map<String, dynamic>.from(map['course'] as Map))
        : null,
    batch: (map['deployment'] ?? map['batch']) is Map
        ? QueueBatch.fromMap(
            Map<String, dynamic>.from(
              (map['deployment'] ?? map['batch']) as Map,
            ),
          )
        : null,
    servedBy: map['served_by'] is Map
        ? StaffRef.fromMap(Map<String, dynamic>.from(map['served_by'] as Map))
        : null,
    calledAt: QfValue.toLocalDate(map['called_at']),
    servedAt: QfValue.toLocalDate(map['served_at']),
    createdAt: QfValue.toLocalDate(map['created_at']),
    arrivedAt: QfValue.toLocalDate(map['arrived_at']),
    cancelledAt: QfValue.toLocalDate(map['cancelled_at']),
    cancelReason: QfValue.toStrOrNull(map['cancel_reason']),
    noShowAt: QfValue.toLocalDate(map['no_show_at']),
    noShowBy: map['no_show_by'] is Map
        ? StaffRef.fromMap(Map<String, dynamic>.from(map['no_show_by'] as Map))
        : null,
    recallCount: QfValue.toInt(map['recall_count']),
    lastRecalledAt: QfValue.toLocalDate(map['last_recalled_at']),
    callDurationMinutes: map['call_duration_minutes'] == null
        ? defaultCallDurationMinutes
        : QfValue.toInt(
            map['call_duration_minutes'],
            fallback: defaultCallDurationMinutes,
          ),
    expiresAt: QfValue.toLocalDate(map['expires_at']),
    remainingSecondsAtSync: QfValue.toInt(map['remaining_seconds']),
    expired: QfValue.toBool(map['is_expired']),
  );

  Map<String, dynamic> toMap() => <String, dynamic>{
    'id': id,
    'queue_number': queueNumber,
    'status': status,
    'priority': priority,
    'purpose': purpose,
    'year_level': yearLevel,
    'has_evidence': hasEvidence,
    'office': office?.toMap(),
    'service': service?.toMap(),
    'station': station?.toMap(),
    'course': course?.toMap(),
    'deployment': batch?.toMap(),
    'served_by': servedBy?.toMap(),
    'called_at': calledAt?.toIso8601String(),
    'served_at': servedAt?.toIso8601String(),
    'created_at': createdAt?.toIso8601String(),
    'arrived_at': arrivedAt?.toIso8601String(),
    'cancelled_at': cancelledAt?.toIso8601String(),
    'cancel_reason': cancelReason,
    'no_show_at': noShowAt?.toIso8601String(),
    'no_show_by': noShowBy?.toMap(),
    'recall_count': recallCount,
    'last_recalled_at': lastRecalledAt?.toIso8601String(),
    'call_duration_minutes': callDurationMinutes,
    'expires_at': expiresAt?.toIso8601String(),
    'remaining_seconds': remainingSecondsAtSync,
    'is_expired': expired,
  };
}

/// Live position of one ticket inside its office queue.
class QueueProgress {
  final int estimatedWaitMinutes;
  final int peopleAhead;
  final int position;
  final int minutesPerTicket;
  final String? nowServing;
  final DateTime? nowServingCalledAt;
  final DateTime? nowServingExpiresAt;
  final int nowServingDurationMinutes;
  final int waitingCount;

  const QueueProgress({
    this.estimatedWaitMinutes = 0,
    this.peopleAhead = 0,
    this.position = 0,
    this.minutesPerTicket = 5,
    this.nowServing,
    this.nowServingCalledAt,
    this.nowServingExpiresAt,
    this.nowServingDurationMinutes = QueueTicket.defaultCallDurationMinutes,
    this.waitingCount = 0,
  });

  bool get isNextInLine => position == 1 && peopleAhead == 0;

  String get waitLabel {
    if (estimatedWaitMinutes <= 0) {
      return 'Any moment';
    }

    if (estimatedWaitMinutes < 60) {
      return '$estimatedWaitMinutes min';
    }

    final int hours = estimatedWaitMinutes ~/ 60;
    final int minutes = estimatedWaitMinutes % 60;

    return minutes == 0 ? '$hours hr' : '$hours hr $minutes min';
  }

  factory QueueProgress.fromMap(Map<String, dynamic> map) {
    final Map<String, dynamic> nowServing = map['now_serving'] is Map
        ? Map<String, dynamic>.from(map['now_serving'] as Map)
        : <String, dynamic>{};

    return QueueProgress(
      estimatedWaitMinutes: QfValue.toInt(map['estimated_wait_minutes']),
      peopleAhead: QfValue.toInt(map['people_ahead']),
      position: QfValue.toInt(map['position']),
      minutesPerTicket: QfValue.toInt(map['minutes_per_ticket'], fallback: 5),
      nowServing: QfValue.toStrOrNull(nowServing['queue_number']),
      nowServingCalledAt: QfValue.toDate(nowServing['called_at']),
      nowServingExpiresAt: QfValue.toLocalDate(nowServing['expires_at']),
      nowServingDurationMinutes: nowServing['call_duration_minutes'] == null
          ? QueueTicket.defaultCallDurationMinutes
          : QfValue.toInt(
              nowServing['call_duration_minutes'],
              fallback: QueueTicket.defaultCallDurationMinutes,
            ),
      waitingCount: QfValue.toInt(map['waiting_count']),
    );
  }

  Map<String, dynamic> toMap() => <String, dynamic>{
    'estimated_wait_minutes': estimatedWaitMinutes,
    'people_ahead': peopleAhead,
    'position': position,
    'minutes_per_ticket': minutesPerTicket,
    'now_serving': nowServing == null
        ? null
        : <String, dynamic>{
            'queue_number': nowServing,
            'called_at': nowServingCalledAt?.toIso8601String(),
            'expires_at': nowServingExpiresAt?.toIso8601String(),
            'call_duration_minutes': nowServingDurationMinutes,
          },
    'waiting_count': waitingCount,
  };
}

// ============================================================
// COMPACT REFERENCE TYPES (mirrors StudentPortalPresenter)
// ============================================================

/// The result of a successful ticket request, shared by both transports.
class CreatedTicket {
  const CreatedTicket({
    required this.ticket,
    required this.queue,
    required this.message,
  });

  final QueueTicket ticket;
  final QueueProgress queue;
  final String message;
}

class OfficeRef {
  final int id;
  final String name;
  final String code;

  /// The daily online/offline switch the server keeps in `offices.status`.
  ///
  /// Defaults to offline when a transport did not send it, because a receipt
  /// that guessed "open" would tell a student to walk to a counter nobody has
  /// switched on.
  final String status;

  /// Whether the office is inside its configured opening hours.
  final bool windowOpen;

  /// Whether a number can be taken right now, as the server resolved it.
  final bool accepting;

  const OfficeRef({
    required this.id,
    required this.name,
    required this.code,
    this.status = AppConstants.officeStatusOffline,
    this.windowOpen = false,
    this.accepting = false,
  });

  bool get isOnline => status == AppConstants.officeStatusOnline;

  /// True when the counter is online and inside its hours.
  ///
  /// [accepting] already folds the open batch into the answer on the server,
  /// so it is preferred; [windowOpen] is the client-side fallback for a
  /// payload that predates the field.
  bool get isOpen => isOnline && (accepting || windowOpen);

  factory OfficeRef.fromMap(Map<String, dynamic> map) => OfficeRef(
    id: QfValue.toInt(map['id']),
    name: QfValue.toStr(map['name']),
    code: QfValue.toStr(map['code']),
    status:
        QfValue.toStrOrNull(map['status']) ?? AppConstants.officeStatusOffline,
    windowOpen: QfValue.toBool(map['window_open']),
    accepting: QfValue.toBool(map['accepting']),
  );

  Map<String, dynamic> toMap() => <String, dynamic>{
    'id': id,
    'name': name,
    'code': code,
    'status': status,
    'window_open': windowOpen,
    'accepting': accepting,
  };
}

class ServiceRef {
  final int id;
  final String name;

  const ServiceRef({required this.id, required this.name});

  factory ServiceRef.fromMap(Map<String, dynamic> map) => ServiceRef(
    id: QfValue.toInt(map['id']),
    name: QfValue.toStr(map['name']),
  );

  Map<String, dynamic> toMap() => <String, dynamic>{'id': id, 'name': name};
}

class StationRef {
  final int id;
  final String name;
  final String? location;

  const StationRef({required this.id, required this.name, this.location});

  factory StationRef.fromMap(Map<String, dynamic> map) => StationRef(
    id: QfValue.toInt(map['id']),
    name: QfValue.toStr(map['name']),
    location: QfValue.toStrOrNull(map['location']),
  );

  Map<String, dynamic> toMap() => <String, dynamic>{
    'id': id,
    'name': name,
    'location': location,
  };
}

class StaffRef {
  final int id;
  final String name;

  const StaffRef({required this.id, required this.name});

  factory StaffRef.fromMap(Map<String, dynamic> map) =>
      StaffRef(id: QfValue.toInt(map['id']), name: QfValue.toStr(map['name']));

  Map<String, dynamic> toMap() => <String, dynamic>{'id': id, 'name': name};
}

/// Ticket counters for the dashboard.
class TicketCounts {
  final int total;
  final int waiting;
  final int serving;
  final int served;
  final int cancelled;
  final int noShow;
  final int today;

  const TicketCounts({
    this.total = 0,
    this.waiting = 0,
    this.serving = 0,
    this.served = 0,
    this.cancelled = 0,
    this.noShow = 0,
    this.today = 0,
  });

  int get active => waiting + serving;

  factory TicketCounts.fromMap(Map<String, dynamic> map) => TicketCounts(
    total: QfValue.toInt(map['total']),
    waiting: QfValue.toInt(map['waiting']),
    serving: QfValue.toInt(map['serving']),
    served: QfValue.toInt(map['served']),
    cancelled: QfValue.toInt(map['cancelled']),
    noShow: QfValue.toInt(map['no_show'] ?? map['noShow']),
    today: QfValue.toInt(map['today']),
  );

  Map<String, dynamic> toMap() => <String, dynamic>{
    'total': total,
    'waiting': waiting,
    'serving': serving,
    'served': served,
    'cancelled': cancelled,
    'no_show': noShow,
    'today': today,
  };

  factory TicketCounts.fromTickets(List<QueueTicket> tickets) {
    int countWhere(bool Function(QueueTicket) test) =>
        tickets.where(test).length;

    return TicketCounts(
      total: tickets.length,
      waiting: countWhere((QueueTicket t) => t.isWaiting),
      serving: countWhere((QueueTicket t) => t.isServing),
      served: countWhere((QueueTicket t) => t.isServed),
      cancelled: countWhere((QueueTicket t) => t.isCancelled),
      noShow: countWhere((QueueTicket t) => t.isNoShow),
      today: countWhere(
        (QueueTicket t) =>
            t.createdAt != null &&
            t.createdAt!.year == DateTime.now().year &&
            t.createdAt!.month == DateTime.now().month &&
            t.createdAt!.day == DateTime.now().day,
      ),
    );
  }
}
