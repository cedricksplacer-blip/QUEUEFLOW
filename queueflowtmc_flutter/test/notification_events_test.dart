import 'package:flutter_test/flutter_test.dart';

import 'package:queueflow_tmc/models/announcement.dart';
import 'package:queueflow_tmc/models/queue_ticket.dart';
import 'package:queueflow_tmc/services/notification_events.dart';

/// ============================================================
/// QUEUEFLOW TMC — SYSTEM NOTIFICATION EVENTS
///
/// The rules that stand between the payload and the user's ears. A bug here
/// is not a cosmetic one: it either mutes somebody whose number is being
/// called, or plays a sound twice for a single event, or wakes a student
/// with something that happened hours ago.
///
/// The suite is organised around the three gates from the module doc —
/// deduplication, recency, and the registrar's switches — plus the two
/// settings-promise events the feed does not carry.
/// ============================================================

void main() {
  final DateTime now = DateTime(2026, 3, 14, 10, 30);

  SystemSettings settingsWith({
    bool issued = true,
    bool almostReady = true,
    bool nowServing = true,
    bool completed = true,
    bool cancelled = true,
    bool announcements = true,
  }) => SystemSettings(
    ticketCreatedNotification: issued,
    queueAlmostReadyNotification: almostReady,
    nowServingNotification: nowServing,
    ticketCompletedNotification: completed,
    ticketCancelledNotification: cancelled,
    announcementNotification: announcements,
  );

  QueueTicket ticket({
    int id = 1,
    String number = 'A-001',
    String status = 'waiting',
    String office = 'Registrar',
    DateTime? calledAt,
    DateTime? servedAt,
    DateTime? createdAt,
  }) => QueueTicket(
    id: id,
    queueNumber: number,
    status: status,
    office: OfficeRef(id: 2, name: office, code: 'REG'),
    service: ServiceRef(id: 5, name: 'Transcript request'),
    calledAt: calledAt,
    servedAt: servedAt,
    createdAt: createdAt,
  );

  Announcement notice({int id = 1, DateTime? createdAt}) => Announcement(
    id: id,
    title: 'Cashier closes at 5:00 PM',
    body: 'Anyone already in line will be called.',
    createdAt: createdAt ?? now,
  );

  NotificationEventBatch build({
    List<Announcement> announcements = const <Announcement>[],
    List<QueueTicket> tickets = const <QueueTicket>[],
    SystemSettings? settings,
    QueueProgress? progress,
    Set<String> seen = const <String>{},
    String prefix = '',
    DateTime? at,
  }) => buildNotificationEvents(
    announcements: announcements,
    tickets: tickets,
    settings: settings ?? SystemSettings(),
    progress: progress,
    seenKeys: seen,
    keyPrefix: prefix,
    now: at ?? now,
  );

  List<String> keysOf(Iterable<NotificationEvent> events) =>
      events.map((NotificationEvent e) => e.key).toList();

  group('what reaches the system layer', () {
    test('carries the feed events through with their content intact', () {
      final NotificationEventBatch batch = build(
        announcements: <Announcement>[notice()],
        tickets: <QueueTicket>[
          ticket(
            status: 'serving',
            calledAt: now.subtract(const Duration(minutes: 4)),
          ),
        ],
      );

      // The service must announce exactly what the Notifications screen
      // shows — same keys, same words — or the two surfaces disagree.
      expect(
        keysOf(batch.toAnnounce),
        containsAll(<String>['ticket:1:serving', 'announcement:1']),
      );

      final NotificationEvent serving = batch.toAnnounce.firstWhere(
        (NotificationEvent e) => e.key == 'ticket:1:serving',
      );
      expect(serving.title, contains('A-001'));
      expect(serving.body, contains('Registrar'));
      expect(serving.when, now.subtract(const Duration(minutes: 4)));
    });

    test('announces a freshly issued number with its office', () {
      final NotificationEventBatch batch = build(
        tickets: <QueueTicket>[
          ticket(createdAt: now.subtract(const Duration(minutes: 1))),
        ],
      );

      final NotificationEvent issued = batch.toAnnounce.firstWhere(
        (NotificationEvent e) => e.key.endsWith(':issued'),
      );
      expect(issued.title, 'Your queue number is A-001.');
      expect(issued.body, contains('Registrar'));
      expect(issued.body, contains('watch the board'));
    });

    test('produces nothing for an empty payload', () {
      expect(build().toAnnounce, isEmpty);
      expect(build().allKeys, isEmpty);
    });
  });

  group('one event, one sound', () {
    test('the second pass over the same payload announces nothing', () {
      final NotificationEventBatch first = build(
        tickets: <QueueTicket>[
          ticket(
            status: 'serving',
            calledAt: now.subtract(const Duration(minutes: 2)),
          ),
        ],
      );

      expect(first.toAnnounce, isNotEmpty);

      final NotificationEventBatch second = build(
        tickets: <QueueTicket>[
          ticket(
            status: 'serving',
            calledAt: now.subtract(const Duration(minutes: 2)),
          ),
        ],
        seen: first.allKeys,
      );

      // The poller re-reads this data every tick; without the seen-set the
      // shade would fill with duplicates and every tick would re-sound.
      expect(second.toAnnounce, isEmpty);
    });

    test('keeps recording keys for events that were already seen', () {
      final QueueTicket serving = ticket(
        status: 'serving',
        calledAt: now.subtract(const Duration(minutes: 2)),
      );

      final NotificationEventBatch first = build(tickets: <QueueTicket>[
        serving,
      ]);
      final NotificationEventBatch second = build(
        tickets: <QueueTicket>[serving],
        seen: first.allKeys,
      );

      // Silent is not the same as forgotten: if the key were dropped the
      // next un-seen pass would announce it all over again.
      expect(second.toAnnounce, isEmpty);
      expect(second.allKeys, contains('ticket:1:serving'));
    });
  });

  group('recency', () {
    test('does not wake anybody with an event older than two hours', () {
      final NotificationEventBatch batch = build(
        tickets: <QueueTicket>[
          ticket(
            status: 'serving',
            calledAt: now.subtract(const Duration(hours: 3)),
          ),
        ],
      );

      expect(batch.toAnnounce, isEmpty);
      // Recorded, not announced — so it can never be announced later either.
      expect(batch.allKeys, contains('ticket:1:serving'));
    });

    test('keeps the window closed for an old issued number too', () {
      final NotificationEventBatch batch = build(
        tickets: <QueueTicket>[
          ticket(createdAt: now.subtract(const Duration(hours: 5))),
        ],
      );

      expect(
        keysOf(batch.toAnnounce),
        isNot(contains('ticket:1:issued')),
      );
      expect(batch.allKeys, contains('ticket:1:issued'));
    });

    test('announces an event from inside the window', () {
      final NotificationEventBatch batch = build(
        tickets: <QueueTicket>[
          ticket(
            status: 'serving',
            calledAt: now.subtract(const Duration(minutes: 90)),
          ),
        ],
      );

      expect(keysOf(batch.toAnnounce), contains('ticket:1:serving'));
    });

    test('tolerates a server clock running ahead instead of muting it', () {
      // A five-minute skew must not hide an event as "too old"; the window
      // only ever rejects the past.
      final NotificationEventBatch batch = build(
        tickets: <QueueTicket>[
          ticket(
            status: 'serving',
            calledAt: now.add(const Duration(minutes: 5)),
          ),
        ],
        settings: settingsWith(issued: false),
      );

      expect(keysOf(batch.toAnnounce), contains('ticket:1:serving'));
    });
  });

  group('the registrar switches', () {
    test('no issued event when that alert is switched off', () {
      final NotificationEventBatch batch = build(
        tickets: <QueueTicket>[
          ticket(createdAt: now.subtract(const Duration(minutes: 1))),
        ],
        settings: settingsWith(issued: false),
      );

      // Off means the key is never even created: re-enabling the switch
      // later should not retro-announce today's tickets.
      expect(batch.allKeys, isEmpty);
      expect(batch.toAnnounce, isEmpty);
    });

    test('feed events obey their switches at the system layer too', () {
      final NotificationEventBatch batch = build(
        tickets: <QueueTicket>[
          ticket(
            status: 'serving',
            calledAt: now.subtract(const Duration(minutes: 3)),
          ),
        ],
        settings: settingsWith(nowServing: false),
      );

      expect(batch.allKeys, isEmpty);
    });

    test('still announces what the switch allows', () {
      final NotificationEventBatch batch = build(
        tickets: <QueueTicket>[
          ticket(
            status: 'serving',
            calledAt: now.subtract(const Duration(minutes: 3)),
          ),
          ticket(
            id: 7,
            number: 'A-007',
            status: 'served',
            servedAt: now.subtract(const Duration(minutes: 1)),
          ),
        ],
        settings: settingsWith(completed: false),
      );

      expect(keysOf(batch.toAnnounce), contains('ticket:1:serving'));
      expect(keysOf(batch.toAnnounce), isNot(contains('ticket:7:served')));
    });

    test('no announcement when that alert is switched off', () {
      final NotificationEventBatch batch = build(
        announcements: <Announcement>[notice()],
        settings: settingsWith(announcements: false),
      );

      expect(batch.allKeys, isEmpty);
    });
  });

  group('when your turn is close', () {
    final QueueTicket waiting = ticket();

    // Issued alerts are switched off throughout this group so the keys
    // under test belong to the almost-ready rule alone.
    NotificationEventBatch nearEnd(int peopleAhead) => build(
      tickets: <QueueTicket>[waiting],
      progress: QueueProgress(peopleAhead: peopleAhead, position: 3),
      settings: settingsWith(issued: false),
    );

    test('fires when two or fewer people are ahead', () {
      final NotificationEventBatch batch = nearEnd(2);

      expect(keysOf(batch.toAnnounce), contains('ticket:1:almost-ready'));
      final NotificationEvent event = batch.toAnnounce.firstWhere(
        (NotificationEvent e) => e.key.endsWith(':almost-ready'),
      );
      expect(event.title, 'Your turn is approaching.');
      expect(event.body, 'Please prepare.');
    });

    test('stays quiet while the queue is still long', () {
      expect(nearEnd(3).toAnnounce, isEmpty);
      expect(nearEnd(12).allKeys, isEmpty);
    });

    test('stays quiet without a position read', () {
      final NotificationEventBatch batch = build(
        tickets: <QueueTicket>[waiting],
        progress: null,
        settings: settingsWith(issued: false),
      );

      expect(batch.allKeys, isEmpty);
    });

    test('stays quiet once the ticket leaves the waiting state', () {
      final NotificationEventBatch batch = build(
        tickets: <QueueTicket>[
          ticket(status: 'serving', calledAt: now),
        ],
        progress: const QueueProgress(peopleAhead: 1),
      );

      // Being called already announced the number; "approaching" after the
      // fact would be stale news wearing an urgent tone.
      expect(batch.allKeys, isNot(contains('ticket:1:almost-ready')));
    });

    test('fires at most once per ticket', () {
      final NotificationEventBatch first = nearEnd(1);
      final NotificationEventBatch second = build(
        tickets: <QueueTicket>[waiting],
        progress: const QueueProgress(peopleAhead: 1),
        settings: settingsWith(issued: false),
        seen: first.allKeys,
      );

      expect(first.toAnnounce, hasLength(1));
      expect(second.toAnnounce, isEmpty);
    });

    test('respects the switch', () {
      final NotificationEventBatch batch = build(
        tickets: <QueueTicket>[waiting],
        progress: const QueueProgress(peopleAhead: 1),
        settings: settingsWith(issued: false, almostReady: false),
      );

      expect(batch.allKeys, isEmpty);
    });

    test('follows the active ticket, not every waiting one', () {
      final NotificationEventBatch batch = build(
        tickets: <QueueTicket>[
          ticket(id: 11, number: 'A-011'),
          ticket(id: 12, number: 'A-012'),
        ],
        progress: const QueueProgress(peopleAhead: 1),
        settings: settingsWith(issued: false),
      );

      // The progress read belongs to the active (first) ticket; announcing
      // "approaching" for the other one would name the wrong number.
      expect(batch.allKeys, contains('ticket:11:almost-ready'));
      expect(batch.allKeys, isNot(contains('ticket:12:almost-ready')));
    });
  });

  group('per-account keys', () {
    test('scopes every key to the signed-in student', () {
      final NotificationEventBatch batch = build(
        announcements: <Announcement>[notice()],
        prefix: 'u7:',
      );

      expect(batch.allKeys, <String>{'u7:announcement:1'});
    });

    test('keeps two accounts on one device independent', () {
      final List<QueueTicket> tickets = <QueueTicket>[
        ticket(
          status: 'serving',
          calledAt: now.subtract(const Duration(minutes: 2)),
        ),
      ];

      final NotificationEventBatch studentA = build(
        tickets: tickets,
        prefix: 'u7:',
      );
      final NotificationEventBatch studentB = build(
        tickets: tickets,
        seen: studentA.allKeys,
        prefix: 'u8:',
      );

      // A consumed event for one account must not mute the next account's
      // announcement of the same ticket.
      expect(studentA.toAnnounce, hasLength(1));
      expect(studentB.toAnnounce, hasLength(1));
    });
  });
}
