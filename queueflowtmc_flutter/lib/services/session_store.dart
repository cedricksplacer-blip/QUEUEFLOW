import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../core/app_constants.dart';
import '../models/app_user.dart';
import '../models/student.dart';

/// ============================================================
/// QUEUEFLOW TMC — SESSION STORE
///
/// Persists the signed-in account AND its bearer token, so the app can skip
/// the login screen on relaunch.
///
/// One store covers all three roles: a student, a staff member and an
/// administrator are all the same `users` row, and [role] is what tells the
/// app which shell to open.
///
/// The token lives here rather than inside the remote backend because it has
/// exactly the same lifetime as the user row - and because [ApiClient] reads
/// it through a callback on every request rather than capturing it, so a
/// sign-in or a sign-out takes effect immediately instead of at the next
/// launch. The role is read back from the cached user row rather than from a
/// separate preference. A second source of truth is a second thing to fall out
/// of sync - an account demoted from staff to student would otherwise keep its
/// old shell until the next cold start.
///
/// [token] is null for the on-device campus, which has no server and therefore
/// no token; nothing in the app treats its absence as an error.
///
/// The account row is a plain preference, but the bearer token is not: it is
/// the credential that authorises every call, so it is kept in the platform's
/// encrypted store (Android Keystore / iOS Keychain) rather than in the
/// world-readable preference file. Reads and writes on that vault are best
/// effort - a device with no usable keystore still signs in and runs, it just
/// cannot carry the session across a cold start.
/// ============================================================

class SessionStore {
  SessionStore._();

  /// The encrypted vault the bearer token lives in. The account row stays in
  /// [SharedPreferences]; only the credential is sealed away.
  static const FlutterSecureStorage _vault = FlutterSecureStorage();

  static AppUser? _user;
  static String? _token;
  static bool _loaded = false;
  static SharedPreferences? _prefs;

  static AppUser? get user => _user;
  static bool get hasSession => _user != null;

  /// The bearer token for the signed-in account, or null when there is
  /// none - either because nobody is signed in or because the active
  /// transport does not use one.
  static String? get token => _token;

  /// The role of the cached account, or [AppRole.unknown] when signed out.
  ///
  /// This decides routing on launch, so it must be answerable
  /// synchronously from what is on disk.
  static AppRole get role => _user?.role ?? AppRole.unknown;

  /// The student-shaped view of the cached account, for the student shell.
  static Student? get student => _user?.asStudent;

  /// Drops the once-per-process guard so [load] reads from disk again. The
  /// app loads exactly once; this exists only so a test can rehearse a cold
  /// start.
  @visibleForTesting
  static void debugReload() => _loaded = false;

  /// Loads the persisted session once, then serves it from memory.
  static Future<void> load() async {
    if (_loaded) {
      return;
    }

    try {
      final SharedPreferences prefs = await SharedPreferences.getInstance();

      _prefs = prefs;
      _user = _decodeUser(prefs.getString(AppConstants.sessionUserKey));

      _token = await _readToken(prefs);
    } catch (_) {
      // A corrupt or unavailable preference store must never block startup.
      _user = null;
      _token = null;
    }

    _loaded = true;
  }

  /// Reads the token from the encrypted vault.
  ///
  /// An earlier build wrote the token in the clear to [prefs]; when the vault
  /// is empty but that legacy value is present, it is sealed into the vault and
  /// the plaintext copy removed, so upgrading never costs the reader a sign-in.
  static Future<String?> _readToken(SharedPreferences prefs) async {
    final String? legacy = prefs.getString(AppConstants.sessionTokenKey);
    final bool hasLegacy = legacy != null && legacy.isNotEmpty;

    try {
      String? token = await _vault.read(key: AppConstants.sessionTokenKey);

      if ((token == null || token.isEmpty) && hasLegacy) {
        await _vault.write(key: AppConstants.sessionTokenKey, value: legacy);
        await prefs.remove(AppConstants.sessionTokenKey);
        token = legacy;
      }

      return (token == null || token.isEmpty) ? null : token;
    } catch (_) {
      // No usable vault: fall back to any plaintext token so an existing
      // session is not silently dropped on a device that cannot encrypt.
      return hasLegacy ? legacy : null;
    }
  }

  /// Seals [token] into the vault, or clears it when [token] is null. Best
  /// effort: the caller already holds the token in memory, so a failed write
  /// costs only persistence, never the running session.
  static Future<void> _writeToken(String? token) async {
    try {
      if (token == null) {
        await _vault.delete(key: AppConstants.sessionTokenKey);
      } else {
        await _vault.write(key: AppConstants.sessionTokenKey, value: token);
      }
    } catch (_) {
      // See the class comment: a device without a keystore still runs.
    }
  }

  /// Clears everything held in memory as well as on disk.
  static Future<void> clear() async {
    _user = null;
    _token = null;

    await _writeToken(null);

    final SharedPreferences? prefs = _prefs;

    if (prefs == null) {
      return;
    }

    await prefs.remove(AppConstants.sessionUserKey);
    await prefs.remove(AppConstants.sessionTokenKey);
  }

  /// Records a successful sign-in.
  static Future<void> save({required AppUser user, String? token}) async {
    _user = user;
    _token = token;

    await _writeToken(token);

    final SharedPreferences? prefs = _prefs;

    if (prefs == null) {
      return;
    }

    await prefs.setString(
      AppConstants.sessionUserKey,
      jsonEncode(user.toMap()),
    );

    // The credential is sealed in the vault, so never leave a plaintext copy
    // in the preference file.
    await prefs.remove(AppConstants.sessionTokenKey);
  }

  /// Refreshes the cached account after an in-app profile edit or a
  /// re-read of the account on launch.
  ///
  /// The token is deliberately left alone: this only describes the account,
  /// and re-reading the profile must not sign the device out.
  static Future<void> updateUser(AppUser user) async {
    _user = user;

    final SharedPreferences? prefs = _prefs;

    if (prefs == null) {
      return;
    }

    await prefs.setString(
      AppConstants.sessionUserKey,
      jsonEncode(user.toMap()),
    );
  }

  /// Convenience shim for the student portal, which still speaks in
  /// [Student] because the student payload answers with one.
  static Future<void> updateStudent(Student student) =>
      updateUser(AppUser.fromStudent(student));

  static AppUser? _decodeUser(String? raw) {
    if (raw == null || raw.isEmpty) {
      return null;
    }

    try {
      return AppUser.fromMap(Map<String, dynamic>.from(jsonDecode(raw) as Map));
    } catch (_) {
      return null;
    }
  }
}
