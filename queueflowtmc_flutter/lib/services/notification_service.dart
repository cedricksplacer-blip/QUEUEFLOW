import 'dart:async';

import 'package:flutter/foundation.dart';
import 'package:flutter/widgets.dart';
import 'package:flutter_local_notifications/flutter_local_notifications.dart';
import 'package:shared_preferences/shared_preferences.dart';

import '../repositories/auth_repository.dart';
import 'notification_events.dart';
import 'notification_router.dart';

/// ============================================================
/// QUEUEFLOW TMC — SYSTEM NOTIFICATION SERVICE
///
/// Makes every notification the campus is entitled to send audible at the
/// system level — in the foreground, in the background, and (best-effort)
/// after the app has been killed — without ever announcing the same event
/// twice or overruling what the user and the registrar decided.
///
/// WHY THERE IS NO PUSH CHANNEL
///
/// This build has no Firebase/FCM project behind it, so a notification cannot
/// be delivered by the server while the app is terminated. What this service
/// does instead: it owns its own polling tick (see [_tick]) so events are
/// detected while the app is alive in any tab or in the background, and it
/// catches up on anything missed within the recency window the moment the app
/// is next opened or resumed. True terminated-app delivery would require
/// adding FCM, which needs Firebase and Apple credentials this repository
/// does not have — see the "Terminated state" note below.
///
/// SOUND, PER PLATFORM
///
/// Android plays the bundled `res/raw/queueflow_chime` chime through the
/// notification channel (created once per [_channelRevision]; Android binds
/// sound to the channel at creation, so a sound change ships as a new
/// channel id). iPhone plays the system default notification sound: iOS only
/// accepts short audio files packaged in the app bundle, and this repository
/// ships no iOS bundle sound yet, so the default — always valid under Apple's
/// requirements — is used instead of a file that might be missing. In both
/// cases the channel/settings sound obeys the system volume, silent mode and
/// Do Not Disturb, and the in-app sound toggle gates [playSound] per display.
///
/// TERMINATED STATE, HONESTLY
///
/// A tap on a notification always opens the app and routes to the right
/// screen (see [NotificationRouter], including cold starts). But event
/// *detection* needs the app alive: Dart timers do not run once the OS
/// suspends or kills the process. Nothing here pretends otherwise.
///
/// WHAT IS ANNOUNCED, AND UNDER WHOSE CONTROL
///
/// The event list comes from [buildNotificationEvents] — the same rows as the
/// in-app feed plus the two settings-screen promises the feed does not carry.
/// Three gates protect the user from noise: a persisted seen-set (one event,
/// one sound, ever), a recency window (no stale wakes), and the campus
/// toggles in [SystemSettings]. Android's own controls sit above all of it:
/// the service checks [AndroidFlutterLocalNotificationsPlugin
/// .areNotificationsEnabled] before every display, never re-requests a
/// permission the user has already answered, and never touches a channel the
/// user has edited in system settings.
///
/// THE CHANNEL IS CREATED ONCE, THEN LEFT ALONE
///
/// Android binds sound, vibration and importance to the channel at creation
/// time and silently ignores later attempts to change them — so the channel
/// is created exactly once (tracked by a revision pref) and only recreated
/// if a future app version changes how it sounds, in which case the new
/// configuration gets a new id and the stale one is deleted. Re-creating the
/// channel on every launch would reset the user's mute decisions, so it never
/// happens.
///
/// ALL LOGS CARRY [_logTag] so a device log capture can be filtered to this
/// feature alone: channel state, permission outcomes, app state changes and
/// every announcement decision.
/// ============================================================
class NotificationService with WidgetsBindingObserver {
  NotificationService._();

  static final NotificationService instance = NotificationService._();

  static const String _logTag = '[QueueFlow Notification]';

  static const String _baseChannelId = 'queueflow_notifications';
  static const String _channelName = 'QueueFlow TMC Notifications';
  static const String _channelDescription =
      'Queue numbers, now-serving alerts and campus announcements from '
      'TMC College.';

