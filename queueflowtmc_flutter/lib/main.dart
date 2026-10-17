import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import 'core/app_colors.dart';
import 'core/app_constants.dart';
import 'core/app_theme.dart';
import 'core/backdrop.dart';
import 'core/motion.dart';
import 'core/network/connectivity_service.dart';
import 'core/storage/endpoint_store.dart';
import 'core/storage/offline_cache.dart';
import 'models/app_user.dart';
import 'repositories/auth_repository.dart';
import 'screens/home_shell.dart';
import 'screens/login_screen.dart';
import 'services/notification_service.dart';
import 'services/session_store.dart';

/// ============================================================
/// QUEUEFLOW TMC
///
/// The student app. One gate, one shell: a persisted session restores into
/// [HomeShell], and anything else opens [LoginScreen].
///
/// STUDENT-ONLY, AND WHY THE OTHER ROLES STILL HAVE TO BE PARSED
///
/// This build serves students. A staff or administrator token must not land on
/// a student dashboard full of "you may not do this", so the gate refuses it
/// here with a plain sentence instead of letting it through. The roles are
/// still recognised rather than collapsed into "unknown" because the users
/// table stores all three, and an account that is refused with "no portal
/// access" is a far better outcome for somebody who mistyped their row than one
/// refused with "role not recognised".
///
/// CONNECTIVITY IS STARTED HERE, AND DELIBERATELY NOT WAITED ON
///
/// [ConnectivityService.initialize] reads the radio and subscribes to
/// changes - both quick - then kicks off the server probe without awaiting
/// it. Awaiting that probe would hold the first frame for up to the request
/// timeout every time the server is slow or down, which is the difference
/// between an app that opens and one that looks hung. Instead the splash is
/// shown, the state settles when it settles, and every screen renders
/// whatever the truth is at that moment - including "the server is not
/// answering", which is something the reader needs told rather than hidden
/// behind a spinner.
/// ============================================================

Future<void> main() async {
  WidgetsFlutterBinding.ensureInitialized();

  await SystemChrome.setPreferredOrientations(<DeviceOrientation>[
    DeviceOrientation.portraitUp,
    DeviceOrientation.portraitDown,
  ]);

  SystemChrome.setSystemUIOverlayStyle(
    const SystemUiOverlayStyle(
      statusBarColor: Colors.transparent,
      statusBarIconBrightness: Brightness.light,
      statusBarBrightness: Brightness.dark,
    ),
  );

  // Read the persisted account and token before the first frame, so the gate
  // below can answer "signed in or not" synchronously and never flashes the
  // login form over a session that already exists.
  await SessionStore.load();

  // Restore a runtime server address chosen in the app (About > Server
  // connection), if any. Must run before the connectivity probe below, so the
  // first health check already goes to the right server instead of probing
  // the compiled-in default and then switching.
  await EndpointStore.load();

  // Open the last-known-good payload store, so a launch with no connection can
  // still render the cached profile, announcements and existing ticket instead
  // of an empty dashboard. Awaited because it is a single local read and the
  // first frame depends on it; it touches no network.
  await OfflineCache.load();

  // Start watching the radio and probing the Laravel server. See the note above:
  // this returns as soon as the subscription exists, not when the probe
  // finishes, so startup cost is one platform-channel round trip.
  await ConnectivityService.instance.initialize();

  // Create the notification channel and attach the announcement listener
  // before the first frame, so the very first repository change — the one
  // carrying the restored tickets — is already being evaluated for system
  // notifications rather than missed. It is awaited because every step is
  // local (a preferences read and a platform-channel call), it never throws
  // (failures are logged and the service stays off), and the permission
  // dialog itself runs after the first frame rather than here.
  await NotificationService.instance.initialize();

  runApp(const QueueFlowApp());
}

class QueueFlowApp extends StatelessWidget {
  const QueueFlowApp({super.key});

