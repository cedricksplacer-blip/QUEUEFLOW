import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:queueflow_tmc/core/app_constants.dart';
import 'package:queueflow_tmc/models/queue_ticket.dart';
import 'package:queueflow_tmc/widgets/cancelled_ticket_dialog.dart';

/// ============================================================
/// QUEUEFLOW TMC — STAFF-CANCELLATION DIALOG
///
/// The popup rules, pinned without a device: which tickets owe a popup,
/// the exact staff reason reaching the dialog, the fallback when the
/// record carries none, and dismissal via OK.
/// ============================================================

QueueTicket ticket({
  required int id,
  String status = AppConstants.statusWaiting,
  String? reason,
}) => QueueTicket(
  id: id,
  queueNumber: 'CASH-${id.toString().padLeft(3, '0')}',
  status: status,
  cancelReason: reason,
);

void main() {
  group('pendingStaffCancellationDialogs', () {
    test('a staff-cancelled ticket with a reason is pending', () {
      final List<QueueTicket> pending = pendingStaffCancellationDialogs(
        tickets: <QueueTicket>[
          ticket(id: 7, status: AppConstants.statusCancelled, reason: 'Office is temporarily closed'),
        ],
        userId: 1,
        ackedKeys: <String>{},
        isSelfCancelled: (_) => false,
        notifyEnabled: true,
      );

      expect(pending.map((QueueTicket t) => t.id), <int>[7]);
    });

    test('waiting, serving and served tickets never pop', () {
      final List<QueueTicket> pending = pendingStaffCancellationDialogs(
        tickets: <QueueTicket>[
          ticket(id: 1, status: AppConstants.statusWaiting),
          ticket(id: 2, status: AppConstants.statusServing),
          ticket(id: 3, status: AppConstants.statusServed),
        ],
        userId: 1,
        ackedKeys: <String>{},
        isSelfCancelled: (_) => false,
        notifyEnabled: true,
      );

      expect(pending, isEmpty);
    });

    test('a self-cancelled ticket never pops', () {
      final List<QueueTicket> pending = pendingStaffCancellationDialogs(
        tickets: <QueueTicket>[
          ticket(id: 9, status: AppConstants.statusCancelled),
        ],
        userId: 1,
        ackedKeys: <String>{},
        isSelfCancelled: (int id) => id == 9,
        notifyEnabled: true,
      );

      expect(pending, isEmpty);
    });

    test('an acknowledged ticket never pops again', () {
      final List<QueueTicket> pending = pendingStaffCancellationDialogs(
        tickets: <QueueTicket>[
          ticket(id: 9, status: AppConstants.statusCancelled, reason: 'Office is temporarily closed'),
        ],
        userId: 1,
        ackedKeys: <String>{cancelDialogKeyFor(1, 9)},
        isSelfCancelled: (_) => false,
        notifyEnabled: true,
      );

      expect(pending, isEmpty);
    });

    test('another account still owes its own popup for the same ticket', () {
      final List<QueueTicket> pending = pendingStaffCancellationDialogs(
        tickets: <QueueTicket>[
          ticket(id: 9, status: AppConstants.statusCancelled, reason: 'Office is temporarily closed'),
        ],
        userId: 2,
        ackedKeys: <String>{cancelDialogKeyFor(1, 9)},
        isSelfCancelled: (_) => false,
        notifyEnabled: true,
      );

      expect(pending.map((QueueTicket t) => t.id), <int>[9]);
    });

    test('nothing pops when the cancellation toggle is off', () {
      final List<QueueTicket> pending = pendingStaffCancellationDialogs(
        tickets: <QueueTicket>[
          ticket(id: 9, status: AppConstants.statusCancelled, reason: 'Office is temporarily closed'),
        ],
        userId: 1,
        ackedKeys: <String>{},
        isSelfCancelled: (_) => false,
        notifyEnabled: false,
      );

      expect(pending, isEmpty);
    });

    test('newest cancelled ticket comes first', () {
      final List<QueueTicket> pending = pendingStaffCancellationDialogs(
        tickets: <QueueTicket>[
          ticket(id: 3, status: AppConstants.statusCancelled),
          ticket(id: 11, status: AppConstants.statusCancelled),
          ticket(id: 5, status: AppConstants.statusCancelled),
        ],
        userId: 1,
        ackedKeys: <String>{},
        isSelfCancelled: (_) => false,
        notifyEnabled: true,
      );

      expect(
        pending.map((QueueTicket t) => t.id).toList(),
        <int>[11, 5, 3],
      );
    });

    test('ack keys are scoped per account and ticket', () {
      expect(cancelDialogKeyFor(1, 9), isNot(cancelDialogKeyFor(2, 9)));
      expect(cancelDialogKeyFor(1, 9), isNot(cancelDialogKeyFor(1, 10)));
    });
  });

  group('cancellationReasonText', () {
    test('returns the exact staff reason', () {
      expect(
        cancellationReasonText(
          ticket(id: 1, status: AppConstants.statusCancelled, reason: 'Office is temporarily closed'),
        ),
        'Office is temporarily closed',
      );
    });

    test('falls back only when the record carries no reason', () {
      expect(
        cancellationReasonText(ticket(id: 1, status: AppConstants.statusCancelled)),
        'No reason was given.',
      );
    });
  });

  group('showCancelledTicketDialog', () {
    testWidgets('shows the staff reason and dismisses via OK', (WidgetTester tester) async {
      SharedPreferences.setMockInitialValues(<String, Object>{});

      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (BuildContext context) => TextButton(
              onPressed: () => showCancelledTicketDialog(
                context,
                ticket(id: 7, status: AppConstants.statusCancelled, reason: 'Office is temporarily closed'),
              ),
              child: const Text('open'),
            ),
          ),
        ),
      );

      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();

      expect(find.text('Your Ticket Has Been Cancelled'), findsOneWidget);
      expect(
        find.text('Your queue ticket has been cancelled by the staff.'),
        findsOneWidget,
      );
      expect(find.text('Cancellation Reason:'), findsOneWidget);
      expect(find.text('Office is temporarily closed'), findsOneWidget);
      expect(find.text('OK'), findsOneWidget);

      await tester.tap(find.text('OK'));
      await tester.pumpAndSettle();

      expect(find.text('Your Ticket Has Been Cancelled'), findsNothing);
    });

    testWidgets('shows the fallback when no reason was recorded', (WidgetTester tester) async {
      SharedPreferences.setMockInitialValues(<String, Object>{});

      await tester.pumpWidget(
        MaterialApp(
          home: Builder(
            builder: (BuildContext context) => TextButton(
              onPressed: () => showCancelledTicketDialog(
                context,
                ticket(id: 7, status: AppConstants.statusCancelled),
              ),
              child: const Text('open'),
            ),
          ),
        ),
      );

      await tester.tap(find.text('open'));
      await tester.pumpAndSettle();

      expect(find.text('No reason was given.'), findsOneWidget);
    });
  });
}
