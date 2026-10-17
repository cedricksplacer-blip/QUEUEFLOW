import 'dart:async';

import 'package:flutter/material.dart';

import '../core/app_colors.dart';
import '../core/glass.dart';
import '../core/motion.dart';
import '../core/network/connectivity_service.dart';
import '../models/queue_ticket.dart';
import '../repositories/auth_repository.dart';
import '../widgets/connection_status.dart';
import '../widgets/generate_ticket_sheet.dart';
import '../widgets/qf_page.dart';
import 'ticket_receipt_screen.dart';

/// ============================================================
/// QUEUEFLOW TMC — MY TICKET
///
/// Shows the student's live slot, polls the queue position while the
/// page is open, and offers cancellation only while the ticket is still
/// waiting — once staff have called it, the server refuses, so the button
/// is hidden rather than left to fail.
/// ============================================================

class MyTicketScreen extends StatefulWidget {
  const MyTicketScreen({super.key});

  @override
  State<MyTicketScreen> createState() => _MyTicketScreenState();
}

class _MyTicketScreenState extends State<MyTicketScreen>
    with WidgetsBindingObserver {
  final AuthRepository _auth = AuthRepository.instance;

  /// How often the live position is re-read while the page is visible.
  ///
  /// The brief asks for live updates every 5-10 seconds. This page is the one
  /// a student keeps open while they wait, so it sits at the faster end.
  static const Duration _poll = Duration(seconds: 6);

  Timer? _timer;
  QueueProgress? _progress;
  bool _loading = true;
  bool _cancelling = false;
  String? _error;

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
    // Polling a backgrounded app drains battery and the phone will have
    // dropped the LAN route anyway.
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
    unawaited(_loadProgress());
    _timer = Timer.periodic(_poll, (Timer _) => _loadProgress());
  }

  void _stopPolling() {
    _timer?.cancel();
    _timer = null;
  }

  Future<void> _loadProgress() async {
    final QueueProgress? result = await _auth.activeProgress();

    if (!mounted) {
      return;
    }

    setState(() {
      _progress = result;
      _loading = false;
    });
  }

  Future<void> _refresh() async {
    setState(() => _loading = true);
    await _auth.refresh();
    await _loadProgress();
  }

  Future<void> _cancel(QueueTicket ticket) async {
    // Checked before the confirmation dialog, not after.
    //
    // Asking somebody to confirm a destructive action and only then telling
    // them the server cannot be reached is backwards: the dialog itself is
    // wasted effort, and "Cancel ticket" / "Cancel ticket" side by side is an
    // easy misread on a phone. One cheap re-probe here means the reader is
    // either asked properly or told why not.
    final AppConnectionState reachable = await ConnectivityService.instance
        .refresh();

    if (!mounted) {
      return;
    }

    if (reachable != AppConnectionState.online) {
      setState(() => _error = ConnectivityService.instance.message.body);
      return;
    }

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

    if (!(confirmed ?? false)) {
      return;
    }

    setState(() {
      _cancelling = true;
      _error = null;
    });

    final String? failure = await _auth.cancelTicket(ticket.id);

    if (!mounted) {
      return;
    }

    setState(() {
      _cancelling = false;
      if (failure != null) {
        _error = failure;
      }
    });

    if (failure == null) {
      await _loadProgress();
    }
  }

  @override
  Widget build(BuildContext context) {
    final QueueTicket? ticket = _auth.activeTicket;
    // A cancelled ticket stops occupying a slot, so [activeTicket] goes
    // null the moment staff cancel it. The newest ticket is kept around so
    // the student still sees the cancellation status and staff's reason
    // instead of a bare "No active ticket" state.
    final QueueTicket? latest = _auth.latestTicket;
    final QueueTicket? cancelled =
        ticket == null && (latest?.isCancelled ?? false) ? latest : null;

    return QfPage(
      title: 'My ticket',
      subtitle: ticket?.officeName ?? 'No queue slot',
      onBack: () => Navigator.of(context).maybePop(),
      actions: <Widget>[
        QfIconButton(
          icon: Icons.refresh_rounded,
          tooltip: 'Refresh',
          onTap: _refresh,
        ),
      ],
      children: <Widget>[
        if (_error != null) ...<Widget>[
          QfBanner(message: _error!),
          const SizedBox(height: 18),
        ],

        if (ticket == null)
          if (cancelled != null) ...<Widget>[
            _CancelledCard(ticket: cancelled),
            const SizedBox(height: 18),
            PrimaryButton(
              label: 'Take a new ticket',
              icon: Icons.add_rounded,
              height: 48,
              onPressed: () async {
                await showGenerateTicketSheet(context);

                if (mounted) {
                  await _refresh();
                }
              },
            ),
          ] else
            QfEmptyState(
              icon: Icons.confirmation_number_outlined,
              title: 'No active ticket',
              message:
                  'Take a number to see your queue position and wait here.',
              action: PrimaryButton(
                label: 'Take a ticket',
                icon: Icons.add_rounded,
                height: 48,
                onPressed: () async {
                  await showGenerateTicketSheet(context);

                  if (mounted) {
                    await _refresh();
                  }
                },
              ),
            )
        else ...<Widget>[
          _NumberCard(ticket: ticket, progress: _progress, loading: _loading),
          // Staff-set countdown: derived from the server-stamped expires_at
          // so it resumes the same deadline after restarts and reconnects.
          if (ticket.isServing) ...<Widget>[
            const SizedBox(height: 12),
            _CallCountdownCard(ticket: ticket, onExpiredSync: _loadProgress),
          ],
          const SizedBox(height: 20),
          const SectionTitle(text: 'Queue position'),
          _PositionCard(ticket: ticket, progress: _progress, loading: _loading),
          const SizedBox(height: 20),
          const SectionTitle(text: 'Details'),
          GlassCard(
            radius: 22,
            child: Column(
              children: <Widget>[
                QfInfoRow(
                  label: 'Office',
                  value: ticket.officeName,
                  icon: Icons.storefront_outlined,
                ),
                QfInfoRow(
                  label: 'Service',
                  value: ticket.serviceName,
                  icon: Icons.category_outlined,
                ),
                QfInfoRow(
                  label: 'Station',
                  value: ticket.station?.name ?? '—',
                  icon: Icons.place_outlined,
                ),
                if (ticket.station?.location != null)
                  QfInfoRow(
                    label: 'Location',
                    value: ticket.station!.location!,
                    icon: Icons.map_outlined,
                  ),
                QfInfoRow(
                  label: 'Priority',
                  value: ticket.isPwd ? 'PWD' : 'Regular',
                  icon: Icons.flag_outlined,
                ),
                if (ticket.purpose != null)
                  QfInfoRow(
                    label: 'Purpose',
                    value: ticket.purpose!,
                    icon: Icons.notes_rounded,
                  ),
                QfInfoRow(
                  label: 'Taken',
                  value: _formatTime(ticket.createdAt),
                  icon: Icons.schedule_rounded,
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),
          _ReceiptAction(
            onTap: () => Navigator.of(context).push<void>(
              QfPageRoute<void>(
                page: TicketReceiptScreen(
                  ticket: ticket,
                  progress: _progress,
                  student: _auth.student,
                ),
              ),
            ),
          ),

          if (ticket.canCancel) ...<Widget>[
            const SizedBox(height: 24),
            ServerActionButton(
              child: PrimaryButton(
                label: _cancelling ? 'Cancelling...' : 'Cancel ticket',
                icon: Icons.cancel_outlined,
                busy: _cancelling,
                gradient: const LinearGradient(
                  colors: <Color>[Color(0xFF7A1E2E), Color(0xFFEF4444)],
                ),
                onPressed: _cancelling ? null : () => _cancel(ticket),
              ),
            ),
          ] else ...<Widget>[
            const SizedBox(height: 20),
            const QfBanner(
              message:
                  'This ticket can no longer be cancelled because it has '
                  'already been called. Please proceed to the office.',
              icon: Icons.info_outline_rounded,
              tone: QfBannerTone.info,
            ),
          ],
        ],
      ],
    );
  }
}

/// The queue number itself, with the live countdown underneath.
class _NumberCard extends StatelessWidget {
  const _NumberCard({
    required this.ticket,
    required this.progress,
    required this.loading,
  });

  final QueueTicket ticket;
  final QueueProgress? progress;
  final bool loading;

  @override
  Widget build(BuildContext context) {
    final bool serving = ticket.isServing;
    final Color accent = serving ? AppColors.success : AppColors.warning;

    return GlassCard(
      radius: 26,
      glow: accent,
      padding: const EdgeInsets.all(22),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
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
                      ticket.statusLabel.toUpperCase(),
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
              if (ticket.isPwd)
                const QfPill(text: 'PWD', color: AppColors.violet),
            ],
          ),
          const SizedBox(height: 20),
          AmbientLoop(
            period: const Duration(milliseconds: 4200),
            builder: (BuildContext context, double t, double phase) {
              final double wave = (t * 2 - 1).abs();

              return GradientText(
                text: ticket.queueNumber,
                colors: <Color>[
                  Colors.white,
                  Color.lerp(Colors.white, accent, wave * 0.55)!,
                ],
                style: const TextStyle(
                  fontSize: 36,
                  fontWeight: FontWeight.w800,
                  letterSpacing: 2.5,
                ),
              );
            },
          ),
          const SizedBox(height: 6),
          Text(
            '${ticket.officeName}  •  ${ticket.serviceName}',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.60),
              fontSize: 13,
            ),
          ),
          const SizedBox(height: 16),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.06),
              borderRadius: BorderRadius.circular(15),
              border: Border.all(color: Colors.white.withValues(alpha: 0.08)),
            ),
            child: Text(
              ticket.statusMessage,
              style: const TextStyle(
                color: AppColors.surfaceTint,
                fontSize: 13,
                height: 1.45,
              ),
            ),
          ),
        ],
      ),
    );
  }
}

