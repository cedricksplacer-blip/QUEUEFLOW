import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:connectivity_plus_platform_interface/connectivity_plus_platform_interface.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:queueflow_tmc/core/api/api_client.dart';
import 'package:queueflow_tmc/core/api/api_config.dart';
import 'package:queueflow_tmc/core/network/connection_messages.dart';
import 'package:queueflow_tmc/core/network/connectivity_service.dart';
import 'package:queueflow_tmc/core/storage/endpoint_store.dart';

/// ============================================================
/// QUEUEFLOW TMC — RUNTIME ENDPOINT OVERRIDE
///
/// The campus LAN address changes when the PC's DHCP lease changes, so the
/// address can be set inside the app (About > Server connection) instead of
/// only at build time. These tests pin the rules that keep that freedom safe:
///
///   - overrides are normalised and validated, never trusted blindly;
///   - plain http:// stays limited to local development addresses;
///   - a public-https override switches the whole app off the "campus Wi-Fi"
///     wording, because that sentence is wrong for a reachable internet host;
///   - the choice survives a restart through SharedPreferences.
/// ============================================================

/// A stub radio the test drives by hand.
class _StubRadio extends ConnectivityPlatform {
  _StubRadio(this._results);

  final List<ConnectivityResult> _results;
  final StreamController<List<ConnectivityResult>> _events =
      StreamController<List<ConnectivityResult>>.broadcast();

  @override
  Future<List<ConnectivityResult>> checkConnectivity() async => _results;

  @override
  Stream<List<ConnectivityResult>> get onConnectivityChanged => _events.stream;

  Future<void> close() => _events.close();
}

void main() {
  late _StubRadio radio;

  setUp(() {
    SharedPreferences.setMockInitialValues(<String, Object>{});
    ApiConfig.clearEndpointOverride();
    radio = _StubRadio(<ConnectivityResult>[ConnectivityResult.wifi]);
    ConnectivityPlatform.instance = radio;
  });

  tearDown(() async {
    ApiConfig.clearEndpointOverride();
    await radio.close();
  });

  group('validation', () {
    test('accepts a public https endpoint', () {
      expect(
        ApiConfig.validateEndpoint('https://example.com/api'),
        isNull,
      );
    });

    test('accepts a bare https host by assuming its /api root', () {
      expect(ApiConfig.validateEndpoint('https://example.com'), isNull);
      ApiConfig.setEndpointOverride('https://example.com');
      expect(ApiConfig.baseUrl, 'https://example.com/api');
    });

    test('accepts http only for local development addresses', () {
      expect(
        ApiConfig.validateEndpoint('http://192.168.1.10:8000/api'),
        isNull,
      );
      expect(
        ApiConfig.validateEndpoint('http://localhost:8000/api'),
        isNull,
      );
      expect(
        ApiConfig.validateEndpoint('http://203.0.113.9/api'),
        isNotNull,
        reason: 'plain http to a public host would send a token in the clear',
      );
    });

    test('rejects empty, malformed and non-http schemes', () {
      expect(ApiConfig.validateEndpoint(''), isNotNull);
      expect(ApiConfig.validateEndpoint('not a url'), isNotNull);
      expect(ApiConfig.validateEndpoint('ftp://example.com/api'), isNotNull);
      expect(
        ApiConfig.validateEndpoint('https://example.com/other'),
        isNotNull,
        reason: 'the base URL must be the /api root, not a deeper path',
      );
    });

    test('normalisation strips slashes and whitespace', () {
      ApiConfig.setEndpointOverride('  https://example.com/api/  ');
      expect(ApiConfig.baseUrl, 'https://example.com/api');
    });
  });

  group('override effect', () {
    test('default build targets the public tunnel', () {
      expect(ApiConfig.hasEndpointOverride, isFalse);
      expect(ApiConfig.requiresSameNetwork, isFalse);
      expect(ApiConfig.isPublicHttps, isTrue);
      expect(ApiConfig.isPrivateHost, isFalse);
    });

    test('a public override makes the build a public build', () {
      ApiConfig.setEndpointOverride('https://tunnel.example.com/api');

      expect(ApiConfig.hasEndpointOverride, isTrue);
      expect(ApiConfig.isPublicHttps, isTrue);
      expect(ApiConfig.isPrivateHost, isFalse);
      expect(ApiConfig.requiresSameNetwork, isFalse);
      expect(ApiConfig.displayHost, 'tunnel.example.com');
      expect(ApiConfig.healthUri.toString(), contains('tunnel.example.com'));
    });

    test('clearing returns to the compiled-in default', () {
      ApiConfig.setEndpointOverride('https://tunnel.example.com/api');
      ApiConfig.clearEndpointOverride();

      expect(ApiConfig.hasEndpointOverride, isFalse);
      expect(
        ApiConfig.baseUrl,
        'https://spelling-ocelot-opposing.ngrok-free.dev/api',
      );
    });
  });

  group('wording follows the endpoint', () {
    ConnectivityService deadServer() => ConnectivityService(
      api: ApiClient(
        client: MockClient(
          (_) async => throw http.ClientException('Connection refused'),
        ),
      ),
      connectivity: Connectivity(),
    );

    test('mobile data on a public build never mentions campus Wi-Fi',
        () async {
      ApiConfig.setEndpointOverride('https://tunnel.example.com/api');

      radio = _StubRadio(<ConnectivityResult>[ConnectivityResult.mobile]);
      ConnectivityPlatform.instance = radio;

      final ConnectivityService service = deadServer();
      await service.initialize();
      await service.checkBackend();

      expect(service.state, AppConnectionState.serverUnavailable);
      expect(service.message.title, ConnectionMessages.serverOfflineTitle);
      expect(service.message.body, isNot(contains('campus Wi-Fi')));
      expect(service.message.body, isNot(contains('No Internet')));

      service.dispose();
    });
  });

  group('persistence', () {
    test('a saved endpoint is restored on the next launch', () async {
      await EndpointStore.save('https://tunnel.example.com/api/');
      expect(ApiConfig.baseUrl, 'https://tunnel.example.com/api');

      // Simulate a restart: memory is reset, storage is not.
      ApiConfig.clearEndpointOverride();
      await EndpointStore.load();

      expect(ApiConfig.baseUrl, 'https://tunnel.example.com/api');
      expect(ApiConfig.hasEndpointOverride, isTrue);
    });

    test('an invalid saved value is ignored, not applied', () async {
      final SharedPreferences prefs = await SharedPreferences.getInstance();
      await prefs.setString(
        EndpointStore.endpointKey,
        'http://203.0.113.9/api',
      );

      await EndpointStore.load();

      expect(ApiConfig.hasEndpointOverride, isFalse);
      expect(
        ApiConfig.baseUrl,
        'https://spelling-ocelot-opposing.ngrok-free.dev/api',
      );
    });

    test('clearing forgets the endpoint', () async {
      await EndpointStore.save('https://tunnel.example.com/api');
      await EndpointStore.clear();

      expect(ApiConfig.hasEndpointOverride, isFalse);

      final SharedPreferences prefs = await SharedPreferences.getInstance();
      expect(prefs.getString(EndpointStore.endpointKey), isNull);
    });
  });
}
