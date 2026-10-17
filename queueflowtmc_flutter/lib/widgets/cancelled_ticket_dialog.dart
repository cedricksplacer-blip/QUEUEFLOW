library;

import 'package:flutter/material.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../core/app_colors.dart';
import '../models/queue_ticket.dart';
import '../repositories/auth_repository.dart';

/// ============================================================
/// QUEUEFLOW TMC — STAFF-CANCELLATION DIALOG
///
/// Pops up exactly once per staff-cancelled ticket — on whatever screen
/// the student happens to be looking at — with the exact reason staff
/// entered, so a voided number is never discovered as a silent empty
/// state.
///
/// DETECTION REUSES THE EXISTING POLLING
///
/// No new channel, no new endpoint. [NotificationService] already
/// re-reads the student's tickets every 10s foreground / 15s background
/// (plus every repository change and every foreground resume), and the
/// ticket payload already carries `status`, `cancelled_at` and
/// `cancel_reason`. [CancelledTicketWatcher] below simply listens to
/// [AuthRepository] like any other widget, so a cancellation is noticed
/// on the next poll after staff cancel — including the first poll after
/// an offline stretch — without any screen having to remember to ask.
///
/// WHY A DIALOG IN ADDITION TO THE FEED AND THE CARD
///
/// The Notifications feed row, the system notification and the My Ticket
/// cancelled card all already show the reason, but each of them waits for
/// the student to go and look. A staff cancellation voids somebody's
/// place in line; that deserves one interruptive, dismissible popup.
///
/// EXACTLY ONCE, PER ACCOUNT
///
/// Three guards, each covering what the others cannot:
///   1. Self-cancels never pop: the student just asked for it
///      ([AuthRepository.isSelfCancelled]).
///   2. An acknowledged ticket never pops again: the key is persisted in
///      SharedPreferences before the dialog is shown, so killing the app
///      mid-popup still counts as seen.
///   3. Keys are prefixed per account (`u{id}:...`), so a second student
///      on the same device still gets their own popup.
/// ============================================================

/// Stable ack key for one ticket's cancellation dialog.
String cancelDialogKeyFor(int userId, int ticketId) =>
    'u$userId:ticket:$ticketId:cancelled-dialog';

/// Persisted list of acknowledged cancellation-dialog keys.
const String cancelDialogAckedPrefsKey = 'qf_cancel_dialog_acked';

/// Which cancelled tickets still owe the student a popup, newest first.
///
/// Pure logic, so the rules are unit-testable without a device:
/// cancelled rows only, never self-cancels, never already-acknowledged
/// rows, and nothing at all when the campus cancellation toggle is off.
///
/// NOTE: this answers "which rows are unacknowledged", not "which rows are
/// news". A row that was already cancelled before the current session
/// started is still returned here when it has never been acknowledged.
/// [CancelledTicketWatcher] filters those historical rows out (see
/// [isHistoricalCancellation]) so logging in lands on the Home Dashboard
/// without an automatic popup; the row itself remains in history and in
/// the notifications feed.
List<QueueTicket> pendingStaffCancellationDialogs({
  required List<QueueTicket> tickets,
  required int userId,
  required Set<String> ackedKeys,
  required bool Function(int ticketId) isSelfCancelled,
  required bool notifyEnabled,
}) {
  if (!notifyEnabled) {
    return const <QueueTicket>[];
  }

  final List<QueueTicket> pending = <QueueTicket>[];

  for (final QueueTicket ticket in tickets) {
    if (!ticket.isCancelled) {
      continue;
    }

    if (isSelfCancelled(ticket.id)) {
      continue;
    }

    if (ackedKeys.contains(cancelDialogKeyFor(userId, ticket.id))) {
      continue;
    }

    pending.add(ticket);
  }

  pending.sort((QueueTicket a, QueueTicket b) => b.id.compareTo(a.id));

  return pending;
}

