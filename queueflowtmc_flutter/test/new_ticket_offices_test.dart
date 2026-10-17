import 'dart:convert';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:queueflow_tmc/core/api/api_client.dart';
import 'package:queueflow_tmc/core/qf_value.dart';

import 'package:queueflow_tmc/core/app_constants.dart';
import 'package:queueflow_tmc/core/app_theme.dart';
import 'package:queueflow_tmc/core/network/connectivity_service.dart';
import 'package:queueflow_tmc/models/app_user.dart';
import 'package:queueflow_tmc/models/campus_catalog.dart';
import 'package:queueflow_tmc/models/queue_ticket.dart';
import 'package:queueflow_tmc/models/student.dart';
import 'package:queueflow_tmc/models/student_bootstrap.dart';
import 'package:queueflow_tmc/repositories/auth_repository.dart';
import 'package:queueflow_tmc/screens/new_ticket_screen.dart';
import 'package:queueflow_tmc/services/local_backend.dart';
import 'package:queueflow_tmc/services/campus_backend_factory.dart';
import 'package:queueflow_tmc/services/remote_backend.dart';

const Size _phone = Size(390, 844);

/// ============================================================
/// QUEUEFLOW TMC — TAKING A TICKET AT ANY OFFICE
///
/// A student must be able to take a number at every office the catalogue
/// lists. The seed starts every office with no deployed batch, so before this
/// change nothing was selectable and the page looked broken.
///
/// The backend half runs against the real seeded campus, because the gate
/// that blocked this lived in `createTicket` and a hand-seeded widget test
/// would have hidden it.
/// ============================================================

