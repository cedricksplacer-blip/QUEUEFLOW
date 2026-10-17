import 'dart:convert';
import 'dart:io';

import 'package:flutter/material.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:image_picker_platform_interface/image_picker_platform_interface.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:queueflow_tmc/core/api/api_client.dart';
import 'package:queueflow_tmc/core/app_constants.dart';
import 'package:queueflow_tmc/core/app_theme.dart';
import 'package:queueflow_tmc/core/network/connectivity_service.dart';
import 'package:queueflow_tmc/models/app_user.dart';
import 'package:queueflow_tmc/models/campus_catalog.dart';
import 'package:queueflow_tmc/models/queue_ticket.dart';
import 'package:queueflow_tmc/models/student.dart';
import 'package:queueflow_tmc/repositories/auth_repository.dart';
import 'package:queueflow_tmc/screens/new_ticket_screen.dart';
import 'package:queueflow_tmc/services/campus_backend_factory.dart';
import 'package:queueflow_tmc/services/remote_backend.dart';

const Size _phone = Size(390, 844);

/// ============================================================
/// QUEUEFLOW TMC — PWD PROOF IMAGE
///
/// A student claiming the Person with Disability priority must attach a photo
/// of their PWD ID. The Laravel side already insists on it (`priority_evidence`
/// on the multipart upload, required whenever priority is `pwd`); these tests
/// pin the Flutter half of that contract:
///
///   - the proof section appears only while PWD is selected, and a proof stops
///     travelling with the form the moment the student drops the claim;
///   - a PWD submit without a photo is refused here, before any request leaves
///     the device, in words the student can act on;
///   - a picked photo is actually attached, under the field name the server
///     reads, and only the photo the student is looking at - never an earlier
///     one they replaced;
///   - cancelling the gallery is a no-op, not an error, and the student can
///     just try again.
/// ============================================================

