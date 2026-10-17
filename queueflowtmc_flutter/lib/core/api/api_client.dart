import 'dart:async';
import 'dart:convert';

import 'package:flutter/foundation.dart';
import 'package:http/http.dart' as http;

import '../app_config.dart';
import '../network/connection_messages.dart';
import 'api_config.dart';

/// ============================================================
/// QUEUEFLOW TMC — API CLIENT
///
/// Central HTTP client for the QueueFlow TMC API.
///
/// This client intentionally supports the existing QueueFlow services,
/// including:
/// - GET
/// - POST
/// - PUT
/// - PATCH
/// - DELETE
/// - multipart requests
/// - query parameters
/// - dynamic token providers
/// - injected HTTP clients
///
/// TWO THINGS IT DELIBERATELY DOES NOT DO
///
/// 1. It does not decide whether the app is "online". It reports what a single
///    call did, and [checkHealth] reports what the server did. The decision to
///    call the app online or offline belongs to ConnectivityService, which owns
///    both the radio state and this client's failures.
///
/// 2. It does not leak transport internals to the reader. A `SocketException`,
///    a `TimeoutException` or `package:http`'s own `ClientException` are all
///    turned into one [ApiException] carrying a sentence somebody can act on.
///    A student holding a phone in a queue line does not need
///    `Connection refused: 192.168.x.x:8000`, and a stack trace printed on
///    a screen is a support ticket nobody can act on.
///
/// Note on `dart:io`: `SocketException` is deliberately not caught by name.
/// `package:http` rethrows every native socket failure as a `ClientException`,
/// and its browser client throws the same type, so catching the one class
/// covers Android, web and desktop alike - and importing `dart:io` would break
/// the web build this client also serves.
/// ============================================================
class ApiClient {
  ApiClient({
    this.tokenProvider,
    http.Client? client,
  }) : client = client ?? http.Client();

  /// Laravel API base URL.
  static String get baseUrl =>
    AppConfig.apiRoot + AppConfig.apiPrefix;

  /// Optional function that supplies the current Bearer token.
  final String? Function()? tokenProvider;

  /// HTTP client.
  final http.Client client;

  /// Build the full API URI.
  ///
  /// [endpoint] is relative to the versioned prefix (`/auth/login`, not
  /// `/api/v1/auth/login`): a leading `/api`, `/v1` or `/api/v1` segment is
  /// stripped so a caller that passes an absolute path cannot produce a
  /// doubled `/api/api/v1/...` URL that 404s as a configuration problem.
  Uri buildUri(
      String endpoint, {
        Map<String, dynamic>? query,
      }) {
    String cleanEndpoint =
        endpoint.startsWith('/') ? endpoint : '/$endpoint';

    for (final String prefix in <String>['/api/v1', '/api', '/v1']) {
      if (cleanEndpoint == prefix ||
          cleanEndpoint.startsWith('$prefix/')) {
        cleanEndpoint = cleanEndpoint.substring(prefix.length);

        if (!cleanEndpoint.startsWith('/')) {
          cleanEndpoint = '/$cleanEndpoint';
        }

        break;
      }
    }

    final uri = Uri.parse('$baseUrl$cleanEndpoint');

    if (query == null || query.isEmpty) {
      return uri;
    }

    final queryParameters = <String, String>{};

    query.forEach((key, value) {
      if (value == null) {
        return;
      }

      if (value is List) {
        queryParameters[key] = value.join(',');
      } else {
        queryParameters[key] = value.toString();
      }
    });

    return uri.replace(
      queryParameters: queryParameters,
    );
  }

  /// Build common headers.
  Future<Map<String, String>> _headers({
    bool json = false,
  }) async {
    final headers = <String, String>{
      'Accept': 'application/json',
      // Required by the ngrok tunnel in front of the Laravel server: without
      // it, ngrok answers API calls with its browser interstitial page
      // instead of proxying to Laravel. Harmless for non-ngrok hosts, which
      // simply ignore an unknown header - so it is sent unconditionally
      // rather than branching on the configured host.
      'ngrok-skip-browser-warning': 'true',
    };

    if (json) {
      headers['Content-Type'] = 'application/json';
    }

    if (tokenProvider != null) {
      final token = tokenProvider!();

      if (token != null && token.isNotEmpty) {
        headers['Authorization'] = 'Bearer $token';
      }
    }

    return headers;
  }

