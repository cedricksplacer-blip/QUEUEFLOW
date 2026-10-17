import 'dart:async';

import 'package:flutter/material.dart';

import '../core/app_colors.dart';
import '../core/glass.dart';
import '../core/motion.dart';
import '../models/campus_catalog.dart';
import '../repositories/auth_repository.dart';
import '../widgets/qf_page.dart';

/// ============================================================
/// QUEUEFLOW TMC — QUEUE STATISTICS
///
/// Reads the live board of every non-Registrar office the student may queue
/// at, plus the per-course Registrar queues, and lays them out as cards with
/// a campus-wide summary across the top.
///
/// The Registrar is seven separate queues (BSIT, BSOA, BSCRIM, BEED, BSED,
/// BACOMM, BAPOLSCI), each its own office row with its own tickets. They are
/// listed individually under PER OFFICE from the registrar-courses endpoint
/// — never folded into one entry — with independent Waiting/Serving/Served
/// counts. The CAMPUS TOTAL sums the non-Registrar boards plus those seven
/// course counts; Registrar office boards are excluded from the sum so the
/// student's own college is never counted twice.
///
/// The offices are polled in sequence rather than in parallel: a shared
/// campus Wi-Fi link to the office server is the bottleneck, and six
/// simultaneous requests mostly queue behind each other anyway.
/// ============================================================

/// Non-Registrar offices: the rows drawn from live boards.
///
/// Registrar offices are excluded on purpose — their tickets are counted
/// through the per-course rows ([campusQueueTotals]) — so a student's own
/// college is never counted twice.
List<Office> generalStatsOffices(List<Office> offices) => <Office>[
  for (final Office office in offices)
    if (!office.isRegistrarOffice) office,
];

/// Campus-wide Waiting/Serving/Served totals.
///
/// Sums the non-Registrar live boards plus the independent per-course
/// Registrar counts. Each ticket belongs to exactly one office, and the
/// per-course counters already exclude cancelled/no-show rows upstream, so
/// the sum counts every live ticket once and no finished row as live.
({int waiting, int serving, int served}) campusQueueTotals({
  required Iterable<LiveOfficeBoard> boards,
  required Iterable<RegistrarCourseOption> courses,
}) {
  int waiting = 0;
  int serving = 0;
  int served = 0;

  for (final LiveOfficeBoard board in boards) {
    waiting += board.waitingCount;
    serving += board.servingCount;
    served += board.servedCount;
  }

  for (final RegistrarCourseOption course in courses) {
    waiting += course.waitingCount;
    serving += course.servingCount;
    served += course.servedCount;
  }

  return (waiting: waiting, serving: serving, served: served);
}

class QueueStatsScreen extends StatefulWidget {
  const QueueStatsScreen({super.key});

  @override
  State<QueueStatsScreen> createState() => _QueueStatsScreenState();
}

