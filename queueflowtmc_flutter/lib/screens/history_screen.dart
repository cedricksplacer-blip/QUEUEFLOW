import 'package:flutter/material.dart';

import '../core/app_colors.dart';
import '../core/motion.dart';
import '../models/queue_ticket.dart';
import '../repositories/auth_repository.dart';
import '../widgets/qf_page.dart';
import 'ticket_receipt_screen.dart';

/// ============================================================
/// QUEUEFLOW TMC — TICKET HISTORY
///
/// Every finished ticket: served and cancelled, newest first. Served
/// tickets show who handled them, because that is the one detail a
/// student tends to want afterwards and the dashboard never keeps it.
/// ============================================================

class HistoryScreen extends StatefulWidget {
  const HistoryScreen({super.key});

  @override
  State<HistoryScreen> createState() => _HistoryScreenState();
}

class _HistoryScreenState extends State<HistoryScreen> {
  final AuthRepository _auth = AuthRepository.instance;

  /// Which slice of the history is shown.
  int _tab = 0;

  @override
  void initState() {
    super.initState();
    _auth.addListener(_onAuthChanged);
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

  @override
  Widget build(BuildContext context) {
    final List<QueueTicket> all = _auth.history;
    final List<QueueTicket> served = all
        .where((QueueTicket t) => t.isServed)
        .toList(growable: false);
    final List<QueueTicket> noShow = all
        .where((QueueTicket t) => t.isNoShow)
        .toList(growable: false);
    final List<QueueTicket> cancelled = all
        .where((QueueTicket t) => t.isCancelled)
        .toList(growable: false);

    final List<QueueTicket> shown = switch (_tab) {
      1 => served,
      2 => noShow,
      3 => cancelled,
      _ => all,
    };

    return QfPage(
      title: 'History',
      subtitle: '${all.length} finished ticket${all.length == 1 ? '' : 's'}',
      actions: <Widget>[
        QfIconButton(
          icon: Icons.refresh_rounded,
          tooltip: 'Refresh',
          onTap: () => _auth.refresh(),
        ),
      ],
      children: <Widget>[
        _TabBar(
          current: _tab,
          counts: <int>[all.length, served.length, noShow.length, cancelled.length],
          onChanged: (int i) => setState(() => _tab = i),
        ),
        const SizedBox(height: 18),

        if (shown.isEmpty)
          QfEmptyState(
            icon: Icons.history_rounded,
            title: switch (_tab) {
              1 => 'No served tickets yet',
              2 => 'No No-Show records',
              3 => 'No cancelled tickets',
              _ => 'Nothing here yet',
            },
            message:
                'Tickets you have taken will be listed here once they are '
                'served, marked No-Show, or cancelled.',
          )
        else
          for (final QueueTicket ticket in shown)
            _HistoryCard(
              ticket: ticket,
              onTap: () => Navigator.of(context).push<void>(
                QfPageRoute<void>(
                  page: TicketReceiptScreen(
                    ticket: ticket,
                    student: _auth.student,
                  ),
                ),
              ),
            ),
      ],
    );
  }
}

/// Four-way segmented control with counts.
class _TabBar extends StatelessWidget {
  const _TabBar({
    required this.current,
    required this.counts,
    required this.onChanged,
  });

  final int current;
  final List<int> counts;
  final ValueChanged<int> onChanged;

  static const List<String> _labels = <String>['All', 'Served', 'No-Show', 'Cancelled'];

  @override
  Widget build(BuildContext context) {
    return Container(
      padding: const EdgeInsets.all(4),
      decoration: BoxDecoration(
        color: Colors.white.withValues(alpha: 0.07),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: Colors.white.withValues(alpha: 0.10)),
      ),
      child: Row(
        children: <Widget>[
          for (int i = 0; i < _labels.length; i++)
            Expanded(
              child: PressScale(
                pressedScale: 0.97,
                onTap: () => onChanged(i),
                child: AnimatedContainer(
                  duration: const Duration(milliseconds: 200),
                  padding: const EdgeInsets.symmetric(vertical: 10),
                  decoration: BoxDecoration(
                    color: i == current
                        ? Colors.white.withValues(alpha: 0.16)
                        : Colors.transparent,
                    borderRadius: BorderRadius.circular(12),
                  ),
                  alignment: Alignment.center,
                  child: Text(
                    '${_labels[i]} (${counts[i]})',
                    style: TextStyle(
                      color: i == current
                          ? Colors.white
                          : Colors.white.withValues(alpha: 0.50),
                      fontSize: 12,
                      fontWeight: FontWeight.w700,
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _HistoryCard extends StatelessWidget {
  const _HistoryCard({required this.ticket, required this.onTap});

  final QueueTicket ticket;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    final bool served = ticket.isServed;
    final bool noShow = ticket.isNoShow;
    final Color accent = served
        ? AppColors.success
        : noShow
            ? AppColors.warning
            : AppColors.danger;
    final String state = served
        ? 'Served'
        : noShow
            ? 'No-Show'
            : 'Cancelled';

    return QfSelectTile(
      title: ticket.queueNumber,
      subtitle:
          '${ticket.officeName}  •  ${ticket.serviceName}\n'
          '$state'
          '${ticket.servedAt != null ? ' on ${_date(ticket.servedAt!)}' : ''}'
          '${noShow && ticket.noShowAt != null ? ' on ${_date(ticket.noShowAt!)}' : ''}'
          '${served && ticket.servedBy != null ? ' by ${ticket.servedBy!.name}' : ''}',
      onTap: onTap,
      leading: Container(
        width: 44,
        height: 44,
        decoration: BoxDecoration(
          borderRadius: BorderRadius.circular(13),
          color: accent.withValues(alpha: 0.15),
        ),
        child: Icon(
          served
              ? Icons.check_rounded
              : noShow
                  ? Icons.timer_off_outlined
                  : Icons.close_rounded,
          color: accent,
          size: 20,
        ),
      ),
      trailing: QfPill(text: state, color: accent),
    );
  }
}

String _date(DateTime when) {
  final String month = when.month.toString().padLeft(2, '0');
  final String day = when.day.toString().padLeft(2, '0');

  return '${when.year}-$month-$day';
}
