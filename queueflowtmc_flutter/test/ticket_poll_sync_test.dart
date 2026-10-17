import 'dart:convert';

import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:queueflow_tmc/core/api/api_client.dart';
import 'package:queueflow_tmc/models/queue_ticket.dart';
import 'package:queueflow_tmc/models/student.dart';
import 'package:queueflow_tmc/repositories/auth_repository.dart';
import 'package:queueflow_tmc/services/campus_backend_factory.dart';
import 'package:queueflow_tmc/services/remote_backend.dart';
import 'package:queueflow_tmc/services/session_store.dart';

/// ============================================================
/// QUEUEFLOW TMC — TICKET POLL ADOPTS STAFF ACTIONS
///
/// The My Ticket screen polls `activeProgress()` every 6 seconds. That read
/// returns the server's fresh ticket row (called status, server-stamped
/// `expires_at`, arrival, cancellation, lazy expiry) — and the repository
/// must adopt the row, not just the progress. Otherwise a staff Call Next
/// would never surface the countdown until a full refresh, and Stop Timer /
/// cancel / expiry would lag the same way.
/// ============================================================
void main() {
  const Student student = Student(
    id: 7,
    name: 'Juan Dela Cruz',
    email: 'juan@tmc.edu.ph',
    studentId: '24-019083',
    course: 'BSIT',
  );

  Map<String, dynamic> ticketMap({
    required String status,
    String? calledAt,
    String? expiresAt,
    String? arrivedAt,
    int duration = 5,
  }) => <String, dynamic>{
    'id': 101,
    'queue_number': 'CASH-001',
    'status': status,
    'priority': 'regular',
    'office': <String, dynamic>{
      'id': 2,
      'name': 'Cashier',
      'code': 'CASH',
    },
    'service': <String, dynamic>{'id': 9, 'name': 'Payment'},
    'called_at': calledAt,
    'call_duration_minutes': duration,
    'expires_at': expiresAt,
    'remaining_seconds': 0,
    'is_expired': false,
    'arrived_at': arrivedAt,
    'is_arrived': arrivedAt != null,
    'created_at': '2026-10-13T08:00:00.000',
  };

  http.Response json(Object body, {int status = 200}) => http.Response(
    jsonEncode(body),
    status,
    headers: <String, String>{
      'content-type': 'application/json; charset=utf-8',
    },
  );

  setUp(() async {
    FlutterSecureStoragePlatform.instance = TestFlutterSecureStoragePlatform(
      <String, String>{},
    );
    await SessionStore.clear();
    SharedPreferences.setMockInitialValues(<String, Object>{});
    await SessionStore.load();
    AuthRepository.instance.debugReset();
    CampusBackendFactory.instance.overrideForTesting(null);
  });

  tearDown(() {
    CampusBackendFactory.instance.overrideForTesting(null);
  });

  test('a staff call surfaces the countdown on the next poll', () async {
    final AuthRepository auth = AuthRepository.instance;

    // Seeded as the ticket was before the call: still waiting.
    auth.debugSeed(
      student: student,
      activeTicket: QueueTicket.fromMap(ticketMap(status: 'waiting')),
    );

    expect(auth.activeTicket!.isWaiting, isTrue);
    expect(auth.activeTicket!.expiresAt, isNull);

    final String calledAt = DateTime.now().toIso8601String();
    final String expiresAt = DateTime.now()
        .add(const Duration(minutes: 5))
        .toIso8601String();

    // The server now reports the ticket as called with its deadline.
    CampusBackendFactory.instance.overrideForTesting(
      RemoteBackend(
        client: ApiClient(
          client: MockClient((http.Request request) async {
            return json(<String, dynamic>{
              'ticket': ticketMap(
                status: 'serving',
                calledAt: calledAt,
                expiresAt: expiresAt,
              ),
            });
          }),
          tokenProvider: () => SessionStore.token,
        ),
      ),
    );

    await auth.activeProgress();

    // The 6-second poll adopted the call: countdown renders from expiresAt.
    expect(auth.activeTicket!.isServing, isTrue);
    expect(auth.activeTicket!.expiresAt, isNotNull);
    expect(auth.activeTicket!.remainingSeconds, greaterThan(4 * 60));
    expect(auth.activeTicket!.countdownLabel, matches(RegExp(r'^\d{2}:\d{2}$')));
  });

  test('a Stop-Timer arrival stops the countdown on the next poll', () async {
    final AuthRepository auth = AuthRepository.instance;

    auth.debugSeed(
      student: student,
      activeTicket: QueueTicket.fromMap(
        ticketMap(
          status: 'serving',
          calledAt: DateTime.now()
              .subtract(const Duration(minutes: 1))
              .toIso8601String(),
          expiresAt: DateTime.now()
              .add(const Duration(minutes: 4))
              .toIso8601String(),
        ),
      ),
    );

    expect(auth.activeTicket!.isArrived, isFalse);

    final String arrivedAt = DateTime.now().toIso8601String();

    CampusBackendFactory.instance.overrideForTesting(
      RemoteBackend(
        client: ApiClient(
          client: MockClient((http.Request request) async {
            return json(<String, dynamic>{
              'ticket': ticketMap(
                status: 'serving',
                calledAt: DateTime.now()
                    .subtract(const Duration(minutes: 1))
                    .toIso8601String(),
                arrivedAt: arrivedAt,
              ),
            });
          }),
          tokenProvider: () => SessionStore.token,
        ),
      ),
    );

    await auth.activeProgress();

    expect(auth.activeTicket!.isArrived, isTrue);
    expect(auth.activeTicket!.countdownLabel, 'ARRIVED');
    expect(auth.activeTicket!.remainingSeconds, 0);
  });

  test('a staff cancel ends the countdown and lands in history', () async {
    final AuthRepository auth = AuthRepository.instance;

    auth.debugSeed(
      student: student,
      activeTicket: QueueTicket.fromMap(
        ticketMap(
          status: 'serving',
          calledAt: DateTime.now()
              .subtract(const Duration(minutes: 1))
              .toIso8601String(),
          expiresAt: DateTime.now()
              .add(const Duration(minutes: 4))
              .toIso8601String(),
        ),
      ),
    );

    CampusBackendFactory.instance.overrideForTesting(
      RemoteBackend(
        client: ApiClient(
          client: MockClient((http.Request request) async {
            return json(<String, dynamic>{
              'ticket': ticketMap(status: 'cancelled'),
            });
          }),
          tokenProvider: () => SessionStore.token,
        ),
      ),
    );

    await auth.activeProgress();

    // No longer active: the countdown card unmounts, history keeps the row.
    expect(auth.activeTicket, isNull);
    expect(auth.history, hasLength(1));
    expect(auth.history.single.isCancelled, isTrue);
  });
}