  /// Bumped only when a sound-, vibration- or importance-affecting property
  /// of the channel changes. Sound lives on the channel forever on Android
  /// 8+, so a fix to how notifications sound must ship as a NEW channel id —
  /// bumping this constant is the migration mechanism: the old id is deleted,
  /// the new one is created, and users keep whatever choices they made on the
  /// old channel only insofar as Android re-prompts (it treats the new
  /// channel as fresh).
  ///
  /// Revision history: 1 = default system sound; 2 = bundled
  /// `res/raw/queueflow_chime` chime.
  static const int _channelRevision = 2;

  /// Raw resource name (no extension) of the bundled Android chime.
  static const String _soundResource = 'queueflow_chime';

  static const String _prefChannelRevision = 'notification_channel_revision';
  static const String _prefChannelId = 'notification_channel_id';
  static const String _prefPermissionRequested =
      'notification_permission_requested';
  static const String _prefIosPermissionRequested =
      'notification_ios_permission_requested';
  static const String _prefSeenEvents = 'notification_seen_events';

  /// In-app sound toggle. Persisted; gates `playSound` on every display.
  /// Defaults to on. Independent of the OS-level mute/DND, which the channel
  /// and the iOS presentation options continue to obey regardless.
  static const String _prefSoundEnabled = 'notification_sound_enabled';

  static const Duration _foregroundInterval = Duration(seconds: 10);
  static const Duration _backgroundInterval = Duration(seconds: 15);
  static const Duration _eventMaxAge = Duration(hours: 2);
  static const int _maxSeenKeys = 1000;

  final FlutterLocalNotificationsPlugin _plugin =
      FlutterLocalNotificationsPlugin();
  final AuthRepository _auth = AuthRepository.instance;

  SharedPreferences? _prefs;
  final Set<String> _seen = <String>{};
  String _channelId = _baseChannelId;
  bool _ready = false;
  bool _evaluating = false;
  bool _reEvaluate = false;
  Timer? _timer;
  Duration? _timerInterval;
  AppLifecycleState _lifecycle = AppLifecycleState.resumed;

  bool get _isForeground => _lifecycle == AppLifecycleState.resumed;

  AndroidFlutterLocalNotificationsPlugin? get _android =>
      _plugin.resolvePlatformSpecificImplementation<
        AndroidFlutterLocalNotificationsPlugin
      >();

  IOSFlutterLocalNotificationsPlugin? get _ios =>
      _plugin.resolvePlatformSpecificImplementation<
        IOSFlutterLocalNotificationsPlugin
      >();

  static void _log(String message) => debugPrint('$_logTag $message');

  /// Whether event displays should play a sound. Reads the persisted in-app
  /// toggle (default on); the OS-level silent mode / Do Not Disturb applies
  /// on top regardless of this value.
  Future<bool> soundEnabled() async {
    try {
      final SharedPreferences prefs =
          _prefs ?? await SharedPreferences.getInstance();
      return prefs.getBool(_prefSoundEnabled) ?? true;
    } catch (error) {
      _log('sound preference unreadable; defaulting to on: $error');
      return true;
    }
  }

  /// Persists the in-app sound toggle. Takes effect on the next display —
  /// Android channel sound itself is immutable after creation, so the toggle
  /// gates the per-display `playSound` flag instead of recreating anything.
  Future<void> setSoundEnabled(bool enabled) async {
    try {
      final SharedPreferences prefs =
          _prefs ?? await SharedPreferences.getInstance();
      await prefs.setBool(_prefSoundEnabled, enabled);
      _log('sound ${enabled ? "enabled" : "muted"} by user');
    } catch (error) {
      _log('sound preference not saved: $error');
    }
  }

