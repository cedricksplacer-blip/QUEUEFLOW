import 'dart:async';

import 'package:flutter/material.dart';

import '../core/app_colors.dart';
import '../core/app_constants.dart';
import '../core/backdrop.dart';
import '../core/glass.dart';
import '../core/motion.dart';
import '../models/announcement.dart';
import '../models/campus_catalog.dart';
import '../models/queue_ticket.dart';
import '../models/student.dart';
import '../repositories/auth_repository.dart';
import '../widgets/connection_status.dart';
import '../widgets/generate_ticket_sheet.dart';
import '../widgets/qf_page.dart';
import 'announcements_screen.dart';
import 'my_ticket_screen.dart';

/// ============================================================
/// QUEUEFLOW TMC — STUDENT DASHBOARD
///
/// Glass panels over the campus photograph, counters that roll up to their
/// value, a breathing status dot, and a shimmer while the first
/// payload is still in flight.
///
/// The dashboard is the screen a student leaves open while they wait,
/// so it is the one page that cannot afford to be stale. It owns a
/// poll of its own: while it is the visible shell tab and the app is
/// in the foreground, the ticket list and the live queue position are
/// re-read on a timer, the counters roll to their new values, and a
/// number moving from *waiting* to *serving* raises a banner rather
/// than waiting to be noticed. Everything on it is also a control —
/// the identity card opens the profile, the counters drill into the
/// history, the ticket card opens the live position, the notice opens
/// the feed — because a dashboard that only displays leaves every
/// action a second trip through the drawer.
/// ============================================================

class DashboardScreen extends StatefulWidget {
  const DashboardScreen({super.key});

  @override
  State<DashboardScreen> createState() => _DashboardScreenState();
}

