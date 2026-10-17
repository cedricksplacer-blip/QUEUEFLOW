import 'package:flutter/material.dart';

import '../core/app_colors.dart';
import '../core/glass.dart';
import '../core/when_text.dart';
import '../models/announcement.dart';
import '../models/queue_ticket.dart';
import '../repositories/auth_repository.dart';
import '../widgets/qf_page.dart';

/// ============================================================
/// QUEUEFLOW TMC — NOTIFICATIONS
///
/// One feed, built entirely from data the server already sent: the published
/// announcements from the bootstrap payload, and the student's own tickets.
///
/// WHY THIS IS NOT A LOCAL INBOX
///
/// A separate notifications table on the device would need a push channel, a
/// sync cursor and a way to reconcile two sources of truth, and the only two
/// things a student actually needs to hear about — their number is moving, and
/// the campus has published a notice — are already in the bootstrap payload.
/// Deriving the feed from that means it cannot drift from what the dashboard
/// shows, and it works offline on the last payload the same as the dashboard
/// does.
///
/// WHICH EVENTS APPEAR IS THE CAMPUS'S CALL
///
/// [SystemSettings] carries the notification toggles the registrar sets in the
/// admin console. An office that has switched off "now serving" alerts is not
/// overruled here; the feed simply omits that row. The app honours the setting
/// rather than overriding it with its own idea of what is worth telling
/// somebody.
/// ============================================================

class NotificationsScreen extends StatelessWidget {
  const NotificationsScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final AuthRepository auth = AuthRepository.instance;
    final List<QueueNotification> feed = buildNotificationFeed(
      announcements: auth.announcements,
      tickets: auth.tickets,
      settings: auth.settings,
      audienceRole: auth.role.wire.isEmpty ? 'user' : auth.role.wire,
    );

    return QfPage(
      title: 'Notifications',
      subtitle: feed.isEmpty
          ? 'Nothing needs your attention'
          : '${feed.length} ${feed.length == 1 ? 'update' : 'updates'}',
      onBack: () => Navigator.of(context).maybePop(),
      children: <Widget>[
        if (feed.isEmpty)
          const QfEmptyState(
            icon: Icons.notifications_none_rounded,
            title: 'No notifications',
            message:
                'Notices from your offices and updates on your queue '
                'numbers will appear here.',
          )
        else
          for (final QueueNotification item in feed) ...<Widget>[
            NotificationTile(item: item),
            const SizedBox(height: 10),
          ],
      ],
    );
  }
}

/// What a notification is about, which decides how it is coloured.
enum NotificationTone {
  /// The student's own number moving.
  queue,

  /// A notice published by the campus.
  notice,

  /// A finished visit.
  done,
}

/// One row in the feed.
class QueueNotification {
  const QueueNotification({
    required this.key,
    required this.title,
    required this.body,
    required this.when,
    required this.tone,
  });

  /// Stable identity of the event behind this row.
  ///
  /// The notification screen never uses it, but the system-notification
  /// service does: it is what allows "one row, one sound" — a row built on
  /// every poll is only ever announced once, because the key is persisted
  /// after the first announcement.
  final String key;

  final String title;
  final String body;
  final DateTime? when;
  final NotificationTone tone;
}

