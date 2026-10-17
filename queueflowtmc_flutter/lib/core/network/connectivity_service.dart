/// ============================================================
/// QUEUEFLOW TMC — CONNECTIVITY SERVICE
///
/// The one place in the app that answers "can we reach QueueFlow right now?".
///
/// WHY THIS IS NOT JUST A WIFI TOGGLE
///
/// A phone on campus Wi-Fi with `artisan serve` stopped has a network
/// connection and no server. A phone on mobile data has a network connection
/// and, when QueueFlow is on a private LAN address, also no server - because
/// RFC 1918 addresses are not routable off the campus router. A phone in a
/// lift has neither. All three look identical to the operating system's
/// connectivity API if you only ask one question, and answering one question
/// produces the single most annoying class of bug in a campus app: telling
/// somebody to turn their Wi-Fi back on when their Wi-Fi was never the
/// problem.
///
/// So this service asks two questions and keeps both answers:
///
///   Level 1 - DEVICE CONNECTIVITY
///     What does the radio say? Wi-Fi / mobile / ethernet / none.
///     From connectivity_plus. Free, instant, and on its own worthless as a
///     statement about our server.
///
///   Level 2 - BACKEND CONNECTIVITY
///     Is Laravel actually serving? Asked over HTTP against the real
///     `/api/v1/health` route. Slow, and the only one that means anything.
///
/// The published [state] is the combination, and [AppConnectionState] has a
/// separate value for each distinguishable case rather than a single bool -
/// see that enum for why "online" and "offline" are not enough.
///
/// NEVER FABRICATES AN ANSWER
///
/// [checkBackend] calls [ApiClient.checkHealth], which is a genuine HTTP
/// request to a real Laravel route. There is no local fallback that reports
/// success, no cached "it worked ten minutes ago", and no assumption that a
/// working Wi-Fi icon means a working server. An app that invents connectivity
/// is worse than one that admits it is lost, because every screen downstream
/// then trusts a lie.
///
/// NEVER BLOCKS THE UI
///
/// Everything here is async. [initialize] is awaited once during startup and
/// the probe is given a real timeout, so a dead server costs a visible
/// "Checking…" state rather than a frozen splash screen. Listeners are
/// notified through [addListener] / [states], like any other Flutter state
/// object, and a slow probe simply arrives late.
/// ============================================================
library;

import 'dart:async';

import 'package:connectivity_plus/connectivity_plus.dart';
import 'package:flutter/foundation.dart';

import '../api/api_client.dart';
import '../api/api_config.dart';
import 'connection_messages.dart';

/// The kind of link the phone currently has.
///
/// Deliberately coarser than [ConnectivityResult]: the plugin distinguishes
/// bluetooth and satellite, neither of which any campus queue app can do
/// anything useful with, and carrying them as separate states would mean every
/// switch in the UI needs a case for a state that behaves exactly like "some
/// network". They are folded into [other] here rather than pretending to be
/// Wi-Fi.
enum NetworkLink {
  /// Wi-Fi. The normal case for reaching a LAN-hosted server.
  wifi,

  /// Cellular data.
  mobile,

  /// Ethernet, VPN, satellite, or anything else the plugin reports that is
  /// neither Wi-Fi nor cellular. Treated as a usable link.
  other,

  /// No link at all.
  none,
}

/// The combined answer: what the radio says AND whether QueueFlow answered.
///
/// Four states, because the four situations need four different things said to
/// the user. Collapsing any two of them would mean showing advice that is
/// wrong for one of them - which is worse than showing nothing.
enum AppConnectionState {
  /// A probe is in flight. Nothing is claimed yet, deliberately: showing
  /// "offline" during a probe would make a good connection look broken every
  /// time the app launched.
  checking,

  /// The radio has a link and Laravel answered.
  online,

  /// The radio has NO link. Nothing was sent and nothing could be.
  ///
  /// Distinct from [serverUnavailable] because the fix is entirely in the
  /// reader's hands: turn on Wi-Fi or mobile data.
  offline,

  /// The radio has a link but Laravel did not answer.
  ///
  /// The server is stopped, the phone is on the wrong network, or a firewall is
  /// in the way. The reader cannot fix all of those, which is why the message
  /// names both possibilities instead of guessing.
  serverUnavailable,
}

