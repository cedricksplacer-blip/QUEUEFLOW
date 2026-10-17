import '../core/qf_value.dart';
import 'announcement.dart';
import 'app_user.dart';
import 'campus_catalog.dart';
import 'queue_ticket.dart';

/// ============================================================
/// QUEUEFLOW TMC — ADMIN & STAFF PAYLOADS
///
/// The student portal hydrates from `GET /api/student/bootstrap`, which
/// is shaped around one student's own tickets. That shape is wrong for
/// the other two roles, so the staff and admin groups get their own
/// payloads:
///
///   GET /api/staff/bootstrap  -> StaffWorkspace
///   GET /api/staff/queue/{office} -> OfficeQueue
///   GET /api/admin/overview   -> AdminOverview
///
/// All three reuse the shared [QueueTicket], [Office] and [QueueBatch]
/// models, so the wording a member of staff reads on the phone is the
/// wording the browser portal shows.
/// ============================================================

/// A staff member's landing payload: who they are, which counters they
/// may work, and anything they need to read on arrival.
class StaffWorkspace {
  final AppUser user;
  final List<Office> offices;
  final List<Announcement> announcements;
  final SystemSettings settings;

  const StaffWorkspace({
    required this.user,
    this.offices = const <Office>[],
    this.announcements = const <Announcement>[],
    this.settings = const SystemSettings(),
  });

  /// Offices a member of staff can actually be assigned to. Empty means the
  /// server did not narrow them, so the console shows every active office
  /// rather than an empty picker.
  List<Office> get assignableOffices =>
      offices.where((Office o) => o.isActive).toList(growable: false);

  factory StaffWorkspace.fromMap(Map<String, dynamic> map) => StaffWorkspace(
    user: AppUser.fromMap(
      Map<String, dynamic>.from(
        (map['user'] ?? map['staff'] ?? <String, dynamic>{}) as Map,
      ),
    ),
    offices: _list(map['offices'], Office.fromMap),
    announcements: _list(map['announcements'], Announcement.fromMap),
    settings: SystemSettings.fromMap(
      Map<String, dynamic>.from(
        (map['settings'] ?? <String, dynamic>{}) as Map,
      ),
    ),
  );
}

/// The working set for one office: who is being served right now, who is
/// still waiting, and what the counters say.
///
/// A member of staff reads this on a phone at a counter, so the waiting
/// list is the payload and the counters are derived from it when the
/// server omits them — an empty list with a stale "12 waiting" banner is
/// worse than a list that shows what is actually there.
class OfficeQueue {
  final int officeId;
  final String officeName;
  final String officeCode;

  final QueueTicket? nowServing;
  final List<QueueTicket> waiting;
  final List<QueueTicket> recentlyServed;
  final QueueBatch? batch;

  final bool windowOpen;
  final int waitingCount;
  final int servingCount;
  final int servedCount;

  const OfficeQueue({
    required this.officeId,
    this.officeName = 'Office',
    this.officeCode = '',
    this.nowServing,
    this.waiting = const <QueueTicket>[],
    this.recentlyServed = const <QueueTicket>[],
    this.batch,
    this.windowOpen = false,
    this.waitingCount = 0,
    this.servingCount = 0,
    this.servedCount = 0,
  });

  bool get isIdle => nowServing == null;

  /// The next number to call, or null when the queue has drained.
  QueueTicket? get nextWaiting => waiting.isEmpty ? null : waiting.first;

  /// Priority tickets first, then oldest first.
  ///
  /// Mirrors how a counter actually works: a PWD ticket jumps the line,
  /// and within a priority the order the numbers were issued is the order
  /// they are served in. The server is expected to send `waiting` already
  /// ordered, so this only re-sorts when a PWD ticket is out of place.
  List<QueueTicket> get callOrder {
    final List<QueueTicket> ordered = List<QueueTicket>.of(waiting);

    ordered.sort((QueueTicket a, QueueTicket b) {
      if (a.isPwd != b.isPwd) {
        return a.isPwd ? -1 : 1;
      }

      final DateTime left = a.createdAt ?? DateTime(1970);
      final DateTime right = b.createdAt ?? DateTime(1970);

      return left.compareTo(right);
    });

    return ordered;
  }