class _DashboardScreenState extends State<DashboardScreen>
    with WidgetsBindingObserver {
  final AuthRepository _auth = AuthRepository.instance;

  /// How often the ticket and its queue position are re-read while this
  /// page is the one on screen.
  ///
  /// The brief asks for live updates every 5-10 seconds, so this sits in the
  /// middle of that window: fast enough that a student watching the counter
  /// sees their number move, slow enough that one phone is not hammering the
  /// campus server all day.
  static const Duration _poll = Duration(seconds: 8);

  Timer? _timer;

  /// Live position of the active ticket. Null until it has been read.
  QueueProgress? _progress;

  /// True when the last attempt to read [progress] came back empty, which
  /// is what turns the strip's placeholder into a retry.
  bool _progressFailed = false;

  /// True while this page is the visible tab of the shell.
  ///
  /// The shell keeps its tabs alive in an [IndexedStack], so the dashboard
  /// keeps polling while it is hidden and while the student is reading the
  /// statistics page next to it. [QfDrawerScope] is what tells it which
  /// case it is in.
  bool _onScreen = false;

  /// True while the app is backgrounded.
  bool _suspended = false;

  /// True while [refresh] is in flight, so the button cannot queue a
  /// second read of the same payload.
  bool _refreshing = false;

  /// True while a cancellation is in flight.
  bool _cancelling = false;

  /// Set when the active ticket has just been called, and cleared once
  /// the student has acted on it.
  bool _called = false;

  /// The status the dashboard last rendered, so the transition into
  /// *serving* can be recognised as new rather than shown again.
  String? _lastStatus;

  /// When the payload on screen was last read.
  DateTime? _updatedAt;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _auth.addListener(_onAuthChanged);

    // Seeded from what is already cached, so a dashboard opened straight
    // after sign-in does not claim the ticket has just been called.
    _lastStatus = _auth.activeTicket?.status;
  }

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();

    final QfDrawerScope? scope = QfDrawerScope.maybeOf(context);
    final bool visible = scope != null && scope.currentTab == 0;

    if (visible == _onScreen) {
      return;
    }

    _onScreen = visible;
    _syncPolling();
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Polling a backgrounded app drains the battery, and the phone has
    // usually dropped the office network route anyway. On resume, force a
    // full server resync so the countdown is recomputed from the
    // server-provided expiration timestamp rather than a stale local tick.
    _suspended = state != AppLifecycleState.resumed;
    if (state == AppLifecycleState.resumed && _onScreen && _auth.isSignedIn) {
      unawaited(_tick());
    } else {
      _syncPolling();
    }
  }

  @override
  void dispose() {
    _stopPolling();
    WidgetsBinding.instance.removeObserver(this);
    _auth.removeListener(_onAuthChanged);
    super.dispose();
  }

  void _onAuthChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  // ==========================================================
  // POLLING
  // ==========================================================

  /// Polls only while the page is both the visible tab and foregrounded.
  void _syncPolling() {
    if (_onScreen && !_suspended && _auth.isSignedIn) {
      _startPolling();
    } else {
      _stopPolling();
    }
  }

  void _startPolling() {
    _stopPolling();

    if (_auth.activeTicket != null) {
      unawaited(_loadProgress());
    }

    _timer = Timer.periodic(_poll, (Timer _) => unawaited(_tick()));
  }

  void _stopPolling() {
    _timer?.cancel();
    _timer = null;
  }

  /// One refresh pass: the ticket list first, because the queue position
  /// is read against the ticket it belongs to.
  Future<void> _tick() async {
    await _auth.refresh();
    _noticeStatusChange();
    await _loadProgress();

    if (mounted) {
      setState(() => _updatedAt = DateTime.now());
    }
  }

  Future<void> _loadProgress() async {
    if (_auth.activeTicket == null) {
      if (mounted && (_progress != null || _progressFailed)) {
        setState(() {
          _progress = null;
          _progressFailed = false;
        });
      }

      return;
    }

    final QueueProgress? result = await _auth.activeProgress();

    if (!mounted) {
      return;
    }

    setState(() {
      // A null read leaves the previous position in place: one dropped
      // request should not blank a number the student is standing in
      // line for. It does replace the placeholder with a retry, so a
      // read that never succeeds does not leave a spinner running for as
      // long as the page is open.
      _progressFailed = result == null;

      if (result != null) {
        _progress = result;
      }
    });
  }

  /// Raises the banner when the ticket has just moved to the counter.
  void _noticeStatusChange() {
    final QueueTicket? ticket = _auth.activeTicket;
    final String? status = ticket?.status;
    final bool changed = status != null && status != _lastStatus;

    _lastStatus = status;

    if (!changed) {
      return;
    }

    setState(() => _called = ticket!.isServing);
  }

  // ==========================================================
  // ACTIONS
  // ==========================================================

  /// Pull-to-refresh and the header button.
  Future<void> _refresh() async {
    if (_refreshing) {
      return;
    }

    setState(() {
      _refreshing = true;
      _auth.clearError();
    });

    await _auth.refresh();
    _noticeStatusChange();
    await _loadProgress();

    if (!mounted) {
      return;
    }

    setState(() {
      _refreshing = false;
      _updatedAt = DateTime.now();
    });
  }

  /// Pushes one of the task screens and re-reads the repository when it
  /// closes, since taking or cancelling a ticket changes the dashboard.
  Future<void> _go(Widget page) async {
    await Navigator.of(context).push<void>(QfPageRoute<void>(page: page));

    if (mounted) {
      await _refresh();
    }
  }

  /// Opens the Generate Ticket popup and re-reads the repository when it
  /// closes, since taking a ticket changes the dashboard.
  Future<void> _takeTicket() async {
    await showGenerateTicketSheet(context);

    if (mounted) {
      await _refresh();
    }
  }

  /// Switches the shell to a tab, resolving it by label so the shortcut
  /// keeps pointing at the right page if the tab order ever changes.
  void _goToTab(String label, int fallback) {
    final QfDrawerScope? scope = QfDrawerScope.maybeOf(context);
    final List<String> labels = scope?.tabLabels ?? const <String>[];
    final int index = labels.indexOf(label);

    scope?.goToTab(index < 0 ? fallback : index);
  }

  Future<void> _cancel(QueueTicket ticket) async {
    final bool? confirmed = await showDialog<bool>(
      context: context,
      builder: (BuildContext context) => AlertDialog(
        backgroundColor: AppColors.primary,
        title: const Text(
          'Cancel this ticket?',
          style: TextStyle(color: Colors.white, fontSize: 17),
        ),
        content: Text(
          'Number ${ticket.queueNumber} will be voided and you will lose your '
          'place in the ${ticket.officeName} queue. You can take a new number '
          'afterwards.',
          style: TextStyle(
            color: Colors.white.withValues(alpha: 0.70),
            fontSize: 13,
            height: 1.5,
          ),
        ),
        actions: <Widget>[
          TextButton(
            onPressed: () => Navigator.of(context).pop(false),
            child: Text(
              'Keep my number',
              style: TextStyle(color: Colors.white.withValues(alpha: 0.75)),
            ),
          ),
          FilledButton(
            style: FilledButton.styleFrom(backgroundColor: AppColors.danger),
            onPressed: () => Navigator.of(context).pop(true),
            child: const Text('Cancel ticket'),
          ),
        ],
      ),
    );

    if (!(confirmed ?? false) || !mounted) {
      return;
    }

    setState(() {
      _cancelling = true;
      _auth.clearError();
    });

    final String? failure = await _auth.cancelTicket(ticket.id);

    if (!mounted) {
      return;
    }

    setState(() {
      _cancelling = false;
      _called = false;
      _progress = null;
    });

    if (failure != null) {
      _showMessage(failure, tone: QfBannerTone.danger);
      return;
    }

    await _loadProgress();
  }

  void _showMessage(String message, {QfBannerTone tone = QfBannerTone.info}) {
    ScaffoldMessenger.of(context)
      ..hideCurrentSnackBar()
      ..showSnackBar(
        SnackBar(
          content: Text(message),
          backgroundColor: switch (tone) {
            QfBannerTone.danger => const Color(0xFF7A1E2E),
            QfBannerTone.success => const Color(0xFF0E5C48),
            QfBannerTone.info => AppColors.primary,
          },
          behavior: SnackBarBehavior.floating,
          shape: RoundedRectangleBorder(
            borderRadius: BorderRadius.circular(14),
          ),
        ),
      );
  }

  // ==========================================================
  // BUILD
  // ==========================================================

  @override
  Widget build(BuildContext context) {
    final Student? student = _auth.student;

    if (student == null) {
      return const Scaffold(
        backgroundColor: AppColors.primary,
        body: CampusBackdrop(
          child: Center(child: CircularProgressIndicator()),
        ),
      );
    }

    final bool firstLoad = _auth.bootstrap == null && _auth.error == null;
    final QueueTicket? active = _auth.activeTicket;

    // Filtered exactly as the announcements feed filters it, so the
    // headline on the dashboard is a notice the feed will actually show.
    // Audience-aware: staff-only notices are excluded from the user app.
    final String audienceRole =
        _auth.role.wire.isEmpty ? 'student' : _auth.role.wire;
    final List<Announcement> published = _auth.announcements
        .where((Announcement a) => a.isLiveForRole(audienceRole))
        .toList(growable: false);
    final Announcement? latest = published.isEmpty ? null : published.first;

    return Scaffold(
      backgroundColor: AppColors.primary,
      body: CampusBackdrop(
        intensity: 0.72,
        child: SafeArea(
          bottom: false,
          child: Column(
            children: <Widget>[
              _TopBar(busy: _refreshing, onRefresh: _refresh),
              Expanded(
                child: RefreshIndicator(
                  onRefresh: _refresh,
                  color: AppColors.blue,
                  backgroundColor: AppColors.primary,
                  child: ListView(
                    padding: const EdgeInsets.fromLTRB(20, 4, 20, 32),
                    children: <Widget>[
                      if (_auth.error != null) ...<Widget>[
                        RevealIn(
                          child: QfBanner(
                            message: _auth.error!,
                            icon: Icons.cloud_off_rounded,
                            tone: QfBannerTone.danger,
                          ),
                        ),
                        const SizedBox(height: 14),
                        RevealIn(
                          child: _RetryRow(
                            busy: _refreshing,
                            onRetry: _refresh,
                          ),
                        ),
                        const SizedBox(height: 14),
                      ],

                      if (firstLoad)
                        const _LoadingSkeleton()
                      else ...<Widget>[
                        if (_called && active != null) ...<Widget>[
                          RevealIn(
                            child: QfBanner(
                              message:
                                  'Number ${active.queueNumber} is being '
                                  'called at ${active.officeName}. Please '
                                  'proceed to the counter now.',
                              icon: Icons.campaign_rounded,
                              tone: QfBannerTone.success,
                            ),
                          ),
                          const SizedBox(height: 22),
                        ],

                        RevealIn(
                          child: _ProfileCard(
                            student: student,
                            onTap: () => _goToTab('Profile', 3),
                          ),
                        ),
                        const SizedBox(height: 22),

                        const RevealIn(
                          delay: Duration(milliseconds: 90),
                          child: SectionTitle(text: 'Your queue'),
                        ),
                        RevealIn(
                          delay: const Duration(milliseconds: 150),
                          child: _StatsRow(
                            counts: _auth.counts,
                            batch: active?.batch,
                            onTap: _onStatTapped,
                          ),
                        ),
                        const SizedBox(height: 22),

                        const RevealIn(
                          delay: Duration(milliseconds: 240),
                          child: SectionTitle(text: 'Active ticket'),
                        ),
                        RevealIn(
                          delay: const Duration(milliseconds: 300),
                          child: _ActiveTicketCard(
                            ticket: active,
                            progress: _progress,
                            progressFailed: _progressFailed,
                            onRetryProgress: _loadProgress,
                            onTap: active == null
                                ? null
                                : () => _go(const MyTicketScreen()),
                            onTakeTicket: active == null ? null : _takeTicket,
                          ),
                        ),
                        const SizedBox(height: 16),

                        RevealIn(
                          delay: const Duration(milliseconds: 350),
                          child: _PrimaryAction(
                            ticket: active,
                            counts: _auth.counts,
                            maxActive: _auth.settings.maxActiveTicketsPerStudent,
                            onTakeTicket: _takeTicket,
                            onViewTicket: () => _go(const MyTicketScreen()),
                          ),
                        ),

                        if (active != null && active.canCancel) ...<Widget>[
                          const SizedBox(height: 10),
                          RevealIn(
                            delay: const Duration(milliseconds: 370),
                            child: _CancelButton(
                              busy: _cancelling,
                              onPressed: () => _cancel(active),
                            ),
                          ),
                        ],
                        const SizedBox(height: 12),

                        RevealIn(
                          delay: const Duration(milliseconds: 400),
                          child: _QuickActions(
                            onTicket: () => _go(const MyTicketScreen()),
                            onStats: () => _goToTab('Queue statistics', 1),
                            onHistory: () => _goToTab('History', 2),
                          ),
                        ),

                        if (latest != null) ...<Widget>[
                          const SizedBox(height: 22),
                          const RevealIn(
                            delay: Duration(milliseconds: 460),
                            child: SectionTitle(text: 'Announcement'),
                          ),
                          RevealIn(
                            delay: const Duration(milliseconds: 520),
                            child: _AnnouncementCard(
                              notice: latest,
                              onTap: () => _go(const AnnouncementsScreen()),
                            ),
                          ),
                        ],

                        const SizedBox(height: 20),
                        RevealIn(
                          delay: const Duration(milliseconds: 560),
                          child: _Freshness(
                            updatedAt: _updatedAt,
                            polling: _onScreen && !_suspended,
                          ),
                        ),
                      ],
                    ],
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }

  /// Routes a counter to the page that explains it.
  void _onStatTapped(String label) {
    // The waiting tickets are, by definition, the active ones — the history
    // only holds what is finished — so both counters lead to the live
    // ticket when there is one. The batch belongs to the ticket the
    // student is holding, so it follows them there too. The remaining
    // counters are counts of finished tickets, which only the history
    // explains.
    if (label == 'Active' || label == 'Waiting' || label == 'Batch') {
      if (_auth.activeTicket != null) {
        _go(const MyTicketScreen());
      } else {
        unawaited(_takeTicket());
      }

      return;
    }

    _goToTab('History', 2);
  }
}

/// Translucent header with the mark, the drawer menu and refresh.
class _TopBar extends StatelessWidget {
  const _TopBar({required this.busy, required this.onRefresh});

  /// True while the refresh it triggers is already running.
  final bool busy;

  final Future<void> Function() onRefresh;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(12, 10, 12, 6),
      child: Row(
        children: <Widget>[
          // Opens the shell's drawer. The dashboard is a shell tab, so it
          // has no back button and this takes its place.
          const QfMenuButton(),
          const SizedBox(width: 6),
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(12),
              gradient: AppColors.brandGradient,
              boxShadow: <BoxShadow>[
                BoxShadow(
                  color: AppColors.primary.withValues(alpha: 0.5),
                  blurRadius: 14,
                  offset: const Offset(0, 5),
                ),
              ],
            ),
            padding: const EdgeInsets.all(6),
            child: Image.asset(
              AppConstants.logoImage,
              fit: BoxFit.contain,
              errorBuilder:
                  (BuildContext context, Object error, StackTrace? st) =>
                      const Icon(Icons.queue, size: 20, color: Colors.white),
            ),
          ),
          const SizedBox(width: 12),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                GradientText(
                  text: AppConstants.appName,
                  colors: const <Color>[Colors.white, AppColors.sky],
                  style: const TextStyle(
                    fontSize: 17,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                Text(
                  'Smart campus queue',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.42),
                    fontSize: 10.5,
                    letterSpacing: 0.4,
                  ),
                ),
              ],
            ),
          ),
          _GlassIconButton(
            icon: busy ? Icons.hourglass_top_rounded : Icons.refresh_rounded,
            tooltip: 'Refresh',
            enabled: !busy,
            onTap: onRefresh,
          ),
        ],
      ),
    );
  }
}

