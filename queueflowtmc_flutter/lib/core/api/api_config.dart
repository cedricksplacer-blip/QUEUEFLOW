/// ============================================================
/// QUEUEFLOW TMC — API CONFIGURATION
/// ============================================================
/// Central configuration for the Laravel API server.
///
/// Default server: the public HTTPS ngrok tunnel below. The phones reach
/// the laptop's Laravel server through it from ANY Wi-Fi or mobile data -
/// they do not need to be on the same network as the laptop. The laptop,
/// `artisan serve` on port 8000, and `ngrok http 8000` must stay running:
/// ngrok is a tunnel, not hosting.
///
/// IMPORTANT:
/// - The API base URL must end with /api (never /api/v1).
/// - Versioned requests append /v1 (ApiConfig adds it per request).
/// - Free ngrok URLs change on every tunnel restart. When it changes, enter
///   the new address once via About > Server connection > Change (no
///   rebuild), or rebuild with:
///   `--dart-define=API_BASE_URL=` with the new `https://.../api` address.
/// - A reserved ngrok domain keeps one stable URL instead.
/// ============================================================

library;

class ApiConfig {
  ApiConfig._();

  // ============================================================
  // SERVER ADDRESS
  // ============================================================

  /// Override at build time using:
  /// `--dart-define=API_BASE_URL=` with the new `https://.../api` address.
  ///
  /// Public hosts must use `https://`. A same-Wi-Fi fallback can still be
  /// entered at runtime (About > Server connection > Change) as
  /// `http://` plus the PC LAN IP plus `/api` - `http://` is only allowed
  /// for loopback/private hosts.
  static const String _rawBaseUrl = String.fromEnvironment(
    'API_BASE_URL',
    defaultValue: 'https://spelling-ocelot-opposing.ngrok-free.dev/api',
  );

  /// Optional server address configured at runtime.
  static String? _overrideBaseUrl;

  /// Normalized API root without a trailing slash.
  static String get baseUrl => _normalize(_overrideBaseUrl ?? _rawBaseUrl);

  /// Whether a runtime server override is active.
  static bool get hasEndpointOverride => _overrideBaseUrl != null;

  /// True while the compiled-in default is still the placeholder.
  ///
  /// The placeholder host (`your-server.example`) does not
  /// resolve, so every probe fails gracefully as Server Offline until one of
  /// the three configuration points supplies the real server URL:
  ///   1. About > Server connection > Change (runtime, no rebuild),
  ///   2. `--dart-define=API_BASE_URL=` with the real host plus `/api`,
  ///   3. the one `defaultValue` line above (fresh installs).
  static bool get isPlaceholder {
    final String? host = Uri.tryParse(baseUrl)?.host;

    return !hasEndpointOverride &&
        (host == 'your-server.example' ||
            host == 'your-lan-ip.example');
  }

  /// One-line instruction shown in the app while [isPlaceholder] is true.
  static String get setupHint =>
      'Enter your server address in About > Server connection > Change '
      '(the public https address ending in /api, or the same-Wi-Fi '
      'http://<lan-ip>:8000/api), or rebuild with '
      '`--dart-define=API_BASE_URL` set to that address.';

  /// Set a runtime server address, or null to use the default.
  static void setEndpointOverride(String? raw) {
    _overrideBaseUrl = raw == null ? null : _normalize(raw);
  }

  /// Reset to the configured default server.
  static void clearEndpointOverride() {
    _overrideBaseUrl = null;
  }

  // ============================================================
  // URL NORMALIZATION
  // ============================================================

  /// Removes trailing slashes, strips an accidental /v1 suffix (the base
  /// must be .../api -- versioning is added per request), and adds /api
  /// when no path exists.
  static String _normalize(String raw) {
    String value = raw.trim();

    while (value.endsWith('/')) {
      value = value.substring(0, value.length - 1);
    }

    // Guard against duplicate version segments: entering .../api/v1 would
    // otherwise produce .../api/v1/v1/... once apiPrefix is appended.
    if (value.toLowerCase().endsWith('/api/v1')) {
      value = value.substring(0, value.length - 3);
    }

    final Uri? uri = Uri.tryParse(value);

    if (uri != null && uri.path.isEmpty) {
      value = '$value/api';
    }

    return value;
  }

