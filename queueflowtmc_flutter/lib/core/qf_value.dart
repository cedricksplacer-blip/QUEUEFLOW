import 'dart:convert';

/// ============================================================
/// QUEUEFLOW TMC
/// VALUE COERCION HELPERS
///
/// The Laravel REST API returns JSON where some columns may be
/// absent, null, or stringly typed. These helpers normalise every
/// value so the models can use one set of parsing rules.
/// ============================================================

class QfValue {
  QfValue._();

  static int toInt(dynamic value, {int fallback = 0}) {
    if (value is int) {
      return value;
    }

    if (value is num) {
      return value.toInt();
    }

    return int.tryParse(value?.toString() ?? '') ?? fallback;
  }

  static int? toIntOrNull(dynamic value) => value == null ? null : toInt(value);

  static bool toBool(dynamic value, {bool fallback = false}) {
    if (value is bool) {
      return value;
    }

    if (value is num) {
      return value != 0;
    }

    final String text = value?.toString().toLowerCase() ?? '';

    if (text.isEmpty) {
      return fallback;
    }

    return text == '1' || text == 'true' || text == 'yes' || text == 'on';
  }

  static String toStr(dynamic value, {String fallback = ''}) =>
      value == null ? fallback : value.toString();

  /// Returns null for null/empty values so optional columns stay optional.
  static String? toStrOrNull(dynamic value) {
    if (value == null) {
      return null;
    }

    final String text = value.toString();

    return text.isEmpty ? null : text;
  }

  /// Parses ISO-8601 (API) and MySQL DATETIME strings alike.
  static DateTime? toDate(dynamic value) {
    if (value == null) {
      return null;
    }

    if (value is DateTime) {
      return value;
    }

    final String text = value.toString().trim();

    if (text.isEmpty || text.startsWith('0000-00-00')) {
      return null;
    }

    return DateTime.tryParse(text.replaceFirst(' ', 'T')) ??
        DateTime.tryParse(text);
  }

  /// The server sends local wall-clock time with no offset, so a naive
  /// string is interpreted as local time rather than UTC.
  static DateTime? toLocalDate(dynamic value) {
    final DateTime? parsed = toDate(value);

    if (parsed == null) {
      return null;
    }

    return parsed.isUtc ? parsed.toLocal() : parsed;
  }

  /// Decodes a JSON column (Laravel `json` cast) into a string list.
  static List<String> toStringList(dynamic value) {
    if (value == null) {
      return const <String>[];
    }

    if (value is List) {
      return value.map((dynamic e) => e.toString()).toList();
    }

    final String text = value.toString().trim();

    if (text.isEmpty) {
      return const <String>[];
    }

    if (text.startsWith('[')) {
      try {
        final dynamic decoded = jsonDecode(text);

        if (decoded is List) {
          return decoded.map((dynamic e) => e.toString()).toList();
        }
      } catch (_) {
        // Not valid JSON — treat the raw text as one entry.
      }
    }

    return <String>[text];
  }
}