/// Shown under a failed read, because knowing something is wrong is not
/// the same as being able to do anything about it.
class _RetryRow extends StatelessWidget {
  const _RetryRow({required this.busy, required this.onRetry});

  final bool busy;
  final Future<void> Function() onRetry;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: <Widget>[
        Expanded(
          child: Text(
            'The campus server could not be reached. Anything below may be '
            'out of date.',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.45),
              fontSize: 11.5,
              height: 1.4,
            ),
          ),
        ),
        const SizedBox(width: 12),
        _GlassIconButton(
          icon: Icons.refresh_rounded,
          tooltip: 'Try again',
          enabled: !busy,
          onTap: onRetry,
        ),
      ],
    );
  }
}

/// Three shortcuts to the pages a student reaches for most, so the common
/// path never needs the drawer.
///
/// Taking a ticket and editing the profile are deliberately absent: the
/// primary action below and the identity card above already open them, and
/// a dashboard that offered both twice would just be longer. Notices are
/// reached from the announcement card further down.
class _QuickActions extends StatelessWidget {
  const _QuickActions({
    required this.onTicket,
    required this.onStats,
    required this.onHistory,
  });

  final VoidCallback onTicket;
  final VoidCallback onStats;
  final VoidCallback onHistory;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: <Widget>[
        Expanded(
          child: _QuickAction(
            icon: Icons.confirmation_number_outlined,
            label: 'My ticket',
            onTap: onTicket,
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: _QuickAction(
            icon: Icons.insights_rounded,
            label: 'Statistics',
            onTap: onStats,
          ),
        ),
        const SizedBox(width: 8),
        Expanded(
          child: _QuickAction(
            icon: Icons.history_rounded,
            label: 'History',
            onTap: onHistory,
          ),
        ),
      ],
    );
  }
}