  /// Shows a sample notification with sound so the user can hear what queue
  /// alerts sound like. Always plays the sound — demonstrating it is the
  /// entire point, even if the user muted event sounds.
  Future<void> testSound() async {
    if (!_ready) {
      _log('test sound ignored: service not ready');
      return;
    }

    if (!await _notificationsEnabled()) {
      _log('test sound withheld: notifications switched off at OS level');
      return;
    }

    try {
      await _plugin.show(
        id: 0x51554646 & 0x7FFFFFFF,
        title: 'QueueFlow TMC',
        body: 'This is how your queue alerts will sound.',
        notificationDetails: NotificationDetails(
          android: AndroidNotificationDetails(
            _channelId,
            _channelName,
            channelDescription: _channelDescription,
            icon: 'ic_qf_notification',
            importance: Importance.high,
            priority: Priority.high,
            playSound: true,
            sound: const RawResourceAndroidNotificationSound(_soundResource),
            enableVibration: true,
            ticker: 'QueueFlow TMC',
          ),
          iOS: const DarwinNotificationDetails(
            presentAlert: true,
            presentBadge: true,
            presentSound: true,
          ),
        ),
      );
      _log('test sound displayed');
    } catch (error) {
      _log('test sound failed: $error');
    }
  }

  // ==========================================================
  // STARTUP
  // ==========================================================

  /// Brings the service up, once, before the first frame.
  ///
  /// Every failure is swallowed and logged: a device that cannot show
  /// notifications must still open the app, and screens keep working from
  /// the in-app feed either way.
  Future<void> initialize() async {
    if (kIsWeb) {
      _log('skipped: the web build has no system notification channel');
      return;
    }

    final bool isAndroid =
        defaultTargetPlatform == TargetPlatform.android;
    final bool isIOS = defaultTargetPlatform == TargetPlatform.iOS;

    if (!isAndroid && !isIOS) {
      _log('skipped: system notifications are wired for Android and iOS only');
      return;
    }

    try {
      _prefs = await SharedPreferences.getInstance();

      _seen
        ..clear()
        ..addAll(_prefs!.getStringList(_prefSeenEvents) ?? const <String>[]);

      if (isAndroid) {
        _channelId = await _prepareChannel();
      }

      await _plugin.initialize(
        settings: const InitializationSettings(
          android: AndroidInitializationSettings('ic_qf_notification'),
          // Permissions are requested explicitly post-frame below, so none
          // are asked for here: initialize must never prompt by itself.
          iOS: DarwinInitializationSettings(
            requestAlertPermission: false,
            requestBadgePermission: false,
            requestSoundPermission: false,
          ),
        ),
        onDidReceiveNotificationResponse: _onNotificationTapped,
      );

      _auth.addListener(_onAuthChanged);
      WidgetsBinding.instance.addObserver(this);
      _ready = true;

      _log('initialized');
      if (isAndroid) {
        _log('Notification channel: $_channelId');
      }
      _log('App state: ${_isForeground ? "foreground" : "background"}');
      _syncTimer();

      // A tap that launched a terminated app arrives here rather than
      // through the tap callback, which needs a running shell to exist.
      // It is queued for the shell, which consumes it on first build.
      try {
        final NotificationAppLaunchDetails? launch = await _plugin
            .getNotificationAppLaunchDetails();
        if (launch?.didNotificationLaunchApp ?? false) {
          NotificationRouter.instance.handleTap(
            launch?.notificationResponse?.payload,
          );
          _log('cold-start notification tap queued');
        }
      } catch (error) {
        _log('launch-details check failed: $error');
      }

      // The permission dialog must never hold the first frame hostage, so it
      // runs after the app has painted and is not awaited.
      WidgetsBinding.instance.addPostFrameCallback((_) {
        unawaited(_requestPermissionOnce());
      });
    } catch (error) {
      _log('initialization failed; system notifications stay off: $error');
    }
  }

  @override
  void didChangeAppLifecycleState(AppLifecycleState state) {
    final bool wasForeground = _isForeground;
    _lifecycle = state;

    _log('App state: ${_isForeground ? "foreground" : "background"}');

    // Back from background: announce whatever the paused period produced
    // straight away instead of waiting for the first timer tick.
    if (_isForeground && !wasForeground) {
      unawaited(_evaluate());
    }

    _syncTimer();
  }

