import 'package:flutter_test/flutter_test.dart';
import 'package:queueflow_tmc/services/notification_router.dart';

/// Tap routing is pure payload parsing plus exactly-once delivery, so it is
/// tested without a device, a plugin, or the shell.
void main() {
  group('destinationForPayload', () {
    test('ticket lifecycle keys route to the ticket screen', () {
      for (final String tail in <String>[
        'ticket:12:issued',
        'ticket:12:almost-ready',
        'ticket:12:cancelled',
        'ticket:12:serving',
        'ticket:12:served',
      ]) {
        expect(
          destinationForPayload('u7:$tail'),
          NotificationDestination.ticket,
          reason: tail,
        );
      }
    });

    test('announcement keys route to the announcements screen', () {
      expect(
        destinationForPayload('u7:announcement:3'),
        NotificationDestination.announcements,
      );
    });

    test('empty, null and unknown payloads route nowhere', () {
      expect(destinationForPayload(null), NotificationDestination.none);
      expect(destinationForPayload(''), NotificationDestination.none);
      expect(
        destinationForPayload('u7:something-else'),
        NotificationDestination.none,
      );
    });
  });

  group('NotificationRouter', () {
    setUp(() => NotificationRouter.instance.debugReset());

    test('a tap is delivered exactly once', () {
      NotificationRouter.instance.handleTap('u7:ticket:12:serving');

      expect(NotificationRouter.instance.hasPending, isTrue);
      expect(
        NotificationRouter.instance.consume(),
        'u7:ticket:12:serving',
      );
      expect(NotificationRouter.instance.hasPending, isFalse);
      expect(NotificationRouter.instance.consume(), isNull);
    });

    test('empty taps are ignored', () {
      NotificationRouter.instance.handleTap('');
      NotificationRouter.instance.handleTap(null);

      expect(NotificationRouter.instance.hasPending, isFalse);
    });

    test('the newest tap wins', () {
      NotificationRouter.instance.handleTap('u7:ticket:12:serving');
      NotificationRouter.instance.handleTap('u7:announcement:3');

      expect(
        NotificationRouter.instance.consume(),
        'u7:announcement:3',
      );
    });
  });
}