class _QuickAction extends StatelessWidget {
  const _QuickAction({
    required this.icon,
    required this.label,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressScale(
      pressedScale: 0.96,
      onTap: onTap,
      child: Container(
        padding: const EdgeInsets.symmetric(vertical: 13),
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: 0.06),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: Colors.white.withValues(alpha: 0.10)),
        ),
        child: Column(
          children: <Widget>[
            Icon(icon, size: 18, color: Colors.white.withValues(alpha: 0.80)),
            const SizedBox(height: 7),
            Text(
              label,
              maxLines: 1,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                color: Colors.white.withValues(alpha: 0.60),
                fontSize: 11,
                fontWeight: FontWeight.w600,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _GlassIconButton extends StatelessWidget {
  const _GlassIconButton({
    required this.icon,
    required this.tooltip,
    required this.onTap,
    this.enabled = true,
  });

  final IconData icon;
  final String tooltip;

  /// Null renders the button dimmed and inert, which is what a header
  /// needs while the action it triggers is already running.
  final Future<void> Function()? onTap;

  final bool enabled;

  @override
  Widget build(BuildContext context) {
    return PressScale(
      pressedScale: 0.88,
      enabled: enabled && onTap != null,
      onTap: () => onTap?.call(),
      child: Tooltip(
        message: tooltip,
        child: AnimatedOpacity(
          duration: const Duration(milliseconds: 200),
          opacity: enabled ? 1 : 0.4,
          child: Container(
            width: 40,
            height: 40,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: Colors.white.withValues(alpha: 0.08),
              border: Border.all(color: Colors.white.withValues(alpha: 0.14)),
            ),
            child: Icon(
              icon,
              size: 19,
              color: Colors.white.withValues(alpha: 0.9),
            ),
          ),
        ),
      ),
    );
  }
}

/// Identity hero with a rotating gradient avatar ring. Opens the profile,
/// which is where the name, the student id and the course can be changed.
class _ProfileCard extends StatelessWidget {
  const _ProfileCard({required this.student, required this.onTap});

  final Student student;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      radius: 28,
      glow: AppColors.violet,
      padding: const EdgeInsets.all(20),
      onTap: onTap,
      child: Row(
        children: <Widget>[
          GlassAvatar(
            photo: student.avatar,
            initials: student.initials,
            radius: 31,
          ),
          const SizedBox(width: 16),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  student.name,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 18,
                    fontWeight: FontWeight.w800,
                  ),
                ),
                const SizedBox(height: 3),
                Text(
                  student.academicSummary,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.62),
                    fontSize: 12,
                  ),
                ),
                if (student.studentId != null) ...<Widget>[
                  const SizedBox(height: 10),
                  _Pill(text: 'ID ${student.studentId}'),
                ],
              ],
            ),
          ),
          const SizedBox(width: 8),
          const Icon(
            Icons.chevron_right_rounded,
            color: Colors.white30,
            size: 22,
          ),
        ],
      ),
    );
  }
}