/// An immutable snapshot of both levels of connectivity.
@immutable
class ConnectionSnapshot {
  const ConnectionSnapshot({
    required this.state,
    required this.link,
    this.reachableHost,
    this.checkedAt,
  });

  /// The starting state, before anything has been asked.
  ///
  /// [checking] rather than [offline]: an app that has not looked yet does not
  /// know it is offline, and claiming otherwise for the first few hundred
  /// milliseconds would flash a warning at every user on every launch.
  const ConnectionSnapshot.initial()
      : state = AppConnectionState.checking,
        link = NetworkLink.none,
        reachableHost = null,
        checkedAt = null;

  /// The combined answer.
  final AppConnectionState state;

  /// What the radio reported.
  final NetworkLink link;

  /// The host the last successful probe reached.
  ///
  /// Null until a probe succeeds, and deliberately not defaulted to the
  /// configured address: this records where the app *actually* got a response,
  /// which is what makes "it says Online" mean something.
  final String? reachableHost;

  /// When [state] was decided.
  final DateTime? checkedAt;

  // ========================================================
  // DERIVED ANSWERS
  // ========================================================

  /// Whether the phone has any link at all.
  bool get hasDeviceConnection => link != NetworkLink.none;

  /// Whether QueueFlow answered.
  bool get isOnline => state == AppConnectionState.online;

  /// Whether a probe is in flight.
  bool get isChecking => state == AppConnectionState.checking;

  /// Whether an action needing the server can be attempted.
  ///
  /// False while checking too, not just while offline. Queueing a ticket or
  /// cancelling one is not something to attempt optimistically during a
  /// reconnect: a request sent into a dead link is a request that may or may
  /// not have been received, and a duplicate ticket is worse than a refused
  /// button.
  bool get canReachServer => state == AppConnectionState.online;

  /// Whether something is wrong that the reader should be told about.
  bool get needsAttention =>
      state == AppConnectionState.offline ||
      state == AppConnectionState.serverUnavailable;

  /// Whether this snapshot says exactly what [other] says.
  ///
  /// Used to suppress redundant notifications, so a Wi-Fi drop that fires the
  /// plugin's stream three times does not produce three re-renders and three
  /// probes.
  bool sameAs(ConnectionSnapshot other) =>
      state == other.state &&
      link == other.link &&
      reachableHost == other.reachableHost;
}

/// The app's connectivity authority.
///
/// A [ChangeNotifier] rather than a bare stream so a widget can rebuild from a
/// single `AnimatedBuilder`, and so the current answer is synchronously
/// readable - which matters because a screen deciding whether to enable a
/// button must not have to await a future to find out.
class ConnectivityService extends ChangeNotifier {
  ConnectivityService({
    ApiClient? api,
    Connectivity? connectivity,
  })  : _api = api ?? ApiClient(),
        _connectivity = connectivity ?? Connectivity();

  /// Process-wide instance.
  ///
  /// Singleton because there is exactly one radio and one server. Two
  /// instances would mean two probes in flight and two answers that could
  /// disagree, which is the failure this class exists to prevent.
  static final ConnectivityService instance = ConnectivityService();

  final ApiClient _api;
  final Connectivity _connectivity;

  StreamSubscription<List<ConnectivityResult>>? _linkSubscription;
  Timer? _debounce;
  Timer? _retryTimer;

  /// In-flight probe generation, used to discard a stale answer.
  ///
  /// Probes overlap: Wi-Fi drops while a probe is mid-flight, or the reader
  /// presses Retry twice. Without this, a slow "offline" from the previous
  /// network could land after a fast "online" from the new one and leave the
  /// app reporting the wrong thing until the next change. Each probe takes a
  /// ticket; only the newest ticket is allowed to publish.
  int _generation = 0;

  /// Whether the probe is currently running.
  ///
  /// The *future*, not a bool, and that is the point. An earlier version used a
  /// bool and had `checkBackend` return the current state immediately when it
  /// saw one - which meant a caller arriving during a probe was handed
  /// `AppConnectionState.checking` and refused, even though the answer was
  /// milliseconds away. The most common caller by far is the action gate, so
  /// that turned "hold on, I am already asking" into "no". Holding the future
  /// lets every caller wait for the answer instead of being turned away by it.
  Future<bool>? _inFlight;

