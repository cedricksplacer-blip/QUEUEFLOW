import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:queueflow_tmc/core/api/api_client.dart';
import 'package:queueflow_tmc/core/api/api_config.dart';
import 'package:queueflow_tmc/models/app_user.dart';
import 'package:queueflow_tmc/models/campus_catalog.dart';
import 'package:queueflow_tmc/models/queue_ticket.dart';
import 'package:queueflow_tmc/models/student_bootstrap.dart';
import 'package:queueflow_tmc/services/campus_backend.dart';
import 'package:queueflow_tmc/services/remote_backend.dart';
import 'package:queueflow_tmc/services/session_store.dart';

/// ============================================================
/// QUEUEFLOW TMC — LIVE PUBLIC FLOW (opt-in)
///
/// The full student flow driven through the REAL Flutter HTTP stack
/// ([ApiClient] + [RemoteBackend] + models) against the REAL backend over a
/// REAL public HTTPS endpoint. This is the test that answers "does the app
/// work on any internet connection" with evidence instead of mocks.
///
/// Runs ONLY when launched with a live public base URL, e.g.:
///   flutter test --dart-define=LIVE_API_BASE_URL=https://public-host/api
///   test/live_public_flow_test.dart
///
/// Without the define the whole group is skipped, so the normal offline
/// suite is unaffected. The throwaway student account it signs in with must
/// exist (see QueueFlow_TMC/setup_verify_tmp.php pattern:
/// QF-VERIFY-001 / Verify123!); the test cancels its own ticket and signs
/// out, leaving no trace.
/// ============================================================

const String _liveBase = String.fromEnvironment('LIVE_API_BASE_URL');
const String _studentId = String.fromEnvironment(
  'LIVE_STUDENT_ID',
  defaultValue: 'QF-VERIFY-001',
);
const String _password = String.fromEnvironment(
  'LIVE_STUDENT_PASSWORD',
  defaultValue: 'Verify123!',
);

void main() {
  group(
    'live public flow',
    skip: _liveBase.isEmpty
        ? 'Set LIVE_API_BASE_URL=https://<host>/api to run against a live tunnel.'
        : false,
    () {
      late RemoteBackend backend;
      late AppUser student;
      late CreatedTicket issued;

      setUpAll(() {
        SharedPreferences.setMockInitialValues(<String, Object>{});
        FlutterSecureStoragePlatform.instance =
            TestFlutterSecureStoragePlatform(<String, String>{});
        ApiConfig.setEndpointOverride(_liveBase);

        // The production stack reads the token from the session store, so
        // sign-in below stores a REAL token there exactly like the app does.
        backend = RemoteBackend(
          client: ApiClient(tokenProvider: () => SessionStore.token),
        );
      });

      tearDownAll(() async {
        ApiConfig.clearEndpointOverride();
        await SessionStore.clear();
      });

      test('1 - public health probe answers', () async {
        expect(ApiConfig.isPublicHttps, isTrue);
        expect(await ApiClient().checkHealth(), isTrue);
      });

      test('2 - student login issues a bearer token', () async {
        student = await backend.signIn(
          identifier: _studentId,
          password: _password,
        );

        expect(student.role, AppRole.student);
        expect(SessionStore.token, isNotNull);
      });

      test('3 - bootstrap and office catalogue read live data', () async {
        final StudentBootstrap bootstrap = await backend.studentBootstrap(
          student,
        );
        final List<Office> offices = await backend.officeCatalogue(student);

        expect(bootstrap.student.studentId, _studentId);
        expect(offices, isNotEmpty);
      });

      test('4 - ticket creation returns a receipt', () async {
        final List<Office> offices = await backend.officeCatalogue(student);
        final Office office = offices.firstWhere((Office o) => o.isOnline);

        // A live service for the office, read off the catalogue the server
        // just sent — never a hard-coded id, so the test survives catalogue
        // changes. Non-course services need no course context.
        final OfficeService service = office.services.firstWhere(
          (OfficeService s) => !s.requiresCourse,
        );
        final RegistrarCourseList courses = await backend.registrarCourses(
          student,
        );

        issued = await backend.createTicket(
          user: student,
          officeId: office.id,
          serviceId: service.id,
          courseId: courses.courses.first.courseId,
          purpose: 'Live public-flow verification',
        );

        expect(issued.ticket.id, greaterThan(0));
        expect(issued.ticket.queueNumber, isNotEmpty);
        expect(issued.message, isNotEmpty);
      });

      test('5 - receipt and queue refresh show the issued ticket', () async {
        final ({QueueTicket ticket, QueueProgress queue}) got = await backend
            .studentTicket(student, issued.ticket.id);

        expect(got.ticket.id, issued.ticket.id);
        expect(got.ticket.queueNumber, issued.ticket.queueNumber);

        final ({List<QueueTicket> tickets, TicketCounts counts}) list =
            await backend.studentTickets(student);

        expect(
          list.tickets.any((QueueTicket t) => t.id == issued.ticket.id),
          isTrue,
        );
      });

      test('6 - a second ticket is refused, never duplicated', () async {
        final List<Office> offices = await backend.officeCatalogue(student);
        final Office office = offices.firstWhere((Office o) => o.isOnline);
        final OfficeService service = office.services.firstWhere(
          (OfficeService s) => !s.requiresCourse,
        );
        final RegistrarCourseList courses = await backend.registrarCourses(
          student,
        );

        try {
          await backend.createTicket(
            user: student,
            officeId: office.id,
            serviceId: service.id,
            courseId: courses.courses.first.courseId,
            purpose: 'Duplicate attempt',
          );
          fail('the active-ticket limit must refuse a second live ticket');
        } on CampusBackendException catch (error) {
          expect(error.message.toLowerCase(), contains('active'));
        }
      });

      test('7 - wrong password is a 401, not a network error', () async {
        try {
          await RemoteBackend().signIn(
            identifier: _studentId,
            password: 'WrongPass1!',
          );
          fail('invalid credentials must not sign in');
        } on CampusBackendException catch (error) {
          expect(error.statusCode, 401);
        }
      });

      test('8 - cleanup: cancel the ticket and sign out', () async {
        final QueueTicket cancelled = await backend.cancelTicket(
          student,
          issued.ticket.id,
        );

        expect(cancelled.status, 'cancelled');

        await backend.signOut();
        expect(SessionStore.token, isNull);
      });
    },
  );
}
