/// ============================================================
/// QUEUEFLOW TMC — ENDPOINT STORE
///
/// Persists a runtime API endpoint override chosen inside the app.
///
/// WHY THIS EXISTS ALONGSIDE `--dart-define`
///
/// The compiled-in default is the public HTTPS tunnel for cross-network use.
/// A same-Wi-Fi LAN address can also be set here at runtime from the About
/// screen - and when the tunnel URL changes after a restart, entering the
/// new address here avoids rebuilding the APK, so the address can also be
/// set at runtime from the About screen and remembered here.
///
/// WHAT IT DOES NOT DO
///
/// It never invents an address and never falls back to one. An absent or
/// invalid saved value means the compiled-in default is used, full stop. The
/// queue still lives on the server; this only decides which server is asked.
/// Like [OfflineCache], every call swallows its own failures: a broken
/// preference store must not stop the app from opening.
/// ============================================================
library;

import 'package:shared_preferences/shared_preferences.dart';

import '../api/api_config.dart';

class EndpointStore {
  EndpointStore._();

  /// The persisted runtime endpoint, exactly as normalised.
  static const String endpointKey = 'qf_api_base_url';

  /// Restores a previously saved endpoint before the first probe runs.
  ///
  /// Called once during startup, ahead of `ConnectivityService.initialize`,
  /// so the very first health check already goes to the right server.
  static Future<void> load() async {
    try {
      final SharedPreferences prefs = await SharedPreferences.getInstance();
      final String? raw = prefs.getString(endpointKey);

      if (raw == null || raw.trim().isEmpty) {
        return;
      }

      // A value saved by an older build may no longer validate. Ignore it
      // rather than pointing the app at an address the current rules refuse.
      if (ApiConfig.validateEndpoint(raw) != null) {
        return;
      }

      ApiConfig.setEndpointOverride(raw);
    } catch (_) {
      // No preference store, no override. The compiled-in default stands.
    }
  }

  /// Saves [raw] as the runtime endpoint and applies it immediately.
  ///
  /// Callers must have passed [raw] through [ApiConfig.validateEndpoint]
  /// first; the normalised form is what is stored, so `https://host` and
  /// `https://host/api/` end up as the same entry.
  static Future<void> save(String raw) async {
    ApiConfig.setEndpointOverride(raw);

    try {
      final SharedPreferences prefs = await SharedPreferences.getInstance();
      await prefs.setString(endpointKey, ApiConfig.baseUrl);
    } catch (_) {
      // The override is already applied in memory; persistence is best effort.
    }
  }

  /// Forgets the runtime endpoint and returns to the compiled-in default.
  static Future<void> clear() async {
    ApiConfig.clearEndpointOverride();

    try {
      final SharedPreferences prefs = await SharedPreferences.getInstance();
      await prefs.remove(endpointKey);
    } catch (_) {
      // The in-memory default is already restored.
    }
  }
}