/// Estimated wait, people ahead, and who is being served right now.
class _PositionCard extends StatelessWidget {
  const _PositionCard({
    required this.ticket,
    required this.progress,
    required this.loading,
  });

  final QueueTicket ticket;
  final QueueProgress? progress;
  final bool loading;

  @override
  Widget build(BuildContext context) {
    if (loading && progress == null) {
      return Shimmer(
        borderRadius: BorderRadius.circular(24),
        child: Container(height: 132, color: Colors.white),
      );
    }

    final QueueProgress? p = progress;

    if (p == null) {
      return const QfEmptyState(
        icon: Icons.query_stats_rounded,
        title: 'Position unavailable',
        message: 'The office has not reported a queue position yet.',
      );
    }

    return Column(
      children: <Widget>[
        Row(
          children: <Widget>[
            Expanded(
              child: _Metric(
                label: 'Estimated wait',
                value: p.waitLabel,
                icon: Icons.hourglass_bottom_rounded,
                color: AppColors.accent,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _Metric(
                label: 'Your position',
                value: p.position > 0 ? '#${p.position}' : '—',
                icon: Icons.low_priority_rounded,
                color: AppColors.info,
              ),
            ),
          ],
        ),
        const SizedBox(height: 10),
        Row(
          children: <Widget>[
            Expanded(
              child: _Metric(
                label: 'People ahead',
                value: '${p.peopleAhead}',
                icon: Icons.groups_rounded,
                color: AppColors.violet,
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: _Metric(
                label: 'Now serving',
                value: p.nowServing ?? '—',
                icon: Icons.campaign_rounded,
                color: AppColors.success,
              ),
            ),
          ],
        ),
        const SizedBox(height: 12),
        Text(
          'Updates every ${_MyTicketScreenState._poll.inSeconds} seconds while this page is open.',
          style: TextStyle(
            color: Colors.white.withValues(alpha: 0.35),
            fontSize: 11,
          ),
        ),
      ],
    );
  }
}

class _Metric extends StatelessWidget {
  const _Metric({
    required this.label,
    required this.value,
    required this.icon,
    required this.color,
  });

  final String label;
  final String value;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return GlassCard(
      radius: 20,
      blur: 14,
      padding: const EdgeInsets.symmetric(vertical: 16, horizontal: 14),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Icon(icon, size: 15, color: color),
              const SizedBox(width: 7),
              Expanded(
                child: Text(
                  label,
                  maxLines: 1,
                  overflow: TextOverflow.ellipsis,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.50),
                    fontSize: 10.5,
                  ),
                ),
              ),
            ],
          ),
          const SizedBox(height: 9),
          Text(
            value,
            maxLines: 1,
            overflow: TextOverflow.ellipsis,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 20,
              fontWeight: FontWeight.w800,
            ),
          ),
        ],
      ),
    );
  }
}