void main() {
  final _FakeImagePicker picker = _FakeImagePicker();
  final Directory tempDir = Directory.systemTemp.createTempSync(
    'qflow_pwd_proof_test',
  );

  setUp(() {
    SharedPreferences.setMockInitialValues(<String, Object>{});
    picker.reset();
    ImagePickerPlatform.instance = picker;
    AuthRepository.instance.debugReset();
    CampusBackendFactory.instance.overrideForTesting(null);
    ConnectivityService.instance.debugReset();
  });

  tearDown(() {
    AuthRepository.instance.debugReset();
    CampusBackendFactory.instance.overrideForTesting(null);
    ConnectivityService.instance.debugReset();
  });

  tearDownAll(() {
    try {
      tempDir.deleteSync(recursive: true);
    } on FileSystemException {
      // Windows can hold a handle open briefly; a leftover temp folder is
      // harmless and not worth failing the suite for.
    }
  });

  // ============================================================
  // THE TRANSPORT: multipart field/file contract
  //
  // Unit tests (plain `test`, real event loop, real file IO) pin exactly what
  // bytes leave the device, so a slack in the widget layer - a field renamed,
  // a file dropped - is caught where it is cheapest to read.
  // ============================================================

  group('the multipart upload carries the evidence', () {
    test('PWD attaches the picked photo under priority_evidence', () async {
      final File proof = _proofFile(tempDir, 'pwd_proof.png');
      final _Transport transport = _Transport();

      final CreatedTicket created =
          await RemoteBackend(
            client: ApiClient(
              client: MockClient(transport.handle),
              tokenProvider: () => null,
            ),
          ).createTicket(
            user: const AppUser(id: 1, name: 'Juan', email: 'juan@tmc.edu.ph'),
            officeId: 1,
            serviceId: 1,
            courseId: 1,
            priority: AppConstants.priorityPwd,
            purpose: 'Statement of Account',
            yearLevel: '2nd Year',
            evidencePath: proof.path,
          );

      final QueueTicket ticket = created.ticket;

      final http.Request request = transport.ticketPosts.single;

      expect(
        request.url.path,
        endsWith('/tickets'),
        reason: 'the evidence has to reach the ticket endpoint',
      );

      final String body = latin1.decode(request.bodyBytes);

      // The file arrives under the exact field name the Laravel validator
      // reads (`priority_evidence`, image, max 2 MB).
      expect(body, contains('name="priority_evidence"'));
      expect(body, contains('filename="pwd_proof.png"'));
      expect(
        _fieldValue(body, 'priority'),
        AppConstants.priorityPwd,
        reason: 'a PWD ticket must declare its priority to match its proof',
      );
      expect(_fieldValue(body, 'office_id'), '1');
      expect(_fieldValue(body, 'service_id'), '1');
      expect(_fieldValue(body, 'course_id'), '1');
      expect(
        _fieldValue(body, 'purpose'),
        'Statement of Account',
        reason: 'a blank purpose is populated, never sent empty',
      );

      expect(ticket.id, 42, reason: 'the created ticket is the one returned');
      expect(ticket.queueNumber, 'A-042');
      expect(ticket.priority, AppConstants.priorityPwd);
    });

    test('a regular ticket never carries an evidence part', () async {
      final _Transport transport = _Transport();

      await RemoteBackend(
        client: ApiClient(
          client: MockClient(transport.handle),
          tokenProvider: () => null,
        ),
      ).createTicket(
        user: const AppUser(id: 1, name: 'Juan', email: 'juan@tmc.edu.ph'),
        officeId: 1,
        serviceId: 1,
        courseId: 1,
        priority: AppConstants.priorityRegular,
        purpose: null,
      );

      final String body = latin1.decode(transport.ticketPosts.single.bodyBytes);

      expect(body, isNot(contains('priority_evidence')));
      expect(_fieldValue(body, 'priority'), AppConstants.priorityRegular);
      expect(_fieldValue(body, 'purpose'), 'General transaction');
    });
  });

  // ============================================================
  // THE SCREEN: the proof section and the refusal
  // ============================================================

  group('the screen ties proof to the PWD claim', () {
    testWidgets('a regular ticket asks for no proof and carries none', (
      WidgetTester tester,
    ) async {
      final _Transport transport = await _openForm(tester);

      expect(find.text('PWD PROOF'), findsNothing);
      expect(find.text('Select Image from Gallery'), findsNothing);

      await _scrollToSubmit(tester);
      await tester.tap(find.text('Generate ticket number'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 200));
      await tester.pump(const Duration(milliseconds: 200));

      // The ticket was issued and its receipt opened by itself, and the
      // multipart that made it carries no evidence part at all.
      expect(find.text('OFFICIAL QUEUE RECEIPT'), findsOneWidget);
      expect(find.text('2021-0042'), findsOneWidget);
      final String body = latin1.decode(transport.ticketPosts.single.bodyBytes);
      expect(body, isNot(contains('priority_evidence')));
      expect(_fieldValue(body, 'priority'), AppConstants.priorityRegular);
    });

    testWidgets('PWD without a photo is refused here, with a reason', (
      WidgetTester tester,
    ) async {
      final _Transport transport = await _openForm(tester);

      await _selectPriority(tester, AppConstants.priorityPwd);
      expect(find.text('PWD PROOF'), findsOneWidget);
      expect(find.text('Select Image from Gallery'), findsOneWidget);

      await _scrollToSubmit(tester);
      await tester.tap(find.text('Generate ticket number'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 200));

      // Refused before any request leaves the device.
      expect(
        find.text('Please select a photo as proof for PWD priority.'),
        findsOneWidget,
      );
      expect(transport.ticketPosts, isEmpty);
      expect(tester.takeException(), isNull);
    });

    testWidgets('PWD with a photo is issued and the photo travels', (
      WidgetTester tester,
    ) async {
      final File proof = _proofFile(tempDir, 'proof_issued.png');
      final _Transport transport = await _openForm(
        tester,
        proofPaths: <String>[proof.path],
      );

      await _selectPriority(tester, AppConstants.priorityPwd);
      await _pickProof(tester);

      // The preview shows exactly what will be uploaded, and both ways to
      // change it.
      expect(find.text('Selected image'), findsOneWidget);
      expect(find.text('Change Image'), findsOneWidget);
      expect(find.text('Remove Image'), findsOneWidget);

      await _scrollToSubmit(tester);

      // The file is read off disk, which is real IO that the test's fake
      // clock never runs. So the submit happens inside a real-async zone, and
      // a real delay spins that zone long enough for the file read, the
      // multipart POST and the post-ticket refresh all to complete before the
      // frames that render the issued dialog are pumped.
      await tester.runAsync(() async {
        await tester.tap(find.text('Generate ticket number'));
        await Future<void>.delayed(const Duration(seconds: 1));
      });
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      expect(find.text('OFFICIAL QUEUE RECEIPT'), findsOneWidget);
      expect(find.text('2021-0042'), findsOneWidget);
      expect(tester.takeException(), isNull);

      final String body = latin1.decode(transport.ticketPosts.single.bodyBytes);
      expect(body, contains('name="priority_evidence"'));
      expect(body, contains('filename="proof_issued.png"'));
      expect(_fieldValue(body, 'priority'), AppConstants.priorityPwd);
    });

    testWidgets('only the final photo is uploaded, never a replaced one', (
      WidgetTester tester,
    ) async {
      final File first = _proofFile(tempDir, 'proof_first.png');
      final File second = _proofFile(tempDir, 'proof_final.png');
      final _Transport transport = await _openForm(
        tester,
        proofPaths: <String>[first.path, second.path],
      );

      await _selectPriority(tester, AppConstants.priorityPwd);
      await _pickProof(tester);

      expect(find.text('Selected image'), findsOneWidget);

      await tester.dragUntilVisible(
        find.text('Change Image'),
        find.byType(ListView),
        const Offset(0, -160),
      );
      await tester.pump(const Duration(milliseconds: 300));

      await tester.tap(find.text('Change Image'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      // Still a preview, now of the changed image.
      expect(find.text('Selected image'), findsOneWidget);
      expect(find.text('Remove Image'), findsOneWidget);

      await _scrollToSubmit(tester);

      await tester.runAsync(() async {
        await tester.tap(find.text('Generate ticket number'));
        await Future<void>.delayed(const Duration(seconds: 1));
      });
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 400));

      expect(find.text('OFFICIAL QUEUE RECEIPT'), findsOneWidget);
      expect(find.text('2021-0042'), findsOneWidget);

      final String body = latin1.decode(transport.ticketPosts.single.bodyBytes);
      expect(body, contains('name="priority_evidence"'));
      expect(body, contains('filename="proof_final.png"'));
      expect(
        body,
        isNot(contains('proof_first.png')),
        reason: 'a replaced photo must not still be attached to the ticket',
      );
    });

    testWidgets(
      'switching back to regular drops both the section and the proof',
      (WidgetTester tester) async {
        final File proof = _proofFile(tempDir, 'proof_dropped.png');
        await _openForm(tester, proofPaths: <String>[proof.path]);

        await _selectPriority(tester, AppConstants.priorityPwd);
        await _pickProof(tester);
        expect(find.text('Change Image'), findsOneWidget);

        // Back up to the priority row.
        await tester.dragUntilVisible(
          find.text('Regular'),
          find.byType(ListView),
          const Offset(0, 240),
        );
        await tester.pump(const Duration(milliseconds: 400));

        await tester.tap(find.text('Regular'));
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 400));

        // The claim is gone, so the section that existed to prove it is gone,
        // and the proof the claim carried is gone with it.
        expect(find.text('PWD PROOF'), findsNothing);
        expect(find.text('Change Image'), findsNothing);

        // Claiming PWD again starts clean: nobody re-issues an old proof.
        await _selectPriority(tester, AppConstants.priorityPwd);
        expect(find.text('PWD PROOF'), findsOneWidget);
        expect(find.text('Select Image from Gallery'), findsOneWidget);
        expect(find.text('Change Image'), findsNothing);
      },
    );

    testWidgets('cancelling the gallery is a no-op and can be retried', (
      WidgetTester tester,
    ) async {
      final File proof = _proofFile(tempDir, 'proof_retry.png');
      await _openForm(tester, proofPaths: <String>[proof.path]);

      await _selectPriority(tester, AppConstants.priorityPwd);

      // First opening is cancelled.
      picker.cancelNext = true;
      await tester.dragUntilVisible(
        find.text('Select Image from Gallery'),
        find.byType(ListView),
        const Offset(0, -160),
      );
      await tester.pump(const Duration(milliseconds: 300));

      await tester.tap(find.text('Select Image from Gallery'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      // Still the empty state, and critically no error: a cancel is a choice,
      // not a failure.
      expect(find.text('Select Image from Gallery'), findsOneWidget);
      expect(find.textContaining('Could not open the gallery'), findsNothing);

      // The next opening works normally.
      await tester.tap(find.text('Select Image from Gallery'));
      await tester.pump();
      await tester.pump(const Duration(milliseconds: 300));

      expect(find.text('Selected image'), findsOneWidget);
      expect(tester.takeException(), isNull);
    });

    testWidgets(
      'removing the proof returns to the empty state and refuses PWD',
      (WidgetTester tester) async {
        final File proof = _proofFile(tempDir, 'proof_removed.png');
        final _Transport transport = await _openForm(
          tester,
          proofPaths: <String>[proof.path],
        );

        await _selectPriority(tester, AppConstants.priorityPwd);
        await _pickProof(tester);
        expect(find.text('Selected image'), findsOneWidget);

        await tester.dragUntilVisible(
          find.text('Remove Image'),
          find.byType(ListView),
          const Offset(0, -160),
        );
        await tester.pump(const Duration(milliseconds: 300));

        await tester.tap(find.text('Remove Image'));
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 300));

        // Back to the one clear entry point.
        expect(find.text('Select Image from Gallery'), findsOneWidget);
        expect(find.text('Change Image'), findsNothing);

        await _scrollToSubmit(tester);
        await tester.tap(find.text('Generate ticket number'));
        await tester.pump();
        await tester.pump(const Duration(milliseconds: 200));

        expect(
          find.text('Please select a photo as proof for PWD priority.'),
          findsOneWidget,
        );
        expect(transport.ticketPosts, isEmpty);
      },
    );
  });
}