void main() {
  final LocalBackend local = LocalBackend.instance;

  setUp(() async {
    SharedPreferences.setMockInitialValues(<String, Object>{});
    local.debugReset();
    await local.ready();
    ConnectivityService.instance.debugReset();
  });

  tearDown(() {
    local.debugReset();
    AuthRepository.instance.debugReset();
    CampusBackendFactory.instance.overrideForTesting(null);
    ConnectivityService.instance.debugReset();
  });

  group('the backend lets any active office issue a number', () {
    test(
      'every active office issues a number unless its batch is full',
      () async {
        final List<Office> offices = await local.offices();
        final List<Office> active = offices
            .where((Office o) => o.isActive)
            .toList(growable: false);

        expect(active, isNotEmpty, reason: 'the seed carries active offices');

        // `accepting` folds in the clock and whether a batch is deployed, so it
        // depends on when this runs. The point of the test is that
        // createTicket ignores it, so assert across the whole active catalogue
        // rather than picking offices by their current status.
        final Set<int> refused = <int>{};

        for (final Office office in active) {
          final AppUser user = await _freshStudent();

          if (office.activeBatch?.isFull ?? false) {
            // A full batch is a real capacity limit, not an hours rule, and is
            // deliberately left in place.
            await expectLater(
              _take(user, office),
              throwsA(
                isA<LocalBackendException>().having(
                  (LocalBackendException e) => e.message,
                  'message',
                  contains('full'),
                ),
              ),
              reason: 'office ${office.code} has a full batch',
            );
            refused.add(office.id);
            continue;
          }

          final QueueTicket ticket = await _take(user, office);

          expect(
            ticket.office?.id,
            office.id,
            reason: 'office ${office.code} refused a number',
          );
          expect(ticket.status, AppConstants.statusWaiting);
          expect(ticket.queueNumber, isNotEmpty);
        }

        // The loop must actually have issued numbers, otherwise a change that
        // refused everything would still read as a pass.
        expect(
          active.length - refused.length,
          greaterThan(0),
          reason: 'at least one office must have issued a number',
        );
      },
    );

    test('an office an administrator switched off still refuses', () async {
      final AppUser user = await _freshStudent();

      final List<Office> inactive = (await local.offices())
          .where((Office o) => !o.isActive)
          .toList();

      // The seed carries a closed office on purpose; without one this test
      // would pass by never running its assertion.
      expect(inactive, isNotEmpty);

      await expectLater(
        _take(user, inactive.first),
        throwsA(isA<LocalBackendException>()),
      );
    });

    test('a number taken at an open office is servable by staff', () async {
      final AppUser user = await _freshStudent();
      final List<Office> offices = await local.offices();

      final Office office = offices.firstWhere(
        (Office o) => o.isActive && !(o.activeBatch?.isFull ?? false),
      );

      final QueueTicket ticket = await _take(user, office);

      // Called by id rather than with `callNext(officeId:)`, because the seed
      // leaves other students already waiting in this office and the counter
      // would serve the oldest of them, not ours.
      final QueueTicket called = await local.callNext(ticketId: ticket.id);

      // The counter works from the ticket's own office rather than its batch,
      // which is why a student may queue before a batch exists and still be
      // called once the office opens.
      expect(called.id, ticket.id);
      expect(called.office?.id, office.id);
      expect(called.status, AppConstants.statusServing);
    });
  });

  group('the on-device campus answers the registrar course list', () {
    // The demo campus runs one undivided Registrar counter rather than seven
    // per-course desks, so the list has to be derived from the courses attached
    // to that office. Reading only the `REG-` code prefix would leave the
    // card's picker permanently empty on a build with no server.
    test('every course the registrar office serves gets a row', () async {
      final AppUser user = await _freshStudent();

      final RegistrarCourseList list = await local.registrarCourses(user);

      expect(list.isEmpty, isFalse, reason: 'the demo seeds a Registrar');

      final Office registrar = (await local.offices()).firstWhere(
        (Office o) => o.code == 'REG',
      );

      for (final RegistrarCourseOption option in list.courses) {
        expect(option.officeId, registrar.id);
        expect(option.code, isNotEmpty);
        expect(
          option.services,
          isNotEmpty,
          reason: '${option.code} needs a service to submit a ticket with',
        );
      }

      // Totals have to add up, because the card shows that sum as the campus
      // figure rather than any single course's batch.
      expect(
        list.totalAvailableSlots,
        list.courses.fold<int>(
          0,
          (int sum, RegistrarCourseOption c) => sum + c.availableSlots,
        ),
      );
      expect(
        list.totalSlots,
        list.courses.fold<int>(
          0,
          (int sum, RegistrarCourseOption c) => sum + c.totalSlots,
        ),
      );
    });

    test("the student's own course is the flagged one", () async {
      final AppUser user = await _freshStudent();

      final RegistrarCourseList list = await local.registrarCourses(user);

      final List<RegistrarCourseOption> mine = list.courses
          .where((RegistrarCourseOption c) => c.isEnrolledCourse)
          .toList();

      expect(mine, hasLength(1));
      expect(mine.single.code, 'BSIT');
      expect(list.enrolledOption(), isNotNull);
    });
  });

  group('the screen offers every office', () {
    const OfficeService cashierService = OfficeService(
      id: 1,
      officeId: 1,
      stationId: 1,
      name: 'Tuition payment',
      isActive: true,
    );

    const OfficeService registrarService = OfficeService(
      id: 4,
      officeId: 2,
      stationId: 2,
      name: 'Certificate of Registration',
      isActive: true,
    );

    const Office open = Office(
      id: 1,
      name: 'Cashier',
      code: 'CASH',
      openingTime: '07:00',
      closingTime: '17:00',
      status: AppConstants.officeStatusOnline,
      accepting: true,
      services: <OfficeService>[cashierService],
    );

    // A student without a course of their own in the list still gets the
    // Registrar card, with the course queues inside it.
    const Office registrar = Office(
      id: 2,
      name: 'Registrar',
      code: 'REG-BSIT',
      openingTime: '07:00',
      closingTime: '17:00',
      status: AppConstants.officeStatusOnline,
      services: <OfficeService>[registrarService],
    );

    const Office switchedOff = Office(
      id: 4,
      name: 'School Clinic',
      code: 'CLNC',
      status: AppConstants.officeStatusOnline,
      isActive: false,
    );

    testWidgets('a closed office is listed and can be chosen', (
      WidgetTester tester,
    ) async {
      tester.view.physicalSize = _phone;
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      // The screen re-reads the campus on entry (that is where the
      // online/offline switch is), so the transport that answers it has to be
      // in place before the widget builds rather than after.
      CampusBackendFactory.instance.overrideForTesting(
        _stubShell(
          student: const Student(
            id: 7,
            name: 'Juan Dela Cruz',
            email: 'juan@tmc.edu.ph',
            course: 'BSIT',
            yearLevel: '2nd Year',
            block: '1',
            studentId: '2021-0042',
          ),
          offices: const <Office>[open, registrar, switchedOff],
        ),
      );

      AuthRepository.instance.debugSeed(
        student: const Student(
          id: 7,
          name: 'Juan Dela Cruz',
          email: 'juan@tmc.edu.ph',
          course: 'BSIT',
          yearLevel: '2nd Year',
          block: '1',
          studentId: '2021-0042',
        ),
        offices: const <Office>[open, registrar, switchedOff],
        registrar: _registrarList(enrolled: 'BSIT'),
      );

      await tester.pumpWidget(
        MaterialApp(theme: AppTheme.dark(), home: const NewTicketScreen()),
      );
      await tester.pump(const Duration(milliseconds: 600));

      // All three names are visible: the catalogue is not silently trimmed.
      expect(find.text('Cashier'), findsOneWidget);
      expect(find.text('Registrar'), findsOneWidget);

      // The switched-off office sits under the fold once the Registrar card
      // takes the space a row used to have, so scroll to it rather than
      // assuming one screen holds everything.
      await tester.dragUntilVisible(
        find.text('School Clinic'),
        find.byType(ListView),
        const Offset(0, -200),
      );
      await tester.pump(const Duration(milliseconds: 400));

      expect(find.text('School Clinic'), findsOneWidget);
      expect(tester.takeException(), isNull);

      // Every office shows its own live "issued/total" reading - none of these
      // have a deployed batch, so each reads 0/100 - with no online label
      // beside its name.
      expect(find.text('0/100'), findsWidgets);
      expect(find.text('Open now'), findsNothing);
      expect(find.text('Offline now'), findsNothing);

      // Selecting the office reveals the rest of the form directly - the
      // service is resolved automatically instead of being offered.
      await tester.tap(find.text('Cashier'));
      await tester.pump(const Duration(milliseconds: 600));

      expect(tester.takeException(), isNull);
      expect(find.text('SELECT SERVICE AT CASHIER'), findsNothing);

      // The rest of the form is below the fold on a phone, so scroll to the
      // end and confirm an office leads all the way to a live submit rather
      // than dead-ending partway down the page.
      await tester.dragUntilVisible(
        find.text('Generate ticket number'),
        find.byType(ListView),
        const Offset(0, -240),
      );
      await tester.pump(const Duration(milliseconds: 600));

      expect(tester.takeException(), isNull);
      expect(find.text('Generate ticket number'), findsOneWidget);
    });

    testWidgets('the registrar queue is auto-assigned from the account', (
      WidgetTester tester,
    ) async {
      tester.view.physicalSize = _phone;
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      CampusBackendFactory.instance.overrideForTesting(
        _stubShell(
          student: const Student(
            id: 7,
            name: 'Juan Dela Cruz',
            email: 'juan@tmc.edu.ph',
            course: 'BSIT',
            yearLevel: '2nd Year',
            block: '1',
            studentId: '2021-0042',
          ),
          offices: const <Office>[open, registrar, switchedOff],
          enrolled: 'BSIT',
        ),
      );

      AuthRepository.instance.debugSeed(
        student: const Student(
          id: 7,
          name: 'Juan Dela Cruz',
          email: 'juan@tmc.edu.ph',
          course: 'BSIT',
          yearLevel: '2nd Year',
          block: '1',
          studentId: '2021-0042',
        ),
        offices: const <Office>[open, registrar, switchedOff],
        registrar: _registrarList(enrolled: 'BSIT'),
      );

      await tester.pumpWidget(
        MaterialApp(theme: AppTheme.dark(), home: const NewTicketScreen()),
      );
      await tester.pump(const Duration(milliseconds: 600));

      // Collapsed: other course queues must not read as rows.
      expect(find.text('BSOA'), findsNothing);

      await tester.tap(find.text('Registrar'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 120));

      // No manual picker: the student's own queue is assigned from the
      // account, with its live batch reading. Other courses are never
      // listed as choices.
      expect(find.text('BSIT — your Registrar queue'), findsOneWidget);
      expect(find.text('BSOA'), findsNothing);
      expect(find.text('Online'), findsWidgets);
    });

    testWidgets('no manual course pick can move a student to another queue', (
      WidgetTester tester,
    ) async {
      tester.view.physicalSize = _phone;
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      CampusBackendFactory.instance.overrideForTesting(
        _stubShell(
          student: const Student(
            id: 7,
            name: 'Juan Dela Cruz',
            email: 'juan@tmc.edu.ph',
            course: 'BSIT',
            yearLevel: '2nd Year',
            block: '1',
            studentId: '2021-0042',
          ),
          offices: const <Office>[open, registrar, switchedOff],
          enrolled: 'BSIT',
        ),
      );

      AuthRepository.instance.debugSeed(
        student: const Student(
          id: 7,
          name: 'Juan Dela Cruz',
          email: 'juan@tmc.edu.ph',
          course: 'BSIT',
          yearLevel: '2nd Year',
          block: '1',
          studentId: '2021-0042',
        ),
        offices: const <Office>[open, registrar, switchedOff],
        registrar: _registrarList(enrolled: 'BSIT'),
      );

      await tester.pumpWidget(
        MaterialApp(theme: AppTheme.dark(), home: const NewTicketScreen()),
      );
      await tester.pump(const Duration(milliseconds: 600));

      await tester.tap(find.text('Registrar'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 120));

      // Auto-assigned from the account: the BSIT queue is shown and no other
      // course can be picked into the form. There is no cross-course warning
      // because there is no manual choice left to warn about.
      expect(find.text('BSIT — your Registrar queue'), findsOneWidget);
      expect(find.text('BSOA'), findsNothing);
      expect(
        find.textContaining('Your profile is enrolled in BSIT'),
        findsNothing,
      );

      await tester.dragUntilVisible(
        find.text('Generate ticket number'),
        find.byType(ListView),
        const Offset(0, -240),
      );
      await tester.pump(const Duration(milliseconds: 600));

      expect(tester.takeException(), isNull);
      expect(find.text('Generate ticket number'), findsOneWidget);
    });

    testWidgets('every listed office shows its own live slot reading', (
      WidgetTester tester,
    ) async {
      tester.view.physicalSize = _phone;
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      CampusBackendFactory.instance.overrideForTesting(
        _stubShell(
          student: const Student(
            id: 7,
            name: 'Juan Dela Cruz',
            email: 'juan@tmc.edu.ph',
            course: 'BSIT',
            yearLevel: '2nd Year',
            block: '1',
            studentId: '2021-0042',
          ),
          offices: const <Office>[open, registrar, switchedOff],
        ),
      );

      AuthRepository.instance.debugSeed(
        student: const Student(
          id: 7,
          name: 'Juan Dela Cruz',
          email: 'juan@tmc.edu.ph',
          course: 'BSIT',
          yearLevel: '2nd Year',
          block: '1',
          studentId: '2021-0042',
        ),
        offices: const <Office>[open, registrar, switchedOff],
        registrar: _registrarList(enrolled: 'BSIT'),
      );

      await tester.pumpWidget(
        MaterialApp(theme: AppTheme.dark(), home: const NewTicketScreen()),
      );
      await tester.pump(const Duration(milliseconds: 600));

      // No "N available" count and no online wording: the offices are simply
      // listed, each carrying its own live issued/total reading (0/100 with no
      // deployed batch).
      expect(find.text('2 available'), findsNothing);
      expect(find.text('0/100'), findsWidgets);
      expect(find.text('Cashier'), findsOneWidget);
    });

    testWidgets('a full batch is still listed and locked', (
      WidgetTester tester,
    ) async {
      tester.view.physicalSize = _phone;
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      // Mirrors what the server writes the moment a batch fills: the open
      // batch disappears (`open_batch` null), the flat counters are resolved
      // against it (used 0, slots_deployed false), but the now-full
      // `active_batch` still reports `submitted_count = 100`. The client has
      // to trust the batch, not the flat numbers, to read "full" at all.
      final Office cashierFull = Office.fromMap(<String, dynamic>{
        'id': 9,
        'name': 'Cashier',
        'code': 'CASH',
        'description': 'Tuition and fees counter',
        'is_active': true,
        'status': AppConstants.officeStatusOnline,
        'opening_time': '07:00',
        'closing_time': '17:00',
        'max_slots': 100,
        'window_open': true,
        'accepting': false,
        'open_batch': null,
        'active_batch': <String, dynamic>{
          'id': 3,
          'batch_no': 2,
          'max_slots': 100,
          'submitted_count': 100,
          'status': 'full',
        },
        'waiting_count': 12,
        'serving_count': 2,
        'slots_deployed': false,
        'used_slots': 0,
        'available_slots': 0,
        'total_slots': 100,
        'queue_status': 'full',
        'services': <Map<String, dynamic>>[
          <String, dynamic>{
            'id': 91,
            'office_id': 9,
            'name': 'Tuition payment',
            'is_active': true,
          },
        ],
      });

      CampusBackendFactory.instance.overrideForTesting(
        _stubShell(
          student: const Student(
            id: 7,
            name: 'Juan Dela Cruz',
            email: 'juan@tmc.edu.ph',
            course: 'BSIT',
            yearLevel: '2nd Year',
            block: '1',
            studentId: '2021-0042',
          ),
          offices: <Office>[open, registrar, cashierFull],
        ),
      );

      AuthRepository.instance.debugSeed(
        student: const Student(
          id: 7,
          name: 'Juan Dela Cruz',
          email: 'juan@tmc.edu.ph',
          course: 'BSIT',
          yearLevel: '2nd Year',
          block: '1',
          studentId: '2021-0042',
        ),
        offices: <Office>[open, registrar, cashierFull],
        registrar: _registrarList(enrolled: 'BSIT'),
      );

      await tester.pumpWidget(
        MaterialApp(theme: AppTheme.dark(), home: const NewTicketScreen()),
      );
      await tester.pump(const Duration(milliseconds: 600));

      // The full counter's own reading (100/100) sits on the tile, with no
      // "batch full" verdict spelled out beside the name - the row is simply
      // locked.
      expect(find.text('100/100'), findsWidgets);
      expect(find.text('Batch full'), findsNothing);

      // A full counter must not be selectable: tapping it reveals nothing, so
      // no service picker appears for it.
      await tester.tap(find.text('Cashier').first);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));

      expect(find.text('Select service at Cashier'), findsNothing);
      expect(tester.takeException(), isNull);
    });

    // A counter that is not accepting stays visible - the student still sees
    // that it exists - but it is locked, with no online/offline label beside
    // its name and the same fixed slot reading as every other office.
    const Office admissionsOffline = Office(
      id: 5,
      name: 'Admissions',
      code: 'ADM',
      openingTime: '07:00',
      closingTime: '17:00',
      status: AppConstants.officeStatusOffline,
    );

    testWidgets('a counter that is not accepting is listed but locked', (
      WidgetTester tester,
    ) async {
      tester.view.physicalSize = _phone;
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      CampusBackendFactory.instance.overrideForTesting(
        _stubShell(
          student: const Student(
            id: 7,
            name: 'Juan Dela Cruz',
            email: 'juan@tmc.edu.ph',
            course: 'BSIT',
            yearLevel: '2nd Year',
            block: '1',
            studentId: '2021-0042',
          ),
          offices: const <Office>[
            open,
            registrar,
            switchedOff,
            admissionsOffline,
          ],
        ),
      );

      AuthRepository.instance.debugSeed(
        student: const Student(
          id: 7,
          name: 'Juan Dela Cruz',
          email: 'juan@tmc.edu.ph',
          course: 'BSIT',
          yearLevel: '2nd Year',
          block: '1',
          studentId: '2021-0042',
        ),
        offices: const <Office>[
          open,
          registrar,
          switchedOff,
          admissionsOffline,
        ],
        registrar: _registrarList(enrolled: 'BSIT'),
      );

      await tester.pumpWidget(
        MaterialApp(theme: AppTheme.dark(), home: const NewTicketScreen()),
      );
      await tester.pump(const Duration(milliseconds: 600));

      // The offline office is under the fold on a phone, so scroll to it. The
      // active-but-offline counter keeps its own locked list rather than being
      // demoted next to the inactive ones.
      await tester.dragUntilVisible(
        find.text('Admissions'),
        find.byType(ListView),
        const Offset(0, -200),
      );
      await tester.pump(const Duration(milliseconds: 400));

      expect(find.text('Admissions'), findsOneWidget);
      // No online/offline wording beside the name, and the live slot reading.
      expect(find.text('Offline now'), findsNothing);
      expect(find.text('0/100'), findsWidgets);
      expect(tester.takeException(), isNull);

      // And tapping it does not open a service list: the gate is enforced on
      // the tile, not left to a 422.
      await tester.tap(find.text('Admissions'), warnIfMissed: false);
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));

      expect(find.text('SELECT SERVICE AT ADMISSIONS'), findsNothing);
      expect(tester.takeException(), isNull);
    });

    // The Registrar card reports the whole group's switch too: offline means
    // the slot total is hidden and every course row is locked, not "full".
    testWidgets('a Registrar not accepting locks its courses in place', (
      WidgetTester tester,
    ) async {
      tester.view.physicalSize = _phone;
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      const Office registrarOffline = Office(
        id: 6,
        name: 'Registrar',
        code: 'REG-BSIT',
        openingTime: '07:00',
        closingTime: '17:00',
        status: AppConstants.officeStatusOffline,
      );

      CampusBackendFactory.instance.overrideForTesting(
        _stubShell(
          student: const Student(
            id: 7,
            name: 'Juan Dela Cruz',
            email: 'juan@tmc.edu.ph',
            course: 'BSIT',
            yearLevel: '2nd Year',
            block: '1',
            studentId: '2021-0042',
          ),
          offices: const <Office>[open, registrarOffline, switchedOff],
          // The server stamps every course row with the office's own offline
          // status (PortalController::registrarCourses), and the rows decode
          // that into locked entries.
          registrarPayload: _registrarPayload(
            enrolled: 'BSIT',
            status: AppConstants.officeStatusOffline,
          ),
        ),
      );

      AuthRepository.instance.debugSeed(
        student: const Student(
          id: 7,
          name: 'Juan Dela Cruz',
          email: 'juan@tmc.edu.ph',
          course: 'BSIT',
          yearLevel: '2nd Year',
          block: '1',
          studentId: '2021-0042',
        ),
        offices: const <Office>[open, registrarOffline, switchedOff],
        registrar: _registrarList(enrolled: 'BSIT'),
      );

      await tester.pumpWidget(
        MaterialApp(theme: AppTheme.dark(), home: const NewTicketScreen()),
      );
      await tester.pump(const Duration(milliseconds: 600));

      // No count and no online/offline wording anywhere on the page.
      expect(find.text('1 available'), findsNothing);
      expect(find.text('Offline now'), findsNothing);

      await tester.tap(find.text('Registrar'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 120));

      // The card opens onto the student's own assigned queue. It is offline
      // per the payload, so it shows the same Offline pill the other office
      // cards use instead of a slot count. No other course is listed, so no
      // other queue can be picked into the form.
      expect(find.text('BSIT — your Registrar queue'), findsOneWidget);
      expect(find.text('BSOA'), findsNothing);
      expect(find.text('Offline'), findsWidgets);

      expect(tester.takeException(), isNull);
    });
  });

  // ============================================================
  // THE SWITCH IS READ, NEVER GUESSED
  //
  // The client's default for `status` is deliberately the pessimistic one.
  // A row the server never vouched for must not become a counter the app
  // offers a student, because the one claim this feature exists to prevent
  // is an office shown as open when nobody has said so.
  // ============================================================
  group('the status the screen trusts is the status the server wrote', () {
    test('a payload that never says is a counter that is not open', () {
      final Office missing = Office.fromMap(<String, dynamic>{
        'id': 11,
        'name': 'Admissions',
        'code': 'ADM',
        'is_active': true,
      });

      expect(missing.status, AppConstants.officeStatusOffline);
      expect(missing.isOnline, isFalse);

      // A blank is the same silence as an absent field, not a value.
      final Office blank = Office.fromMap(<String, dynamic>{
        'id': 12,
        'name': 'Cashier',
        'code': 'CASH',
        'status': '   ',
      });

      expect(blank.isOnline, isFalse);
    });

    test('a course queue row with no switch is locked', () {
      final RegistrarCourseOption option = RegistrarCourseOption.fromMap(
        <String, dynamic>{
          'office_id': 60,
          'office_name': 'Registrar - BSIT',
          'course_code': 'BSIT',
          'course': <String, dynamic>{
            'id': 10,
            'code': 'BSIT',
            'name': 'BS in Information Technology',
          },
        },
      );

      expect(option.isOnline, isFalse);
      // The row is also the office the form would queue at, so the lock has
      // to survive the conversion rather than being read only in the picker.
      expect(option.office.isOnline, isFalse);
    });

    test('only the switch moves when a fresh read lands', () {
      const Office base = Office(
        id: 1,
        name: 'Cashier',
        code: 'CASH',
        openingTime: '07:00',
        closingTime: '17:00',
        status: AppConstants.officeStatusOnline,
        accepting: true,
      );

      final Office corrected = base.withStatus(
        AppConstants.officeStatusOffline,
      );

      expect(corrected.isOnline, isFalse);
      expect(corrected.id, base.id);
      expect(corrected.accepting, base.accepting);
      expect(corrected.openingTime, base.openingTime);
      expect(corrected.maxSlots, base.maxSlots);
    });
  });

  group('the screen keeps up with the switch', () {
    // The office these two tests queue at. Stated here rather than reusing
    // the catalogue of the group above: what matters is that the switch moves
    // under a form that is already filled in, not which offices exist.
    const OfficeService tuition = OfficeService(
      id: 1,
      officeId: 1,
      stationId: 1,
      name: 'Tuition payment',
      isActive: true,
    );

    const Office cashierOnline = Office(
      id: 1,
      name: 'Cashier',
      code: 'CASH',
      openingTime: '07:00',
      closingTime: '17:00',
      status: AppConstants.officeStatusOnline,
      accepting: true,
      services: <OfficeService>[tuition],
    );

    testWidgets('a counter closed mid-form stops the ticket and says why', (
      WidgetTester tester,
    ) async {
      tester.view.physicalSize = _phone;
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      // The shell and the status endpoint start out agreeing, which is what
      // lets the student fill the form in. The list is the same object the
      // test mutates, so the switch can move at any point in the flow - which
      // is exactly the situation the pre-submit check exists for.
      final List<Office> liveStatus = <Office>[cashierOnline];

      CampusBackendFactory.instance.overrideForTesting(
        _stubShell(
          student: const Student(
            id: 7,
            name: 'Juan Dela Cruz',
            email: 'juan@tmc.edu.ph',
            course: 'BSIT',
            yearLevel: '2nd Year',
            block: '1',
            studentId: '2021-0042',
          ),
          offices: const <Office>[cashierOnline],
          courses: <Course>[
            const Course(id: 1, code: 'BSIT', name: 'BS in IT'),
          ],
          statusOffices: liveStatus,
        ),
      );

      AuthRepository.instance.debugSeed(
        student: const Student(
          id: 7,
          name: 'Juan Dela Cruz',
          email: 'juan@tmc.edu.ph',
          course: 'BSIT',
          yearLevel: '2nd Year',
          block: '1',
          studentId: '2021-0042',
        ),
        offices: const <Office>[cashierOnline],
        courses: <Course>[
          const Course(id: 1, code: 'BSIT', name: 'BS in IT'),
        ],
        registrar: _registrarList(enrolled: 'BSIT'),
      );

      ConnectivityService.instance.debugSetServerReachable();

      await tester.pumpWidget(
        MaterialApp(theme: AppTheme.dark(), home: const NewTicketScreen()),
      );
      await tester.pump(const Duration(milliseconds: 600));

      await tester.tap(find.text('Cashier'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));

      await tester.dragUntilVisible(
        find.text('Generate ticket number'),
        find.byType(ListView),
        const Offset(0, -240),
      );
      await tester.pump(const Duration(milliseconds: 600));

      // The staff member shuts the counter while the form is open. Nothing
      // on screen changes by itself: the list is only as new as the last
      // read, which is the whole reason there is one more before the ticket.
      liveStatus[0] = const Office(
        id: 1,
        name: 'Cashier',
        code: 'CASH',
        openingTime: '07:00',
        closingTime: '17:00',
        status: AppConstants.officeStatusOffline,
        accepting: true,
        services: <OfficeService>[tuition],
      );

      await tester.tap(find.text('Generate ticket number'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));

      // The refusal names the counter and the rule, rather than the
      // connection problem the gate would otherwise report.
      expect(find.text('Office Offline'), findsOneWidget);
      expect(
        find.textContaining(
          'The Cashier is no longer accepting queue tickets at this time.',
        ),
        findsOneWidget,
      );
      expect(find.text('OFFICIAL QUEUE RECEIPT'), findsNothing);
      expect(tester.takeException(), isNull);

      await tester.tap(find.text('Got it'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));

      // And the list is caught up with it: the cashier stays listed, locked,
      // and with no online/offline wording beside its name.
      expect(find.text('Office Offline'), findsNothing);
      expect(find.text('Cashier'), findsOneWidget);
      expect(find.text('Offline now'), findsNothing);
      expect(find.text('0/100'), findsWidgets);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a refused ticket is reported with the server reason', (
      WidgetTester tester,
    ) async {
      tester.view.physicalSize = _phone;
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      // The server is reachable and the office is online, so the request is
      // actually sent - and refused with the campus's own sentence. The screen
      // must repeat *that*, not the connection state the gate happened to see.
      CampusBackendFactory.instance.overrideForTesting(
        _stubShell(
          student: const Student(
            id: 7,
            name: 'Juan Dela Cruz',
            email: 'juan@tmc.edu.ph',
            course: 'BSIT',
            yearLevel: '2nd Year',
            block: '1',
            studentId: '2021-0042',
          ),
          offices: const <Office>[cashierOnline],
          courses: <Course>[
            const Course(id: 1, code: 'BSIT', name: 'BS in IT'),
          ],
          ticketRefusal:
              'You already have an active queue ticket. '
              'Cancel it before taking another.',
        ),
      );

      AuthRepository.instance.debugSeed(
        student: const Student(
          id: 7,
          name: 'Juan Dela Cruz',
          email: 'juan@tmc.edu.ph',
          course: 'BSIT',
          yearLevel: '2nd Year',
          block: '1',
          studentId: '2021-0042',
        ),
        offices: const <Office>[cashierOnline],
        courses: <Course>[
          const Course(id: 1, code: 'BSIT', name: 'BS in IT'),
        ],
        registrar: _registrarList(enrolled: 'BSIT'),
      );

      ConnectivityService.instance.debugSetServerReachable();

      await tester.pumpWidget(
        MaterialApp(theme: AppTheme.dark(), home: const NewTicketScreen()),
      );
      await tester.pump(const Duration(milliseconds: 600));

      await tester.tap(find.text('Cashier'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));

      await tester.dragUntilVisible(
        find.text('Generate ticket number'),
        find.byType(ListView),
        const Offset(0, -240),
      );
      await tester.pump(const Duration(milliseconds: 600));

      await tester.tap(find.text('Generate ticket number'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 600));

      expect(
        find.textContaining('You already have an active queue ticket'),
        findsOneWidget,
      );
      expect(find.text('Connected'), findsNothing);
      expect(find.text('OFFICIAL QUEUE RECEIPT'), findsNothing);
      expect(tester.takeException(), isNull);
    });

    testWidgets('a status read nothing answers is said out loud', (
      WidgetTester tester,
    ) async {
      tester.view.physicalSize = _phone;
      tester.view.devicePixelRatio = 1.0;
      addTearDown(tester.view.reset);

      CampusBackendFactory.instance.overrideForTesting(
        _stubShell(
          student: const Student(
            id: 7,
            name: 'Juan Dela Cruz',
            email: 'juan@tmc.edu.ph',
            course: 'BSIT',
            yearLevel: '2nd Year',
            block: '1',
            studentId: '2021-0042',
          ),
          offices: const <Office>[cashierOnline],
          statusUnreachable: true,
        ),
      );

      AuthRepository.instance.debugSeed(
        student: const Student(
          id: 7,
          name: 'Juan Dela Cruz',
          email: 'juan@tmc.edu.ph',
          course: 'BSIT',
          yearLevel: '2nd Year',
          block: '1',
          studentId: '2021-0042',
        ),
        offices: const <Office>[cashierOnline],
        registrar: _registrarList(enrolled: 'BSIT'),
      );

      await tester.pumpWidget(
        MaterialApp(theme: AppTheme.dark(), home: const NewTicketScreen()),
      );
      await tester.pump(const Duration(milliseconds: 600));

      // The wording has to separate "the server is not there" from "the
      // server said no", because the two send the reader to do different
      // things - and only one of them is fixed by pressing Try again.
      expect(find.text('Server Offline'), findsOneWidget);
      expect(
        find.textContaining('Unable to connect to QueueFlow TMC.'),
        findsOneWidget,
      );
      expect(find.text('Try again'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });
  });
}

/// Every course queue the campus runs, with [enrolled] flagged as the
/// student's own.
RegistrarCourseList _registrarList({required String enrolled}) {
  final Map<String, dynamic> payload = _registrarPayload(enrolled: enrolled);

  return RegistrarCourseList.fromMap(payload);
}

/// The payload the backend actually sends, so the widget is exercised through
/// the real decode rather than a hand-built object. [status] is written to
/// every row exactly as the server stamps the office's own switch on them,
/// and defaults to online so a queue that is meant to be choosable is not
/// locked out by an absent field - a row with no status decodes as offline,
/// which is the client's rule for a switch it was never told about.
Map<String, dynamic> _registrarPayload({
  required String enrolled,
  String? status,
}) {
  final List<Map<String, dynamic>> rows = <Map<String, dynamic>>[];

  for (int i = 0; i < AppConstants.registrarCourses.length; i++) {
    final String code = AppConstants.registrarCourses[i];

    rows.add(<String, dynamic>{
      'course': <String, dynamic>{
        'id': 10 + i,
        'code': code,
        'name': 'BS in $code',
      },
      'course_code': code,
      'office_id': 60 + i,
      'office_name': 'Registrar - $code',
      'status': status ?? AppConstants.officeStatusOnline,
      // Two queues shut, the rest open: the card has to sum rather than report
      // any single one of them.
      'slots_deployed': true,
      'total_slots': 100,
      'used_slots': i < 2 ? 100 : 50,
      'available_slots': i < 2 ? 0 : 50,
      'queue_status': i < 2 ? 'closed' : 'open',
      'accepting': i >= 2,
      'is_enrolled_course': code == enrolled,
      'services': <Map<String, dynamic>>[
        <String, dynamic>{
          'id': 40 + i,
          'office_id': 60 + i,
          'name': 'Course listing',
          'is_active': true,
        },
      ],
    });
  }

  return <String, dynamic>{
    'registrar_courses': rows,
    'totals': <String, dynamic>{
      'available_slots': rows.fold<int>(
        0,
        (int sum, Map<String, dynamic> r) =>
            sum + QfValue.toInt(r['available_slots']),
      ),
      'total_slots': rows.fold<int>(
        0,
        (int sum, Map<String, dynamic> r) =>
            sum + QfValue.toInt(r['total_slots']),
      ),
      'course_count': rows.length,
    },
  };
}

/// A transport that answers everything a taking-a-ticket screen asks on entry.
///
/// The screen re-reads the campus on opening (the online/offline switch lives
/// on the server), so a stub has to serve the student shell, the ticket list
/// and the status endpoint before the widget builds - not just the Registrar
/// endpoint, which is only asked when the card opens.
///
/// [statusOffices] answers `/offices`, the call the screen makes to re-read
/// the switch: on entry, on a pull, on its timer and once more immediately
/// before a ticket is issued. It defaults to [offices] so a test only has to
/// say when the switch has *moved*, which is the whole point of that call -
/// a catalogue that answered with the rows it opened with would let a stale
/// "online" sail through the last check in the app.
RemoteBackend _stubShell({
  required Student student,
  required List<Office> offices,
  List<Course> courses = const <Course>[],
  String? enrolled,
  Map<String, dynamic>? registrarPayload,
  List<Office>? statusOffices,
  bool statusUnreachable = false,
  String? ticketRefusal,
}) {
  return RemoteBackend(
    client: ApiClient(
      client: MockClient((http.Request request) async {
        final String path = request.url.path;

        if (path.endsWith('/student/bootstrap')) {
          return _json(
            StudentBootstrap(
              student: student,
              offices: offices,
              courses: courses,
            ).toMap(),
          );
        }

        if (path.endsWith('/offices')) {
          // A transport failure, not a refusal: nothing answers at all, so
          // the screen has to say the server is offline rather than that the
          // request was denied.
          if (statusUnreachable) {
            throw http.ClientException('no route to host');
          }

          return _json(<String, dynamic>{
            'offices': (statusOffices ?? offices)
                .map((Office o) => o.toMap())
                .toList(),
          });
        }

        // Taking a number: the stub can refuse it with the server's own
        // sentence, so a screen that masks a refusal can be caught.
        if (path.endsWith('/tickets') && request.method == 'POST') {
          if (ticketRefusal != null) {
            return http.Response(
              jsonEncode(<String, dynamic>{'message': ticketRefusal}),
              422,
              headers: const <String, String>{
                'content-type': 'application/json; charset=utf-8',
              },
            );
          }
        }

        // Empty history: the one-active-ticket rule is not what these tests
        // are about.
        if (path.endsWith('/tickets')) {
          return _json(<String, dynamic>{
            'tickets': <Object>[],
            'counts': const TicketCounts().toMap(),
          });
        }

        if (path.endsWith('/registrar/courses')) {
          return _json(
            registrarPayload ??
                _registrarPayload(enrolled: enrolled ?? student.course ?? ''),
          );
        }

        throw http.ClientException('unexpected call ${request.url.path}');
      }),
      tokenProvider: () => null,
    ),
  );
}

http.Response _json(Object payload) => http.Response(
  // No `data` envelope: the API returns the payload at the top level,
  // and a stub that wrapped it would test a contract the server does not
  // have.
  jsonEncode(payload),
  200,
  headers: const <String, String>{
    'content-type': 'application/json; charset=utf-8',
  },
);

int _freshAccount = 0;

/// A brand new student with no queue history.
///
/// The seeded students all hold a waiting number, and the one-active-ticket
/// rule is not what these tests are about, so each office gets its own clean
/// account rather than tidying up somebody else's tickets.
Future<AppUser> _freshStudent() async {
  _freshAccount++;

  return LocalBackend.instance.createAccount(
    name: 'Test Student $_freshAccount',
    email: 'queue.tester$_freshAccount@tmc.edu.ph',
    role: AppRole.student,
    password: 'tester123',
    studentId: '2099-${_freshAccount.toString().padLeft(4, '0')}',
    course: 'BSIT',
    block: '1',
    yearLevel: '2nd Year',
  );
}

/// Takes a number at [office], using its first active service.
Future<QueueTicket> _take(AppUser user, Office office) async {
  final OfficeService service = office.services.firstWhere(
    (OfficeService s) => s.isActive,
    orElse: () => throw StateError('office ${office.code} has no service'),
  );

  final CreatedTicket created = await LocalBackend.instance.createTicket(
    user: user,
    officeId: office.id,
    serviceId: service.id,
    // The backend checks the course row exists, not that it belongs to the
    // office, so one value covers every office in the catalogue.
    courseId: 1,
  );

  return created.ticket;
}
