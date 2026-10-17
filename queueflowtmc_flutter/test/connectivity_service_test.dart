import 'dart:async';
import 'dart:convert';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:connectivity_plus_platform_interface/connectivity_plus_platform_interface.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:http/http.dart' as http;
import 'package:http/testing.dart';

import 'package:queueflow_tmc/core/api/api_client.dart';
import 'package:queueflow_tmc/core/api/api_config.dart';
import 'package:queueflow_tmc/core/network/connectivity_service.dart';

/// ============================================================
/// QUEUEFLOW TMC â€” CONNECTIVITY
///
/// The four situations in the brief, asserted one by one, because the whole
/// value of this layer is that it keeps them apart:
///
///   A  Wi-Fi up, Laravel up              -> ONLINE
///   B  Wi-Fi up, Laravel stopped         -> SERVER_UNAVAILABLE (not OFFLINE)
///   C  mobile data, Laravel on the LAN   -> SERVER_UNAVAILABLE, mobile-specific
///   D  no Wi-Fi and no mobile data       -> OFFLINE
///   E  Wi-Fi returns while running       -> back to ONLINE, no restart
///
/// Case B is the one worth a test on its own: it is the failure a
/// connectivity-only implementation gets wrong, and the wrong answer tells a
/// student standing in a queue to fix a network that is working perfectly.
///
/// Every test here runs against a stub radio and a stub HTTP client, so the
/// suite needs no device, no server and no network.
/// ============================================================

/// A stub radio the test drives by hand.
class FakeRadio extends ConnectivityPlatform {
  FakeRadio(this._results);

  List<ConnectivityResult> _results;
  final StreamController<List<ConnectivityResult>> _events =
      StreamController<List<ConnectivityResult>>.broadcast();

  @override
  Future<List<ConnectivityResult>> checkConnectivity() async => _results;

  @override
  Stream<List<ConnectivityResult>> get onConnectivityChanged => _events.stream;

  /// Simulates the OS reporting a new link.
  void emit(List<ConnectivityResult> results) {
    _results = results;

    _events.add(results);
  }

  Future<void> close() => _events.close();
}