// ============================================================
// TEST DOUBLE: the system image picker
//
// The screen owns the button, so a widget test cannot hand it a fake picker.
// Instead the real `ImagePicker()` call inside the widget is redirected by
// replacing the platform instance - no production seam needed, and the press
// that opens the gallery is exercised for real.
// ============================================================

class _FakeImagePicker extends ImagePickerPlatform {
  List<String> paths = const <String>[];
  bool cancelNext = false;
  int _calls = 0;

  void reset() {
    paths = const <String>[];
    cancelNext = false;
    _calls = 0;
  }

  @override
  Future<XFile?> getImage({
    required ImageSource source,
    double? maxWidth,
    double? maxHeight,
    int? imageQuality,
    CameraDevice preferredCameraDevice = CameraDevice.rear,
  }) async {
    if (cancelNext) {
      cancelNext = false;
      return null;
    }

    if (_calls >= paths.length) {
      throw StateError(
        'picker asked ${_calls + 1} times but only ${paths.length} '
        'proofs were seeded',
      );
    }

    return XFile(paths[_calls++]);
  }
}

// ============================================================
// STUB BACKEND: tickets, bootstrap, tickets list
//
// Mirrors the Laravel shapes exactly (no `data` envelope). The POST /tickets
// response is captured whole so the multipart bytes can be asserted, and the
// refresh that follows a successful internals POST is answered so the screen
// reaches the issued-ticket dialog.
// ============================================================