  /// GET request.
  Future<Map<String, dynamic>> get(
      String endpoint, {
        Map<String, dynamic>? query,
      }) async {
    try {
      final response = await client.get(
        buildUri(
          endpoint,
          query: query,
        ),
        headers: await _headers(),
      ).timeout(AppConfig.requestTimeout);

      return _handleResponse(response);
    } catch (error) {
      throw _transportFailure(error);
    }
  }

  /// POST request.
  Future<Map<String, dynamic>> post(
      String endpoint, {
        Map<String, dynamic>? body,
      }) async {
    try {
      final response = await client.post(
        buildUri(endpoint),
        headers: await _headers(json: true),
        body: jsonEncode(
          body ?? <String, dynamic>{},
        ),
      ).timeout(AppConfig.requestTimeout);

      return _handleResponse(response);
    } catch (error) {
      throw _transportFailure(error);
    }
  }

  /// PUT request.
  Future<Map<String, dynamic>> put(
      String endpoint, {
        Map<String, dynamic>? body,
      }) async {
    try {
      final response = await client.put(
        buildUri(endpoint),
        headers: await _headers(json: true),
        body: jsonEncode(
          body ?? <String, dynamic>{},
        ),
      ).timeout(AppConfig.requestTimeout);

      return _handleResponse(response);
    } catch (error) {
      throw _transportFailure(error);
    }
  }

  /// PATCH request.
  Future<Map<String, dynamic>> patch(
      String endpoint, {
        Map<String, dynamic>? body,
      }) async {
    try {
      final response = await client.patch(
        buildUri(endpoint),
        headers: await _headers(json: true),
        body: jsonEncode(
          body ?? <String, dynamic>{},
        ),
      ).timeout(AppConfig.requestTimeout);

      return _handleResponse(response);
    } catch (error) {
      throw _transportFailure(error);
    }
  }

  /// DELETE request.
  Future<Map<String, dynamic>> delete(
      String endpoint, {
        Map<String, dynamic>? body,
      }) async {
    try {
      final response = await client.delete(
        buildUri(endpoint),
        headers: await _headers(json: body != null),
        body: body == null ? null : jsonEncode(body),
      ).timeout(AppConfig.requestTimeout);

      return _handleResponse(response);
    } catch (error) {
      throw _transportFailure(error);
    }
  }

  /// Multipart/form-data request.
  ///
  /// [fields] contains normal form fields.
  /// [files] contains file uploads where the key is the field name
  /// and the value is the file path.
  Future<Map<String, dynamic>> multipart(
      String endpoint, {
        Map<String, dynamic>? fields,
        String? fileField,
        String? filePath,
        String method = 'POST',
      }) async {
    try {
      final request = http.MultipartRequest(
        method.toUpperCase(),
        buildUri(endpoint),
      );

      request.headers.addAll(
        await _headers(),
      );

      if (fields != null) {
        for (final entry in fields.entries) {
          if (entry.value != null) {
            request.fields[entry.key] = entry.value.toString();
          }
        }
      }

      if (fileField != null &&
          fileField.isNotEmpty &&
          filePath != null &&
          filePath.isNotEmpty) {
        request.files.add(
          await http.MultipartFile.fromPath(
            fileField,
            filePath,
          ),
        );
      }

      final streamedResponse = await client.send(request).timeout(AppConfig.requestTimeout);

      final response = await http.Response.fromStream(
        streamedResponse,
      );

      return _handleResponse(response);
    } catch (error) {
      throw _transportFailure(error);
    }
  }

  // ========================================================
  // HEALTH
  // ========================================================