void main() {
  const List<ConnectivityResult> wifi = <ConnectivityResult>[
    ConnectivityResult.wifi,
  ];
  const List<ConnectivityResult> mobile = <ConnectivityResult>[
    ConnectivityResult.mobile,
  ];
  const List<ConnectivityResult> none = <ConnectivityResult>[
    ConnectivityResult.none,
  ];

  late FakeRadio radio;

  /// A backend answering [handler]; defaults to a healthy Laravel.
  ConnectivityService serviceAnswering(
    http.Response Function(http.Request request) handler,
  ) => ConnectivityService(
        api: ApiClient(
          client: MockClient(
            (http.Request request) async => handler(request),
          ),
        ),
        connectivity: Connectivity(),
      );

  /// A backend whose /api/v1/health answers exactly what Laravel's HealthController
  /// answers.
  ConnectivityService serviceWithHealthyLaravel() =>
      serviceAnswering(
        (_) => http.Response(
          jsonEncode(<String, dynamic>{
            'status': 'ok',
            'api': 'queueflow-tmc',
            'version': 'v1',
          }),
          200,
          headers: <String, String>{
            'content-type': 'application/json; charset=utf-8',
          },
        ),
      );

  /// A backend where nothing is listening: the connection is refused.
  ///
  /// This is what a stopped `artisan serve` actually produces, as opposed to a
  /// timeout, and it is the case that must read as SERVER_UNAVAILABLE.
  ConnectivityService serviceWithDeadServer() => serviceAnswering(
        (_) => throw http.ClientException(
          'Connection refused',
          Uri.parse(ApiConfig.healthUri.toString()),
        ),
      );

  setUp(() {
    radio = FakeRadio(wifi);
    ConnectivityPlatform.instance = radio;
  });

  tearDown(() async {
    ApiConfig.clearEndpointOverride();
    await radio.close();
  });

  group('the four situations from the brief', () {
    test('A - Wi-Fi up and Laravel up is ONLINE', () async {
      final ConnectivityService service = serviceWithHealthyLaravel();

      await service.initialize();
      await service.checkBackend();

      expect(service.state, AppConnectionState.online);
      expect(service.link, NetworkLink.wifi);
      expect(service.isOnline, isTrue);
      expect(service.canReachServer, isTrue);

      service.dispose();
    });

    test('B - Wi-Fi up but Laravel stopped is SERVER_UNAVAILABLE', () async {
      final ConnectivityService service = serviceWithDeadServer();

      await service.initialize();
      await service.checkBackend();

      // The critical distinction. NOT offline: this phone has a perfectly good
      // network connection, and telling the reader otherwise sends them to fix
      // something that is already working.
      expect(service.state, AppConnectionState.serverUnavailable);
      expect(service.link, NetworkLink.wifi);
      expect(service.hasDeviceConnection, isTrue);
      expect(service.canReachServer, isFalse);

      // And the wording must not claim there is no internet.
      expect(service.message.body, isNot(contains('No Internet')));

      service.dispose();
    });

    test(
      'C - mobile data against a LAN server is SERVER_UNAVAILABLE and says so',
      () async {
        // A LAN override (same-Wi-Fi setup), which genuinely cannot be
        // reached over cellular. The shipped default is the public tunnel;
        // the LAN address below stands in for a stale same-Wi-Fi override
        // exercised here over a mobile link.
        ApiConfig.setEndpointOverride('http://192.168.1.154:8000/api');

        try {
          radio = FakeRadio(mobile);
          ConnectivityPlatform.instance = radio;

          final ConnectivityService service = serviceWithDeadServer();

          await service.initialize();
          await service.checkBackend();

          expect(service.state, AppConnectionState.serverUnavailable);
          expect(service.link, NetworkLink.mobile);

          // The configured base URL is a private LAN address, so this build
          // genuinely cannot reach it over cellular. The message has to name
          // that rather than claim there is no internet - there very much is.
          expect(ConnectionMessagesProbe.serverIsPrivate, isTrue);
          expect(service.message.title, 'Server Not Reachable');
          expect(service.message.body, contains('mobile data'));
          expect(service.message.body, contains('campus Wi-Fi'));

          service.dispose();
        } finally {
          ApiConfig.clearEndpointOverride();
        }
      },
    );

    test('D - no Wi-Fi and no mobile data is OFFLINE', () async {
      radio = FakeRadio(none);
      ConnectivityPlatform.instance = radio;

      final ConnectivityService service = serviceWithHealthyLaravel();

      await service.initialize();
      await service.checkBackend();

      expect(service.state, AppConnectionState.offline);
      expect(service.link, NetworkLink.none);
      expect(service.message.body, contains('No Internet Connection'));

      service.dispose();
    });
  });

  group('automatic reconnection', () {
    test('E - Wi-Fi returning while the app runs goes back to ONLINE', () async {
      radio = FakeRadio(none);
      ConnectivityPlatform.instance = radio;

      bool laravelUp = false;

      final ConnectivityService service = serviceAnswering((_) {
        if (!laravelUp) {
          throw http.ClientException(
            'Connection refused',
            Uri.parse(ApiConfig.healthUri.toString()),
          );
        }

        return http.Response(
          jsonEncode(<String, dynamic>{'status': 'ok'}),
          200,
          headers: <String, String>{
            'content-type': 'application/json; charset=utf-8',
          },
        );
      });

      await service.initialize();
      await service.checkBackend();
      expect(service.state, AppConnectionState.offline);

      // The phone walks back into range and somebody restarts artisan serve.
      laravelUp = true;
      radio.emit(wifi);

      // Long enough for the internal settle delay, so the debounced probe runs.
      await Future<void>.delayed(const Duration(milliseconds: 900));
      await service.checkBackend();

      expect(service.state, AppConnectionState.online);
      expect(service.link, NetworkLink.wifi);

      service.dispose();
    });

    test('a burst of radio events settles into one answer', () async {
      final ConnectivityService service = serviceWithHealthyLaravel();

      await service.initialize();

      // Android emits several events while a link associates. Each must not
      // produce its own probe.
      radio.emit(wifi);
      radio.emit(<ConnectivityResult>[ConnectivityResult.wifi]);
      radio.emit(<ConnectivityResult>[
        ConnectivityResult.wifi,
        ConnectivityResult.mobile,
      ]);

      await Future<void>.delayed(const Duration(milliseconds: 900));

      // Wi-Fi wins over mobile when both radios are up, because the phone will
      // prefer Wi-Fi for the request and the LAN server is reachable there.
      expect(service.link, NetworkLink.wifi);

      service.dispose();
    });
  });

  group('the health probe', () {
    test('is a real request to the real Laravel route', () async {
      final List<http.Request> seen = <http.Request>[];

      final ConnectivityService service = serviceAnswering((http.Request r) {
        seen.add(r);

        return http.Response(
          jsonEncode(<String, dynamic>{'status': 'ok'}),
          200,
          headers: <String, String>{
            'content-type': 'application/json; charset=utf-8',
          },
        );
      });

      await service.initialize();
      await service.checkBackend();

      expect(seen, isNotEmpty);

      final http.Request probe = seen.first;

      // /api/v1/health, the same versioned prefix every other call uses: the
      // probe must exercise the versioned route, not a legacy alias.
      expect(probe.url.path, endsWith('/api/v1/health'));
      expect(probe.method, 'GET');

      service.dispose();
    });

    test('never reports a server as reachable on its own', () async {
      final ConnectivityService service = serviceWithDeadServer();

      await service.initialize();
      await service.checkBackend();

      expect(service.isOnline, isFalse);

      // A host is only recorded when a probe actually got a response.
      expect(service.snapshot.reachableHost, isNull);

      service.dispose();
    });

    test('a caller arriving mid-probe waits instead of being refused', () async {
      // The startup path fires a probe in the background, so the very first
      // screen to ask for connectivity is always asking while a probe is
      // already in flight. Returning `checking` to it - which is what a plain
      // bool guard did - refused the login form on every single launch.
      int probes = 0;

      final ConnectivityService service = serviceAnswering((_) {
        probes++;

        return http.Response(
          jsonEncode(<String, dynamic>{'status': 'ok'}),
          200,
          headers: <String, String>{
            'content-type': 'application/json; charset=utf-8',
          },
        );
      });

      await service.initialize();

      // No sleep: this must land while the background probe is still running.
      final AppConnectionState settled = await service.checkBackend();

      expect(
        settled,
        AppConnectionState.online,
        reason: 'a mid-probe caller gets the answer, not a refusal',
      );
      expect(
        probes,
        1,
        reason: 'and it must not have issued a second probe to get it',
      );

      service.dispose();
    });

    test('a failed real request downgrades a stale ONLINE state', () async {
      final ConnectivityService service = serviceWithHealthyLaravel();

      await service.initialize();
      await service.checkBackend();
      expect(service.state, AppConnectionState.online);

      // The server dies between probes. Nothing has told the service yet, so it
      // is still briefly optimistic - which is exactly why a failed request is
      // allowed to correct it.
      service.reportUnreachable();

      expect(service.state, AppConnectionState.serverUnavailable);

      service.dispose();
    });
  });

  group('the action gate', () {
    test('refuses a server action while the server is unreachable', () async {
      final ConnectivityService service = serviceWithDeadServer();

      await service.initialize();
      await service.checkBackend();

      bool blocked = false;
      bool ran = false;

      final bool? result = await service.requireServer<bool>(
        () async {
          ran = true;
          return true;
        },
        onBlocked: (_) => blocked = true,
      );

      expect(ran, isFalse, reason: 'no request may be sent while unreachable');
      expect(blocked, isTrue);
      expect(result, isNull);

      service.dispose();
    });

    test('allows the action once the server answers', () async {
      final ConnectivityService service = serviceWithHealthyLaravel();

      await service.initialize();
      await service.checkBackend();

      bool ran = false;

      final bool? result = await service.requireServer<bool>(
        () async {
          ran = true;
          return true;
        },
        onBlocked: (_) {},
      );

      expect(ran, isTrue);
      expect(result, isTrue);

      service.dispose();
    });

    test('a stale offline reading is re-probed, not trusted', () async {
      radio = FakeRadio(none);
      ConnectivityPlatform.instance = radio;

      final ConnectivityService service = serviceWithHealthyLaravel();

      await service.initialize();
      await service.checkBackend();
      expect(service.state, AppConnectionState.offline);

      // The radio came back but the service has not been told yet - exactly the
      // moment a user walks back into Wi-Fi range and taps a button. Failing
      // them on the stale reading would be the most annoying version of this
      // bug, so the gate re-reads the radio instead of trusting its cache.
      radio.emit(wifi);

      bool ran = false;

      await service.requireServer<void>(
        () async => ran = true,
        onBlocked: (_) {},
      );

      expect(ran, isTrue);
      expect(service.state, AppConnectionState.online);

      service.dispose();
    });

    test('the gate forgets a host that has stopped answering', () async {
      bool laravelUp = true;

      final ConnectivityService service = serviceAnswering((_) {
        if (!laravelUp) {
          throw http.ClientException(
            'Connection refused',
            Uri.parse(ApiConfig.healthUri.toString()),
          );
        }

        return http.Response(
          jsonEncode(<String, dynamic>{'status': 'ok'}),
          200,
          headers: <String, String>{
            'content-type': 'application/json; charset=utf-8',
          },
        );
      });

      await service.initialize();
      await service.checkBackend();
      expect(service.snapshot.reachableHost, isNotNull);

      // Losing the server must also drop the address. Keeping it would let the
      // UI show a stale development IP next to "unreachable" and claim we
      // know where the server is, which is only true if something answered.
      laravelUp = false;
      await service.checkBackend();

      expect(service.state, AppConnectionState.serverUnavailable);
      expect(
        service.snapshot.reachableHost,
        isNull,
        reason: 'a host is only remembered when a probe actually reached it',
      );

      service.dispose();
    });
  });

  group('ApiConfig', () {
    test('defaults to the public tunnel API, never localhost', () {
      // The single most important assertion in this file. `localhost` on a
      // phone is the phone, so a build defaulting to it works over USB and
      // fails the moment the cable is pulled. The default is the public
      // HTTPS tunnel, so the shipped app works over any Wi-Fi or mobile
      // data while the laptop, Laravel and the tunnel are running.
      expect(
        ApiConfig.baseUrl,
        'https://spelling-ocelot-opposing.ngrok-free.dev/api',
      );
      expect(ApiConfig.baseUrl, isNot(contains('localhost')));
      expect(ApiConfig.baseUrl, isNot(contains('127.0.0.1')));
      expect(ApiConfig.isPrivateHost, isFalse);
      expect(ApiConfig.isPublicHttps, isTrue);
      expect(ApiConfig.requiresSameNetwork, isFalse);
    });

    test('a LAN override is recognised as private, so mobile data is not faked',
        () {
      ApiConfig.setEndpointOverride('http://192.168.1.154:8000/api');

      try {
        expect(ApiConfig.isPrivateHost, isTrue);
        expect(ApiConfig.isSecure, isFalse);
        expect(ApiConfig.requiresSameNetwork, isTrue);
      } finally {
        ApiConfig.clearEndpointOverride();
      }
    });

    test('a public host is neither private nor assumed secure-less', () {
      expect(_isPrivateHost('https://queueflow.example.edu/api'), isFalse);
      expect(_isPrivateHost('http://172.16.4.9:8000/api'), isTrue);
      expect(_isPrivateHost('http://172.32.4.9:8000/api'), isFalse);
      expect(_isPrivateHost('http://10.1.2.3:8000/api'), isTrue);
      expect(_isPrivateHost('http://192.168.1.5:8000/api'), isTrue);
    });
  });
}

/// Re-implements ApiConfig's private-host test against an arbitrary base, so
/// the ranges are checked without needing six builds.
///
/// Mirrors [ApiConfig.isPrivateHost]; if that ever changes, this should change
/// with it.
bool _isPrivateHost(String baseUrl) {
  final String host = Uri.parse(baseUrl).host;

  if (host.startsWith('10.') ||
      host.startsWith('192.168.') ||
      host.startsWith('127.') ||
      host.startsWith('169.254.') ||
      host.endsWith('.local')) {
    return true;
  }

  if (host.startsWith('172.')) {
    // The SECOND octet is what identifies 172.16.0.0/12, so 172.16.x.x and
    // 172.31.x.x are in and 172.15.x.x / 172.32.x.x are out.
    final int? second = int.tryParse(host.split('.')[1]);

    return second != null && second >= 16 && second <= 31;
  }

  return false;
}

/// Small indirection so the mobile-data test reads clearly.
abstract final class ConnectionMessagesProbe {
  static bool get serverIsPrivate => _isPrivateHost(ApiConfig.baseUrl);
}