  // ==========================================================
  // DETECTION
  // ==========================================================

  /// Reacts to every repository change (sign-in, sign-out, refresh, ticket
  /// actions). [AuthRepository] notifies several times per refresh, so this
  /// only kicks the cheap checks — [_evaluate] itself is idempotent.
  void _onAuthChanged() {
    _syncTimer();
    unawaited(_evaluate());
  }

  /// The service's own read loop.
  ///
  /// Screen pollers deliberately pause when their tab is hidden or the app is
  /// backgrounded (see dashboard/my-ticket/queue-stats), which is exactly the
  /// window in which "your number is being served" still happens. This tick
  /// is the one read that runs regardless of what any screen is doing.
  Future<void> _tick() async {
    if (!_auth.isSignedIn) {
      _syncTimer();
      return;
    }

    try {
      await _auth.refresh();

      if (_auth.activeTicket != null) {
        await _auth.activeProgress();
      }
    } catch (error) {
      // refresh/activeProgress record their own failures; this only guards
      // against a programming error escaping into the timer.
      _log('background refresh failed: $error');
    }

    await _evaluate();
  }

  /// Starts, restarts or stops the tick so it always matches the current
  /// session and lifecycle state. Foreground polls tighter (10s) than
  /// background (15s) — background polling is the app's substitute for push
  /// while the OS still allows it, and intervals beyond ~30s start feeling
  /// like a different product.
  void _syncTimer() {
    if (!_ready) {
      return;
    }

    if (!_auth.isSignedIn) {
      if (_timer != null) {
        _timer!.cancel();
        _timer = null;
        _timerInterval = null;
        _log('no student session: background polling stopped');
      }
      return;
    }

    final Duration interval = _isForeground
        ? _foregroundInterval
        : _backgroundInterval;

    if (_timer != null && _timerInterval == interval) {
      return;
    }

    _timer?.cancel();
    _timer = Timer.periodic(interval, (_) => unawaited(_tick()));
    _timerInterval = interval;

    _log(
      'polling every ${interval.inSeconds}s '
      '(${_isForeground ? "foreground" : "background"})',
    );
  }

  // ==========================================================
  // ANNOUNCEMENTS
  // ==========================================================

  /// Runs one announcement pass, serialized: repository notifications and
  /// timer ticks can overlap, and two concurrent passes could both read the
  /// same key as unseen and play the sound twice. A request arriving during
  /// a pass does not start a second one — it queues a follow-up pass instead.
  Future<void> _evaluate() async {
    if (!_ready) {
      return;
    }

    if (_evaluating) {
      _reEvaluate = true;
      return;
    }

    _evaluating = true;
    try {
      do {
        _reEvaluate = false;
        await _evaluateOnce();
      } while (_reEvaluate);
    } finally {
      _evaluating = false;
    }
  }

  Future<void> _evaluateOnce() async {
    if (!_auth.isSignedIn) {
      return;
    }

    final NotificationEventBatch batch = buildNotificationEvents(
      announcements: _auth.announcements,
      tickets: _auth.tickets,
      settings: _auth.settings,
      progress: _auth.lastProgress,
      seenKeys: _seen,
      keyPrefix: 'u${_auth.user?.id ?? 0}:',
      maxAge: _eventMaxAge,
    );

    // Record every key the payload produced — announced or not, old or new.
    // The seen-set is the deduplication contract: from here on, this event
    // can never produce another sound on this account.
    bool changed = false;
    for (final String key in batch.allKeys) {
      if (_seen.add(key)) {
        changed = true;
      }
    }

    if (changed) {
      _pruneSeen();
      await _prefs?.setStringList(_prefSeenEvents, _seen.toList());
    }

    if (batch.toAnnounce.isEmpty) {
      return;
    }

    if (!await _notificationsEnabled()) {
      _log(
        '${batch.toAnnounce.length} new event(s) withheld: notifications '
        'are switched off in Android settings',
      );
      return;
    }

    for (final NotificationEvent event in batch.toAnnounce) {
      _log('Notification received: ${event.key}');
      await _show(event);
    }
  }