  factory OfficeQueue.fromMap(Map<String, dynamic> map) {
    final Map<String, dynamic> office = map['office'] is Map
        ? Map<String, dynamic>.from(map['office'] as Map)
        : <String, dynamic>{};

    final Map<String, dynamic> nowServing = map['now_serving'] is Map
        ? Map<String, dynamic>.from(map['now_serving'] as Map)
        : <String, dynamic>{};

    final List<QueueTicket> waiting = _list(
      map['waiting'],
      QueueTicket.fromMap,
    );
    final List<QueueTicket> served = _list(
      map['recently_served'] ?? map['served'],
      QueueTicket.fromMap,
    );

    return OfficeQueue(
      officeId: QfValue.toInt(office['id'] ?? map['office_id']),
      officeName: QfValue.toStr(
        office['name'] ?? map['office_name'],
        fallback: 'Office',
      ),
      officeCode: QfValue.toStr(office['code'] ?? map['office_code']),
      nowServing: nowServing.isEmpty ? null : QueueTicket.fromMap(nowServing),
      waiting: waiting,
      recentlyServed: served,
      batch: (map['batch'] ?? map['deployment'] ?? map['active_batch']) is Map
          ? QueueBatch.fromMap(
              Map<String, dynamic>.from(
                (map['batch'] ?? map['deployment'] ?? map['active_batch'])
                    as Map,
              ),
            )
          : null,
      windowOpen: QfValue.toBool(map['window_open']),
      // Fall back to the list length so the banner can never disagree with
      // the rows underneath it.
      waitingCount: QfValue.toIntOrNull(map['waiting_count']) ?? waiting.length,
      servingCount: QfValue.toIntOrNull(map['serving_count']) ?? 0,
      servedCount: QfValue.toIntOrNull(map['served_count']) ?? served.length,
    );
  }
}

/// The administrator's landing payload: campus-wide counters plus the
/// offices behind them.
class AdminOverview {
  final TicketCounts counts;
  final List<Office> offices;
  final List<Announcement> announcements;
  final SystemSettings settings;

  final int officeCount;
  final int activeOfficeCount;
  final int stationCount;
  final int studentCount;
  final int staffCount;

  const AdminOverview({
    this.counts = const TicketCounts(),
    this.offices = const <Office>[],
    this.announcements = const <Announcement>[],
    this.settings = const SystemSettings(),
    this.officeCount = 0,
    this.activeOfficeCount = 0,
    this.stationCount = 0,
    this.studentCount = 0,
    this.staffCount = 0,
  });

  /// Offices currently issuing numbers, which is the number an
  /// administrator actually watches during operating hours.
  int get openOfficeCount =>
      offices.where((Office o) => o.isActive && o.accepting).length;

  /// Total students queued across every office right now.
  int get waitingTotal => counts.waiting;

  factory AdminOverview.fromMap(Map<String, dynamic> map) {
    final List<Office> offices = _list(map['offices'], Office.fromMap);

    int stationsIn(List<Office> rows) =>
        rows.fold<int>(0, (int sum, Office o) => sum + o.stations.length);

    return AdminOverview(
      counts: TicketCounts.fromMap(
        Map<String, dynamic>.from(
          (map['counts'] ?? <String, dynamic>{}) as Map,
        ),
      ),
      offices: offices,
      announcements: _list(map['announcements'], Announcement.fromMap),
      settings: SystemSettings.fromMap(
        Map<String, dynamic>.from(
          (map['settings'] ?? <String, dynamic>{}) as Map,
        ),
      ),
      // The server's scalars win, but a summary derived from the office
      // rows beats showing a zero next to a populated list, so every one
      // of these has a fallback.
      officeCount: QfValue.toIntOrNull(map['office_count']) ?? offices.length,
      activeOfficeCount:
          QfValue.toIntOrNull(map['active_office_count']) ??
          offices.where((Office o) => o.isActive).length,
      stationCount:
          QfValue.toIntOrNull(map['station_count']) ?? stationsIn(offices),
      studentCount: QfValue.toIntOrNull(map['student_count']) ?? 0,
      staffCount: QfValue.toIntOrNull(map['staff_count']) ?? 0,
    );
  }
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
