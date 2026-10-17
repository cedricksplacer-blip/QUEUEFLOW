import 'package:flutter_test/flutter_test.dart';

import 'package:queueflow_tmc/core/when_text.dart';
import 'package:queueflow_tmc/models/announcement.dart';
import 'package:queueflow_tmc/models/queue_ticket.dart';
import 'package:queueflow_tmc/screens/notifications_screen.dart';

/// ============================================================
/// QUEUEFLOW TMC — NOTIFICATION FEED
///
/// The feed is derived from the bootstrap payload rather than stored on the
/// device. These tests pin the two things that decides: which events reach a
/// student, and that the registrar's notification switches actually switch
/// something off.
///
/// The switches are asserted because a feed that ignores them is worse than no
/// feed at all: the campus turns "now serving" off at a quiet office precisely
/// so students are not interrupted, and a client that overrules that is a bug
/// the office cannot work around.
/// ============================================================

void main() {
  final DateTime now = DateTime(2026, 3, 14, 10, 30);

  SystemSettings settingsWith({
    bool nowServing = true,
    bool completed = true,
    bool cancelled = true,
    bool announcements = true,
  }) => SystemSettings(
    nowServingNotification: nowServing,
    ticketCompletedNotification: completed,
    ticketCancelledNotification: cancelled,
    announcementNotification: announcements,
  );

  QueueTicket ticket({
    int id = 1,
    String number = 'A-001',
    String status = 'serving',
    String office = 'Registrar',
    String? service = 'Transcript request',
    DateTime? calledAt,
    DateTime? servedAt,
    DateTime? createdAt,
  }) => QueueTicket(
    id: id,
    queueNumber: number,
    status: status,
    office: OfficeRef(id: 2, name: office, code: 'REG'),
    service: service == null ? null : ServiceRef(id: 5, name: service),
    calledAt: calledAt,
    servedAt: servedAt,
    createdAt: createdAt,
  );

  group('ticket events', () {
    test('announces a number that is being served', () {
      final List<QueueNotification> feed = buildNotificationFeed(
        announcements: const <Announcement>[],
        tickets: <QueueTicket>[
          ticket(
            calledAt: now.subtract(const Duration(minutes: 4)),
          ),
        ],
        settings: settingsWith(),
      );

      expect(feed, hasLength(1));
      expect(feed.single.tone, NotificationTone.queue);
      expect(feed.single.title, contains('A-001'));
      expect(feed.single.body, contains('Registrar'));
      // The service is what tells a student which window to walk to.
      expect(feed.single.body, contains('Transcript request'));
    });

    test('stays quiet while a ticket is only waiting', () {
      final List<QueueNotification> feed = buildNotificationFeed(
        announcements: const <Announcement>[],
        tickets: <QueueTicket>[ticket(status: 'waiting')],
        settings: settingsWith(),
      );

      // "You are still waiting" is the dashboard's job, not the feed's.
      expect(feed, isEmpty);
    });

    test('confirms a completed visit', () {
      final List<QueueNotification> feed = buildNotificationFeed(
        announcements: const <Announcement>[],
        tickets: <QueueTicket>[
          ticket(
            status: 'served',
            servedAt: now.subtract(const Duration(hours: 2)),
          ),
        ],
        settings: settingsWith(),
      );

      expect(feed.single.tone, NotificationTone.done);
      expect(feed.single.title, contains('was served'));
    });

    test('leaves out the service name when the office has none', () {
      final List<QueueNotification> feed = buildNotificationFeed(
        announcements: const <Announcement>[],
        tickets: <QueueTicket>[ticket(service: null)],
        settings: settingsWith(),
      );

      // A separator with nothing after it reads as a rendering bug.
      expect(feed.single.body, 'Go to Registrar before your timer expires.');
    });
  });

  group('the registrar switches', () {
    test('omits now-serving when that alert is switched off', () {
      final List<QueueNotification> feed = buildNotificationFeed(
        announcements: const <Announcement>[],
        tickets: <QueueTicket>[ticket()],
        settings: settingsWith(nowServing: false),
      );

      expect(feed, isEmpty);
    });

    test('omits completions when that alert is switched off', () {
      final List<QueueNotification> feed = buildNotificationFeed(
        announcements: const <Announcement>[],
        tickets: <QueueTicket>[ticket(status: 'served', servedAt: now)],
        settings: settingsWith(completed: false),
      );

      expect(feed, isEmpty);
    });

    test('omits cancellations when that alert is switched off', () {
      final List<QueueNotification> feed = buildNotificationFeed(
        announcements: const <Announcement>[],
        tickets: <QueueTicket>[
          ticket(status: 'cancelled', servedAt: now),
        ],
        settings: settingsWith(cancelled: false),
      );

      expect(feed, isEmpty);
    });

    test('omits notices when announcements are switched off', () {
      final List<QueueNotification> feed = buildNotificationFeed(
        announcements: <Announcement>[
          Announcement(
            id: 1,
            title: 'Cashier closes at 5:00 PM',
            body: 'Bring your ID.',
            createdAt: now,
          ),
        ],
        tickets: const <QueueTicket>[],
        settings: settingsWith(announcements: false),
      );

      expect(feed, isEmpty);
    });

    test('still announces one event while the others are switched off', () {
      // The switches are per-event, not a master mute: a student who has
      // turned off completion notices still needs to know they were called.
      final List<QueueNotification> feed = buildNotificationFeed(
        announcements: const <Announcement>[],
        tickets: <QueueTicket>[ticket()],
        settings: settingsWith(completed: false, cancelled: false),
      );

      expect(feed, hasLength(1));
      expect(feed.single.tone, NotificationTone.queue);
    });
  });

  group('announcements', () {
    test('never shows a draft, whatever the active flag says', () {
      final List<QueueNotification> feed = buildNotificationFeed(
        announcements: <Announcement>[
          Announcement(
            id: 1,
            title: 'Draft notice',
            body: 'Not sent yet.',
            status: 'draft',
            createdAt: now,
          ),
        ],
        tickets: const <QueueTicket>[],
        settings: settingsWith(),
      );

      // A draft reaching the whole campus is the worst possible failure here.
      expect(feed, isEmpty);
    });

    test('never shows an archived notice', () {
      final List<QueueNotification> feed = buildNotificationFeed(
        announcements: <Announcement>[
          Announcement(
            id: 1,
            title: 'Old notice',
            body: 'No longer applies.',
            status: 'archived',
            createdAt: now,
          ),
        ],
        tickets: const <QueueTicket>[],
        settings: settingsWith(),
      );

      expect(feed, isEmpty);
    });

    test('shows a published, active notice', () {
      final List<QueueNotification> feed = buildNotificationFeed(
        announcements: <Announcement>[
          Announcement(
            id: 1,
            title: 'Cashier closes at 5:00 PM',
            body: 'Anyone already in line will be called.',
            createdAt: now,
          ),
        ],
        tickets: const <QueueTicket>[],
        settings: settingsWith(),
      );

      expect(feed, hasLength(1));
      expect(feed.single.tone, NotificationTone.notice);
      expect(feed.single.title, 'Cashier closes at 5:00 PM');
    });

    test('treats casing and padding in the status as published', () {
      final Announcement notice = Announcement.fromMap(<String, dynamic>{
        'id': 1,
        'title': 'Notice',
        'body': 'Body',
        'status': '  PUBLISHED ',
        'is_active': 1,
      });

      expect(notice.isPublished, isTrue);
    });

    test('an active flag alone is not enough', () {
      final Announcement notice = Announcement.fromMap(<String, dynamic>{
        'id': 1,
        'title': 'Notice',
        'body': 'Body',
        'status': 'draft',
        'is_active': 1,
      });

      expect(notice.isPublished, isFalse);
    });
  });

  group('ordering', () {
    test('puts the newest row first regardless of kind', () {
      final List<QueueNotification> feed = buildNotificationFeed(
        announcements: <Announcement>[
          Announcement(
            id: 1,
            title: 'Older notice',
            body: 'Body',
            createdAt: now.subtract(const Duration(days: 2)),
          ),
        ],
        tickets: <QueueTicket>[
          // Being served reads off called_at, not served_at, so the timestamp
          // has to be on the field the event actually uses.
          ticket(calledAt: now.subtract(const Duration(minutes: 5))),
          ticket(
            id: 2,
            number: 'A-002',
            status: 'served',
            servedAt: now.subtract(const Duration(hours: 1)),
          ),
        ],
        settings: settingsWith(),
      );

      expect(feed, hasLength(3));
      expect(feed[0].title, contains('A-001'));
      expect(feed[1].title, contains('A-002'));
      expect(feed[2].title, 'Older notice');
    });

    test('sorts a row with no timestamp last rather than crashing', () {
      final List<QueueNotification> feed = buildNotificationFeed(
        announcements: const <Announcement>[],
        tickets: <QueueTicket>[
          ticket(servedAt: now.subtract(const Duration(minutes: 5))),
          ticket(id: 2, number: 'A-002', status: 'served'),
        ],
        settings: settingsWith(),
      );

      // The server can omit served_at on a row it considers done; a sort that
      // threw here would take the whole feed down.
      expect(feed, hasLength(2));
      expect(feed.first.title, contains('A-001'));
    });
  });

  group('unread badge', () {
    test('counts a live number', () {
      final int count = unreadNotificationCount(
        announcements: const <Announcement>[],
        activeTicket: ticket(),
      );

      expect(count, 1);
    });

    test('counts nothing when the ticket is finished', () {
      final int count = unreadNotificationCount(
        announcements: const <Announcement>[],
        activeTicket: ticket(status: 'served'),
      );

      // The student has already seen that on the ticket screen.
      expect(count, 0);
    });

    test('survives having no ticket at all', () {
      final int count = unreadNotificationCount(
        announcements: const <Announcement>[],
        activeTicket: null,
      );

      expect(count, 0);
    });

    test('counts only notices published today', () {
      final int count = unreadNotificationCount(
        announcements: <Announcement>[
          Announcement(id: 1, title: 'Today', body: 'b', createdAt: now),
          Announcement(
            id: 2,
            title: 'Yesterday',
            body: 'b',
            createdAt: now.subtract(const Duration(days: 1)),
          ),
        ],
        activeTicket: null,
        now: now,
      );

      // Yesterday's notices are on the announcements screen by now.
      expect(count, 1);
    });
  });

  group('WhenText.relative', () {
    test('reads as "Just now" inside the first minute', () {
      expect(
        WhenText.relative(
          now.subtract(const Duration(seconds: 20)),
          now: now,
        ),
        'Just now',
      );
    });

    test('counts minutes, then hours, then days', () {
      expect(
        WhenText.relative(
          now.subtract(const Duration(minutes: 7)),
          now: now,
        ),
        '7m ago',
      );
      expect(
        WhenText.relative(now.subtract(const Duration(hours: 5)), now: now),
        '5h ago',
      );
      expect(
        WhenText.relative(now.subtract(const Duration(days: 3)), now: now),
        '3d ago',
      );
    });

    test('falls back to a date once it is no longer recent', () {
      expect(
        WhenText.relative(now.subtract(const Duration(days: 40)), now: now),
        '2 Feb 2026',
      );
    });

    test('reads a future timestamp as "Just now" rather than minus a day', () {
      // A clock-skewed server must not produce "1h ago" in twenty minutes.
      expect(
        WhenText.relative(
          now.add(const Duration(minutes: 20)),
          now: now,
        ),
        'Just now',
      );
    });

    test('pads the time and the day', () {
      final DateTime early = DateTime(2026, 3, 4, 9, 5);

      expect(WhenText.date(early), '4 Mar 2026');
      expect(WhenText.time(early), '09:05');
      expect(WhenText.dateTime(early), '4 Mar 2026, 09:05');
    });
  });
}
