import 'dart:convert';

import 'package:flutter_secure_storage/test/test_flutter_secure_storage_platform.dart';
import 'package:flutter_secure_storage_platform_interface/flutter_secure_storage_platform_interface.dart';
import 'package:flutter_test/flutter_test.dart';
import 'package:shared_preferences/shared_preferences.dart';

import 'package:queueflow_tmc/core/app_constants.dart';
import 'package:queueflow_tmc/models/app_user.dart';
import 'package:queueflow_tmc/services/session_store.dart';

/// ============================================================
/// QUEUEFLOW TMC — THE SEALED TOKEN
///
/// The bearer token is the credential that authorises every call, so
/// [SessionStore] keeps it in the platform keychain rather than in the
/// world-readable preference file. These tests pin the two decisions that
/// matter when that changes under the reader's feet:
///
///   - a token signed in on a previous run comes back after a cold start, and
///     is stored in the vault, never in the clear;
///   - a token left in plaintext by an earlier build is sealed into the vault
///     on first read, so upgrading never costs the reader a sign-in.
///
/// The package's in-memory platform stands in for the keychain, which does not
/// exist in a test process.
/// ============================================================
void main() {
  const Map<String, dynamic> student = <String, dynamic>{
    'id': 7,
    'name': 'Juan Dela Cruz',
    'email': 'juan@tmc.edu.ph',
    'role': 'student',
    'student_id': '24-019083',
    'course': 'BSIT',
    'is_active': true,
  };

  late Map<String, String> vault;

  setUp(() {
    vault = <String, String>{};
    FlutterSecureStoragePlatform.instance = TestFlutterSecureStoragePlatform(
      vault,
    );
    SharedPreferences.setMockInitialValues(<String, Object>{});
    SessionStore.debugReload();
  });

  test('a token signed in earlier comes back after a cold start', () async {
    await SessionStore.load();
    await SessionStore.save(
      user: AppUser.fromMap(student),
      token: 'sealed-token',
    );

    // The credential is sealed away, not left beside the account row.
    final SharedPreferences prefs = await SharedPreferences.getInstance();
    expect(prefs.getString(AppConstants.sessionTokenKey), isNull);
    expect(vault[AppConstants.sessionTokenKey], 'sealed-token');

    // A cold start re-reads the account and the sealed token.
    SessionStore.debugReload();
    await SessionStore.load();

    expect(SessionStore.hasSession, isTrue);
    expect(SessionStore.token, 'sealed-token');
  });

  test('a legacy plaintext token is sealed into the vault on first read', () async {
    SharedPreferences.setMockInitialValues(<String, Object>{
      AppConstants.sessionUserKey: jsonEncode(student),
      AppConstants.sessionTokenKey: 'legacy-token',
    });
    SessionStore.debugReload();

    await SessionStore.load();

    // The session survives the upgrade...
    expect(SessionStore.token, 'legacy-token');
    expect(SessionStore.hasSession, isTrue);

    // ...and the plaintext copy is gone, sealed into the vault instead.
    final SharedPreferences prefs = await SharedPreferences.getInstance();
    expect(prefs.getString(AppConstants.sessionTokenKey), isNull);
    expect(vault[AppConstants.sessionTokenKey], 'legacy-token');
  });

  test('signing out empties the vault as well as the account row', () async {
    await SessionStore.load();
    await SessionStore.save(
      user: AppUser.fromMap(student),
      token: 'sealed-token',
    );

    await SessionStore.clear();

    expect(SessionStore.hasSession, isFalse);
    expect(SessionStore.token, isNull);
    expect(vault.containsKey(AppConstants.sessionTokenKey), isFalse);
  });
}