/// Four ticket counters that roll up from zero, plus the batch the active
/// ticket was issued in. Each one is a shortcut to the page that explains
/// the number it is showing.
class _StatsRow extends StatelessWidget {
  const _StatsRow({
    required this.counts,
    required this.batch,
    required this.onTap,
  });

  final TicketCounts counts;

  /// The batch governing the active ticket. Null when the student holds
  /// no ticket, which the tile reads as an em dash rather than a zero —
  /// "no batch" is not the same claim as "batch zero".
  final QueueBatch? batch;

  /// Receives the counter's label so the screen decides where it goes.
  final void Function(String label) onTap;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: <Widget>[
        Expanded(
          child: _StatTile(
            label: 'Active',
            value: counts.active,
            icon: Icons.bolt_rounded,
            color: AppColors.info,
            onTap: () => onTap('Active'),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: _StatTile(
            label: 'Waiting',
            value: counts.waiting,
            icon: Icons.hourglass_top_rounded,
            color: AppColors.warning,
            onTap: () => onTap('Waiting'),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: _StatTile(
            label: 'Served',
            value: counts.served,
            icon: Icons.check_rounded,
            color: AppColors.success,
            onTap: () => onTap('Served'),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: _StatTile(
            label: 'Today',
            value: counts.today,
            icon: Icons.today_rounded,
            color: AppColors.violet,
            onTap: () => onTap('Today'),
          ),
        ),
        const SizedBox(width: 10),
        Expanded(
          child: _StatTile(
            label: 'Batch',
            icon: Icons.layers_rounded,
            color: AppColors.sky,
            text: batch == null ? '—' : '${batch!.batchNo}',
            onTap: () => onTap('Batch'),
          ),
        ),
      ],
    );
  }
}

class _StatTile extends StatelessWidget {
  const _StatTile({
    required this.label,
    required this.icon,
    required this.color,
    required this.onTap,
    this.value,
    this.text,
  }) : assert(
         value != null || text != null,
         'A stat tile needs something to show.',
       );

  /// The number to roll up. Mutually exclusive with [text].
  final int? value;

  /// A fixed string to show instead of a counter, for a tile whose number
  /// is an identity (a batch) rather than a quantity.
  final String? text;

  final String label;
  final IconData icon;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      radius: 20,
      blur: 14,
      padding: const EdgeInsets.symmetric(vertical: 14, horizontal: 4),
      onTap: onTap,
      child: Column(
        children: <Widget>[
          Container(
            width: 30,
            height: 30,
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: color.withValues(alpha: 0.18),
            ),
            child: Icon(icon, size: 15, color: color),
          ),
          const SizedBox(height: 8),
          if (text != null)
            Text(
              text!,
              maxLines: 1,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 21,
                fontWeight: FontWeight.w800,
              ),
            )
          else
            AnimatedCounter(
              value: value!,
              style: const TextStyle(
                color: Colors.white,
                fontSize: 21,
                fontWeight: FontWeight.w800,
              ),
            ),
          const SizedBox(height: 2),
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.50),
              fontSize: 10.5,
            ),
          ),
        ],
      ),
    );
  }
}

/// The one action the dashboard exists for.
///
/// The label depends on whether the student is allowed another ticket,
/// because offering "take another" past the limit is a button whose only
/// outcome is a refusal from the server. When they are at the limit the
/// primary action becomes opening the ticket they already hold.
class _PrimaryAction extends StatelessWidget {
  const _PrimaryAction({
    required this.ticket,
    required this.counts,
    required this.maxActive,
    required this.onTakeTicket,
    required this.onViewTicket,
  });

  final QueueTicket? ticket;
  final TicketCounts counts;

  /// Campus rule for how many tickets one student may hold at once.
  final int maxActive;

  final VoidCallback onTakeTicket;
  final VoidCallback onViewTicket;

  /// A zero limit means the campus has not set one, so the student may
  /// keep taking tickets.
  bool get _atLimit => maxActive > 0 && counts.active >= maxActive;

  @override
  Widget build(BuildContext context) {
    if (ticket == null) {
      // A ticket number is issued by the server, so taking one is a
      // server-backed action like any other and is disabled while the app
      // cannot reach QueueFlow. Navigating to the form would only produce a
      // dead end a few taps later.
      return ServerActionButton(
        child: PrimaryButton(
          label: 'Take a ticket',
          icon: Icons.add_rounded,
          onPressed: onTakeTicket,
        ),
      );
    }

    if (!_atLimit) {
      return ServerActionButton(
        child: PrimaryButton(
          label: 'Take another ticket',
          icon: Icons.add_rounded,
          onPressed: onTakeTicket,
        ),
      );
    }

    // Viewing an existing ticket is a local read of already-fetched data, so
    // it stays available offline - which is exactly when a student most wants
    // to check their number.
    return PrimaryButton(
      label: 'View my ticket',
      icon: Icons.confirmation_number_outlined,
      onPressed: onViewTicket,
    );
  }
}