  ConnectionSnapshot _snapshot = const ConnectionSnapshot.initial();

  ConnectionSnapshot get snapshot => _snapshot;

  // Convenience passthroughs, so a widget reads `service.isOnline` rather than
  // `service.snapshot.state == AppConnectionState.online` at every call site.
  AppConnectionState get state => _snapshot.state;

  NetworkLink get link => _snapshot.link;

  bool get isOnline => _snapshot.isOnline;

  bool get hasDeviceConnection => _snapshot.hasDeviceConnection;

  bool get canReachServer => _snapshot.canReachServer;

  bool get needsAttention => _snapshot.needsAttention;

  bool get isChecking => _snapshot.isChecking;

  // ========================================================
  // TEST SEAMS
  //
  // The singleton starts at `checking`, which is the honest answer for a
  // process that has not probed yet - but it also means every widget test
  // would see a button that refuses to press. There is no legitimate way for
  // a test to reach the state it needs: the real answer comes from a plugin
  // and an HTTP probe. These two methods are the whole surface, so a test
  // says what it needs ("the server is reachable") and puts it back
  // afterwards, and nothing in lib/ can call them in production.
  // ========================================================

  /// Declares the server reachable without probing, for widget tests that
  /// need an enabled action button.
  @visibleForTesting
  void debugSetServerReachable() {
    _snapshot = ConnectionSnapshot(
      state: AppConnectionState.online,
      link: NetworkLink.wifi,
      checkedAt: DateTime.now(),
    );
    notifyListeners();
  }

  /// Returns the snapshot to the untouched launch state, so a test that forced
  /// online does not leak that answer into the next one.
  @visibleForTesting
  void debugReset() {
    _snapshot = const ConnectionSnapshot.initial();
    notifyListeners();
  }

  /// How long to coalesce bursts of connectivity events.
  ///
  /// Android emits several events when Wi-Fi associates - it does not just fire
  /// once. Probing on each one would put two or three requests in flight
  /// within a second of each other, on a link that is still settling, and the
  /// slowest of them would win. A short quiet period gets one probe, issued
  /// once the link has stopped moving.
  static const Duration _settleDelay = Duration(milliseconds: 700);

  /// Backoff between automatic retries while the server is unreachable.
  ///
  /// This exists only for the case where the app is sitting on a working
  /// connection to a server that is down - a phone left open on a desk while
  /// somebody restarts `artisan serve`. The interval starts long enough not to
  /// hammer a machine that is deliberately off, and grows to a ceiling so a
  /// phone in a pocket does not drain its battery probing every few seconds.
  static const List<Duration> _retryLadder = <Duration>[
    Duration(seconds: 5),
    Duration(seconds: 10),
    Duration(seconds: 20),
    Duration(seconds: 30),
  ];

  int _retryStep = 0;

  // ========================================================
  // LIFECYCLE
  // ========================================================

  /// Starts watching the radio and takes a first reading.
  ///
  /// Called once during startup. Deliberately does NOT wait for a successful
  /// probe before returning: a student on a train should reach the login form
  /// and be told the server is unreachable, not watch a splash screen for the
  /// length of three timeouts. The probe runs in the background and the state
  /// settles when it answers.
  ///
  /// Safe to call more than once - a second call is a no-op rather than a
  /// second subscription, because two listeners would double every probe.
  Future<void> initialize() async {
    if (_linkSubscription != null) {
      return;
    }

    // Read the radio once up front so the very first state is not
    // `NetworkLink.none` when the app in fact has Wi-Fi. Cheap, and it stops
    // the first frame from claiming the phone is offline.
    await _syncLink();

    // Subscribe BEFORE probing, so a change that happens during the probe is
    // not missed. The reverse order would leave a window in which the app
    // reports a network that has already changed.
    _linkSubscription = _connectivity.onConnectivityChanged.listen(
      (List<ConnectivityResult> results) {
        _onLinkChanged(results);
      },
      onError: (Object _) {
        // A platform channel failure here must not take the app down. The
        // snapshot keeps its last good value and the scheduled probe will
        // correct it, which is strictly better than losing connectivity
        // awareness entirely because a plugin threw once.
      },
    );

    // Fire and forget on purpose: see the doc comment. The UI shows
    // `checking` until this resolves.
    unawaited(refresh());
  }

