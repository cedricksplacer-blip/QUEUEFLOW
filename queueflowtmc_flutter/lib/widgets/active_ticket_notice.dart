import 'package:flutter/material.dart';

import '../core/app_colors.dart';
import '../models/queue_ticket.dart';

/// ============================================================
/// QUEUEFLOW TMC — ACTIVE TICKET GATE NOTICE (SHARED)
///
/// One active ticket across all offices: holding a Registrar number locks
/// Cashier, Admissions and every other office until it is served or
/// cancelled. Both ticket entry points (the popup sheet and the full-screen
/// form) render this notice with the blocking ticket's details, and disable
/// their Generate action while it is showing. The server enforces the same
/// rule, so the UI only decides what the student is told.
///
/// Renders only while an active ticket exists. The repository listener on
/// each screen rebuilds the moment the ticket finishes, which re-enables
/// generation with no sign-out or restart.
/// ============================================================

/// The sentence shown when [ticket] blocks a new one.
String activeTicketMessage(QueueTicket ticket) {
  final String office = ticket.office?.name ?? 'another office';

  return 'You already have an active queue ticket at the $office. '
      'Please complete your current queue before getting another ticket.';
}

class ActiveTicketNotice extends StatelessWidget {
  const ActiveTicketNotice({super.key, required this.ticket});

  final QueueTicket ticket;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: double.infinity,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: AppColors.accent.withValues(alpha: 0.12),
        borderRadius: BorderRadius.circular(16),
        border: Border.all(color: AppColors.accent.withValues(alpha: 0.45)),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          const Row(
            children: <Widget>[
              Icon(
                Icons.confirmation_number_rounded,
                size: 16,
                color: AppColors.accent,
              ),
              SizedBox(width: 8),
              Text(
                'Active queue ticket',
                style: TextStyle(
                  color: AppColors.accent,
                  fontSize: 12,
                  fontWeight: FontWeight.w800,
                ),
              ),
            ],
          ),
          const SizedBox(height: 8),
          Text(
            activeTicketMessage(ticket),
            style: const TextStyle(
              color: Colors.white,
              fontSize: 12.5,
              height: 1.45,
            ),
          ),
          const SizedBox(height: 8),
          Text(
            <String>[
              ticket.queueNumber,
              if ((ticket.office?.name ?? '').isNotEmpty)
                ticket.office!.name,
              if ((ticket.purpose ?? '').isNotEmpty) ticket.purpose!,
              ticket.statusLabel,
            ].join('  •  '),
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.65),
              fontSize: 11.5,
            ),
          ),
        ],
      ),
    );
  }
}