String _formatTime(DateTime? when) {
  if (when == null) {
    return '—';
  }

  final String hour = when.hour.toString().padLeft(2, '0');
  final String minute = when.minute.toString().padLeft(2, '0');

  return '${when.year}-${when.month.toString().padLeft(2, '0')}-'
      '${when.day.toString().padLeft(2, '0')} $hour:$minute';
}

/// Live countdown for a called ticket, driven by the server-stamped
/// expiry rather than a locally restarted timer.
///
/// Ticks every second in MM:SS, warns under 60s, and shows TICKET EXPIRED
/// once the deadline passes. The deadline itself comes from the ticket
/// payload (refreshed by the parent poll + [onExpiredSync]), so closing,
/// restarting, locking the screen, or losing connectivity never restarts
/// it — the next sync resumes the original server deadline via expires_at.
class _CallCountdownCard extends StatefulWidget {
  const _CallCountdownCard({required this.ticket, required this.onExpiredSync});

  final QueueTicket ticket;
  final Future<void> Function() onExpiredSync;

  @override
  State<_CallCountdownCard> createState() => _CallCountdownCardState();
}

class _CallCountdownCardState extends State<_CallCountdownCard>
    with WidgetsBindingObserver {
  Timer? _tick;
  bool _syncRequested = false;

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addObserver(this);
    _tick = Timer.periodic(const Duration(seconds: 1), (_) {
      if (mounted) {
        setState(() {});
      }
      // Once expired, ask the parent to re-sync once so a recall /
      // no-show decided on the server is reflected without waiting
      // out the next poll interval.
      if (widget.ticket.isCountdownExpired && !_syncRequested) {
        _syncRequested = true;
        unawaited(widget.onExpiredSync());
      }
    });
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    // Recalculate from the server expiration timestamp on resume: the local
    // 1s ticker kept running on stale data while paused, so force a fresh
    // server read the moment the app is foregrounded again.
    if (state == AppLifecycleState.resumed) {
      _syncRequested = false;
      unawaited(widget.onExpiredSync());
    }
  }

  @override
  void didUpdateWidget(covariant _CallCountdownCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.ticket.id != widget.ticket.id ||
        oldWidget.ticket.expiresAt != widget.ticket.expiresAt ||
        oldWidget.ticket.arrivedAt != widget.ticket.arrivedAt ||
        oldWidget.ticket.status != widget.ticket.status) {
      _syncRequested = false;
    }
  }

  @override
  void dispose() {
    WidgetsBinding.instance.removeObserver(this);
    _tick?.cancel();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    final QueueTicket ticket = widget.ticket;
    final bool arrived = ticket.isArrived;
    final bool expired = !arrived && ticket.isCountdownExpired;
    final bool warning = !arrived && ticket.isCountdownWarning;
    final bool urgent =
        !arrived && !expired && ticket.remainingSeconds <= 30 && ticket.expiresAt != null;
    final Color accent = arrived
        ? AppColors.success
        : expired
            ? AppColors.danger
            : urgent
                ? const Color(0xFFFF7043)
                : warning
                    ? AppColors.warning
                    : AppColors.success;

    final int totalSecs = ticket.callDurationMinutes > 0
        ? ticket.callDurationMinutes * 60
        : 300;
    final double progress = arrived
        ? 1.0
        : expired
            ? 0.0
            : totalSecs > 0
                ? (ticket.remainingSeconds / totalSecs).clamp(0.0, 1.0)
                : 0.0;
    final String remaining = arrived
        ? 'ARRIVED'
        : expired
            ? 'TIME EXPIRED'
            : '${ticket.countdownLabel} remaining';

    return GlassCard(
      radius: 20,
      glow: accent,
      padding: const EdgeInsets.all(18),
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
                size: 26,
                color: accent,
              ),
              const SizedBox(width: 14),
              Expanded(
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Text(
                      arrived
                          ? 'YOU HAVE ARRIVED — BEING ATTENDED TO'
                          : expired
                              ? 'TICKET EXPIRED'
                              : 'YOUR TICKET HAS BEEN CALLED',
                      style: TextStyle(
                        color: arrived || expired ? accent : Colors.white,
                        fontSize: arrived || expired ? 16 : 14,
                        fontWeight: FontWeight.w800,
                        letterSpacing: arrived || expired ? 0.6 : 0.3,
                      ),
                    ),
                    const SizedBox(height: 4),
                    Text(
                      '${ticket.queueNumber} • ${ticket.officeName} • ${ticket.displayStatus}',
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: 0.60),
                        fontSize: 11.5,
                        fontWeight: FontWeight.w600,
                      ),
                    ),
                  ],
                ),
              ),
              if (!expired && !arrived)
                Text(
                  ticket.countdownLabel,
                  style: TextStyle(
                    color: accent,
                    fontSize: 22,
                    fontWeight: FontWeight.w800,
                  ),
                ),
            ],
          ),
          const SizedBox(height: 12),
          ClipRRect(
            borderRadius: BorderRadius.circular(999),
            child: LinearProgressIndicator(
              value: progress,
              minHeight: 8,
              backgroundColor: Colors.white.withValues(alpha: 0.12),
              valueColor: AlwaysStoppedAnimation<Color>(accent),
            ),
          ),
          const SizedBox(height: 8),
          Text(
            arrived
                ? 'Timer stopped. Please stay at the counter while staff assist you.'
                : expired
                    ? 'Your call time passed. Watch for a recall or take guidance from the office for another number.'
                    : '$remaining — proceed to ${ticket.officeName} before the timer expires.',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.60),
              fontSize: 11.5,
            ),
          ),
          if (!arrived && !expired) ...<Widget>[
            const SizedBox(height: 4),
            Text(
              warning
                  ? 'Time almost up: 1 minute remaining of your ${ticket.callDurationMinutes} min call.'
                  : '${ticket.callDurationMinutes} min call — please proceed to the counter.'
                        '${ticket.recallCount > 0 ? ' (Called ${ticket.recallCount + 1}×)' : ''}',
              style: TextStyle(
                color: Colors.white.withValues(alpha: 0.60),
                fontSize: 11.5,
              ),
            ),
          ],
        ],
      ),
    );
  }
}