  Future<void> _show(NotificationEvent event) async {
    final bool withSound = await soundEnabled();

    try {
      await _plugin.show(
        id: _stableId(event.key),
        title: event.title,
        body: event.body,
        payload: event.key,
        notificationDetails: NotificationDetails(
          android: AndroidNotificationDetails(
            _channelId,
            _channelName,
            channelDescription: _channelDescription,
            icon: 'ic_qf_notification',
            importance: Importance.high,
            priority: Priority.high,
            playSound: withSound,
            sound: const RawResourceAndroidNotificationSound(_soundResource),
            enableVibration: true,
            when: event.when?.millisecondsSinceEpoch,
            ticker: 'QueueFlow TMC',
            styleInformation: BigTextStyleInformation(
              event.body,
              contentTitle: event.title,
            ),
          ),
          // iOS uses the system default sound: this repository ships no
          // iOS bundle sound file, and the default is always valid under
          // Apple's requirements. Foreground presentation (banner + sound)
          // is handled by the plugin's notification-center delegate.
          iOS: DarwinNotificationDetails(
            presentAlert: true,
            presentBadge: true,
            presentSound: withSound,
          ),
        ),
      );

      _log('Local notification displayed: ${event.key}');
    } catch (error) {
      _log('failed to display ${event.key}: $error');
    }
  }

  /// Drops the oldest keys once the set outgrows its cap.
  ///
  /// Insertion-ordered, so the pruned keys are the least recently seen —
  /// anything old enough to be pruned sits far outside the recency window
  /// and cannot be announced as news again.
  void _pruneSeen() {
    if (_seen.length <= _maxSeenKeys) {
      return;
    }

    final List<String> keys = _seen.toList();
    _seen
      ..clear()
      ..addAll(keys.sublist(keys.length - _maxSeenKeys));
  }

  void _onNotificationTapped(NotificationResponse response) {
    _log('notification tapped: ${response.payload}');
    // The shell consumes this and pushes the ticket or announcements
    // screen. Queued rather than navigated here: this service owns no
    // BuildContext, and a tap can arrive before the shell is built.
    NotificationRouter.instance.handleTap(response.payload);
  }

  // ==========================================================
  // ANDROID SETUP
  // ==========================================================

  /// Creates the channel exactly once per configuration revision and then
  /// leaves it alone, so Android keeps honouring whatever the user chose for
  /// it (sound on/off, importance) instead of resetting those decisions on
  /// every launch. Listing the channels afterwards is diagnostics only — it
  /// proves what Android actually stored, which is the only place the
  /// "Sound: enabled" promise can be verified.
  Future<String> _prepareChannel() async {
    final AndroidFlutterLocalNotificationsPlugin? android = _android;
    final SharedPreferences prefs = _prefs!;

    final String desiredId = _channelRevision <= 1
        ? _baseChannelId
        : '${_baseChannelId}_v$_channelRevision';
    final int storedRevision = prefs.getInt(_prefChannelRevision) ?? 0;
    final String? storedId = prefs.getString(_prefChannelId);

    if (storedRevision != _channelRevision) {
      if (storedId != null && storedId != desiredId) {
        await android?.deleteNotificationChannel(channelId: storedId);
        _log('retired superseded notification channel: $storedId');
      }

      await android?.createNotificationChannel(
        AndroidNotificationChannel(
          desiredId,
          _channelName,
          description: _channelDescription,
          importance: Importance.high,
          playSound: true,
          sound: const RawResourceAndroidNotificationSound(_soundResource),
          enableVibration: true,
        ),
      );

      await prefs.setInt(_prefChannelRevision, _channelRevision);
      await prefs.setString(_prefChannelId, desiredId);
      _log('created notification channel: $desiredId');
    } else {
      _log(
        'Notification channel: $desiredId (already configured; Android '
        'settings kept as-is)',
      );
    }

    try {
      final List<AndroidNotificationChannel>? channels = await android
          ?.getNotificationChannels();

      for (final AndroidNotificationChannel channel
          in channels ?? const <AndroidNotificationChannel>[]) {
        _log(
          'channel present: ${channel.id} | importance: ${channel.importance} '
          '| sound: ${channel.playSound ? "enabled" : "disabled"} '
          '| vibration: ${channel.enableVibration ? "enabled" : "disabled"}',
        );
      }
    } catch (error) {
      _log('channel diagnostics unavailable: $error');
    }

    return desiredId;
  }

