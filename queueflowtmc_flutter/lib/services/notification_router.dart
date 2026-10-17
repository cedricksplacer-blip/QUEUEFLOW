import 'package:flutter/foundation.dart';

/// ============================================================
/// QUEUEFLOW TMC — NOTIFICATION TAP ROUTER (PURE LOGIC)
///
/// Decides which screen a tapped system notification should open, from the
/// payload key the notification was shown with. Payload keys are the stable
/// event keys from `notification_events.dart`, prefixed per account
/// (`u{id}:...`), so routing is string matching on the unprefixed tail:
///
///   `...:ticket:{id}:issued|cancelled|serving|served|almost-ready`
///       -> the student's ticket screen (My Ticket)
///   `...:announcement:{id}` -> the announcements screen
///   anything else (or empty) -> no deep link; the app just opens
///
/// Pure logic: no platform calls, no navigation — the shell consumes the
/// pending payload and performs the actual push. Every rule here is
/// unit-testable without a device.
/// ============================================================

/// Where a tapped notification leads.
enum NotificationDestination {
  /// The ticket screen (any ticket lifecycle event).
  ticket,

  /// The announcements screen.
  announcements,

  /// No deep link: open the app as normal.
  none,
}

/// Parses a notification payload into a destination.
NotificationDestination destinationForPayload(String? payload) {
  if (payload == null || payload.isEmpty) {
    return NotificationDestination.none;
  }

  if (payload.contains(':ticket:')) {
    return NotificationDestination.ticket;
  }

  if (payload.contains('announcement:')) {
    return NotificationDestination.announcements;
  }

  return NotificationDestination.none;
}

/// Holds a tapped-notification payload until the shell can act on it.
///
/// A tap can arrive before the shell exists (cold start) or while it is
/// already showing (warm tap). [consume] hands the payload over exactly
/// once, so a single tap can never open the same screen twice.
class NotificationRouter with ChangeNotifier {
  NotificationRouter._();

  static final NotificationRouter instance = NotificationRouter._();

  String? _pending;

  /// Queues a tapped payload for the shell. Overwrites any older unconsumed
  /// tap: the newest tap describes what the user most recently asked for.
  void handleTap(String? payload) {
    if (payload == null || payload.isEmpty) {
      return;
    }

    _pending = payload;
    notifyListeners();
  }

  /// Takes the pending payload, if any, leaving none behind.
  String? consume() {
    final String? payload = _pending;
    _pending = null;
    return payload;
  }

  /// Whether a tap is waiting for the shell.
  bool get hasPending => _pending != null;

  @visibleForTesting
  void debugReset() {
    _pending = null;
    notifyListeners();
  }
}