/// Whether a cancelled ticket is historical baggage from before this
/// session rather than a fresh staff cancellation observed while signed in.
///
/// Pure logic, so the login-suppression rule is unit-testable without a
/// device:
///
/// * A ticket previously seen in a non-cancelled state this session
///   ([seenAliveIds] contains its id) and now cancelled is a genuine
///   alive -> cancelled transition: NOT historical, so it may pop.
/// * A ticket never seen alive but whose [QueueTicket.cancelledAt] is at
///   or after [sessionStart] was created and cancelled inside this session
///   faster than the poller could observe the alive state: NOT historical,
///   so it may pop.
/// * Anything else — never seen alive and cancelled before the session
///   started (or carrying no timestamp at all) — is a historical row that
///   must NOT pop on login. It stays visible in history and in the
///   notifications feed; here it is silently acknowledged instead.
///
/// [sessionStart] is the moment the current watcher session began (shell
/// creation or account switch). [seenAliveIds] accumulates every
/// non-cancelled ticket id observed since then.
bool isHistoricalCancellation({
  required QueueTicket ticket,
  required Set<int> seenAliveIds,
  required DateTime sessionStart,
}) {
  if (seenAliveIds.contains(ticket.id)) {
    return false;
  }

  final DateTime? cancelledAt = ticket.cancelledAt;

  if (cancelledAt != null && !cancelledAt.isBefore(sessionStart)) {
    return false;
  }

  return true;
}

/// The exact staff reason, or the fallback shown only when the record
/// carries none (e.g. a self-cancel synced from another device or a row
/// predating the reason field).
String cancellationReasonText(QueueTicket ticket) {
  final String reason = (ticket.cancelReason ?? '').trim();

  return reason.isEmpty ? 'No reason was given.' : reason;
}

/// Shows the staff-cancellation popup for [ticket].
///
/// Title, message and button are fixed; only the reason comes from the
/// backend record — never invented here.
Future<void> showCancelledTicketDialog(
  BuildContext context,
  QueueTicket ticket,
) {
  return showDialog<void>(
    context: context,
    barrierDismissible: false,
    builder: (BuildContext context) => AlertDialog(
      backgroundColor: AppColors.primary,
      title: const Text(
        'Your Ticket Has Been Cancelled',
        style: TextStyle(color: Colors.white, fontSize: 17),
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.start,
        children: <Widget>[
          Text(
            'Your queue ticket has been cancelled by the staff.',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.70),
              fontSize: 13,
              height: 1.5,
            ),
          ),
          const SizedBox(height: 14),
          Text(
            'Cancellation Reason:',
            style: TextStyle(
              color: Colors.white.withValues(alpha: 0.50),
              fontSize: 10.5,
              fontWeight: FontWeight.w700,
            ),
          ),
          const SizedBox(height: 6),
          Text(
            cancellationReasonText(ticket),
            style: const TextStyle(
              color: Colors.white,
              fontSize: 13.5,
              height: 1.5,
            ),
          ),
        ],
      ),
      actions: <Widget>[
        FilledButton(
          style: FilledButton.styleFrom(backgroundColor: AppColors.danger),
          onPressed: () => Navigator.of(context).pop(),
          child: const Text('OK'),
        ),
      ],
    ),
  );
}

/// Listens for staff cancellations and pops the dialog, anywhere.
///
/// Drop once inside the signed-in shell: it renders nothing, follows the
/// repository through polls, refreshes and reconnects, and shows each
/// *fresh* staff cancellation exactly once (see the guards above). No ticket
/// is ever created here and no state is ever fabricated — a popup only
/// ever describes a cancelled row the server actually sent.
///
/// NO POPUP ON LOGIN: tickets that were already cancelled before this
/// session started are silently acknowledged on first sight (see
/// [isHistoricalCancellation]) so signing in lands directly on the Home
/// Dashboard. They remain in history and in the notifications feed. Only an
/// alive -> cancelled transition observed while signed in — or a row stamped
/// cancelled at/after the session started — ever pops.
class CancelledTicketWatcher extends StatefulWidget {
  const CancelledTicketWatcher({super.key});

  @override
  State<CancelledTicketWatcher> createState() => _CancelledTicketWatcherState();
}

class _CancelledTicketWatcherState extends State<CancelledTicketWatcher> {
  final AuthRepository _auth = AuthRepository.instance;

  /// A dialog is already on screen: concurrent repository notifications
  /// must queue behind it rather than stacking a second popup.
  bool _showing = false;

  bool _prefsLoaded = false;
  final Set<String> _acked = <String>{};

  /// When the current watcher session began (shell creation or account
  /// switch). Historical cancellations are those stamped before this moment
  /// that were never observed alive in this session.
  DateTime _sessionStart = DateTime.now();

  /// Account the session tracking below belongs to. Reset on switch so a
  /// second student on the same device gets their own login-suppression
  /// window rather than inheriting the previous account's observed ids.
  int? _sessionUserId;