  @override
  void dispose() {
    _linkSubscription?.cancel();
    _linkSubscription = null;

    _debounce?.cancel();
    _debounce = null;

    _retryTimer?.cancel();
    _retryTimer = null;

    super.dispose();
  }

  // ========================================================
  // PUBLIC API
  // ========================================================

  /// Re-checks device connectivity and then the backend.
  ///
  /// This is what the "Retry Connection" button calls, and it is safe to call
  /// at any time. Concurrent calls collapse: a second call while a probe is
  /// running is ignored rather than queued, because there is nothing a second
  /// in-flight probe would learn.
  ///
  /// Returns the state it settled on, so a caller can react without waiting for
  /// a notification - the login screen uses this to decide whether to even
  /// attempt a sign-in.
  Future<AppConnectionState> refresh() => checkBackend();

  /// Probes the Laravel server and publishes the resulting state.
  ///
  /// The order matters and is the whole design:
  ///
  ///   1. No link? -> OFFLINE. Stop. There is nothing to send.
  ///   2. Link present -> CHECKING, then probe.
  ///   3. Probe answered -> ONLINE. Probe did not -> SERVER_UNAVAILABLE.
  ///
  /// Step 1 is not an optimisation. Sending a request into a known-dead link
  /// costs a full timeout to learn something already known, and on mobile data
  /// aimed at a LAN address it costs that timeout *every single time* - which
  /// is how a phone that is online but cannot see the server ends up looking
  /// permanently broken instead of clearly, quickly unavailable.
  Future<AppConnectionState> checkBackend() async {
    // Re-read the radio first rather than trusting [ConnectionSnapshot.link].
    //
    // The cached link is only as fresh as the last plugin event, and plugin
    // events are delivered asynchronously. A student walking back into Wi-Fi
    // range and tapping "Take a ticket" in the same instant is the case this
    // exists for: the radio has already reconnected, the event has not been
    // delivered yet, and a gate reading the stale link refuses an action that
    // would have worked. One platform call to ask again is a far smaller price
    // than that.
    await _syncLink();

    if (!_snapshot.hasDeviceConnection) {
      _publish(
        _snapshot.copyWith(
          state: AppConnectionState.offline,
          reachableHost: null,
          checkedAt: DateTime.now(),
        ),
      );

      _scheduleRetry();

      return AppConnectionState.offline;
    }

    // Someone is already asking. Wait for their answer rather than starting a
    // second probe - two probes against one server tell us nothing the first
    // one is not already telling us.
    final Future<bool>? running = _inFlight;

    if (running != null) {
      await running;

      return _snapshot.state;
    }

    final Future<bool> probe = _probe();

    _inFlight = probe;

    try {
      await probe;
    } finally {
      // Only clear our own, never a newer probe's.
      if (identical(_inFlight, probe)) {
        _inFlight = null;
      }
    }

    return _snapshot.state;
  }

  /// Asks Laravel whether it is there, and publishes the result.
  ///
  /// Split out of [checkBackend] so the in-flight future can be published
  /// before the first `await`. A probe started and awaited inline would let a
  /// second caller start a duplicate while the first was suspended.
  Future<bool> _probe() async {
    final int ticket = ++_generation;

    _publish(
      _snapshot.copyWith(state: AppConnectionState.checking),
    );

    final bool alive = await _api.checkHealth();

    // A newer probe started, or the link was lost, while this one was in
    // flight. Its answer describes a network that no longer exists and the
    // current truth belongs to whoever superseded it.
    if (ticket != _generation) {
      return _snapshot.isOnline;
    }

    final AppConnectionState settled = alive
        ? AppConnectionState.online
        : AppConnectionState.serverUnavailable;

    _publish(
      _snapshot.copyWith(
        state: settled,
        // Only a successful probe may claim a reachable host. Clearing it on
        // failure is what stops the UI continuing to show a server address
        // next to a state that says the server could not be reached.
        reachableHost: alive ? ApiConfig.displayHost : null,
        checkedAt: DateTime.now(),
      ),
    );

    if (alive) {
      // Reset the ladder on success, so the next outage starts from the short
      // interval instead of inheriting the ceiling from the last one.
      _retryStep = 0;
      _retryTimer?.cancel();
      _retryTimer = null;
    } else {
      _scheduleRetry();
    }

    return alive;
  }

