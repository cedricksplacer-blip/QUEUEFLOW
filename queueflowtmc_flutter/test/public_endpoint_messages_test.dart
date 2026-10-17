import 'package:flutter_test/flutter_test.dart';

import 'package:queueflow_tmc/core/api/api_config.dart';
import 'package:queueflow_tmc/core/network/connection_messages.dart';

/// ============================================================
/// QUEUEFLOW TMC — PUBLIC ENDPOINT MESSAGING
///
/// A build pointed at a public HTTPS endpoint must not tell the reader to
/// join the same Wi-Fi as the server PC, and no user-facing sentence may name
/// a hard-coded development address that goes stale on the next DHCP lease.
/// These tests pin the wording so a future edit cannot reintroduce either.
/// ============================================================

void main() {
  group('unreachable-server wording', () {
    test('names no hard-coded development address', () {
      expect(ConnectionMessages.actionFailedBody, isNot(contains('192.168')));
      expect(ConnectionMessages.actionFailedBody, isNot(contains('127.0.0.1')));
      expect(ConnectionMessages.actionFailedBody, isNot(contains('localhost')));
    });

    test('says Server Offline and offers a retry', () {
      expect(ConnectionMessages.actionFailedBody, contains('Server Offline'));
      expect(ConnectionMessages.actionFailedBody, contains('Retry'));
      expect(
        ConnectionMessages.actionFailedBody,
        contains('internet connection'),
      );
    });

    test('diagnostic variant names the configured host', () {
      final String body = ConnectionMessages.actionFailedBodyWithHost();

      expect(body, contains(ApiConfig.displayHost));
      expect(body, contains('Server Offline'));
    });
  });

  group('endpoint shape', () {
    test('the default targets the public tunnel', () {
      // The compiled-in default is the public HTTPS tunnel: reachable over
      // any Wi-Fi or mobile data while the laptop, Laravel and the tunnel
      // are running. A same-Wi-Fi LAN address remains available as a
      // runtime override from the About screen.
      expect(ApiConfig.baseUrl,
          'https://spelling-ocelot-opposing.ngrok-free.dev/api');
      expect(ApiConfig.isPrivateHost, isFalse);
      expect(ApiConfig.isPublicHttps, isTrue);
      expect(ApiConfig.requiresSameNetwork, isFalse);
      expect(ApiConfig.isSecure, isTrue);
    });

    test('a LAN-configured build keeps the mobile-data wording', () {
      // The shipped default IS the LAN address, so this path is the normal
      // one: a phone on mobile data cannot reach the campus server, and the
      // message must say so instead of claiming there is no internet.
      ApiConfig.setEndpointOverride('http://192.168.1.154:8000/api');

      try {
        expect(ConnectionMessages.serverIsOnPrivateNetwork, isTrue);
        expect(
          ConnectionMessages.mobileDataPrivateServerBody,
          contains('mobile data'),
        );
      } finally {
        ApiConfig.clearEndpointOverride();
      }
    });
  });
}