  /// Ids of tickets observed in a non-cancelled state during this session.
  /// Only a ticket in this set that later appears cancelled is a genuine
  /// transition that may pop; anything else first seen as already-cancelled
  /// is login baggage and is silently acknowledged.
  final Set<int> _seenAliveIds = <int>{};

  @override
  void initState() {
    super.initState();
    _auth.addListener(_onAuthChanged);
    WidgetsBinding.instance.addPostFrameCallback((_) => _check());
  }

  @override
  void dispose() {
    _auth.removeListener(_onAuthChanged);
    super.dispose();
  }

  void _onAuthChanged() {
    _check();
  }

  Future<void> _check() async {
    if (!mounted || _showing || !_auth.isSignedIn) {
      return;
    }

    if (!_prefsLoaded) {
      try {
        final SharedPreferences prefs =
            await SharedPreferences.getInstance();
        _acked.addAll(
          prefs.getStringList(cancelDialogAckedPrefsKey) ?? const <String>[],
        );
      } catch (_) {
        // No persisted acks: every cancelled row is treated as unseen,
        // which is the honest answer when the store is unreadable.
      }
      _prefsLoaded = true;

      if (!mounted || !_auth.isSignedIn) {
        return;
      }
    }

    final int userId = _auth.user?.id ?? 0;

    // A new account (or a fresh shell after sign-in) starts a new
    // suppression window: nothing observed for the previous account may
    // decide what pops for this one.
    if (_sessionUserId != userId) {
      _sessionUserId = userId;
      _sessionStart = DateTime.now();
      _seenAliveIds.clear();
    }

    // Remember every ticket observed in a non-cancelled state this session.
    // This runs on every check — including checks where the ticket list is
    // still empty right after login — so a historical cancelled row that
    // arrives on a later refresh is still recognised as "never seen alive"
    // rather than mistaken for a fresh cancellation.
    for (final QueueTicket seen in _auth.tickets) {
      if (!seen.isCancelled) {
        _seenAliveIds.add(seen.id);
      }
    }

    final List<QueueTicket> pending = pendingStaffCancellationDialogs(
      tickets: _auth.tickets,
      userId: userId,
      ackedKeys: _acked,
      isSelfCancelled: _auth.isSelfCancelled,
      notifyEnabled: _auth.settings.ticketCancelledNotification,
    );

    if (pending.isEmpty) {
      return;
    }

    // Split unacknowledged cancellations into login baggage vs. genuine
    // transitions. Historical rows are silently acknowledged so signing in
    // lands directly on the Home Dashboard with no automatic popup; their
    // records, reasons and history rows are untouched.
    final List<QueueTicket> genuine = <QueueTicket>[];
    final List<QueueTicket> historical = <QueueTicket>[];
    for (final QueueTicket ticket in pending) {
      if (isHistoricalCancellation(
        ticket: ticket,
        seenAliveIds: _seenAliveIds,
        sessionStart: _sessionStart,
      )) {
        historical.add(ticket);
      } else {
        genuine.add(ticket);
      }
    }

    if (historical.isNotEmpty) {
      bool added = false;
      for (final QueueTicket ticket in historical) {
        if (_acked.add(cancelDialogKeyFor(userId, ticket.id))) {
          added = true;
        }
      }
      if (added) {
        try {
          final SharedPreferences prefs =
              await SharedPreferences.getInstance();
          await prefs.setStringList(
            cancelDialogAckedPrefsKey,
            _acked.toList(),
          );
        } catch (_) {
          // Persistence is best effort; the in-memory keys still guard
          // this session.
        }
      }
    }

    if (genuine.isEmpty) {
      return;
    }

    _showing = true;
    try {
      for (final QueueTicket ticket in genuine) {
        if (!mounted || !_auth.isSignedIn) {
          break;
        }

        // Recorded before showing, not after: a process killed with the
        // popup open must still count as acknowledged.
        if (_acked.add(cancelDialogKeyFor(userId, ticket.id))) {
          try {
            final SharedPreferences prefs =
                await SharedPreferences.getInstance();
            await prefs.setStringList(
              cancelDialogAckedPrefsKey,
              _acked.toList(),
            );
          } catch (_) {
            // Persistence is best effort; the in-memory key still guards
            // this session.
          }
        }

        if (!mounted || !_auth.isSignedIn) {
          break;
        }

        await showCancelledTicketDialog(context, ticket);
      }
    } finally {
      _showing = false;
    }
  }

  @override
  Widget build(BuildContext context) => const SizedBox.shrink();
}