  @override
  Widget build(BuildContext context) {
    return MaterialApp(
      title: AppConstants.appName,
      debugShowCheckedModeBanner: false,
      theme: AppTheme.dark(),
      darkTheme: AppTheme.dark(),
      themeMode: ThemeMode.dark,
      home: const AuthGate(),
    );
  }
}

/// Cross-fades between the splash and the login form or the student shell.
class AuthGate extends StatefulWidget {
  const AuthGate({super.key});

  @override
  State<AuthGate> createState() => _AuthGateState();
}

class _AuthGateState extends State<AuthGate> {
  final AuthRepository _auth = AuthRepository.instance;

  @override
  void initState() {
    super.initState();
    _auth.addListener(_onAuthChanged);
    _auth.restore();
  }

  @override
  void dispose() {
    _auth.removeListener(_onAuthChanged);
    super.dispose();
  }

  void _onAuthChanged() {
    if (mounted) {
      setState(() {});
    }
  }

  /// The shell this build ships, or null when the session cannot use it.
  ///
  /// [AuthRepository.isSignedIn] already rejects an unknown role, so anything
  /// reaching this point is a student — and only a student — by the time the
  /// shell is chosen.
  Widget? _shellFor(AppRole role) => switch (role) {
    AppRole.student => const HomeShell(key: ValueKey<String>('shell-student')),
    AppRole.staff ||
    AppRole.admin ||
    AppRole.unknown => null,
  };

  @override
  Widget build(BuildContext context) {
    final bool showSplash = _auth.status == AuthStatus.restoring;
    final Widget? shell = _auth.isSignedIn ? _shellFor(_auth.role) : null;

    final Widget next = showSplash
        ? const BrandSplash(key: ValueKey<String>('splash'))
        : shell ?? const LoginScreen(key: ValueKey<String>('login'));

    return AnimatedSwitcher(
      duration: const Duration(milliseconds: 620),
      switchInCurve: QfCurves.entrance,
      switchOutCurve: Curves.easeIn,
      transitionBuilder: (Widget child, Animation<double> animation) {
        return FadeTransition(
          opacity: animation,
          child: ScaleTransition(
            scale: Tween<double>(begin: 1.04, end: 1.0).animate(animation),
            child: child,
          ),
        );
      },
      child: next,
    );
  }
}

/// Brand splash: the mark floats in on the campus photograph.
class BrandSplash extends StatelessWidget {
  const BrandSplash({super.key});

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      backgroundColor: AppTheme.backdrop,
      body: CampusBackdrop(
        child: Center(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              RevealIn(
                duration: const Duration(milliseconds: 1100),
                curve: QfCurves.spring,
                scale: 0.5,
                offset: Offset.zero,
                child: const CampusLogo(size: 124),
              ),
              const SizedBox(height: 26),
              RevealIn(
                delay: const Duration(milliseconds: 320),
                child: GradientText(
                  text: AppConstants.appName,
                  colors: const <Color>[
                    Colors.white,
                    AppColors.sky,
                    Colors.white,
                  ],
                  style: const TextStyle(
                    fontSize: 24,
                    fontWeight: FontWeight.w800,
                    letterSpacing: 1.4,
                  ),
                ),
              ),
              const SizedBox(height: 10),
              RevealIn(
                delay: const Duration(milliseconds: 440),
                child: Text(
                  AppConstants.appTagline,
                  textAlign: TextAlign.center,
                  style: TextStyle(
                    color: Colors.white.withValues(alpha: 0.62),
                    fontSize: 12,
                    letterSpacing: 1.4,
                  ),
                ),
              ),
              const SizedBox(height: 40),
              // Indeterminate bar rather than a spinner, to match the theme.
              RevealIn(
                delay: const Duration(milliseconds: 560),
                child: SizedBox(
                  width: 130,
                  child: ClipRRect(
                    borderRadius: BorderRadius.circular(999),
                    child: LinearProgressIndicator(
                      minHeight: 3,
                      backgroundColor: Colors.white.withValues(alpha: 0.14),
                      valueColor: const AlwaysStoppedAnimation<Color>(
                        AppColors.accent,
                      ),
                    ),
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
