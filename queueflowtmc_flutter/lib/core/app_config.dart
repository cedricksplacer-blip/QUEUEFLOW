/// ============================================================
/// QUEUEFLOW TMC
/// APPLICATION CONFIGURATION
///
/// Central application configuration for the Laravel REST API.
///
/// The API URL, API prefix, connection timeouts, and health probe
/// are managed by ApiConfig in:
/// lib/core/api/api_config.dart
///
/// Same-Wi-Fi or cross-network connection:
///   The compiled-in default in ApiConfig is the public HTTPS ngrok tunnel,
///   which works from any Wi-Fi or mobile data while the laptop, Laravel
///   (`artisan serve` on port 8000), and `ngrok http 8000` are running. A same-Wi-Fi fallback can be entered at
///   runtime in About > Server connection > Change, or baked in with:
///   flutter run --dart-define=API_BASE_URL=http://[lan-ip]:8000/api
///
/// Free ngrok URLs change on every tunnel restart: when the URL changes,
/// enter the new one at runtime or rebuild the APK with the new address.
/// A reserved ngrok domain keeps one stable URL instead. ngrok is a tunnel,
/// not hosting: if the laptop sleeps or the tunnel stops, the API is down.
///
/// Run the app:
///   flutter run
///
/// Build Android APK:
///   flutter build apk --release
///
/// IMPORTANT:
/// - The single base URL lives in ApiConfig.
/// - Keep the base URL ending in /api, not /api/v1.
/// - ApiConfig adds the versioned /v1 prefix.
/// - The Laravel server (via the tunnel) must be running for live data.
/// ============================================================

library;

import 'api/api_config.dart';

class AppConfig {
  AppConfig._();

  // ==========================================================
  // API CONNECTION
  // ==========================================================

  /// API root URL without a trailing slash.
  ///
  /// Example:
  /// https://your-public-host.example/api
  static String get apiRoot => ApiConfig.baseUrl;

  /// Versioned API prefix, usually /v1.
  static String get apiPrefix => ApiConfig.apiPrefix;

  /// Builds an absolute API URL from a path.
  ///
  /// Example:
  /// apiUrl('/auth/login')
  static String apiUrl(String path) => ApiConfig.url(path);

  /// Maximum duration for an individual API request.
  static Duration get requestTimeout => ApiConfig.requestTimeout;

  /// Stable device name recorded for Sanctum authentication tokens.
  static const String deviceName = 'queueflow-android';

  /// Human-readable API source for About and Settings screens.
  static String get dataSource => 'QueueFlow server ($apiRoot)';

  // ==========================================================
  // APPLICATION INFORMATION
  // ==========================================================

  static const String appVersion = '1.0.0';
}