  /// Runs [action] only if the server is reachable, and reports why if not.
  ///
  /// The one supported way for a screen to decide whether a server-backed
  /// action is allowed. Centralised so that "can this button be pressed" is
  /// answered by the same code that decided the state, and so no screen
  /// invents its own notion of online - which is how a form ends up submitting
  /// to a server that is not there.
  ///
  /// Returns the action's result, or null when the action was skipped. The
  /// caller supplies [onBlocked] to explain the refusal, because only the
  /// screen knows where the message should go.
  Future<T?> requireServer<T>(
    Future<T> Function() action, {
    required void Function(ConnectionSnapshot snapshot) onBlocked,
  }) async {
    if (_snapshot.canReachServer) {
      return action();
    }

    // One cheap re-probe before refusing. A user who has just walked back into
    // Wi-Fi range pressed the button believing they are online; the radio may
    // not have reported the transition yet, and failing them on a stale
    // reading is the most annoying version of this bug.
    final AppConnectionState settled = await checkBackend();

    if (settled == AppConnectionState.online) {
      return action();
    }

    onBlocked(_snapshot);

    return null;
  }

  /// Records that a real request failed to reach the server.
  ///
  /// Called by the repository layer when an actual API call comes back as a
  /// transport failure. This is not redundant with the probe - it is a
  /// correction. The probe is periodic by nature, so a server that dies
  /// between two probes leaves a briefly optimistic state, and the user who
  /// discovers it is the one whose request just failed. Without this, the
  /// status pill would keep saying "Online" while every action on the screen
  /// was refusing.
  ///
  /// Only ever downgrades. A failed call cannot prove the server is up, so it
  /// must not publish ONLINE - that stays the exclusive privilege of a probe
  /// that actually got a response. The next probe promotes the state again.
  void reportUnreachable() {
    // Already saying something the reader can act on: nothing to change.
    if (_snapshot.needsAttention) {
      return;
    }

    // A request was actually attempted and actually failed. That is stronger
    // evidence than anything the radio has said, so the state resolves now
    // rather than waiting for a probe that may be slow, stalled, or already
    // finished and been discarded.
    //
    // Which of the two it resolves to still depends on the radio, because that
    // is the only thing that distinguishes them: no link at all is the reader's
    // to fix, and a link with no answer is not.
    //
    // Note that [AppConnectionState.checking] is included. A failed request
    // during a probe is real evidence - the reader's own tap just proved the
    // server did not answer - and refusing to act on it left the UI sitting on
    // "Checking connection..." indefinitely, which is the one state a reader
    // cannot act on at all. The probe still publishes its own answer when it
    // settles; until then the known failure is the more useful thing to show.
    _publish(
      _snapshot.copyWith(
        state: _snapshot.hasDeviceConnection
            ? AppConnectionState.serverUnavailable
            : AppConnectionState.offline,
        reachableHost: null,
        checkedAt: DateTime.now(),
      ),
    );

    _scheduleRetry();
  }