  /// Asks the Laravel server whether it is actually serving.
  ///
  /// This is the second of the two questions the app asks about the network.
  /// The first - does this phone have a link at all - is answered by the
  /// operating system through connectivity_plus, and it is not enough: a phone
  /// on campus WiFi with `artisan serve` stopped has a link and no server, and
  /// telling that student to reconnect their WiFi would be wrong advice.
  ///
  /// So this is a real request against a real Laravel route
  /// (`HealthController`, canonical at `/api/v1/health` with a legacy
  /// unversioned alias at `/api/health`).
  /// Nothing here fabricates an "ok": the answer is only `true` for HTTP 200
  /// with the JSON health payload (`status: online`). Anything else is
  /// `false`, with the cause named in the log so Laravel route errors,
  /// tunnel errors and server errors can be told apart:
  ///
  ///   - 401/422: the server answered but refused; a connectivity probe, never
  ///     an authenticated call, so this is a server configuration problem, not
  ///     a network outage. Authenticated API failures keep their own status
  ///     and message via [_handleResponse] and are never converted to offline.
  ///   - 404: route or base-URL configuration problem (e.g. a doubled
  ///     `/api/api/...` prefix or a stale tunnel URL), not a dead server.
  ///   - 5xx: the server is reachable and broken.
  ///   - HTML/non-JSON 2xx (ngrok interstitial without the bypass header, or
  ///     any proxy/captive-portal page): not valid API JSON, so not online.
  ///   - Timeout / DNS / TLS / connection-refused: genuine network failures.
  ///
  /// Deliberately token-free, on the versioned `/api/v1/health` route. A probe
  /// that needed a bearer token could not report on the requests that fail
  /// before a token exists, which is exactly the case worth reporting. Probing
  /// the versioned route (rather than the legacy `/api/health` alias) exercises
  /// the same `/api/v1` prefix every other call uses.
  ///
  /// Uses the full request timeout rather than a short probe timeout: the
  /// answer decides what the whole app shows, and a late answer is better than
  /// a wrong "server offline" on a congested campus link. It is awaited as a
  /// future and never blocks a frame, so a slow probe shows a
  /// "Checking connection…" state rather than a frozen screen. SSL
  /// verification is never disabled: a TLS failure is reported as a network
  /// failure, not bypassed.
  Future<bool> checkHealth() async {
    final Stopwatch stopwatch = Stopwatch()..start();
    try {
      debugPrint('[QueueFlow] Checking server...');
      debugPrint('[QueueFlow] Base URL: ${ApiConfig.baseUrl}');
      debugPrint('[QueueFlow] Health URL: ${ApiConfig.healthUri}');
      final http.Response response = await client
          .get(
            ApiConfig.healthUri,
            headers: await _headers(),
          )
          .timeout(ApiConfig.requestTimeout);

      stopwatch.stop();
      debugPrint(
        '[QueueFlow] Response: ${response.statusCode} '
        'in ${stopwatch.elapsedMilliseconds}ms '
        '(${_bodySummary(response.body)})',
      );

      if (response.statusCode == 401 || response.statusCode == 422) {
        // The server IS up (it answered with a refusal). Never a network
        // outage: authenticated callers surface the server's own message via
        // ApiException instead. For the probe itself this is a configuration
        // problem, not offline, but it is still not ONLINE.
        debugPrint(
          '[QueueFlow] Server answered ${response.statusCode} '
          '(refusal, not a network outage)',
        );
        return false;
      }

      if (response.statusCode == 404) {
        debugPrint(
          '[QueueFlow] Route/config problem: health route not found. '
          'Check the base URL for a doubled prefix (e.g. /api/api) '
          'or a stale tunnel URL.',
        );
        return false;
      }

      if (response.statusCode >= 500) {
        debugPrint('[QueueFlow] Server error ${response.statusCode}');
        return false;
      }

      if (response.statusCode < 200 || response.statusCode >= 300) {
        debugPrint('[QueueFlow] Server returned non-2xx');
        return false;
      }

      // 2xx: still not trusted until it proves it is Laravel JSON and not an
      // ngrok interstitial / proxy / captive-portal HTML page with a 200.
      final String contentType =
          response.headers['content-type']?.toLowerCase() ?? '';
      final String trimmed = response.body.trim();

      if (trimmed.isEmpty) {
        debugPrint('[QueueFlow] Empty body: not a valid health response');
        return false;
      }

      if (trimmed.startsWith('<') || !contentType.contains('json')) {
        // HTML pages fail JSON parsing below anyway; naming them here keeps
        // the tunnel-interstitial case obvious in logs.
        debugPrint(
          '[QueueFlow] Non-JSON body (content-type: $contentType): '
          'not a valid API response',
        );
      }

      try {
        final dynamic decoded = jsonDecode(trimmed);

        if (decoded is Map) {
          final dynamic status = decoded['status'];

          if (status == 'online' || status == 'ok') {
            debugPrint('[QueueFlow] Server ONLINE');
            return true;
          }

          debugPrint(
            '[QueueFlow] Unexpected health status field: $status',
          );
          return false;
        }

        debugPrint('[QueueFlow] Health body is not a JSON object');
        return false;
      } catch (_) {
        debugPrint('[QueueFlow] Health body is not valid API JSON');
        return false;
      }
    } catch (error) {
      stopwatch.stop();
      debugPrint(
        '[QueueFlow] Connection failed '
        'after ${stopwatch.elapsedMilliseconds}ms',
      );
      debugPrint('[QueueFlow] Error type: ${error.runtimeType}');
      if (error is TimeoutException) {
        debugPrint('[QueueFlow] Timeout: server did not answer in time');
      } else {
        debugPrint('[QueueFlow] Target: ${ApiConfig.displayHost}:${_getPort()}');
      }
      // A probe reports, it does not raise. The caller gets `false` and turns
      // that into the right state and the right sentence; throwing here would
      // force every listener to wrap this call in a try/catch to do what a bool
      // already says.
      return false;
    }
  }

