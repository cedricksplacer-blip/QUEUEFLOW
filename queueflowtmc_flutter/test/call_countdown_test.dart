import 'package:flutter_test/flutter_test.dart';
import 'package:queueflow_tmc/models/queue_ticket.dart';

/// Queue timeout countdown: server deadline is the clock, arrival stops it.
void main() {
  QueueTicket ticket({
    int id = 1,
    String status = 'serving',
    DateTime? expiresAt,
    DateTime? arrivedAt,
    bool expired = false,
    int duration = 5,
  }) => QueueTicket(
    id: id,
    queueNumber: 'CASH-001',
    status: status,
    callDurationMinutes: duration,
    expiresAt: expiresAt,
    arrivedAt: arrivedAt,
    expired: expired,
  );

  test('live call counts down from the server deadline', () {
    final QueueTicket t = ticket(
      expiresAt: DateTime.now().add(const Duration(minutes: 4, seconds: 30)),
    );

    expect(t.isCountdownExpired, isFalse);
    expect(t.isArrived, isFalse);
    expect(t.remainingSeconds, greaterThan(4 * 60));
    expect(t.countdownLabel, matches(RegExp(r'^\d{2}:\d{2}$')));
  });

  test('expired deadline reads TICKET EXPIRED', () {
    final QueueTicket t = ticket(
      expiresAt: DateTime.now().subtract(const Duration(seconds: 1)),
    );

    expect(t.isCountdownExpired, isTrue);
    expect(t.remainingSeconds, 0);
    expect(t.countdownLabel, 'TICKET EXPIRED');
  });

  test('arrival stops the timer and never expires', () {
    final QueueTicket t = ticket(
      expiresAt: null,
      arrivedAt: DateTime.now().subtract(const Duration(minutes: 1)),
    );

    expect(t.isArrived, isTrue);
    expect(t.isCountdownExpired, isFalse);
    expect(t.remainingSeconds, 0);
    expect(t.countdownLabel, 'ARRIVED');
    expect(t.statusLabel, 'Arrived');
    expect(t.statusMessage, contains('being attended to'));
  });

  test('warning fires in the last minute before expiry', () {
    final QueueTicket t = ticket(
      expiresAt: DateTime.now().add(const Duration(seconds: 45)),
    );

    expect(t.isCountdownWarning, isTrue);
  });

  test('arrived ticket is not a warning', () {
    final QueueTicket t = ticket(
      expiresAt: DateTime.now().add(const Duration(seconds: 45)),
      arrivedAt: DateTime.now(),
    );

    expect(t.isCountdownWarning, isFalse);
  });

  test('waiting ticket has no countdown', () {
    final QueueTicket t = ticket(status: 'waiting', expiresAt: null);

    expect(t.remainingSeconds, 0);
    expect(t.isCountdownExpired, isFalse);
    expect(t.countdownLabel, '--:--');
  });

  test('arrival + cancel reason survive a map round-trip', () {
    final DateTime arrived = DateTime.now().subtract(
      const Duration(minutes: 1),
    );
    final QueueTicket t = QueueTicket(
      id: 7,
      queueNumber: 'ADM-003',
      status: 'serving',
      arrivedAt: arrived,
      cancelledAt: null,
      cancelReason: 'Student did not arrive',
    );

    final QueueTicket back = QueueTicket.fromMap(t.toMap());

    expect(back.arrivedAt?.millisecondsSinceEpoch, arrived.millisecondsSinceEpoch);
    expect(back.cancelReason, 'Student did not arrive');
    expect(back.isArrived, isTrue);
  });
}