  /// The message to show for the current state.
  ///
  /// Resolved here rather than in the widgets so the wording is impossible to
  /// fork, and so the mobile-data case can be detected in exactly one place.
  ConnectionMessage get message {
    switch (_snapshot.state) {
      case AppConnectionState.online:
        return const ConnectionMessage(
          title: ConnectionMessages.onlineTitle,
          body: ConnectionMessages.onlineBody,
        );

      case AppConnectionState.checking:
        return const ConnectionMessage(
          title: ConnectionMessages.checkingTitle,
          body: ConnectionMessages.checkingBody,
        );

      case AppConnectionState.offline:
        return const ConnectionMessage(
          title: ConnectionMessages.offlineTitle,
          body: ConnectionMessages.offlineBody,
        );

      case AppConnectionState.serverUnavailable:
        // The one case where more than the generic sentence applies.
        //
        // On mobile data with a private base URL, "check your Wi-Fi" is not
        // merely incomplete, it is confusing: the reader's Wi-Fi may be fine
        // and irrelevant. Naming the actual situation is the difference
        // between a message they can act on and one they have to reason about.
        if (_snapshot.link == NetworkLink.mobile &&
            ConnectionMessages.serverIsOnPrivateNetwork) {
          return const ConnectionMessage(
            title: ConnectionMessages.wrongNetworkTitle,
            body: ConnectionMessages.mobileDataPrivateServerBody,
          );
        }

        return const ConnectionMessage(
          title: ConnectionMessages.serverOfflineTitle,
          body: ConnectionMessages.serverOfflineBody,
        );
    }
  }

  // ========================================================
  // INTERNALS
  // ========================================================

  /// Reads the radio without letting a platform failure escape.
  ///
  /// A plugin throw here must not become a crash on launch. Returning an empty
  /// list makes [_linkOf] yield [NetworkLink.none], and the scheduled probe
  /// will correct it on the next event - a brief wrong answer beats no app.
  Future<List<ConnectivityResult>> _safeCheck() async {
    try {
      return await _connectivity.checkConnectivity();
    } catch (error) {
      return const <ConnectivityResult>[];
    }
  }

  /// Asks the radio what it says now and applies the answer.
  ///
  /// Every path that decides whether the server can be reached goes through
  /// here first, so "the link" always means the link as of this moment rather
  /// than as of the last plugin event.
  Future<void> _syncLink() async {
    final List<ConnectivityResult> current = await _safeCheck();

    _applyLink(_linkOf(current));
  }

  /// Handles a connectivity event from the plugin.
  ///
  /// Two things happen, in this order, and the order is the behaviour:
  ///
  /// The link is applied immediately, without waiting. A phone that has just
  /// lost Wi-Fi should show OFFLINE at once rather than sitting in CHECKING
  /// for the length of a probe that is already known to be pointless.
  ///
  /// The backend probe is then debounced, because Android emits a burst when a
  /// link associates and probing each event would put several requests in
  /// flight on a link that has not settled.
  void _onLinkChanged(List<ConnectivityResult> results) {
    _applyLink(_linkOf(results));

    _debounce?.cancel();

    _debounce = Timer(_settleDelay, () {
      unawaited(checkBackend());
    });
  }

  /// Folds the plugin's transport list into one [NetworkLink].
  ///
  /// A device can report several at once - Wi-Fi and cellular both up while
  /// mobile data is on standby, or VPN layered over Wi-Fi. The list is ordered
  /// by usefulness to this app rather than by the plugin's enum order, because
  /// the only question being asked is "how should the reader be advised?":
  ///
  ///   Wi-Fi first, unconditionally.
  ///     A phone with both radios up is on Wi-Fi as far as a LAN server is
  ///     concerned, and the phone will silently prefer Wi-Fi for the request
  ///     anyway. Reporting `mobile` here would tell somebody their ticket
  ///     cannot be taken when it very much can.
  ///
  ///   Then mobile.
  ///     Only reachable when cellular is genuinely the carrier - which is the
  ///     case worth the "server is on the campus network" warning.
  ///
  ///   `none` is checked first and on its own.
  ///     The plugin guarantees `none` appears only as a single-element list,
  ///     so finding it anywhere means there is no usable link.
  static NetworkLink _linkOf(List<ConnectivityResult> results) {
    if (results.isEmpty || results.contains(ConnectivityResult.none)) {
      return NetworkLink.none;
    }

    if (results.contains(ConnectivityResult.wifi)) {
      return NetworkLink.wifi;
    }

    if (results.contains(ConnectivityResult.mobile)) {
      return NetworkLink.mobile;
    }

    // ethernet, vpn, satellite, other - all a usable link, none of them
    // something a queue screen can give advice about.
    return NetworkLink.other;
  }