/// Void a waiting ticket without leaving the dashboard.
class _CancelButton extends StatelessWidget {
  const _CancelButton({required this.busy, required this.onPressed});

  final bool busy;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return PressScale(
      pressedScale: 0.97,
      enabled: !busy,
      onTap: onPressed,
      child: Container(
        height: 50,
        alignment: Alignment.center,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(18),
          color: AppColors.danger.withValues(alpha: 0.12),
          border: Border.all(color: AppColors.danger.withValues(alpha: 0.38)),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            if (busy)
              const Padding(
                padding: EdgeInsets.only(right: 10),
                child: SizedBox(
                  width: 16,
                  height: 16,
                  child: CircularProgressIndicator(
                    strokeWidth: 2,
                    color: AppColors.danger,
                  ),
                ),
              )
            else
              const Padding(
                padding: EdgeInsets.only(right: 10),
                child: Icon(
                  Icons.cancel_outlined,
                  size: 19,
                  color: AppColors.danger,
                ),
              ),
            Text(
              busy ? 'Cancelling...' : 'Cancel this ticket',
              style: const TextStyle(
                color: AppColors.danger,
                fontSize: 14,
                fontWeight: FontWeight.w800,
                letterSpacing: 0.4,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// The live queue slot, or an empty state.
class _ActiveTicketCard extends StatelessWidget {
  const _ActiveTicketCard({
    required this.ticket,
    required this.progress,
    required this.progressFailed,
    required this.onRetryProgress,
    required this.onTap,
    required this.onTakeTicket,
  });

  final QueueTicket? ticket;
  final QueueProgress? progress;
  final bool progressFailed;
  final VoidCallback onRetryProgress;
  final VoidCallback? onTap;
  final VoidCallback? onTakeTicket;

  @override
  Widget build(BuildContext context) {
    final QueueTicket? active = ticket;

    if (active == null) {
      return PressScale(
        pressedScale: 0.985,
        onTap: onTakeTicket ?? onTap,
        child: GlassCard(
          radius: 24,
          child: Row(
            children: <Widget>[
              Container(
                width: 46,
                height: 46,
                decoration: BoxDecoration(
                  borderRadius: BorderRadius.circular(15),
                  color: Colors.white.withValues(alpha: 0.07),
                ),
                child: const Icon(
                  Icons.confirmation_number_outlined,
                  color: Colors.white54,
                ),
              ),
              const SizedBox(width: 15),
              const Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(
                      'No active ticket',
                      style: TextStyle(
                        color: Colors.white,
                        fontSize: 15.5,
                        fontWeight: FontWeight.w700,
                      ),
                    ),
                    SizedBox(height: 3),
                    Text(
                      'You have no queue slot right now.',
                      style: TextStyle(color: Colors.white54, fontSize: 12.5),
                    ),
                  ],
                ),
              ),
              const Icon(
                Icons.chevron_right_rounded,
                color: Colors.white30,
                size: 22,
              ),
            ],
          ),
        ),
      );
    }

    final bool serving = active.isServing;
    final bool expired = active.isCountdownExpired && !active.isArrived;
    final bool warning = active.isCountdownWarning;
    final Color accent = expired
        ? AppColors.danger
        : serving
            ? (warning ? AppColors.warning : AppColors.success)
            : AppColors.warning;

    return PressScale(
      pressedScale: 0.985,
      onTap: onTap,
      child: GlassCard(
        radius: 26,
        glow: accent,
        padding: const EdgeInsets.all(22),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: <Widget>[
            Row(
              children: <Widget>[
                // Breathing dot marks the ticket as live.
                Container(
                  padding: const EdgeInsets.symmetric(
                    horizontal: 12,
                    vertical: 7,
                  ),
                  decoration: BoxDecoration(
                    color: accent.withValues(alpha: 0.16),
                    borderRadius: BorderRadius.circular(999),
                    border: Border.all(color: accent.withValues(alpha: 0.4)),
                  ),
                  child: Row(
                    mainAxisSize: MainAxisSize.min,
                    children: <Widget>[
                      BreathingDot(color: accent, size: 7),
                      const SizedBox(width: 8),
                      Text(
                        active.displayStatus.toUpperCase(),
                        style: TextStyle(
                          color: accent,
                          fontSize: 10.5,
                          fontWeight: FontWeight.w800,
                          letterSpacing: 0.9,
                        ),
                      ),
                    ],
                  ),
                ),
                const Spacer(),
                if (active.isPwd)
                  const _Pill(text: 'PWD', color: AppColors.violet),
              ],
            ),
            const SizedBox(height: 20),

            // Queue number glows and drifts slightly on an ambient loop.
            AmbientLoop(
              period: const Duration(milliseconds: 4200),
              builder: (BuildContext context, double t, double phase) {
                final double wave = (t * 2 - 1).abs();

                return Row(
                  crossAxisAlignment: CrossAxisAlignment.baseline,
                  textBaseline: TextBaseline.alphabetic,
                  children: <Widget>[
                    GradientText(
                      text: active.queueNumber,
                      colors: <Color>[
                        Colors.white,
                        Color.lerp(Colors.white, accent, wave * 0.55)!,
                      ],
                      style: const TextStyle(
                        fontSize: 34,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 2.5,
                      ),
                    ),
                  ],
                );
              },
            ),
            const SizedBox(height: 5),
            Text(
              '${active.officeName}  •  ${active.serviceName}',
              style: TextStyle(
                color: Colors.white.withValues(alpha: 0.60),
                fontSize: 13,
              ),
            ),
            const SizedBox(height: 16),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(15),
              decoration: BoxDecoration(
                color: Colors.white.withValues(alpha: 0.06),
                borderRadius: BorderRadius.circular(16),
                border: Border.all(color: Colors.white.withValues(alpha: 0.08)),
              ),
              child: Text(
                active.statusMessage,
                style: const TextStyle(
                  color: AppColors.surfaceTint,
                  fontSize: 13,
                  height: 1.45,
                ),
              ),
            ),
            // Server-synced countdown: same expires_at the staff console and
            // My Ticket read, recalculated every second and resynced on poll.
            if (active.isServing) ...<Widget>[
              const SizedBox(height: 12),
              _DashboardCountdown(ticket: active),
            ],

            const SizedBox(height: 14),
            _LiveStrip(
              progress: progress,
              serving: serving,
              failed: progressFailed,
              onRetry: onRetryProgress,
            ),
          ],
        ),
      ),
    );
  }
}

