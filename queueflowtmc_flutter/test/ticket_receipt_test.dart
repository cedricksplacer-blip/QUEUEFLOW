import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:queueflow_tmc/core/app_constants.dart';
import 'package:queueflow_tmc/core/app_theme.dart';
import 'package:queueflow_tmc/models/queue_ticket.dart';
import 'package:queueflow_tmc/models/student.dart';
import 'package:queueflow_tmc/screens/ticket_receipt_screen.dart';

// Tall enough that the whole receipt and its action buttons are laid out at
// once: the page scrolls lazily, so an action below a phone-sized fold is
// never built and a finder would miss it.
const Size _phone = Size(390, 2600);

/// ============================================================
/// QUEUEFLOW TMC — TICKET RECEIPT
///
/// The receipt leads with the queue number the server assigned and then states
/// the ticket's own details - the campus office, the purpose of visit, the
/// priority and the date & time it was generated - followed by the student's
/// identity. These tests pin that list, and pin that the old chrome the receipt
/// used to carry (the position block, the status pill, the "please wait"
/// callout) stays gone.
/// ============================================================

void main() {
  testWidgets('the receipt leads with the assigned queue number', (
    WidgetTester tester,
  ) async {
    await _pumpReceipt(tester, ticket: _ticket());

    // The number the server issued, labelled and set large.
    expect(find.text('QUEUE NUMBER'), findsOneWidget);
    expect(find.text('R-0012'), findsOneWidget);

    // The ticket's own details.
    expect(find.text('Campus Office'), findsOneWidget);
    expect(find.text('Registrar'), findsOneWidget);
    expect(find.text('Purpose of Visit'), findsOneWidget);
    expect(find.text('Enrollment'), findsOneWidget);
    expect(find.text('Priority'), findsOneWidget);
    expect(find.text('Regular'), findsOneWidget);
    expect(find.text('Date & Time'), findsOneWidget);
    expect(find.text('2026-10-11 09:30'), findsOneWidget);

    // The student's identity is kept alongside them.
    expect(find.text('Student'), findsOneWidget);
    expect(find.text('Student ID'), findsOneWidget);
    expect(find.text('Course'), findsOneWidget);
    expect(find.text('Year Level'), findsOneWidget);
    expect(find.text('Juan Dela Cruz'), findsOneWidget);
    expect(find.text('2021-0042'), findsOneWidget);
    expect(find.text('BSIT'), findsOneWidget);
    expect(find.text('2nd Year'), findsOneWidget);
  });

  testWidgets('the receipt omits the old chrome', (WidgetTester tester) async {
    await _pumpReceipt(tester, ticket: _ticket());

    expect(find.text('YOUR QUEUE TICKET'), findsNothing);
    expect(find.text('QUEUE POSITION'), findsNothing);
    expect(find.text('Students ahead'), findsNothing);
    expect(find.text('Estimated wait'), findsNothing);
    expect(find.text('Service'), findsNothing);
    expect(find.text('Batch'), findsNothing);
    expect(find.text('WAITING'), findsNothing);
    expect(
      find.text('Please wait for your ticket number to be called.'),
      findsNothing,
    );
  });

  testWidgets('a PWD ticket reads PWD in the priority field', (
    WidgetTester tester,
  ) async {
    await _pumpReceipt(
      tester,
      ticket: _ticket(priority: AppConstants.priorityPwd),
    );

    expect(find.text('PWD'), findsOneWidget);
  });
}

Future<void> _pumpReceipt(
  WidgetTester tester, {
  required QueueTicket ticket,
}) async {
  tester.view.physicalSize = _phone;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  await tester.pumpWidget(
    MaterialApp(
      theme: AppTheme.dark(),
      home: TicketReceiptScreen(
        ticket: ticket,
        student: const Student(
          id: 7,
          name: 'Juan Dela Cruz',
          email: 'juan@tmc.edu.ph',
          course: 'BSIT',
          yearLevel: '2nd Year',
          block: '1',
          studentId: '2021-0042',
        ),
      ),
    ),
  );
  await tester.pump();
}

QueueTicket _ticket({
  String priority = AppConstants.priorityRegular,
}) {
  return QueueTicket(
    id: 42,
    queueNumber: 'R-0012',
    status: AppConstants.statusWaiting,
    priority: priority,
    purpose: 'Enrollment',
    office: const OfficeRef(id: 2, name: 'Registrar', code: 'REG-BSIT'),
    service: const ServiceRef(id: 1, name: 'Certificate of Registration'),
    createdAt: DateTime(2026, 10, 11, 9, 30),
  );
}