class _Transport {
  final List<http.Request> ticketPosts = <http.Request>[];

  RemoteBackend build() => RemoteBackend(
    client: ApiClient(client: MockClient(handle), tokenProvider: () => null),
  );

  Future<http.Response> handle(http.Request request) async {
    final String path = request.url.path;

    if (request.method == 'POST' && path.endsWith('/tickets')) {
      ticketPosts.add(request);
      return _json(<String, dynamic>{
        'ticket': <String, dynamic>{
          'id': 42,
          'queue_number': 'A-042',
          'status': AppConstants.statusWaiting,
          'priority': AppConstants.priorityPwd,
          'has_evidence': true,
          'office': <String, dynamic>{
            'id': 1,
            'name': 'Cashier',
            'code': 'CASH',
          },
          'service': <String, dynamic>{'id': 1, 'name': 'Tuition payment'},
          'created_at': DateTime.now().toIso8601String(),
        },
      });
    }

    if (request.method == 'GET' && path.endsWith('/student/bootstrap')) {
      // The screen re-reads the campus on entry, so the answer has to carry
      // the same catalogue the test seeded - an empty office list here would
      // send `refresh` to overwrite the seed with nothing.
      return _json(<String, dynamic>{
        'student': <String, dynamic>{
          'id': 7,
          'name': 'Juan Dela Cruz',
          'email': 'juan@tmc.edu.ph',
          'course': 'BSIT',
          'year_level': '2nd Year',
          'student_id': '2021-0042',
        },
        'announcements': <Object>[],
        'courses': const <Course>[
          Course(id: 1, code: 'BSIT', name: 'BS in Information Technology'),
        ].map((Course c) => c.toMap()).toList(),
        'offices': const <Office>[
          _cashier,
          _registrar,
          _switchedOff,
        ].map((Office o) => o.toMap()).toList(),
      });
    }

    if (request.method == 'GET' && path.endsWith('/offices')) {
      // The screen re-reads the switch before it issues a number, so the
      // status endpoint has to answer with the catalogue the test seeded -
      // the same rows, carrying their own status, rather than a bare object.
      return _json(<String, dynamic>{
        'offices': const <Office>[
          _cashier,
          _registrar,
          _switchedOff,
        ].map((Office o) => o.toMap()).toList(),
      });
    }

    if (request.method == 'GET' && path.endsWith('/tickets')) {
      return _json(<String, dynamic>{
        'tickets': <Object>[],
        'counts': <String, dynamic>{'waiting': 0, 'serving': 0, 'served': 0},
      });
    }

    throw http.ClientException('unexpected call ${request.method} $path');
  }
}

