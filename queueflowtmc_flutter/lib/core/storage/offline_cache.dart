/// ============================================================
/// QUEUEFLOW TMC — OFFLINE CACHE
///
/// A small last-known-good store for the payloads the app would otherwise have
/// to fetch again to render anything at all.
///
/// WHAT THIS IS FOR
///
/// A student who opens the app on the way to the registrar, in a lift, or on a
/// bus should still see their name, their course, the announcements and their
/// existing ticket. Without a cache the alternative is a login form and an
/// empty dashboard - which reads as "the app is broken" rather than "you are
/// offline", and loses the one thing the reader most wants while they cannot
/// act anyway.
///
/// WHAT THIS IS NOT
///
/// This is NOT a second source of truth.
///
/// Nothing here is ever treated as authoritative. A cached value is only ever
/// read when the server could not be reached, it is always labelled as cached
/// by the screens that show it, and it is replaced wholesale the moment the
/// server answers. There is no merging, no reconciling and no offline write -
/// the queue lives on the server, and a ticket taken offline would be a ticket
/// the counter has never heard of.
///
/// WHY IT IS DELIBERATELY DUMB
///
/// One string key in SharedPreferences per payload. No schema, no migrations,
/// no versioning beyond a `_v` marker on each entry that invalidates itself on
/// a model change. A payload that cannot be decoded is discarded and treated as
/// absent: a cache that throws on a shape change would turn a cosmetic problem
/// into a crash on launch, which is a far worse outcome than a cold start.
///
/// Nothing is written on a background thread and nothing blocks a frame. Every
/// call here is async and swallows its own failures, because a cache is never
/// important enough to be the reason a screen did not open.
/// ============================================================
library;

import 'dart:convert';

import 'package:shared_preferences/shared_preferences.dart';

/// Cache keys.
///
/// Namespaced so they cannot collide with the session keys in `SessionStore`,
/// which live in the same preference store and are cleared on sign-out for
/// reasons that have nothing to do with caching.
class OfflineCacheKeys {
  OfflineCacheKeys._();

  /// The whole `GET /api/student/bootstrap` payload.
  static const String studentBootstrap = 'qf.cache.student_bootstrap';

  /// The student's tickets and counts as of the last successful read.
  static const String studentTickets = 'qf.cache.student_tickets';

  /// Schema marker, bumped when a cached model's `toMap` shape changes.
  ///
  /// A stale entry is not migrated or guessed at. It is thrown away, because a
  /// partially-understood payload rendered as a real-looking dashboard is worse
  /// than an honest empty one.
  static const String _version = 'v1';
}

class OfflineCache {
  OfflineCache._();

  static SharedPreferences? _prefs;

  /// Opens the store. Safe to call repeatedly.
  ///
  /// Failures are swallowed: an unavailable preference store means the app runs
  /// without a cache, which is a missing convenience rather than a fault.
  static Future<void> load() async {
    if (_prefs != null) {
      return;
    }

    try {
      _prefs = await SharedPreferences.getInstance();
    } catch (error) {
      _prefs = null;
    }
  }

  /// Reads a cached map, or null when there is nothing usable.
  ///
  /// Returns null - never throws - for a missing key, a decode failure, a
  /// version mismatch, or a stored value of the wrong shape. Every one of those
  /// means the same thing to a caller: there is no cache, fetch from the server.
  static Map<String, dynamic>? read(String key) {
    final SharedPreferences? prefs = _prefs;

    if (prefs == null) {
      return null;
    }

    final String? raw = prefs.getString('${key}_${OfflineCacheKeys._version}');

    if (raw == null || raw.isEmpty) {
      return null;
    }

    try {
      final Object? decoded = jsonDecode(raw);

      if (decoded is! Map) {
        return null;
      }

      return Map<String, dynamic>.from(decoded);
    } catch (error) {
      // A corrupt entry is removed rather than left to fail on every launch.
      unawaitedRemove(key);

      return null;
    }
  }

  /// Stores a payload for later. Failures are ignored, for the reason above.
  static Future<void> write(String key, Map<String, dynamic> value) async {
    final SharedPreferences? prefs = _prefs;

    if (prefs == null) {
      return;
    }

    try {
      await prefs.setString('${key}_${OfflineCacheKeys._version}', jsonEncode(value));
    } catch (error) {
      // Nothing to do. The cache is an optimisation.
    }
  }

  /// Drops one entry.
  static Future<void> remove(String key) async {
    final SharedPreferences? prefs = _prefs;

    if (prefs == null) {
      return;
    }

    try {
      await prefs.remove('${key}_${OfflineCacheKeys._version}');
    } catch (error) {
      // Nothing to do.
    }
  }

  /// Drops every cached payload.
  ///
  /// Called on sign-out alongside [SessionStore.clear]. Cache entries are
  /// scoped to an account - a shared phone must not show the previous user's
  /// announcements to the next one - so they cannot outlive the session that
  /// fetched them.
  static Future<void> clear() async {
    await remove(OfflineCacheKeys.studentBootstrap);
    await remove(OfflineCacheKeys.studentTickets);
  }

  /// Fire-and-forget removal from a synchronous context.
  ///
  /// [read] is synchronous because it runs inside a catch block that must not
  /// itself throw; [remove] is async because writing to preferences is. This
  /// bridges the two without making [read] await.
  static void unawaitedRemove(String key) {
    // ignore: unawaited_futures
    remove(key);
  }
}