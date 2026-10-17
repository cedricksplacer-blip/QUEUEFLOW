import 'package:flutter_test/flutter_test.dart';

import 'package:queueflow_tmc/core/network/connection_messages.dart';

/// ============================================================
/// QUEUEFLOW TMC — GENERATE TICKET NEVER ANSWERS "CONNECTED"
///
/// Pressing Generate Ticket ends in exactly three ways: a receipt (the
/// server issued a number), the server's own refusal sentence, or the
/// connectivity wording for a request that never left the phone. The link
/// description "Connected" is none of those: it says the radio reached the
/// server, which is not a statement about the ticket.
///
/// A refused gate read its message after settling, and a retry probe can
/// flip the state back to online in between - handing the screen the online
/// body for an action with no answer. Both ticket screens route that case
/// through [ConnectionMessages.blockedActionBody]; these tests pin that the
/// helper never returns the link state as an action result.
/// ============================================================

void main() {
  test('a blocked action while offline reports the offline wording', () {
    expect(
      ConnectionMessages.blockedActionBody(
        canReachServer: false,
        liveBody: ConnectionMessages.offlineBody,
      ),
      ConnectionMessages.offlineBody,
    );
  });

  test('a blocked action while the server is down reports that wording', () {
    expect(
      ConnectionMessages.blockedActionBody(
        canReachServer: false,
        liveBody: ConnectionMessages.serverOfflineBody,
      ),
      ConnectionMessages.serverOfflineBody,
    );
  });

  test('a blocked action that settles online never answers "Connected"', () {
    final String body = ConnectionMessages.blockedActionBody(
      canReachServer: true,
      liveBody: ConnectionMessages.onlineBody,
    );

    expect(body, isNot(contains('Connected')));
    expect(body, ConnectionMessages.actionResultUnknownBody);
    expect(body, contains('not issued'));
  });

  test('the online body itself is unchanged for the status pill', () {
    // The pill legitimately shows the link state; only action results must
    // not. If this wording ever changes, the test above still guards the
    // rule because it feeds the constant in rather than the literal.
    expect(ConnectionMessages.onlineBody, 'Connected');
  });
}