// ============================================================
// AUTH / BACKEND / SCREEN HARNESS
// ============================================================

/// Seeds the student, installs the stub transport, declares the server
/// reachable and pumps the screen up to the point where an office and a
/// service have been chosen. Returns the transport so the test can read what
/// was posted.
Future<_Transport> _openForm(
  WidgetTester tester, {
  List<String> proofPaths = const <String>[],
}) async {
  final _Transport transport = _Transport();

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
    offices: const <Office>[_cashier, _registrar, _switchedOff],
    courses: const <Course>[
      Course(id: 1, code: 'BSIT', name: 'BS in Information Technology'),
    ],
  );

  CampusBackendFactory.instance.overrideForTesting(transport.build());
  ConnectivityService.instance.debugSetServerReachable();

  tester.view.physicalSize = _phone;
  tester.view.devicePixelRatio = 1.0;
  addTearDown(tester.view.reset);

  // setUp installed the fake picker; this just primes the proofs it returns.
  (ImagePickerPlatform.instance as _FakeImagePicker).paths = proofPaths;

  await tester.pumpWidget(
    MaterialApp(theme: AppTheme.dark(), home: const NewTicketScreen()),
  );
  await tester.pump(const Duration(milliseconds: 600));

  await tester.tap(find.text('Cashier'));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 600));

  return transport;
}