class _QueueStatsScreenState extends State<QueueStatsScreen>
    with WidgetsBindingObserver {
  final AuthRepository _auth = AuthRepository.instance;

  /// Live boards keyed by office id, in request order.
  final Map<int, LiveOfficeBoard> _boards = <int, LiveOfficeBoard>{};

  /// Offices whose board could not be read on the last pass.
  final Set<int> _unreachable = <int>{};

  /// This page reads every office board on each pass, so it is the heaviest of
  /// the three polling screens and sits at the slow end of the 5-10 second
  /// window the brief asks for.
  static const Duration _poll = Duration(seconds: 10);

  Timer? _timer;
  bool _loading = true;
  DateTime? _updatedAt;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _auth.addListener(_onAuthChanged);
    _startPolling();
  }

  @override
  void dispose() {
    _stopPolling();
    WidgetsBinding.instance.removeObserver(this);
    _auth.removeListener(_onAuthChanged);
    super.dispose();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    if (state == AppLifecycleState.resumed) {
      _startPolling();
    } else if (state == AppLifecycleState.paused) {
      _stopPolling();
    }
  }

  void _onAuthChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  void _startPolling() {
    _stopPolling();
    unawaited(_load());
    _timer = Timer.periodic(_poll, (Timer _) => _load());
  }

  void _stopPolling() {
    _timer?.cancel();
    _timer = null;
  }

  Future<void> _load() async {
    final List<Office> offices = _auth.offices;
    final Map<int, LiveOfficeBoard> fresh = <int, LiveOfficeBoard>{};
    final Set<int> missing = <int>{};

    for (final Office office in offices) {
      // Registrar queues are read from the per-course endpoint below, which
      // carries independent Waiting/Serving/Served counts for all seven
      // courses. Polling their boards here as well would double-fetch them
      // (and double-count the student's own college in the total).
      if (office.isRegistrarOffice) {
        continue;
      }

      final LiveOfficeBoard? board = await _auth.liveBoard(office.id);

      if (board == null) {
        missing.add(office.id);
      } else {
        fresh[office.id] = board;
      }
    }

    // All seven Registrar course queues with their own live counters.
    // Best effort: a failed read keeps the previous course rows rather than
    // blanking them, matching how unreachable office boards are handled.
    try {
      if (_auth.isSignedIn) {
        await _auth.refreshRegistrarCourses();
      }
    } catch (_) {
      // Previous course rows stay on screen; the totals below sum whatever
      // is currently held.
    }

    if (!mounted) {
      return;
    }

    setState(() {
      _boards
        ..clear()
        ..addAll(fresh);
      _unreachable
        ..clear()
        ..addAll(missing);
      _loading = false;
      _updatedAt = DateTime.now();
    });
  }

  @override
  Widget build(BuildContext context) {
    // Non-Registrar offices only: the seven Registrar course queues are
    // summed from their per-course counts below, so including a Registrar
    // office board here would count the student's own college twice.
    final List<Office> generalOffices = generalStatsOffices(_auth.offices);

    final List<RegistrarCourseOption> courses = _auth.registrarCourses.courses;

    final ({int waiting, int serving, int served}) totals = campusQueueTotals(
      boards: _boards.values,
      courses: courses,
    );
    final int waiting = totals.waiting;
    final int serving = totals.serving;
    final int served = totals.served;

    final int officeCardCount = generalOffices.length + courses.length;

    return QfPage(
      title: 'Queue statistics',
      subtitle: officeCardCount == 0
          ? 'Live across all offices'
          : 'Live across $officeCardCount office${officeCardCount == 1 ? '' : 's'}',
      actions: <Widget>[
        QfIconButton(
          icon: _loading ? Icons.hourglass_top_rounded : Icons.refresh_rounded,
          tooltip: 'Refresh',
          onTap: _loading ? null : _load,
        ),
      ],
      children: <Widget>[
        const SectionTitle(text: 'Campus total'),
        Row(
          children: <Widget>[
            Expanded(
              child: _Total(
                label: 'Waiting',
                value: waiting,
                icon: Icons.hourglass_bottom_rounded,
                color: AppColors.accent,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _Total(
                label: 'Serving',
                value: serving,
                icon: Icons.campaign_rounded,
                color: AppColors.success,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _Total(
                label: 'Served',
                value: served,
                icon: Icons.task_alt_rounded,
                color: AppColors.info,
              ),
            ),
          ],
        ),
        if (_updatedAt != null) ...<Widget>[
          const SizedBox(height: 10),
          Text(
            'Updated ${_clock(_updatedAt!)} • refreshes every '
            '${_QueueStatsScreenState._poll.inSeconds}s',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.35),
              fontSize: 11,
            ),
          ),
        ],

        const SizedBox(height: 22),
        const SectionTitle(text: 'Per office'),
        if (officeCardCount == 0)
          const QfEmptyState(
            icon: Icons.bar_chart_rounded,
            title: 'No offices to report',
            message: 'The office catalogue is empty for your course.',
          )
        else ...<Widget>[
          for (final Office office in generalOffices)
            _BoardCard(
              office: office,
              board: _boards[office.id],
              unreachable: _unreachable.contains(office.id),
            ),
          if (courses.isNotEmpty) ...<Widget>[
            const SectionTitle(text: 'Registrar courses'),
            for (final RegistrarCourseOption course in courses)
              _CourseCard(course: course),
          ],
        ],
      ],
    );
  }
}