/// Compact server-synced countdown for the dashboard ticket card.
///
/// Reads ticket.expiresAt (the Laravel-stamped deadline) every second so the
/// dashboard, My Ticket and the staff console show the same remaining time.
/// Survives minimize / lock: the next poll re-adopts the server row and the
/// remaining time is recomputed from expiresAt rather than a local timer.
class _DashboardCountdown extends StatefulWidget {
  const _DashboardCountdown({required this.ticket});

  final QueueTicket ticket;

  @override
  State<_DashboardCountdown> createState() => _DashboardCountdownState();
}

class _DashboardCountdownState extends State<_DashboardCountdown> {
  Timer? _tick;

  @override
  void initState() {
    super.initState();
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) {
        setState(() {});
      }
    });
  }

  @override
  void dispose() {
    _tick?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final QueueTicket ticket = widget.ticket;
    final bool arrived = ticket.isArrived;
    final bool expired = !arrived && ticket.isCountdownExpired;
    final bool warning = !arrived && ticket.isCountdownWarning;
    final Color accent = arrived
        ? AppColors.success
        : expired
            ? AppColors.danger
            : warning
                ? AppColors.warning
                : AppColors.success;
    final int total = ticket.callDurationMinutes > 0
        ? ticket.callDurationMinutes * 60
        : 300;
    final double progress = arrived
        ? 1.0
        : expired
            ? 0.0
            : total > 0
                ? (ticket.remainingSeconds / total).clamp(0.0, 1.0)
                : 0.0;
    final String label = arrived
        ? 'ARRIVED — timer stopped'
        : expired
            ? 'TIME EXPIRED — follow office guidance'
            : '${ticket.countdownLabel} remaining • ${ticket.displayStatus}';

    return Container(
      width: double.infinity,
      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 14),
      decoration: BoxDecoration(
        color: accent.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(14),
        border: Border.all(color: accent.withValues(alpha: 0.28)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Icon(
                arrived
                    ? Icons.check_circle_outline_rounded
                    : expired
                        ? Icons.timer_off_outlined
                        : Icons.timer_outlined,
                size: 18,
                color: accent,
              ),
              const SizedBox(width: 8),
              Expanded(
                child: Text(
                  label,
                  maxLines: 2,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.85),
                    fontSize: 12,
                    fontWeight: FontWeight.w700,
                  ),
                ),
              ),
              if (!arrived && !expired)
                Text(
                  ticket.countdownLabel,
                  style: TextStyle(
                    color: accent,
                    fontSize: 16,
                    fontWeight: FontWeight.w800,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 8),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(
              value: progress,
              minHeight: 6,
              backgroundColor: Colors.white.withValues(alpha: 0.12),
              valueColor: AlwaysStoppedAnimation<Color>(accent),
            ),
          ),
        ],
      ),
    );
  }
}

/// Estimated wait, position and people ahead, read live.
class _LiveStrip extends StatelessWidget {
  const _LiveStrip({
    required this.progress,
    required this.serving,
    required this.failed,
    required this.onRetry,
  });

  final QueueProgress? progress;
  final bool serving;

  /// True when the last read came back empty.
  final bool failed;

  final VoidCallback onRetry;

  @override
  Widget build(BuildContext context) {
    final QueueProgress? p = progress;

    if (p == null) {
      // Nothing has been read yet. Saying so is better than showing zeros,
      // which read as "you are first in line".
      if (failed) {
        return PressScale(
          pressedScale: 0.97,
          onTap: onRetry,
          child: Row(
            children: <Widget>[
              const Icon(
                Icons.sync_problem_rounded,
                size: 13,
                color: Colors.white38,
              ),
              const SizedBox(width: 10),
              Expanded(
                child: Text(
                  'Could not read your position. Tap to retry.',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.40),
                    fontSize: 11.5,
                  ),
                ),
              ),
            ],
          ),
        );
      }