  /// One safe line describing a health body for the log.
  ///
  /// Names the `status` field when the body is the JSON object the health
  /// route sends, and says what the body is otherwise - never the body
  /// itself, which past this point is only ever diagnostics.
  String _bodySummary(String body) {
    final String trimmed = body.trim();

    if (trimmed.isEmpty) {
      return 'empty body';
    }

    try {
      final dynamic decoded = jsonDecode(trimmed);

      if (decoded is Map) {
        final dynamic status = decoded['status'];

        return status == null
            ? 'json object without status field'
            : 'status=$status';
      }

      return 'non-object json';
    } catch (_) {
      return 'non-json body (${trimmed.length} chars)';
    }
  }

  String _getPort() {
    final uri = Uri.tryParse(ApiConfig.baseUrl);
    return uri?.port.toString() ?? '8000';
  }

  // ========================================================
  // INTERNALS
  // ========================================================

  /// Turns a transport-level failure into one sentence a reader can act on.
  ///
  /// An [ApiException] is passed through untouched: a response that arrived and
  /// was refused is a different problem from one that never arrived, and the
  /// server's own wording beats anything invented here.
  ///
  /// Everything else is classified, because the cases below need genuinely
  /// different advice and a generic "unable to connect" sends people to check
  /// the wrong thing:
  ///
  ///   - [TimeoutException]      the request was sent and nothing came back
  ///   - [http.ClientException]  nothing was ever established (refused, DNS,
  ///                             no route to host)
  ///   - anything else           unexpected; still reported as unreachable
  ///
  /// The distinction between the first two is what lets the app say "the server
  /// took too long" instead of "there is no internet", which matters because
  /// the phone may well have a perfectly good connection to a campus access
  /// point that simply cannot route to the machine running Laravel.
  ApiException _transportFailure(Object error) {
    if (error is ApiException) {
      return error;
    }

    final ApiFailure failure;

    if (error is TimeoutException) {
      failure = ApiFailure.timeout;
    } else if (error is http.ClientException) {
      failure = ApiFailure.unreachable;
    } else if (error.toString().toLowerCase().contains('connection refused') ||
        error.toString().toLowerCase().contains('refused') ||
        error.toString().toLowerCase().contains('socketexception')) {
      failure = ApiFailure.unreachable;
    } else {
      failure = ApiFailure.unknown;
    }

    return ApiException(
      failure == ApiFailure.timeout
          ? 'Connection Timeout\n'
              'The QueueFlow TMC server could not be reached.'
          : ConnectionMessages.actionFailedBody,
      0,
      failure: failure,
      originalError: error,
    );
  }

  /// Process Laravel's API response.
  Map<String, dynamic> _handleResponse(
      http.Response response,
      ) {
    final statusCode = response.statusCode;

    dynamic decoded;

    if (response.body.trim().isEmpty) {
      // A successful call that returns no body at all is not a valid QueueFlow
      // response, and treating it as an empty object hands the caller a screen
      // with nothing in it instead of saying the server misbehaved. Refusing it
      // here is what keeps that from reaching a model that would then fail on a
      // missing field.
      if (statusCode >= 200 && statusCode < 300) {
        throw ApiException(
          'The QueueFlow TMC server sent an empty response, which could not '
          'be read. Please try again in a moment.',
          statusCode,
        );
      }

      decoded = <String, dynamic>{};
    } else {
      try {
        decoded = jsonDecode(response.body);
      } catch (error) {
        // A body that is not JSON at all is what a build pointed at the wrong
        // URL actually receives: the web server's own 404 page, or a login
        // screen from something that is not the API. Naming it as a
        // configuration problem is the difference between a support message
        // and a user hunting for a password problem they do not have.
        throw ApiException(
          statusCode == 404
              ? 'The QueueFlow TMC API was not found at the configured API '
                    'address. Check the server configuration.'
              : 'The server returned an invalid response.',
          statusCode,
          originalError: error,
        );
      }
    }

    if (decoded is! Map<String, dynamic>) {
      throw ApiException(
        'Unexpected response from the QueueFlow server.',
        statusCode,
      );
    }

    if (statusCode >= 200 && statusCode < 300) {
      return decoded;
    }

    final Map<String, List<String>> fieldErrors = _fieldErrorsOf(decoded);

    throw ApiException(
      // Laravel's summary is written for a developer ("... (and 1 more
      // error)"). The per-field sentence is the one a student can act on, so
      // when the server named exactly one field, that sentence is the message.
      // With several fields the summary is the more useful of the two, because
      // naming one of them would imply the others do not matter.
      fieldErrors.length == 1
          ? fieldErrors.values.first.first
          : decoded['message']?.toString() ?? _defaultErrorMessage(statusCode),
      statusCode,
      data: decoded,
      fieldErrors: fieldErrors,
    );
  }

