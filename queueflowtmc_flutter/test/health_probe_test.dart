import 'dart:async';

import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'package:queueflow_tmc/core/api/api_client.dart';
import 'package:queueflow_tmc/core/api/api_config.dart';

/// ============================================================
/// QUEUEFLOW TMC — HEALTH PROBE CONTRACT
///
/// Pins what [ApiClient.checkHealth] promises the rest of the app:
///
///   - it asks exactly `<base>/api/v1/health` (never a doubled segment),
///   - HTTP 200 with the valid JSON health payload (`status: online`)
///     means the server is online,
///   - 401/422 are refusals, not network outages (still not online),
///   - 404 is a route/configuration problem (still not online),
///   - 5xx is a server error (still not online),
///   - HTML/interstitial pages, empty bodies and non-JSON 2xx bodies are
///     NOT valid API responses (an ngrok interstitial with a 200 must never
///     read as Online),
///   - timeouts, DNS/TLS failures and refused connections are `false`,
///   - it never throws: callers get a bool, not an exception.
///
/// The strict body check is the tunnel guard: without the bypass header,
/// ngrok answers with an HTML interstitial page instead of proxying to
/// Laravel, and a probe that accepted any 2xx would report Online while
/// every real API call failed.
/// ============================================================

void main() {
  const String base = 'https://probe.example.com/api';

  setUp(() {
    ApiConfig.setEndpointOverride(base);
  });

  tearDown(() {
    ApiConfig.clearEndpointOverride();
  });

  ApiClient clientAnswering(
    Future<http.Response> Function(http.Request request) handler,
  ) =>
      ApiClient(
        client: MockClient(
          (http.Request request) async => handler(request),
        ),
      );

  group('health URL construction', () {
    test('resolves to <base>/api/v1/health with no doubled segment', () async {
      http.Request? seen;

      final bool alive = await clientAnswering((http.Request request) async {
        seen = request;

        return http.Response('{"status":"online"}', 200);
      }).checkHealth();

      expect(alive, isTrue);
      expect(seen, isNotNull);
      expect(seen!.url.toString(), '$base/v1/health');
      expect(seen!.url.path, '/api/v1/health');
    });

    test('sends the tunnel bypass header the probe depends on', () async {
      http.Request? seen;

      await clientAnswering((http.Request request) async {
        seen = request;

        return http.Response('{"status":"online"}', 200);
      }).checkHealth();

      expect(seen!.headers['ngrok-skip-browser-warning'], 'true');
    });

    test('a base entered with /api/v1 still probes /api/v1/health once', () {
      ApiConfig.setEndpointOverride('https://probe.example.com/api/v1');

      expect(ApiConfig.healthUri.toString(),
          'https://probe.example.com/api/v1/health');
    });
  });

  group('successful answers', () {
    test('200 with status online is online', () async {
      final bool alive = await clientAnswering(
        (_) async => http.Response(
          '{"status":"online","database":"connected"}',
          200,
          headers: <String, String>{'content-type': 'application/json'},
        ),
      ).checkHealth();

      expect(alive, isTrue);
    });

    test('200 with status ok is online', () async {
      final bool alive = await clientAnswering(
        (_) async => http.Response(
          '{"status":"ok"}',
          200,
          headers: <String, String>{'content-type': 'application/json'},
        ),
      ).checkHealth();

      expect(alive, isTrue);
    });

    test('200 with an empty body is not a valid health response', () async {
      final bool alive = await clientAnswering(
        (_) async => http.Response('', 200),
      ).checkHealth();

      expect(alive, isFalse);
    });

    test('200 with a non-JSON body is not online (interstitial guard)',
        () async {
      final bool alive = await clientAnswering(
        (_) async => http.Response('<html>proxy page</html>', 200),
      ).checkHealth();

      expect(alive, isFalse);
    });

    test('a doubled /api prefix never reaches the server', () async {
      http.Request? seen;

      final ApiClient client = ApiClient(
        client: MockClient((http.Request request) async {
          seen = request;

          return http.Response(
            '{"status":"online"}',
            200,
            headers: <String, String>{'content-type': 'application/json'},
          );
        }),
      );

      // Absolute paths are tolerated: no /api/api/v1/... URL is built.
      final Uri uri = client.buildUri('/api/v1/auth/login');

      expect(uri.toString(), '${ApiConfig.baseUrl}/v1/auth/login');
      expect(uri.path, '/api/v1/auth/login');
      expect(seen, isNull);
    });
  });

  group('failed answers', () {
    test('500 is a server error, not online', () async {
      final bool alive = await clientAnswering(
        (_) async => http.Response('{"message":"boom"}', 500),
      ).checkHealth();

      expect(alive, isFalse);
    });

    test('404 is a route/config problem, not online', () async {
      final bool alive = await clientAnswering(
        (_) async => http.Response('{"message":"nope"}', 404),
      ).checkHealth();

      expect(alive, isFalse);
    });

    test('401 is a refusal, not a network outage (still not online)',
        () async {
      final bool alive = await clientAnswering(
        (_) async => http.Response('{"message":"Unauthenticated."}', 401),
      ).checkHealth();

      expect(alive, isFalse);
    });

    test('422 is a refusal, not a network outage (still not online)',
        () async {
      final bool alive = await clientAnswering(
        (_) async => http.Response('{"message":"Invalid."}', 422),
      ).checkHealth();

      expect(alive, isFalse);
    });

    test('a refused connection is not online and does not throw', () async {
      final bool alive = await clientAnswering(
        (_) async => throw http.ClientException('Connection refused'),
      ).checkHealth();

      expect(alive, isFalse);
    });

    test('a timed-out probe is not online and does not throw', () async {
      final bool alive = await clientAnswering(
        (_) async => throw TimeoutException('too slow'),
      ).checkHealth();

      expect(alive, isFalse);
    });
  });
}
