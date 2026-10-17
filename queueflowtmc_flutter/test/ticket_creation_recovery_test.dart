import 'dart:convert';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:queueflow_tmc/core/api/api_client.dart';
import 'package:queueflow_tmc/core/network/connectivity_service.dart';
import 'package:queueflow_tmc/models/queue_ticket.dart';
import 'package:queueflow_tmc/models/student.dart';
import 'package:queueflow_tmc/repositories/auth_repository.dart';
import 'package:queueflow_tmc/services/campus_backend_factory.dart';
import 'package:queueflow_tmc/services/remote_backend.dart';

/// ============================================================
/// QUEUEFLOW TMC — TAKING A TICKET RECOVERY
///
/// Generating a number is the one action that must not be answered with a
/// guess. A reply that never arrives or a server that breaks partway leaves the
/// question open: the row may already exist at the counter. The repository has
/// to hand the screen enough to tell that apart from a refusal, because the
/// screen decides whether to re-read the queue before it reports failure - and
/// reporting a number the counter is already holding is the one wrong answer.
///
/// These tests pin the flag that carries that decision.
/// ============================================================

void main() {
  setUp(() {
    SharedPreferences.setMockInitialValues(<String, Object>{});
    AuthRepository.instance.debugReset();
    ConnectivityService.instance.debugReset();
  });

  tearDown(() {
    AuthRepository.instance.debugReset();
    CampusBackendFactory.instance.overrideForTesting(null);
    ConnectivityService.instance.debugReset();
  });

  test('a lost reply leaves the outcome uncertain, not failed', () async {
    _install(
      MockClient((http.Request request) async {
        throw http.ClientException('connection reset by peer');
      }),
    );

    final ({CreatedTicket? created, String? error, bool uncertain}) result =
        await AuthRepository.instance.createTicket(
          officeId: 1,
          serviceId: 1,
          courseId: 1,
        );

    expect(result.created, isNull);
    expect(result.error, isNotNull);
    expect(
      result.uncertain,
      isTrue,
      reason: 'the POST may have reached the server and its reply been lost, '
          'so the screen must re-read before calling it a failure',
    );
  });

  test('an outright refusal is certain, and never triggers a re-read', () async {
    _install(
      MockClient((http.Request request) async {
        return http.Response(
          jsonEncode(<String, String>{
            'message': 'This office is currently closed.',
          }),
          422,
          headers: <String, String>{'content-type': 'application/json'},
        );
      }),
    );

    final ({CreatedTicket? created, String? error, bool uncertain}) result =
        await AuthRepository.instance.createTicket(
          officeId: 1,
          serviceId: 1,
          courseId: 1,
        );

    expect(result.created, isNull);
    expect(result.error, contains('closed'));
    expect(
      result.uncertain,
      isFalse,
      reason: 'the server answered and created nothing, so a second read '
          'would only invent a ticket that is not there',
    );
  });
}

void _install(MockClient client) {
  CampusBackendFactory.instance.overrideForTesting(
    RemoteBackend(client: ApiClient(client: client, tokenProvider: () => null)),
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
  );

  ConnectivityService.instance.debugSetServerReachable();
}