      return Row(
        children: <Widget>[
          const SizedBox(
            width: 13,
            height: 13,
            child: CircularProgressIndicator(
              strokeWidth: 2,
              color: Colors.white24,
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              'Reading your place in the queue...',
              style: TextStyle(
                color: Colors.white.withValues(alpha: 0.40),
                fontSize: 11.5,
              ),
            ),
          ),
        ],
      );
    }

    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: <Widget>[
        Row(
          children: <Widget>[
            Expanded(
              child: _Mini(
                label: 'Est. wait',
                value: serving ? 'At counter' : p.waitLabel,
                color: AppColors.accent,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _Mini(
                label: 'Position',
                value: p.position > 0 ? '#${p.position}' : '—',
                color: AppColors.info,
              ),
            ),
            const SizedBox(width: 8),
            Expanded(
              child: _Mini(
                label: 'Ahead',
                value: '${p.peopleAhead}',
                color: AppColors.violet,
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Row(
          children: <Widget>[
            Icon(
              Icons.campaign_rounded,
              size: 12,
              color: Colors.white.withValues(alpha: 0.35),
            ),
            const SizedBox(width: 6),
            Expanded(
              child: Text(
                p.nowServing == null
                    ? 'No number is being served yet'
                    : 'Now serving ${p.nowServing}',
                maxLines: 1,
                overflow: TextOverflow.ellipsis,
                style: TextStyle(
                  color: Colors.white.withValues(alpha: 0.38),
                  fontSize: 11,
                ),
              ),
            ),
          ],
        ),
      ],
    );
  }
}

class _Mini extends StatelessWidget {
  const _Mini({required this.label, required this.value, required this.color});

  final String label;
  final String value;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.symmetric(vertical: 10, horizontal: 9),
      decoration: BoxDecoration(
        color: color.withValues(alpha: 0.10),
        borderRadius: BorderRadius.circular(13),
        border: Border.all(color: color.withValues(alpha: 0.20)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            label,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.45),
              fontSize: 9.5,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 5),
          Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 14,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }
}

/// Latest announcement, opening the full feed.
class _AnnouncementCard extends StatelessWidget {
  const _AnnouncementCard({required this.notice, required this.onTap});

  final Announcement notice;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      radius: 22,
      onTap: onTap,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Container(
            width: 38,
            height: 38,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(12),
              color: AppColors.accent.withValues(alpha: 0.16),
            ),
            child: const Icon(
              Icons.campaign_rounded,
              size: 18,
              color: AppColors.accent,
            ),
          ),
          const SizedBox(width: 13),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  'LATEST',
                  style: TextStyle(
                    color: AppColors.accent,
                    fontSize: 11,
                    fontWeight: FontWeight.w800,
                    letterSpacing: 1,
                  ),
                ),
                const SizedBox(height: 4),
                Text(
                  notice.title,
                  style: const TextStyle(
                    color: AppColors.surfaceTint,
                    fontSize: 13.5,
                    height: 1.45,
                  ),
                ),
                const SizedBox(height: 8),
                Text(
                  'Tap to read all notices',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.35),
                    fontSize: 11,
                    fontWeight: FontWeight.w600,
                  ),
                ),
              ],
            ),
          ),
          const Icon(Icons.chevron_right_rounded, color: Colors.white24, size: 20),
        ],
      ),
    );
  }
}

/// Says how fresh the page is, so a silent poll is not mistaken for a
/// queue that has stopped moving.
class _Freshness extends StatelessWidget {
  const _Freshness({required this.updatedAt, required this.polling});

  final DateTime? updatedAt;

  /// False when the page is hidden or the app is backgrounded.
  final bool polling;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: <Widget>[
        Icon(
          polling ? Icons.sync_rounded : Icons.pause_circle_outline_rounded,
          size: 12,
          color: Colors.white.withValues(alpha: 0.28),
        ),
        const SizedBox(width: 6),
        Expanded(
          child: Text(
            updatedAt == null
                ? 'Pull down to refresh'
                : 'Updated ${_clock(updatedAt!)}  •  '
                      '${polling ? 'refreshes every ${_DashboardScreenState._poll.inSeconds}s' : 'paused while you are away'}',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.28),
              fontSize: 11,
            ),
          ),
        ),
      ],
    );
  }
}

/// Small rounded label.
class _Pill extends StatelessWidget {
  const _Pill({required this.text, this.color});

  final String text;
  final Color? color;

  @override
  Widget build(BuildContext context) {
    final Color tint = color ?? Colors.white;

    return Container(
      padding: const EdgeInsets.symmetric(horizontal: 11, vertical: 5),
      decoration: BoxDecoration(
        color: tint.withValues(alpha: 0.16),
        borderRadius: BorderRadius.circular(999),
        border: Border.all(color: tint.withValues(alpha: 0.35)),
      ),
      child: Text(
        text,
        style: TextStyle(
          color: tint,
          fontSize: 10.5,
          fontWeight: FontWeight.w800,
          letterSpacing: 0.6,
        ),
      ),
    );
  }
}

/// Shimmer placeholders shown until the first payload lands.
class _LoadingSkeleton extends StatelessWidget {
  const _LoadingSkeleton();

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: <Widget>[
        Shimmer(
          borderRadius: BorderRadius.circular(28),
          child: Container(height: 108, color: Colors.white),
        ),
        const SizedBox(height: 22),
        Row(
          children: List<Widget>.generate(4, (int i) {
            return Expanded(
              child: Padding(
                padding: EdgeInsets.only(right: i == 3 ? 0 : 10),
                child: Shimmer(
                  borderRadius: BorderRadius.circular(20),
                  child: Container(height: 96, color: Colors.white),
                ),
              ),
            );
          }),
        ),
        const SizedBox(height: 22),
        Shimmer(
          borderRadius: BorderRadius.circular(26),
          child: Container(height: 210, color: Colors.white),
        ),
      ],
    );
  }
}

String _clock(DateTime when) {
  final String hour = when.hour.toString().padLeft(2, '0');
  final String minute = when.minute.toString().padLeft(2, '0');
  final String second = when.second.toString().padLeft(2, '0');

  return '$hour:$minute:$second';
}