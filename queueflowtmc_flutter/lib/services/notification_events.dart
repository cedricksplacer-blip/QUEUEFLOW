import '../models/announcement.dart';
import '../models/queue_ticket.dart';
import '../screens/notifications_screen.dart';

/// ============================================================
/// QUEUEFLOW TMC — SYSTEM NOTIFICATION EVENTS (PURE LOGIC)
///
/// Decides *what* the Android notification layer is allowed to announce:
/// which events exist for the current payload, which of them this device has
/// already announced, and which are too old to be worth a sound right now.
/// It performs no platform calls — the plugin glue lives in
/// `notification_service.dart` — so every rule below is unit-testable.
///
/// ONE SOURCE OF TRUTH FOR CONTENT
///
/// The four feed events (cancelled / serving / served / announcement) are
/// produced by [buildNotificationFeed], the same builder that draws the
/// in-app Notifications screen, so a system notification can never disagree
/// with the feed row the student later reads. The builder assigns each row a
/// stable key, and that key is what makes an event announceable exactly once.
///
/// TWO EVENTS THE FEED DOES NOT CARRY
///
/// "Issued" and "turn close" are settings-screen promises without a feed row
/// (the issued dialog already tells the student at the moment of taking a
/// ticket), so they are built here from the ticket itself. They follow the
/// same rules: the registrar's toggle decides whether they exist at all.
///
/// THE THREE GATES ON ANY ANNOUNCEMENT
///
/// 1. Seen — a key already on the device is never announced again, no matter
///    how many times the poller runs, so one event means one sound.
/// 2. Recency — a timestamped event older than [maxAge] has its key recorded
///    but is not announced: waking a student with a two-hour-old "number is
///    being served" is noise, not information. Events without a timestamp
///    (the live "almost ready" probe) are exempt, because they describe the
///    present moment rather than something that happened.
/// 3. The campus toggles — handled upstream: an event whose toggle is off is
///    never built, so it leaves no key behind either. If the registrar
///    re-enables the toggle within the recency window, the event becomes
///    announceable, exactly like it becomes visible in the feed.
/// ============================================================

/// One candidate for the system-notification layer.
class NotificationEvent {
  const NotificationEvent({
    required this.key,
    required this.title,
    required this.body,
    this.when,
  });

  /// Stable identity: one key, one announcement, ever.
  final String key;
  final String title;
  final String body;

  /// When the underlying thing happened; null for live-only events.
  final DateTime? when;
}

/// The result of one evaluation pass.
class NotificationEventBatch {
  const NotificationEventBatch({
    required this.toAnnounce,
    required this.allKeys,
  });

  /// Unseen, recent, toggle-on events, newest first.
  final List<NotificationEvent> toAnnounce;

  /// Every key the current payload produced, seen or not. The caller
  /// persists this so the next pass treats today's events as history.
  final Set<String> allKeys;
}

/// Builds the announcement decisions for the current payload.
///
/// [seenKeys] must already be persisted (it is only read here). [keyPrefix]
/// scopes keys per account so a second student signing in on the same device
/// still hears about announcements the first one consumed.
NotificationEventBatch buildNotificationEvents({
  required List<Announcement> announcements,
  required List<QueueTicket> tickets,
  required SystemSettings settings,
  required QueueProgress? progress,
  required Set<String> seenKeys,
  String keyPrefix = '',
  DateTime? now,
  Duration maxAge = const Duration(hours: 2),
}) {
  final DateTime reference = now ?? DateTime.now();
  final List<NotificationEvent> candidates = <NotificationEvent>[];

  // The feed events, content untouched.
  for (final QueueNotification row in buildNotificationFeed(
    announcements: announcements,
    tickets: tickets,
    settings: settings,
  )) {
    candidates.add(
      NotificationEvent(
        key: '$keyPrefix${row.key}',
        title: row.title,
        body: row.body,
        when: row.when,
      ),
    );
  }

  for (final QueueTicket ticket in tickets) {
    // "When your ticket is issued": only while the ticket is still waiting —
    // announcing a number that has already been served or cancelled would
    // contradict the served/cancelled event that covers it instead.
    if (ticket.isWaiting && settings.ticketCreatedNotification) {
      candidates.add(
        NotificationEvent(
          key: '${keyPrefix}ticket:${ticket.id}:issued',
          title: 'Your queue number is ${ticket.queueNumber}.',
          body: '${ticket.officeName} — keep this number and watch the board.',
          when: ticket.createdAt,
        ),
      );
    }
  }

  // "When your turn is close": the live progress read belongs to the active
  // ticket, so it is matched against that ticket only, and only while it is
  // still waiting. peopleAhead <= 2 mirrors the near-end of the queue the
  // dashboard itself shows; the event has no timestamp because it describes
  // the present and is held to the one-shot key rather than the age gate.
  QueueTicket? active;
  for (final QueueTicket ticket in tickets) {
    if (ticket.isActive) {
      active = ticket;
      break;
    }
  }

  final QueueTicket? nearEnd = active;

  if (nearEnd != null &&
      nearEnd.isWaiting &&
      settings.queueAlmostReadyNotification &&
      progress != null &&
      progress.peopleAhead <= 2) {
    candidates.add(
      NotificationEvent(
        key: '${keyPrefix}ticket:${nearEnd.id}:almost-ready',
        title: 'Your turn is approaching.',
        body: 'Please prepare.',
      ),
    );
  }

  candidates.sort((NotificationEvent a, NotificationEvent b) {
    final DateTime left = a.when ?? reference;
    final DateTime right = b.when ?? reference;

    return right.compareTo(left);
  });

  final List<NotificationEvent> toAnnounce = <NotificationEvent>[];
  final Set<String> allKeys = <String>{};

  for (final NotificationEvent event in candidates) {
    if (!allKeys.add(event.key) || seenKeys.contains(event.key)) {
      continue;
    }

    final DateTime? when = event.when;
    if (when != null && reference.difference(when) > maxAge) {
      continue;
    }

    toAnnounce.add(event);
  }

  return NotificationEventBatch(toAnnounce: toAnnounce, allKeys: allKeys);
}