class _Total extends StatelessWidget {
  const _Total({
    required this.label,
    required this.value,
    required this.icon,
    required this.color,
  });

  final String label;
  final int value;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      radius: 20,
      blur: 14,
      padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 10),
      child: Column(
        children: <Widget>[
          Icon(icon, size: 17, color: color),
          const SizedBox(height: 9),
          Text(
            '$value',
            style: const TextStyle(
              color: Colors.white,
              fontSize: 21,
              fontWeight: FontWeight.w800,
            ),
          ),
          const SizedBox(height: 3),
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.48),
              fontSize: 10.5,
            ),
          ),
        ],
      ),
    );
  }
}

/// One office's live board: now serving, waiting, and the recent run of
/// numbers that have already been handled.
class _BoardCard extends StatelessWidget {
  const _BoardCard({
    required this.office,
    required this.board,
    required this.unreachable,
  });

  final Office office;
  final LiveOfficeBoard? board;
  final bool unreachable;

  @override
  Widget build(BuildContext context) {
    final LiveOfficeBoard? b = board;

    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: GlassCard(
        radius: 22,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Row(
              children: <Widget>[
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(
                        office.name,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 14.5,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        office.scheduleLabel,
                        style: TextStyle(
                          color: Colors.white.withValues(alpha: 0.45),
                          fontSize: 11,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: <Widget>[
                QfPill(
                  text: office.isOnline ? 'ONLINE' : 'OFFLINE',
                  color: office.isOnline
                      ? AppColors.success
                      : AppColors.danger,
                ),
                if (office.batchLabel != null)
                  QfPill(
                    text: office.batchLabel!,
                    color: AppColors.info,
                  ),
                QfPill(
                  text: office.deployedSlotsLabel,
                  color: AppColors.accent,
                ),
                if (office.batchStateLabel != null)
                  QfPill(
                    text: office.batchStateLabel!,
                    color: office.isBatchClosed
                        ? AppColors.danger
                        : AppColors.success,
                  ),
              ],
            ),
            if (office.closedReason != null) ...<Widget>[
              const SizedBox(height: 8),
              Text(
                office.closedReason!,
                style: TextStyle(
                  color: Colors.white.withValues(alpha: 0.55),
                  fontSize: 11.5,
                  height: 1.4,
                ),
              ),
            ],
            const SizedBox(height: 16),

            if (unreachable)
              Text(
                'Could not reach this office board.',
                style: TextStyle(
                  color: Colors.white.withValues(alpha: 0.40),
                  fontSize: 12,
                ),
              )
            else if (b == null)
              const Shimmer(
                borderRadius: BorderRadius.all(Radius.circular(14)),
                child: SizedBox(height: 64),
              )
            else ...<Widget>[
              Row(
                children: <Widget>[
                  Expanded(
                    child: _Stat(
                      label: 'Now serving',
                      value: b.nowServing ?? '—',
                      color: AppColors.success,
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: _Stat(
                      label: 'Waiting',
                      value: '${b.waitingCount}',
                      color: AppColors.accent,
                    ),
                  ),
                  const SizedBox(width: 10),
                  Expanded(
                    child: _Stat(
                      label: 'Served',
                      value: '${b.servedCount}',
                      color: AppColors.info,
                    ),
                  ),
                ],
              ),
              if (b.recentServed.isNotEmpty) ...<Widget>[
                const SizedBox(height: 14),
                Text(
                  'Recently served',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.40),
                    fontSize: 10.5,
                    fontWeight: FontWeight.w800,
                    letterSpacing: 1.0,
                  ),
                ),
                const SizedBox(height: 8),
                Wrap(
                  spacing: 6,
                  runSpacing: 6,
                  children: <Widget>[
                    for (final ServedNumber n in b.recentServed)
                      Container(
                        padding: const EdgeInsets.symmetric(
                          horizontal: 9,
                          vertical: 5,
                        ),
                        decoration: BoxDecoration(
                          color: Colors.white.withValues(alpha: 0.06),
                          borderRadius: BorderRadius.circular(9),
                          border: Border.all(
                            color: Colors.white.withValues(alpha: 0.10),
                          ),
                        ),
                        child: Text(
                          n.queueNumber,
                          style: const TextStyle(
                            color: Colors.white70,
                            fontSize: 11,
                            fontWeight: FontWeight.w700,
                          ),
                        ),
                      ),
                  ],
                ),
              ],
            ],
          ],
        ),
      ),
    );
  }
}

