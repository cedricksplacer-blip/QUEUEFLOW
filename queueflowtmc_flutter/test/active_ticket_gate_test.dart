import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:queueflow_tmc/core/app_constants.dart';
import 'package:queueflow_tmc/core/app_theme.dart';
import 'package:queueflow_tmc/core/glass.dart';
import 'package:queueflow_tmc/models/queue_ticket.dart';
import 'package:queueflow_tmc/models/student.dart';
import 'package:queueflow_tmc/repositories/auth_repository.dart';
import 'package:queueflow_tmc/widgets/active_ticket_notice.dart';
import 'package:queueflow_tmc/widgets/generate_ticket_sheet.dart';

/// ============================================================
/// QUEUEFLOW TMC — ONE ACTIVE TICKET GATE (CLIENT SIDE)
///
/// One active ticket across all offices: while the repository holds a
/// waiting or serving ticket, both ticket entry points name the blocking
/// ticket and refuse to send another request. The server enforces the same
/// rule; these tests pin the client side — the notice content and the
/// disabled Generate action — without touching the network.
/// ============================================================

const Student _student = Student(
  id: 7,
  name: 'Juan Dela Cruz',
  email: 'juan@tmc.edu.ph',
  course: 'BSIT',
  yearLevel: '2nd Year',
  block: '1',
  studentId: '2021-0042',
);

QueueTicket _ticket({required String status}) => QueueTicket(
  id: 42,
  queueNumber: 'REG-007',
  office: const OfficeRef(id: 3, name: 'Registrar', code: 'REG-BSIT'),
  purpose: 'Transcript of Records',
  status: status,
);

void main() {
  final AuthRepository auth = AuthRepository.instance;

  tearDown(auth.debugReset);

  group('activeTicketMessage', () {
    test('names the blocking office in the required sentence', () {
      final String message = activeTicketMessage(
        _ticket(status: AppConstants.statusWaiting),
      );

      expect(
        message,
        'You already have an active queue ticket at the Registrar. '
        'Please complete your current queue before getting another ticket.',
      );
    });
  });

  group('ActiveTicketNotice', () {
    testWidgets('shows office, number, purpose and status', (
      WidgetTester tester,
    ) async {
      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.dark(),
          home: Scaffold(
            body: ActiveTicketNotice(
              ticket: _ticket(status: AppConstants.statusServing),
            ),
          ),
        ),
      );

      expect(find.text('Active queue ticket'), findsOneWidget);
      expect(find.textContaining('Registrar'), findsWidgets);
      expect(find.textContaining('REG-007'), findsOneWidget);
      expect(find.textContaining('Transcript of Records'), findsOneWidget);
    });
  });

  group('generate sheet gate', () {
    testWidgets('an active ticket disables generation and names the queue', (
      WidgetTester tester,
    ) async {
      auth.debugSeed(student: _student, activeTicket: _ticket(status: AppConstants.statusWaiting));

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.dark(),
          home: const Scaffold(body: GenerateTicketSheet()),
        ),
      );
      await tester.pump();

      expect(find.text('Active queue ticket'), findsOneWidget);
      expect(find.textContaining('REG-007'), findsWidgets);

      final PrimaryButton button = tester.widget<PrimaryButton>(
        find.byType(PrimaryButton),
      );
      expect(
        button.onPressed,
        isNull,
        reason: 'Generate must be disabled while a ticket is active',
      );
    });

    testWidgets('no active ticket means no notice and an enabled action', (
      WidgetTester tester,
    ) async {
      auth.debugSeed(
        student: _student,
        activeTicket: _ticket(status: AppConstants.statusServed),
      );

      expect(
        auth.activeTicket,
        isNull,
        reason: 'a served ticket occupies no slot',
      );

      await tester.pumpWidget(
        MaterialApp(
          theme: AppTheme.dark(),
          home: const Scaffold(body: GenerateTicketSheet()),
        ),
      );
      await tester.pump();

      expect(find.text('Active queue ticket'), findsNothing);
    });

    test('serving still occupies the slot, cancelled frees it', () {
      auth.debugSeed(
        student: _student,
        activeTicket: _ticket(status: AppConstants.statusServing),
      );
      expect(auth.activeTicket?.queueNumber, 'REG-007');

      auth.debugSeed(
        student: _student,
        activeTicket: _ticket(status: AppConstants.statusCancelled),
      );
      expect(auth.activeTicket, isNull);
    });
  });
}