/// Shown when the student's newest ticket was cancelled: the cancellation
/// status plus the exact reason staff entered, so the student knows why
/// their number was voided without asking at the counter.
class _CancelledCard extends StatelessWidget {
  const _CancelledCard({required this.ticket});

  final QueueTicket ticket;

  @override
  Widget build(BuildContext context) {
    final String reason = (ticket.cancelReason ?? '').trim();

    return GlassCard(
      radius: 26,
      glow: AppColors.danger,
      padding: const EdgeInsets.all(22),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Row(
            children: <Widget>[
              Container(
                padding: const EdgeInsets.symmetric(
                  horizontal: 12,
                  vertical: 7,
                ),
                decoration: BoxDecoration(
                  color: AppColors.danger.withValues(alpha: 0.16),
                  borderRadius: BorderRadius.circular(999),
                  border: Border.all(
                    color: AppColors.danger.withValues(alpha: 0.4),
                  ),
                ),
                child: const Row(
                  mainAxisSize: MainAxisSize.min,
                  children: <Widget>[
                    BreathingDot(color: AppColors.danger, size: 7),
                    SizedBox(width: 8),
                    Text(
                      'CANCELLED',
                      style: TextStyle(
                        color: AppColors.danger,
                        fontSize: 10.5,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 0.9,
                      ),
                    ),
                  ],
                ),
              ),
              const Spacer(),
              if (ticket.isPwd)
                const QfPill(text: 'PWD', color: AppColors.violet),
            ],
          ),
          const SizedBox(height: 20),
          Text(
            ticket.queueNumber,
            style: const TextStyle(
              color: Colors.white,
              fontSize: 36,
              fontWeight: FontWeight.w800,
              letterSpacing: 2.5,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            '${ticket.officeName}  •  ${ticket.serviceName}',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.60),
              fontSize: 13,
            ),
          ),
          const SizedBox(height: 16),
          Container(
            width: double.infinity,
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: Colors.white.withValues(alpha: 0.06),
              borderRadius: BorderRadius.circular(15),
              border: Border.all(color: Colors.white.withValues(alpha: 0.08)),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  'Reason for cancellation',
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.50),
                    fontSize: 10.5,
                    fontWeight: FontWeight.w700,
                  ),
                ),
                const SizedBox(height: 6),
                Text(
                  reason.isEmpty ? 'No reason was provided.' : reason,
                  style: const TextStyle(
                    color: AppColors.surfaceTint,
                    fontSize: 13,
                    height: 1.45,
                  ),
                ),
              ],
            ),
          ),
        ],
      ),
    );
  }
}

/// Bordered action that opens the digital receipt for the active ticket.
class _ReceiptAction extends StatelessWidget {
  const _ReceiptAction({required this.onTap});

  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return PressScale(
      enabled: true,
      onTap: onTap,
      pressedScale: 0.98,
      child: Container(
        height: 50,
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: 0.06),
          borderRadius: BorderRadius.circular(16),
          border: Border.all(color: Colors.white.withValues(alpha: 0.18)),
        ),
        child: Row(
          mainAxisAlignment: MainAxisAlignment.center,
          children: <Widget>[
            const Icon(
              Icons.receipt_long_rounded,
              size: 19,
              color: Colors.white,
            ),
            const SizedBox(width: 9),
            const Text(
              'View digital receipt',
              style: TextStyle(
                color: Colors.white,
                fontSize: 13,
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ),
      ),
    );
  }
}