/// Taps the priority row for [priority] and settles the section swap. Callers
/// exercise the row by its visible name, like a user would.
Future<void> _selectPriority(WidgetTester tester, String priority) async {
  final String label = priority == AppConstants.priorityPwd
      ? 'Person with disability'
      : 'Regular';

  await tester.dragUntilVisible(
    find.text(label),
    find.byType(ListView),
    const Offset(0, -160),
  );
  await tester.pump(const Duration(milliseconds: 300));

  await tester.tap(find.text(label));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 400));
}

/// Opens the gallery and settles the resulting preview.
Future<void> _pickProof(WidgetTester tester) async {
  await tester.dragUntilVisible(
    find.text('Select Image from Gallery'),
    find.byType(ListView),
    const Offset(0, -160),
  );
  await tester.pump(const Duration(milliseconds: 300));

  await tester.tap(find.text('Select Image from Gallery'));
  await tester.pump();
  await tester.pump(const Duration(milliseconds: 400));
}

/// Brings the submit button on screen.
Future<void> _scrollToSubmit(WidgetTester tester) async {
  await tester.dragUntilVisible(
    find.text('Generate ticket number'),
    find.byType(ListView),
    const Offset(0, -160),
  );
  await tester.pump(const Duration(milliseconds: 300));
}

// ============================================================
// HELPERS
// ============================================================

/// The catalogue the screens under test offer.
///
/// Matched against AppConstants.queueOffices because `AuthRepository.offices`
/// filters to those names, and the lowest-ranked row becomes the Registrar
/// card: with 'Registrar' present, 'Cashier' stays a plain tile and takes the
/// proof steps below. The closed clinic is carried because the existing office
/// tests do, and keeps the registrar predicate honest.
const OfficeService _cashierService = OfficeService(
  id: 1,
  officeId: 1,
  stationId: 1,
  name: 'Tuition payment',
  isActive: true,
);

const OfficeService _registrarService = OfficeService(
  id: 4,
  officeId: 2,
  stationId: 2,
  name: 'Certificate of Registration',
  isActive: true,
);

const Office _cashier = Office(
  id: 1,
  name: 'Cashier',
  code: 'CASH',
  openingTime: '07:00',
  closingTime: '17:00',
  status: AppConstants.officeStatusOnline,
  accepting: true,
  services: <OfficeService>[_cashierService],
);

const Office _registrar = Office(
  id: 2,
  name: 'Registrar',
  code: 'REG-BSIT',
  openingTime: '07:00',
  closingTime: '17:00',
  status: AppConstants.officeStatusOnline,
  services: <OfficeService>[_registrarService],
);

const Office _switchedOff = Office(
  id: 4,
  name: 'School Clinic',
  code: 'CLNC',
  status: AppConstants.officeStatusOnline,
  isActive: false,
);

/// A real file whose bytes stand in for a photo. Content does not matter -
/// the server validates the size and the widget degrades gracefully when the
/// bytes are not a decodable image - what matters is that a file with a
/// real name exists, because the multipart layer reads it off disk.
File _proofFile(Directory dir, String name) {
  final File file = File('${dir.path}${Platform.pathSeparator}$name');
  file.writeAsBytesSync(<int>[0x89, 0x50, 0x4E, 0x47]);
  return file;
}

/// Reads a plain text field out of a multipart body.
String? _fieldValue(String body, String name) {
  final RegExpMatch? match = RegExp(
    'name="$name"\\r\\n\\r\\n([^\\r]+)',
  ).firstMatch(body);

  return match?.group(1)?.trim();
}

http.Response _json(Map<String, dynamic> body) => http.Response(
  jsonEncode(body),
  200,
  headers: const <String, String>{
    'content-type': 'application/json; charset=utf-8',
  },
);
