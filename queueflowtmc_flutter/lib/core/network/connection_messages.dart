/// ============================================================
/// QUEUEFLOW TMC — CONNECTION MESSAGES
///
/// Every sentence the app shows about the network lives here.
///
/// This is centralised for a reason that is about correctness rather than
/// tidiness. Two places that both decide how to describe an unreachable server
/// will eventually disagree, and the user is the one who ends up holding both
/// versions: a banner saying "no internet" while a different screen says
/// "server offline", neither of which matches what is actually wrong. One
/// table, keyed by state, means the login screen, the status pill, the offline
/// banner and the API error path cannot drift apart.
///
/// NOTHING HERE PROMISES INTERNET EXISTS
///
/// The wording is deliberately split along the line that actually matters:
/// "the phone has no network" and "the phone has a network but QueueFlow is
/// not answering" are different problems with different fixes. Collapsing them
/// into one "no internet" message is the single most misleading thing this app
/// could do, because the reader's next action - reconnect WiFi versus tell the
/// registrar's office the server is down - is completely different in each
/// case.
///
/// Also note what is NOT here: no stack traces, no `SocketException`, no
/// hard-coded development address in a user-facing sentence, and no "please
/// check your firewall". Those are for logs. [ApiConfig.displayHost] is used
/// only where a host is genuinely the point, such as the About screen.
/// ============================================================
library;

import '../api/api_config.dart';

/// The user-facing wording for each connection state.
///
/// Pure functions of the state and the current base URL - no I/O, no
/// dependencies beyond [ApiConfig]. That keeps them trivially testable and
/// means the UI can call them synchronously while building, which is what lets
/// a banner appear in the same frame as the state that caused it.
class ConnectionMessages {
  ConnectionMessages._();

  // ========================================================
  // TITLES
  // ========================================================

  /// Short label for the state. Used by the status pill.
  static const String offlineTitle = 'Offline';

  static const String serverOfflineTitle = 'Server Offline';

  static const String checkingTitle = 'Checking…';

  static const String onlineTitle = 'Online';

  /// Shown when the phone is on mobile data but the server is on a LAN the
  /// phone is not on. Deliberately does NOT say "no internet": the phone has
  /// internet, and claiming otherwise is the exact failure this whole system
  /// exists to avoid.
  static const String wrongNetworkTitle = 'Server Not Reachable';

  // ========================================================
  // BODIES
  // ========================================================

  /// No network at all. The radio has nothing to work with.
  static const String offlineBody =
      'No Internet Connection\n'
      'Please connect to Wi-Fi or mobile data.';

  /// The phone has a link, and the QueueFlow server did not answer.
  ///
  /// Shown for every unreachable server behind a public HTTPS endpoint, over
  /// any Wi-Fi or mobile data connection: the network type never decides the
  /// message, the failed reachability probe does. Paired with the Retry
  /// button on the banner, pill and snackbar.
  static const String serverOfflineBody =
      'Server Offline\n'
      'Please check your internet connection or try again later.';

  /// Shown while a probe is in flight. Never blocks anything.
  static const String checkingBody = 'Checking connection...';

  static const String onlineBody = 'Connected';

  /// Fallback when a ticket action ends without the server's answer AND the
  /// phone currently reads as online.
  ///
  /// The online state's body ("Connected") describes the link, not the
  /// action, so showing it as the result of pressing Generate Ticket reads
  /// as a fake success. This sentence says the honest thing instead: the
  /// number was not issued and nothing was decided.
  static const String actionResultUnknownBody =
      'Your number was not issued. Please try again.';

  /// The wording for a server-gated action the gate refused to run.
  ///
  /// [canReachServer] is the gate's answer at the moment the refusal is
  /// reported, and [liveBody] is the connectivity message body for that
  /// same moment. When the phone reads as online, the live body would be
  /// the link description ("Connected") - which is not an action result -
  /// so the undecided-action sentence is returned instead. Centralised so
  /// both ticket screens answer a refused Generate identically, and so no
  /// screen can reintroduce the link state as a ticket result.
  static String blockedActionBody({
    required bool canReachServer,
    required String liveBody,
  }) => canReachServer ? actionResultUnknownBody : liveBody;

  // ========================================================
  // MOBILE DATA
  // ========================================================

  /// True when this build points at a private address.
  ///
  /// A private address is not routable from a cellular connection, by
  /// definition of RFC 1918 - there is no route to 192.168.x.x once the packet
  /// leaves the campus router. So when the phone is on mobile data, the server
  /// being unreachable is not a fault to retry into oblivion; it is a
  /// statement of fact about which network the phone is on.
  static bool get serverIsOnPrivateNetwork => ApiConfig.isPrivateHost;

  /// The sentence for "mobile data, and the server is on the campus LAN".
  ///
  /// Says the true thing and names the one action that fixes it. Saying "no
  /// internet connection" here would be a lie - there is internet - and would
  /// send the reader to toggle Wi-Fi on a phone that is already online.
  static const String mobileDataPrivateServerBody =
      'You are on mobile data.\n'
      'The QueueFlow TMC server is on the campus network, so it can only be '
      'reached over Wi-Fi. Connect to the campus Wi-Fi to continue.';

  /// The long form used when an action is attempted and fails.
  ///
  /// A checklist rather than a sentence, because "unable to connect" on its own
  /// gives the reader nothing to do. Deliberately names no hard-coded host: the
  /// development address is a DHCP lease that changes, and a production build
  /// points at a public HTTPS URL - either way a literal IP in this sentence
  /// goes stale. Callers that need the host append [ApiConfig.displayHost].
  static const String actionFailedBody =
      'Server Offline\n'
      'Unable to connect to QueueFlow TMC.\n\n'
      'Please check:\n'
      '•  Your internet connection (Wi-Fi or mobile data)\n'
      '•  That the QueueFlow TMC server is running and reachable\n'
      '•  Then tap Retry to reconnect.';

  /// Same checklist with the configured host appended, for diagnostics.
  ///
  /// Used on screens where showing which server was dialled helps support
  /// (e.g. About/Settings). Not a const because the host is build-configured.
  static String actionFailedBodyWithHost({String? host}) {
    final String target = (host == null || host.isEmpty)
        ? ApiConfig.displayHost
        : host;

    return '$actionFailedBody\nServer: $target';
  }

  // ========================================================
  // BUTTONS
  // ========================================================

  static const String retryLabel = 'Retry Connection';

  static const String retryingLabel = 'Retrying...';

  // ========================================================
  // OFFLINE ACTION GUARDS
  // ========================================================

  /// Shown on an action that cannot be completed without the server.
  static const String actionRequiresConnection =
      "You're currently offline.\nReconnect to continue.";

  /// Tooltip / accessibility label for the status pill.
  static String pillSemantics(String label) => 'Connection status: $label';
}