/// ============================================================
/// QUEUEFLOW TMC — CONNECTION UI
///
/// The three pieces of connection feedback the app needs, built once here so
/// no screen grows its own:
///
///   ConnectionStatusPill  a compact, always-visible indicator
///   ConnectionBanner      the full explanation, with a Retry button
///   ConnectionGuard       wraps content that needs the server
///
/// These deliberately reuse the existing visual language - [AppColors], the
/// frosted [GlassCard] panels, the pulsing [BreathingDot] used elsewhere for
/// "live" state - rather than introducing a parallel set of styles. A status
/// indicator that looked like it came from a different app would be a design
/// regression, not a feature.
///
/// REACTIVITY, NOT POLLING
///
/// Every widget here listens to [ConnectivityService] rather than polling it.
/// The service already knows when the radio changes and when a probe settles,
/// so a widget that re-queried on a timer would be doing the same work twice
/// and would still be a frame late.
/// ============================================================
library;

import 'package:flutter/material.dart';

import '../core/app_colors.dart';
import '../core/glass.dart';
import '../core/motion.dart';
import '../core/network/connection_messages.dart';
import '../core/network/connectivity_service.dart';

/// ============================================================
/// STATUS PILL
/// ============================================================

/// A compact "● Online" / "● Offline" indicator.
///
/// Shows [label] when given, otherwise a short default for the current state.
/// Defaults to collapsing itself while everything is fine - an indicator that
/// is permanently on screen saying "Online" trains people to stop reading it,
/// which is exactly when it would matter. Pass `alwaysVisible: false` (the
/// default) for that behaviour, or `true` on a screen where a tester is
/// deliberately checking the connection.
class ConnectionStatusPill extends StatelessWidget {
  const ConnectionStatusPill({
    super.key,
    this.service,
    this.alwaysVisible = false,
    this.onTap,
  });

  /// The service to observe. Defaults to the process-wide instance.
  final ConnectivityService? service;

  /// Whether to show while the connection is healthy.
  final bool alwaysVisible;

  /// Optional tap handler - typically [ConnectivityService.refresh].
  ///
  /// A tappable pill gives the indicator a purpose beyond decoration: it is
  /// the smallest possible Retry.
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final ConnectivityService connectivity = service ?? ConnectivityService.instance;

    return AnimatedBuilder(
      animation: connectivity,
      builder: (BuildContext context, _) {
        final ConnectionSnapshot snapshot = connectivity.snapshot;

        if (!alwaysVisible && !snapshot.needsAttention && !snapshot.isChecking) {
          return const SizedBox.shrink();
        }

        return _Pill(
          label: _labelOf(snapshot),
          color: _colorOf(snapshot.state),
          busy: snapshot.isChecking,
          onTap: onTap,
        );
      },
    );
  }

  /// The short form shown in the pill.
  ///
  /// Says what the link is, not merely that there is one: "Wi-Fi" and "Mobile
  /// Data" are both "online" as far as a radio is concerned, but they are
  /// completely different for reaching a LAN server, and the pill is often the
  /// only place that difference is visible before an action fails.
  static String _labelOf(ConnectionSnapshot snapshot) {
    if (snapshot.isChecking) {
      return 'Checking…';
    }

    return switch (snapshot.state) {
      AppConnectionState.online => switch (snapshot.link) {
        NetworkLink.wifi => 'Online · Wi-Fi',
        NetworkLink.mobile => 'Online · Mobile Data',
        NetworkLink.other => 'Online',
        NetworkLink.none => 'Online',
      },
      AppConnectionState.offline => 'Offline',
      AppConnectionState.serverUnavailable =>
        snapshot.link == NetworkLink.mobile ? 'Mobile Data · No Server' : 'Server Offline',
      AppConnectionState.checking => 'Checking…',
    };
  }

  /// Green only when QueueFlow actually answered.
  ///
  /// Amber for "you have internet but not the server" is the whole point of the
  /// colour choice: a green dot next to the word "Online" while nothing can be
  /// reached is the misleading state this system was built to avoid.
  static Color _colorOf(AppConnectionState state) => switch (state) {
        AppConnectionState.online => AppColors.success,
        AppConnectionState.checking => AppColors.info,
        AppConnectionState.offline => AppColors.danger,
        AppConnectionState.serverUnavailable => AppColors.warning,
      };
}

class _Pill extends StatelessWidget {
  const _Pill({
    required this.label,
    required this.color,
    required this.busy,
    this.onTap,
  });