/// Builds the feed, newest first.
///
/// Ticket events are derived from the student's own rows rather than from a
/// device-side log, so a ticket taken on another device still shows up here.
/// An event the campus has switched off in [SystemSettings] is omitted rather
/// than filtered at the display layer, so the count in the header and the rows
/// underneath it can never disagree.
List<QueueNotification> buildNotificationFeed({
  required List<Announcement> announcements,
  required List<QueueTicket> tickets,
  required SystemSettings settings,
  String audienceRole = 'user',
}) {
  final List<QueueNotification> feed = <QueueNotification>[];

  for (final QueueTicket ticket in tickets) {
    final String where = ticket.officeName;
    final String number = ticket.queueNumber;

    // The office reference, not the display string: the model substitutes a
    // placeholder when there is no service, and comparing against that
    // placeholder's character is how a separator ends up on its own.
    final ServiceRef? service = ticket.service;

    if (ticket.isCancelled && settings.ticketCancelledNotification) {
      final String reason = (ticket.cancelReason ?? '').trim();

      feed.add(
        QueueNotification(
          key: 'ticket:${ticket.id}:cancelled',
          title: 'Number $number was cancelled',
          body: reason.isEmpty
              ? 'Your ticket at $where is no longer in the queue.'
              : 'Your ticket at $where is no longer in the queue. Reason: $reason',
          when: ticket.servedAt ?? ticket.calledAt ?? ticket.createdAt,
          tone: NotificationTone.queue,
        ),
      );
    }

    if (ticket.isServing && settings.nowServingNotification) {
      feed.add(
        QueueNotification(
          key: 'ticket:${ticket.id}:serving',
          title: ticket.isArrived
              ? 'Number $number — you have arrived'
              : 'Number $number is now being served',
          body: ticket.isArrived
              ? 'Timer stopped at $where. Please stay while staff assist you.'
              : service == null
                  ? 'Go to $where before your timer expires.'
                  : 'Go to $where — ${service.name} — before your timer expires.',
          when: ticket.arrivedAt ?? ticket.calledAt,
          tone: NotificationTone.queue,
        ),
      );

      // One-minute warning while the countdown is still running.
      if (ticket.isCountdownWarning) {
        feed.add(
          QueueNotification(
            key: 'ticket:${ticket.id}:ending-soon',
            title: 'Number $number — 1 minute left',
            body: 'Proceed to $where now or your serving position will be released.',
            when: ticket.calledAt,
            tone: NotificationTone.queue,
          ),
        );
      }

      // Deadline passed without arrival: the server releases the position
      // (scheduler + lazy expiry), and this row explains what happened.
      if (ticket.isCountdownExpired) {
        feed.add(
          QueueNotification(
            key: 'ticket:${ticket.id}:expired',
            title: 'Number $number — call expired',
            body:
                'Your serving position at $where was released. Follow the office rules for another queue number.',
            when: ticket.expiresAt ?? ticket.calledAt,
            tone: NotificationTone.queue,
          ),
        );
      }
    }

    if (ticket.isNoShow) {
      feed.add(
        QueueNotification(
          key: 'ticket:${ticket.id}:no-show',
          title: 'Number $number marked as No-Show',
          body: 'Staff at $where recorded this ticket as No-Show. It is kept in your history.',
          when: ticket.noShowAt ?? ticket.expiresAt ?? ticket.calledAt,
          tone: NotificationTone.queue,
        ),
      );
    }

    if (ticket.isServed && settings.ticketCompletedNotification) {
      feed.add(
        QueueNotification(
          key: 'ticket:${ticket.id}:served',
          title: 'Number $number was served',
          body: 'Your visit to $where is complete.',
          when: ticket.servedAt,
          tone: NotificationTone.done,
        ),
      );
    }
  }

  if (settings.announcementNotification) {
    for (final Announcement announcement in announcements) {
      if (!announcement.isLiveForRole(audienceRole)) {
        continue;
      }

      feed.add(
        QueueNotification(
          key: 'announcement:${announcement.id}',
          title: announcement.title,
          body: announcement.body,
          when: announcement.createdAt,
          tone: NotificationTone.notice,
        ),
      );
    }
  }

  feed.sort((QueueNotification a, QueueNotification b) {
    final DateTime left = a.when ?? DateTime(1970);
    final DateTime right = b.when ?? DateTime(1970);

    return right.compareTo(left);
  });

  return feed;
}

/// How many rows a bell should show.
///
/// Only counts what the reader has not already been shown on the ticket
/// screens: a live number, and notices published today. Read state is
/// deliberately not persisted — a dot that survives a restart and cannot be
/// cleared is worse than one that returns after a refresh.
int unreadNotificationCount({
  required List<Announcement> announcements,
  required QueueTicket? activeTicket,
  DateTime? now,
  String audienceRole = 'user',
}) {
  final DateTime reference = now ?? DateTime.now();
  final DateTime startOfToday = DateTime(
    reference.year,
    reference.month,
    reference.day,
  );

  int count = announcements
      .where(
        (Announcement a) =>
            a.isLiveForRole(audienceRole) &&
            (a.createdAt?.isAfter(startOfToday) ?? false),
      )
      .length;

  if (activeTicket != null && activeTicket.isActive) {
    count++;
  }

  return count;
}

/// A single row.
class NotificationTile extends StatelessWidget {
  const NotificationTile({super.key, required this.item});

  final QueueNotification item;

  static Color accentFor(NotificationTone tone) => switch (tone) {
    NotificationTone.queue => AppColors.accent,
    NotificationTone.notice => AppColors.blue,
    NotificationTone.done => AppColors.success,
  };

  static IconData iconFor(NotificationTone tone) => switch (tone) {
    NotificationTone.queue => Icons.confirmation_number_rounded,
    NotificationTone.notice => Icons.campaign_outlined,
    NotificationTone.done => Icons.check_circle_outline_rounded,
  };

  @override
  Widget build(BuildContext context) {
    final Color accent = accentFor(item.tone);

    return GlassCard(
      padding: const EdgeInsets.all(16),
      radius: 20,
      blur: 18,
      glow: accent,
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Container(
            width: 42,
            height: 42,
            decoration: BoxDecoration(
              color: accent.withValues(alpha: 0.16),
              borderRadius: BorderRadius.circular(13),
              border: Border.all(color: accent.withValues(alpha: 0.42)),
            ),
            child: Icon(iconFor(item.tone), color: accent, size: 21),
          ),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Text(
                  item.title,
                  style: const TextStyle(
                    color: Colors.white,
                    fontSize: 14.5,
                    fontWeight: FontWeight.w800,
                    height: 1.3,
                  ),
                ),
                const SizedBox(height: 5),
                Text(
                  item.body,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.66),
                    fontSize: 12.5,
                    height: 1.45,
                  ),
                ),
                if (item.when != null) ...<Widget>[
                  const SizedBox(height: 8),
                  Text(
                    WhenText.relative(item.when!),
                    style: TextStyle(
                      color: Colors.white.withValues(alpha: 0.40),
                      fontSize: 11,
                      fontWeight: FontWeight.w600,
                    ),
                  ),
                ],
              ],
            ),
          ),
        ],
      ),
    );
  }
}