/// One Registrar course queue with its own independent counters.
///
/// Each of the seven courses (BSIT, BSOA, BSCRIM, BEED, BSED, BACOMM,
/// BAPOLSCI) is its own office row on the server, so Waiting/Serving/Served
/// here count only tickets belonging to this course. Same glass style as the
/// per-office cards; the only difference is the stat triple (no "now
/// serving" number, which lives on the per-office board instead).
class _CourseCard extends StatelessWidget {
  const _CourseCard({required this.course});

  final RegistrarCourseOption course;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: GlassCard(
        radius: 22,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Row(
              children: <Widget>[
                Expanded(
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: <Widget>[
                      Text(
                        course.officeName,
                        maxLines: 1,
                        overflow: TextOverflow.ellipsis,
                        style: const TextStyle(
                          color: Colors.white,
                          fontSize: 14.5,
                          fontWeight: FontWeight.w700,
                        ),
                      ),
                      const SizedBox(height: 2),
                      Text(
                        course.batchLabel == null
                            ? 'Course ${course.code}'
                            : 'Course ${course.code} • ${course.batchLabel}',
                        style: TextStyle(
                          color: Colors.white.withValues(alpha: 0.45),
                          fontSize: 11,
                        ),
                      ),
                    ],
                  ),
                ),
              ],
            ),
            const SizedBox(height: 10),
            Wrap(
              spacing: 6,
              runSpacing: 6,
              children: <Widget>[
                QfPill(
                  text: course.code,
                  color: AppColors.info,
                ),
                QfPill(
                  text: course.isOnline ? 'ONLINE' : 'OFFLINE',
                  color: course.isOnline
                      ? AppColors.success
                      : AppColors.danger,
                ),
                if (course.batchStateLabel != null)
                  QfPill(
                    text: course.batchStateLabel!,
                    color: course.isBatchClosed
                        ? AppColors.danger
                        : AppColors.success,
                  ),
                QfPill(
                  text: '${course.totalSlots} Slots',
                  color: AppColors.accent,
                ),
                if (course.isEnrolledCourse)
                  const QfPill(
                    text: 'YOUR COURSE',
                    color: AppColors.success,
                  ),
              ],
            ),
            if (course.closedReason != null) ...<Widget>[
              const SizedBox(height: 8),
              Text(
                course.closedReason!,
                style: TextStyle(
                  color: Colors.white.withValues(alpha: 0.55),
                  fontSize: 11.5,
                  height: 1.4,
                ),
              ),
            ],
            const SizedBox(height: 16),
            Row(
              children: <Widget>[
                Expanded(
                  child: _Stat(
                    label: 'Waiting',
                    value: '${course.waitingCount}',
                    color: AppColors.accent,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: _Stat(
                    label: 'Serving',
                    value: '${course.servingCount}',
                    color: AppColors.success,
                  ),
                ),
                const SizedBox(width: 10),
                Expanded(
                  child: _Stat(
                    label: 'Served',
                    value: '${course.servedCount}',
                    color: AppColors.info,
                  ),
                ),
              ],
            ),
          ],
        ),
      ),
    );
  }
}

class _Stat extends StatelessWidget {  const _Stat({required this.label, required this.value, required this.color});

  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 10),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: color.withValues(alpha: 0.22)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.50),
              fontSize: 10,
            ),
          ),
          const SizedBox(height: 5),
          Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 16,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }
}

String _clock(DateTime when) {
  final String hour = when.hour.toString().padLeft(2, '0');
  final String minute = when.minute.toString().padLeft(2, '0');
  final String second = when.second.toString().padLeft(2, '0');

  return '$hour:$minute:$second';
}