  /// Publishes a new link, promoting the state where the link alone decides it.
  ///
  /// Only a change of link is applied here; the backend half of the answer is
  /// never guessed from a radio event. Moving to [NetworkLink.none] is the one
  /// exception, and it is not a guess: with no link there is provably no
  /// server, so OFFLINE is a deduction and not a probe result.
  void _applyLink(NetworkLink link) {
    if (link == _snapshot.link) {
      return;
    }

    final bool lostEverything = link == NetworkLink.none;

    // A link change invalidates any in-flight probe: it was issued against a
    // network that no longer exists. Bumping the generation is enough to
    // discard the answer; clearing [_inFlight] lets the next caller start a
    // fresh probe immediately instead of waiting out the abandoned one.
    if (lostEverything) {
      _generation++;
      _inFlight = null;
    }

    _publish(
      _snapshot.copyWith(
        link: link,
        state: lostEverything
            ? AppConnectionState.offline
            // Still offline? Keep saying offline - a Wi-Fi drop while already
            // offline is not new information, and flapping to CHECKING here
            // would make the banner flicker every time a second network
            // appears and disappears.
            : _snapshot.state == AppConnectionState.offline
            ? AppConnectionState.offline
            : _snapshot.state,
        reachableHost: lostEverything ? null : _snapshot.reachableHost,
      ),
    );

    if (lostEverything) {
      _retryTimer?.cancel();
      _retryTimer = null;

      // Nothing to probe against. The plugin's next event will start this
      // again, so there is no timer to arm here.
      return;
    }

    // A link came back. Reset the ladder so recovery is quick rather than
    // inheriting a 30-second wait from a previous outage.
    _retryStep = 0;
  }

  /// Queues the next automatic retry, backing off each time it fails.
  void _scheduleRetry() {
    if (!_snapshot.hasDeviceConnection) {
      return;
    }

    _retryTimer?.cancel();

    final Duration wait =
        _retryLadder[_retryStep.clamp(0, _retryLadder.length - 1)];

    // Only climb the ladder once the ceiling has been reached; a probe that
    // succeeds resets it, and that is checked in checkBackend.
    if (_retryStep < _retryLadder.length - 1) {
      _retryStep++;
    }

    _retryTimer = Timer(wait, () {
      unawaited(checkBackend());
    });
  }

  /// Publishes a snapshot, notifying only on a real change.
  void _publish(ConnectionSnapshot next) {
    if (next.sameAs(_snapshot)) {
      return;
    }

    _snapshot = next;

    notifyListeners();
  }
}

/// A resolved, displayable connection message.
@immutable
class ConnectionMessage {
  const ConnectionMessage({required this.title, required this.body});

  final String title;
  final String body;
}

/// Sentinel telling `copyWith` to keep the current value of a nullable field.
///
/// Without it `reachableHost: null` would be indistinguishable from "not
/// supplied", and `?? this.reachableHost` would silently keep the stale host -
/// so the UI would carry on showing a server address next to a state that says
/// the server could not be reached. That is precisely the claim the state
/// machine exists to prevent, so clearing has to actually work.
const Object _keepCurrent = Object();

extension ConnectionSnapshotCopy on ConnectionSnapshot {
  /// A copy with the given fields replaced.
  ///
  /// An extension rather than a method so [ConnectionSnapshot] can stay a
  /// `const` immutable value - which is what lets a `const` initial snapshot
  /// exist and lets the UI skip rebuilding when nothing changed.
  ///
  /// [reachableHost] and [checkedAt] take [Object] rather than their real types
  /// so that passing `null` means *clear this*, while passing nothing means
  /// *keep it*. See [_keepCurrent].
  ConnectionSnapshot copyWith({
    AppConnectionState? state,
    NetworkLink? link,
    Object? reachableHost = _keepCurrent,
    Object? checkedAt = _keepCurrent,
  }) => ConnectionSnapshot(
        state: state ?? this.state,
        link: link ?? this.link,
        reachableHost: identical(reachableHost, _keepCurrent)
            ? this.reachableHost
            : reachableHost as String?,
        checkedAt: identical(checkedAt, _keepCurrent)
            ? this.checkedAt
            : checkedAt as DateTime?,
      );
}