import 'package:flutter/foundation.dart';

import 'campus_backend.dart';
import 'local_backend.dart';
import 'remote_backend.dart';

/// ============================================================
/// QUEUEFLOW TMC — BACKEND SELECTION
///
/// Hands the same [RemoteBackend] instance to every caller, so there is exactly
/// one place in the app that decides where the data comes from.
///
/// There is no fallback. If Laravel cannot be reached the student is told the
/// server is not answering and offered a retry; the app never quietly switches
/// to another transport, because a queue system that invented its own queue
/// would be worse than one that admitted it was offline.
///
/// [LocalBackend] remains in the tree as a test fixture — the repository tests
/// exercise the ticket rules against a seeded campus without needing a server.
/// It is reachable only through [overrideForTesting]; no build flag and no code
/// path in `main.dart` can select it.
/// ============================================================

class CampusBackendFactory {
  CampusBackendFactory._();

  static final CampusBackendFactory instance = CampusBackendFactory._();

  CampusBackend? _backend;

  /// The transport this build talks to.
  ///
  /// Created on first use and then reused, because the remote one carries an
  /// HTTP client and its connection pool. Handing out a fresh instance per call
  /// would drop sockets mid-session.
  CampusBackend get current => _backend ??= _create();

  /// Overrides the transport for the rest of the process.
  ///
  /// Tests use this to point the repositories at a seeded campus without
  /// needing a server, and to inject a double. Passing null restores the real
  /// transport.
  @visibleForTesting
  void overrideForTesting(CampusBackend? backend) {
    _backend = backend;
  }

  /// Convenience for the tests that want the seeded campus back afterwards.
  @visibleForTesting
  void useSeededCampusForTesting() => _backend = LocalBackend.instance;

  static CampusBackend _create() => RemoteBackend();
}