  /// Asks for notification permission once per install, per platform.
  ///
  /// Once is the whole contract: if the user has already answered — either
  /// way — a repeat request is useless on Android (auto-dismissed) and
  /// forbidden-feeling on iOS (the system ignores re-requests), so from then
  /// on the app observes the setting instead. iOS asks for alert, badge and
  /// sound together: a badge without a banner, or a banner without a sound,
  /// would each be a half-working notification feature.
  Future<void> _requestPermissionOnce() async {
    if (defaultTargetPlatform == TargetPlatform.iOS) {
      await _requestIosPermissionOnce();
      return;
    }

    final AndroidFlutterLocalNotificationsPlugin? android = _android;

    if (android == null) {
      return;
    }

    try {
      final bool enabled = await android.areNotificationsEnabled() ?? false;
      if (enabled) {
        _log('notifications already enabled');
        return;
      }

      if (_prefs?.getBool(_prefPermissionRequested) ?? false) {
        _log(
          'permission already asked and declined; not asking again — the '
          'user can enable it in Android settings',
        );
        return;
      }

      final bool granted =
          await android.requestNotificationsPermission() ?? false;

      // Recorded after the prompt completed: a process that dies mid-dialog
      // has left the question unanswered, and it should be asked again.
      await _prefs?.setBool(_prefPermissionRequested, true);

      _log('permission request: ${granted ? "granted" : "denied"}');
    } catch (error) {
      _log('permission handling failed: $error');
    }
  }

  Future<void> _requestIosPermissionOnce() async {
    final IOSFlutterLocalNotificationsPlugin? ios = _ios;

    if (ios == null) {
      return;
    }

    try {
      if (_prefs?.getBool(_prefIosPermissionRequested) ?? false) {
        _log('iOS permission already answered; not asking again');
        return;
      }

      final bool granted =
          await ios.requestPermissions(
            alert: true,
            badge: true,
            sound: true,
          ) ??
          false;

      await _prefs?.setBool(_prefIosPermissionRequested, true);

      _log('iOS permission request: ${granted ? "granted" : "denied"}');
    } catch (error) {
      _log('iOS permission handling failed: $error');
    }
  }

  Future<bool> _notificationsEnabled() async {
    // The iOS plugin exposes no "are notifications enabled" query; a denied
    // notification is dropped silently by the system, so the display is
    // always attempted and its absence is the signal.
    if (defaultTargetPlatform == TargetPlatform.iOS) {
      return true;
    }

    try {
      return await _android?.areNotificationsEnabled() ?? false;
    } catch (error) {
      // The check failed, not the permission: attempt the display anyway and
      // let show() report a real block if there is one.
      _log('notification-enabled check failed: $error');
      return true;
    }
  }

  /// Deterministic 31-bit id for an event key.
  ///
  /// Dart does not promise String.hashCode stability across runs, and the id
  /// decides whether a re-announcement replaces a previous notification or
  /// stacks beside it — so it is computed rather than inherited. Positive and
  /// fixed-width: what the Android side expects.
  static int _stableId(String key) {
    int hash = 0x811c9dc5;

    for (final int unit in key.codeUnits) {
      hash ^= unit;
      hash = (hash * 0x01000193) & 0xFFFFFFFF;
    }

    return hash & 0x7FFFFFFF;
  }
}