  /// Pulls Laravel's `errors` bag into a map the screens can read.
  Map<String, List<String>> _fieldErrorsOf(Map<String, dynamic> decoded) {
    final Map<String, List<String>> fieldErrors = <String, List<String>>{};
    final dynamic errors = decoded['errors'];

    if (errors is Map) {
      errors.forEach((Object? key, Object? value) {
        if (value is List) {
          fieldErrors[key.toString()] = value
              .map((Object? item) => item.toString())
              .toList(growable: false);
        } else if (value != null) {
          fieldErrors[key.toString()] = <String>[value.toString()];
        }
      });
    }

    return fieldErrors;
  }

  String _defaultErrorMessage(
      int statusCode,
      ) {
    switch (statusCode) {
      case 400:
        return 'The request was invalid.';

      case 401:
        return 'Your session has expired. Please sign in again.';

      case 403:
        return 'You do not have permission to perform this action.';

      case 404:
        return 'The requested resource was not found.';

      case 409:
        return 'The request conflicts with the current queue state.';

      case 422:
        return 'Please check the information you entered.';

      case 429:
        return 'Too many requests. Please try again later.';

      case 500:
        return 'The QueueFlow server encountered an error.';

      case 502:
      case 503:
      case 504:
        return 'The QueueFlow server is temporarily unavailable.';

      default:
        return 'Request failed with status code $statusCode.';
    }
  }
}

/// Why a call never produced a response.
///
/// Distinct from an HTTP status code, which always means the server *did*
/// answer. This enum only ever appears on an [ApiException] with a
/// [ApiException.statusCode] of 0 - the "no answer at all" case - and exists
/// so callers can tell "the phone is in a dead spot" from "the campus server
/// is not running" from "something unexpected broke", which is what lets the
/// UI say something true instead of one vague sentence for all three.
enum ApiFailure {
  /// Nothing was ever established: connection refused, DNS failure, no route
  /// to host.
  ///
  /// On a phone this is the normal result of asking for a private LAN address
  /// over mobile data, which is not a bug to be worked around but a fact to be
  /// reported honestly.
  unreachable,

  /// The request was sent and the server did not answer in time.
  timeout,

  /// Anything else. Treated as unreachable for wording purposes, because
  /// "something unexpected broke" is not advice a reader can act on.
  unknown,
}

/// QueueFlow API exception.
class ApiException implements Exception {
  final String message;
  final int statusCode;
  final dynamic data;
  final Map<String, List<String>> fieldErrors;

  /// The underlying transport error, kept for logging only.
  ///
  /// Never shown. [message] is the sentence written for a reader; this is the
  /// `SocketException` or `ClientException` behind it, which belongs in a log
  /// a developer reads and nowhere a student has to look at it.
  final Object? originalError;

  /// Which transport failure this was, when [statusCode] is 0.
  ///
  /// Null for a response that arrived and was refused, where the status code
  /// already says everything.
  final ApiFailure? failure;

  const ApiException(
      this.message,
      this.statusCode, {
        this.data,
        this.fieldErrors = const {},
        this.originalError,
        this.failure,
      });

  bool get isUnauthorized => statusCode == 401;

  bool get isForbidden => statusCode == 403;

  bool get isValidationError => statusCode == 422;

  bool get isServerError => statusCode >= 500;

  bool get isNetworkError => statusCode == 0;

  /// Whether this exception means "the server could not be reached".
  ///
  /// True only when nothing answered. A 500 is a server that IS reachable and
  /// is broken, which is a different thing to tell somebody: the first is
  /// "come back later or check the network", the second is "the campus server
  /// has a bug and support has been notified".
  bool get isUnreachable => isNetworkError;

  /// Whether the server took too long to answer.
  bool get isTimeout => failure == ApiFailure.timeout;

  @override
  String toString() => message;
}