  final String label;
  final Color color;
  final bool busy;
  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    return Semantics(
      label: ConnectionMessages.pillSemantics(label),
      button: onTap != null,
      child: GestureDetector(
        onTap: onTap,
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 12, vertical: 7),
          decoration: BoxDecoration(
            color: color.withValues(alpha: 0.14),
            borderRadius: BorderRadius.circular(999),
            border: Border.all(color: color.withValues(alpha: 0.45)),
          ),
          child: Row(
            mainAxisSize: MainAxisSize.min,
            children: <Widget>[
              // A spinner while checking, a breathing dot once settled. A
              // pulsing dot during a probe would claim a liveness that has not
              // been established yet.
              if (busy)
                SizedBox(
                  width: 9,
                  height: 9,
                  child: CircularProgressIndicator(
                    strokeWidth: 1.8,
                    color: color,
                  ),
                )
              else
                BreathingDot(color: color, size: 8),
              const SizedBox(width: 8),
              Text(
                label,
                style: TextStyle(
                  color: Colors.white.withValues(alpha: 0.92),
                  fontSize: 11.5,
                  fontWeight: FontWeight.w700,
                  letterSpacing: 0.2,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// ============================================================
/// BANNER
/// ============================================================

/// The full connection explanation, with a Retry button.
///
/// Renders nothing at all while the connection is healthy, so it can be dropped
/// into any screen's layout without reserving space for a message nobody
/// needs. Use [ConnectionStatusPill] for the always-there indicator and this
/// for the explanation.
class ConnectionBanner extends StatefulWidget {
  const ConnectionBanner({
    super.key,
    this.service,
    this.showWhenOnline = false,
  });

  /// The service to observe. Defaults to the process-wide instance.
  final ConnectivityService? service;

  /// Whether to stay on screen while connected.
  ///
  /// Off by default. Useful on a debug or settings screen, where confirming
  /// the app can see the server is the entire point of the screen.
  final bool showWhenOnline;

  @override
  State<ConnectionBanner> createState() => _ConnectionBannerState();
}

class _ConnectionBannerState extends State<ConnectionBanner> {
  /// Guards against a double-tap firing two probes.
  ///
  /// The service already collapses concurrent probes, but that means the
  /// second tap would appear to do nothing while the button spins - which
  /// reads as broken. Disabling on press gives the tap an honest response.
  bool _retrying = false;

  Future<void> _retry() async {
    if (_retrying) {
      return;
    }

    setState(() => _retrying = true);

    final ConnectivityService connectivity =
        widget.service ?? ConnectivityService.instance;

    // Awaited, not fired and forgotten: the button stays disabled until the
    // probe has actually settled, so "Retrying…" is a true statement rather
    // than a flicker. It never blocks a frame either way.
    await connectivity.refresh();

    if (mounted) {
      setState(() => _retrying = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final ConnectivityService connectivity =
        widget.service ?? ConnectivityService.instance;

    return AnimatedBuilder(
      animation: connectivity,
      builder: (BuildContext context, _) {
        final ConnectionSnapshot snapshot = connectivity.snapshot;

        // Stay visible while a manual retry is in flight: without this the
        // banner (and its Retrying spinner) vanishes the moment the probe
        // flips the state to checking, at the exact moment the user needs
        // proof the tap did something. Automatic background probes do not
        // force the banner visible — the pill already shows Checking… for
        // those — so a launch does not grow an extra card that pushes the
        // form off-screen.
        final bool visible = widget.showWhenOnline ||
            snapshot.needsAttention ||
            _retrying;

        if (!visible) {
          return const SizedBox.shrink();
        }

        final ConnectionMessage message = connectivity.message;

        final Color accent = switch (snapshot.state) {
          AppConnectionState.online => AppColors.success,
          AppConnectionState.checking => AppColors.info,
          AppConnectionState.offline => AppColors.danger,
          AppConnectionState.serverUnavailable => AppColors.warning,
        };

        return Padding(
          padding: const EdgeInsets.fromLTRB(18, 12, 18, 4),
          child: GlassCard(
            padding: const EdgeInsets.fromLTRB(16, 14, 14, 14),
            radius: 20,
            blur: 18,
            glow: accent,
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: <Widget>[
                Row(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  children: <Widget>[
                    Icon(_iconOf(snapshot.state), color: accent, size: 20),
                    const SizedBox(width: 11),
                    Expanded(
                      child: Column(
                        crossAxisAlignment: CrossAxisAlignment.start,
                        children: <Widget>[
                          Text(
                            message.title,
                            style: const TextStyle(
                              color: Colors.white,
                              fontSize: 14.5,
                              fontWeight: FontWeight.w800,
                            ),
                          ),
                          const SizedBox(height: 4),
                          Text(
                            message.body,
                            style: TextStyle(
                              color: Colors.white.withValues(alpha: 0.72),
                              fontSize: 12.5,
                              height: 1.5,
                            ),
                          ),
                        ],
                      ),
                    ),
                  ],
                ),
                if (snapshot.needsAttention || _retrying) ...<Widget>[
                  const SizedBox(height: 13),
                  Align(
                    alignment: Alignment.centerRight,
                    child: _RetryButton(
                      busy: _retrying || snapshot.isChecking,
                      onPressed: _retry,
                    ),
                  ),
                ],
              ],
            ),
          ),
        );
      },
    );
  }

  static IconData _iconOf(AppConnectionState state) => switch (state) {
        AppConnectionState.online => Icons.cloud_done_outlined,
        AppConnectionState.checking => Icons.sync_rounded,
        AppConnectionState.offline => Icons.wifi_off_rounded,
        AppConnectionState.serverUnavailable => Icons.cloud_off_outlined,
      };
}

class _RetryButton extends StatelessWidget {
  const _RetryButton({required this.busy, required this.onPressed});

  final bool busy;
  final VoidCallback onPressed;

  @override
  Widget build(BuildContext context) {
    return PressScale(
      onTap: busy ? null : onPressed,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 10),
        decoration: BoxDecoration(
          color: Colors.white.withValues(alpha: busy ? 0.06 : 0.13),
          borderRadius: BorderRadius.circular(999),
          border: Border.all(
            color: Colors.white.withValues(alpha: busy ? 0.14 : 0.30),
          ),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            if (busy)
              SizedBox(
                width: 13,
                height: 13,
                child: CircularProgressIndicator(
                  strokeWidth: 1.8,
                  color: Colors.white.withValues(alpha: 0.75),
                ),
              )
            else
              const Icon(Icons.refresh_rounded, size: 16, color: Colors.white),
            const SizedBox(width: 8),
            Text(
              busy ? ConnectionMessages.retryingLabel : ConnectionMessages.retryLabel,
              style: TextStyle(
                color: Colors.white.withValues(alpha: busy ? 0.65 : 0.95),
                fontSize: 12.5,
                fontWeight: FontWeight.w700,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

/// ============================================================
/// GUARD
/// ============================================================

/// A snackbar in the app's own voice, for a blocked action.
///
/// Separate from [ConnectionBanner] because the two answer different
/// questions. The banner is ambient and persistent - it explains the state of
/// the world. This is a response to something the reader just did, and it has
/// to appear immediately at the point of the button they pressed, or it reads
/// as unrelated.
class ConnectionSnack {
  ConnectionSnack._();

  /// Explains a refused action and offers Retry.
  ///
  /// [onRetry] is optional so a caller that has nothing useful to repeat -
  /// a form that has since been cleared, say - can show the reason alone
  /// rather than a button that would fail again.
  static void showBlocked(
    BuildContext context,
    ConnectivityService connectivity, {
    Future<void> Function()? onRetry,
  }) {
    final ScaffoldMessengerState messenger = ScaffoldMessenger.of(context);

    messenger.hideCurrentSnackBar();

    messenger.showSnackBar(
      SnackBar(
        behavior: SnackBarBehavior.floating,
        backgroundColor: AppColors.danger.withValues(alpha: 0.92),
        duration: const Duration(seconds: 5),
        content: Text(
          connectivity.message.body,
          style: const TextStyle(
            color: Colors.white,
            fontSize: 12.5,
            height: 1.45,
          ),
        ),
        action: onRetry == null
            ? null
            : SnackBarAction(
                label: 'Retry',
                textColor: Colors.white,
                onPressed: onRetry,
              ),
      ),
    );
  }
}

/// ============================================================
/// OFFLINE ACTION GUARD
/// ============================================================

/// A disabled, labelled button for an action the server must serve.
///
/// Used instead of simply hiding a button, because a button that vanishes when
/// the connection drops leaves the reader wondering whether they have broken
/// the app. This says plainly that the action needs a connection.
///
/// Purely presentational: it observes the state and dims the child, and does
/// not intercept the press. The refusal itself belongs in the action, which
/// calls [ConnectivityService.requireServer] so that a tap which slips through
/// anyway - a stale frame, a race with a reconnect - is still handled by one
/// piece of shared logic rather than two that can disagree.
///
/// Wraps the real button rather than replacing it, so the disabled control
/// keeps its own layout, icon and busy state - the screen does not grow a
/// second code path for the offline case.
class ServerActionButton extends StatelessWidget {
  const ServerActionButton({
    super.key,
    required this.child,
    this.service,
  });

  /// The real button. Kept as a child so this widget adds a reason, not a
  /// different-looking control.
  final Widget child;

  final ConnectivityService? service;

  @override
  Widget build(BuildContext context) {
    final ConnectivityService connectivity =
        service ?? ConnectivityService.instance;

    return AnimatedBuilder(
      animation: connectivity,
      builder: (BuildContext context, _) {
        final bool allowed = connectivity.canReachServer;

        if (allowed) {
          return child;
        }

        return Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          mainAxisSize: MainAxisSize.min,
          children: <Widget>[
            Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Row(
                children: <Widget>[
                  Icon(
                    Icons.cloud_off_outlined,
                    size: 14,
                    color: Colors.white.withValues(alpha: 0.55),
                  ),
                  const SizedBox(width: 7),
                  Expanded(
                    child: Text(
                      connectivity.message.body,
                      style: TextStyle(
                        color: Colors.white.withValues(alpha: 0.58),
                        fontSize: 11.5,
                        height: 1.4,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            Opacity(
              // Dimmed rather than removed, so the control keeps its place in
              // the layout and the reader can see what became unavailable.
              opacity: 0.45,
              child: IgnorePointer(child: child),
            ),
          ],
        );
      },
    );
  }
}