  // ============================================================
  // SERVER ADDRESS VALIDATION
  // ============================================================

  /// Returns null when the supplied API address is valid.
  /// Otherwise, returns an error message.
  static String? validateEndpoint(String input) {
    final String value = input.trim();

    if (value.isEmpty) {
      return 'Enter the server address.';
    }

    final String normalized = _normalize(value);
    final Uri? uri = Uri.tryParse(normalized);

    if (uri == null || !uri.hasScheme || uri.host.isEmpty) {
      return 'Enter a valid server address, such as '
          'https://example.com/api';
    }

    if (uri.scheme != 'https' && uri.scheme != 'http') {
      return 'The address must use HTTPS or HTTP.';
    }

    if (uri.scheme == 'http' && !hostIsPrivate(uri.host)) {
      return 'Public servers must use HTTPS.';
    }

    if (uri.path != '/api') {
      return 'The server address must end with /api.';
    }

    return null;
  }

  // ============================================================
  // API ROUTES
  // ============================================================

  /// Version prefix used by the Laravel API.
  static const String apiPrefix = '/v1';

  /// Builds a versioned API URL.
  ///
  /// Example:
  /// ApiConfig.url('/auth/login')
  /// -> https://your-host/api/v1/auth/login
  static String url(String path) {
    final String suffix = path.startsWith('/') ? path : '/$path';

    return '$baseUrl$apiPrefix$suffix';
  }

  /// Health endpoint relative to the /api base URL.
  ///
  /// Canonical versioned probe: `<base>/api/v1/health` (e.g.
  /// `https://host/api` + `/v1/health`). Laravel also answers the legacy
  /// unversioned alias `/api/health`, but the app probes the versioned route
  /// so the liveness check exercises the same `/api/v1` prefix every other
  /// call uses. [_normalize] strips a user-entered `/api/v1` suffix back to
  /// `/api`, so this never builds a doubled `/api/v1/v1/health` URL.
  static const String healthPath = '/v1/health';

  /// Fully resolved server health URL.
  static Uri get healthUri => Uri.parse('$baseUrl$healthPath');

  // ============================================================
  // NETWORK TIMEOUTS
  // ============================================================

  /// Maximum time to establish a connection.
  static const Duration connectTimeout = Duration(seconds: 15);

  /// Maximum time to receive a server response.
  static const Duration receiveTimeout = Duration(seconds: 20);

  /// Maximum total request duration.
  static const Duration requestTimeout = Duration(seconds: 35);

  // ============================================================
  // CONNECTION STATUS
  // ============================================================

  /// Whether the current API address uses HTTPS.
  static bool get isSecure => baseUrl.startsWith('https://');

  /// Whether the API uses a public HTTPS address.
  static bool get isPublicHttps {
    if (!isSecure || isPrivateHost) {
      return false;
    }

    final String? host = Uri.tryParse(baseUrl)?.host;

    if (host == null || host.isEmpty) {
      return false;
    }

    return host != 'localhost' && host != '127.0.0.1';
  }

  /// Whether the API requires the same local network.
  static bool get requiresSameNetwork => !isPublicHttps;

  /// Whether the configured server is on a private network.
  static bool get isPrivateHost {
    final String? host = Uri.tryParse(baseUrl)?.host;

    if (host == null || host.isEmpty) {
      return false;
    }

    return hostIsPrivate(host);
  }

  /// Checks whether a host is local or private.
  static bool hostIsPrivate(String host) {
    if (host.isEmpty || host == 'localhost') {
      return true;
    }

    if (host.startsWith('10.') ||
        host.startsWith('192.168.') ||
        host.startsWith('127.') ||
        host.startsWith('169.254.')) {
      return true;
    }

    if (host.startsWith('172.')) {
      final List<String> parts = host.split('.');

      if (parts.length == 4) {
        final int? second = int.tryParse(parts[1]);

        if (second != null && second >= 16 && second <= 31) {
          return true;
        }
      }
    }

    if (host.endsWith('.local')) {
      return true;
    }

    return false;
  }

  /// Hostname displayed in the app's connection status.
  static String get displayHost {
    final String? host = Uri.tryParse(baseUrl)?.host;

    return (host == null || host.isEmpty) ? baseUrl : host;
  }
}
