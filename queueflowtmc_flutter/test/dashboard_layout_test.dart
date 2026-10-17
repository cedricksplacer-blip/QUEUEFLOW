import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';

import 'package:queueflow_tmc/core/app_constants.dart';
import 'package:queueflow_tmc/core/app_theme.dart';
import 'package:queueflow_tmc/core/glass.dart';
import 'package:queueflow_tmc/models/announcement.dart';
import 'package:queueflow_tmc/models/campus_catalog.dart';
import 'package:queueflow_tmc/models/queue_ticket.dart';
import 'package:queueflow_tmc/models/student.dart';
import 'package:queueflow_tmc/repositories/auth_repository.dart';
import 'package:queueflow_tmc/screens/dashboard_screen.dart';

const Size _phone = Size(390, 844);

const Student _student = Student(
  id: 7,
  name: 'Juan Dela Cruz',
  email: 'juan@tmc.edu.ph',
  course: 'BSIT',
  yearLevel: '2nd Year',
  block: '1',
  studentId: '2021-0042',
);

void main() {
  final AuthRepository auth = AuthRepository.instance;

  tearDown(auth.debugReset);

  Widget harness() =>
      MaterialApp(theme: AppTheme.dark(), home: const DashboardScreen());

  testWidgets('dashboard shows the profile and counters', (
    WidgetTester tester,
  ) async {
    tester.view.physicalSize = _phone;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    auth.debugSeed(
      student: _student,
      // Distinct values so each counter is individually identifiable.
      // Active is derived as waiting + serving = 3.
      counts: const TicketCounts(
        total: 9,
        waiting: 1,
        serving: 2,
        served: 5,
        today: 7,
      ),
    );

    await tester.pumpWidget(harness());
    await tester.pump(const Duration(seconds: 2));

    expect(tester.takeException(), isNull);
    expect(find.text('Juan Dela Cruz'), findsOneWidget);
    expect(find.text('BSIT • 2nd Year • Block 1'), findsOneWidget);
    expect(find.text('ID 2021-0042'), findsOneWidget);

    for (final String value in <String>['3', '1', '5', '7']) {
      expect(find.text(value), findsOneWidget, reason: 'counter $value');
    }

    expect(find.text('Active'), findsOneWidget);
    expect(find.text('Waiting'), findsOneWidget);
    expect(find.text('Served'), findsOneWidget);
    expect(find.text('Today'), findsOneWidget);
    expect(find.text('Batch'), findsOneWidget);

    // No ticket, so no batch: the tile says so rather than counting from zero.
    expect(find.text('—'), findsOneWidget);
    expect(find.text('No active ticket'), findsOneWidget);
  });

  testWidgets('the queue row shows the batch the active ticket sits in', (
    WidgetTester tester,
  ) async {
    tester.view.physicalSize = _phone;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    auth.debugSeed(
      student: _student,
      counts: const TicketCounts(waiting: 1),
      activeTicket: QueueTicket(
        id: 42,
        queueNumber: 'A-014',
        office: const OfficeRef(id: 1, name: 'Registrar', code: 'REG'),
        service: const ServiceRef(id: 2, name: 'Enrollment'),
        batch: const QueueBatch(
          id: 3,
          officeId: 1,
          batchNo: 2,
          status: 'open',
        ),
      ),
    );

    await tester.pumpWidget(harness());
    await tester.pump(const Duration(seconds: 2));

    expect(tester.takeException(), isNull);
    expect(find.text('Batch'), findsOneWidget);
    expect(find.text('2'), findsOneWidget);
  });

  testWidgets('dashboard renders a live ticket without overflow', (
    WidgetTester tester,
  ) async {
    tester.view.physicalSize = _phone;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    auth.debugSeed(
      student: _student,
      counts: const TicketCounts(waiting: 1, serving: 1),
      activeTicket: QueueTicket(
        id: 42,
        queueNumber: 'A-014',
        status: 'serving',
        office: const OfficeRef(id: 1, name: 'Registrar', code: 'REG'),
        service: const ServiceRef(id: 2, name: 'Enrollment'),
      ),
    );

    await tester.pumpWidget(harness());
    await tester.pump(const Duration(seconds: 2));

    expect(tester.takeException(), isNull);
    expect(find.text('A-014'), findsOneWidget);
    expect(find.text('YOUR TURN'), findsOneWidget);
    expect(find.text('Registrar  •  Enrollment'), findsOneWidget);
  });

  testWidgets('the profile card shows the account photograph', (
    WidgetTester tester,
  ) async {
    tester.view.physicalSize = _phone;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    auth.debugSeed(
      student: _student.copyWith(avatar: 'assets/images/student.jpg'),
    );

    await tester.pumpWidget(harness());
    await tester.pump(const Duration(seconds: 2));

    expect(tester.takeException(), isNull);
    expect(
      find.widgetWithImage(
        GlassAvatar,
        const AssetImage('assets/images/student.jpg'),
      ),
      findsOneWidget,
    );

    final Image portrait = tester.widget<Image>(
      find.descendant(
        of: find.byType(GlassAvatar),
        matching: find.byType(Image),
      ),
    );

    expect(portrait.fit, BoxFit.cover);
    // The photograph replaces the initials rather than sitting behind them.
    expect(find.text('JD'), findsNothing);
  });

  testWidgets('an account with no photograph of its own falls back', (
    WidgetTester tester,
  ) async {
    tester.view.physicalSize = _phone;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    auth.debugSeed(student: _student);

    await tester.pumpWidget(harness());
    await tester.pump(const Duration(seconds: 2));

    expect(tester.takeException(), isNull);
    // The bundled portrait, which is the constant every screen falls back to.
    expect(
      find.widgetWithImage(
        GlassAvatar,
        const AssetImage(AppConstants.profilePhoto),
      ),
      findsOneWidget,
    );
  });

testWidgets('a long student name does not break the header', (
    WidgetTester tester,
  ) async {
    tester.view.physicalSize = const Size(320, 640);
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    auth.debugSeed(
      student: Student(
        id: 8,
        name: 'Maximillian Christopheretheus Dela Cruz III',
        email: 'max@tmc.edu.ph',
        course: 'BSED',
        yearLevel: '4th Year',
        block: '12',
        studentId: '2019-1234',
      ),
    );

    await tester.pumpWidget(harness());
    await tester.pump(const Duration(seconds: 2));

    expect(tester.takeException(), isNull);
  });

  testWidgets('an idle dashboard offers a ticket and no cancellation', (
    WidgetTester tester,
  ) async {
    tester.view.physicalSize = _phone;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    auth.debugSeed(student: _student);

    await tester.pumpWidget(harness());
    await tester.pump(const Duration(seconds: 2));

    expect(tester.takeException(), isNull);
    expect(find.text('Take a ticket'), findsOneWidget);
    expect(find.text('Cancel this ticket'), findsNothing);
  });

  testWidgets('at the ticket limit the primary action opens the ticket', (
    WidgetTester tester,
  ) async {
    tester.view.physicalSize = _phone;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    // The campus ships a limit of one active ticket per student, so
    // offering "take another" here would only ever earn a refusal.
    auth.debugSeed(
      student: _student,
      counts: const TicketCounts(waiting: 1),
      activeTicket: QueueTicket(
        id: 42,
        queueNumber: 'A-014',
        office: const OfficeRef(id: 1, name: 'Registrar', code: 'REG'),
        service: const ServiceRef(id: 2, name: 'Enrollment'),
      ),
    );

    await tester.pumpWidget(harness());
    await tester.pump(const Duration(seconds: 2));

    expect(tester.takeException(), isNull);
    expect(find.text('View my ticket'), findsOneWidget);
    expect(find.text('Take another ticket'), findsNothing);
  });

  testWidgets('a waiting ticket can be cancelled from the dashboard', (
    WidgetTester tester,
  ) async {
    tester.view.physicalSize = _phone;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    auth.debugSeed(
      student: _student,
      counts: const TicketCounts(waiting: 1),
      activeTicket: QueueTicket(
        id: 42,
        queueNumber: 'A-014',
        office: const OfficeRef(id: 1, name: 'Registrar', code: 'REG'),
        service: const ServiceRef(id: 2, name: 'Enrollment'),
      ),
    );

    await tester.pumpWidget(harness());
    await tester.pump(const Duration(seconds: 2));

    expect(tester.takeException(), isNull);
    expect(find.text('Cancel this ticket'), findsOneWidget);
  });

  testWidgets('a called ticket cannot be cancelled from the dashboard', (
    WidgetTester tester,
  ) async {
    tester.view.physicalSize = _phone;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    auth.debugSeed(
      student: _student,
      counts: const TicketCounts(serving: 1),
      activeTicket: QueueTicket(
        id: 42,
        queueNumber: 'A-014',
        status: 'serving',
        office: const OfficeRef(id: 1, name: 'Registrar', code: 'REG'),
        service: const ServiceRef(id: 2, name: 'Enrollment'),
      ),
    );

    await tester.pumpWidget(harness());
    await tester.pump(const Duration(seconds: 2));

    expect(tester.takeException(), isNull);
    expect(find.text('Cancel this ticket'), findsNothing);
  });

  testWidgets('the shortcut row and the identity card are reachable', (
    WidgetTester tester,
  ) async {
    tester.view.physicalSize = _phone;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    auth.debugSeed(
      student: _student,
      announcements: const <Announcement>[
        Announcement(
          id: 1,
          title: 'Registrar opens at 8am',
          body: 'The Registrar counter opens at 8am.',
        ),
      ],
    );

    await tester.pumpWidget(harness());
    await tester.pump(const Duration(seconds: 2));

    expect(tester.takeException(), isNull);

    for (final String label in <String>[
      'My ticket',
      'Statistics',
      'History',
    ]) {
      expect(find.text(label), findsOneWidget, reason: label);
    }

    // Notices are reached from the announcement card, not the shortcut row.
    expect(find.text('Notices'), findsNothing);

    // The notice on the dashboard is the same one the feed will show.
    expect(find.text('Registrar opens at 8am'), findsOneWidget);
  });

  testWidgets('the live position strip reports an unreadable queue', (
    WidgetTester tester,
  ) async {
    tester.view.physicalSize = _phone;
    tester.view.devicePixelRatio = 1.0;
    addTearDown(tester.view.reset);

    auth.debugSeed(
      student: _student,
      counts: const TicketCounts(waiting: 1),
      activeTicket: QueueTicket(
        id: 42,
        queueNumber: 'A-014',
        office: const OfficeRef(id: 1, name: 'Registrar', code: 'REG'),
        service: const ServiceRef(id: 2, name: 'Enrollment'),
      ),
    );

    await tester.pumpWidget(harness());
    await tester.pump(const Duration(seconds: 2));

    expect(tester.takeException(), isNull);
    // No read has happened, and the dashboard is not hosted in a shell
    // here, so the strip must say it is waiting rather than claim the
    // student is first in line.
    expect(find.text('Reading your place in the queue...'), findsOneWidget);
  });
}
