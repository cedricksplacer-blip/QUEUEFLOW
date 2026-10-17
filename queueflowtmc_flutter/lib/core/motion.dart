import 'dart:math' as math;

import 'package:flutter/material.dart';

/// ============================================================
/// QUEUEFLOW TMC — MOTION
///
/// Every animation in the app is hand-written on Flutter's own
/// AnimationController: no animation packages, so the APK stays
/// small and the curves stay consistent with each other.
/// ============================================================

/// Shared easing curves so timing feels the same everywhere.
class QfCurves {
  QfCurves._();

  /// Expo-out. The default for anything entering the screen.
  static const Curve entrance = Cubic(0.16, 1.0, 0.3, 1.0);

  /// Standard Material smooth.
  static const Curve smooth = Cubic(0.4, 0.0, 0.2, 1.0);

  /// Back-out. For playful pops such as the logo.
  static const Curve spring = Cubic(0.34, 1.56, 0.64, 1.0);

  /// Slow sine, used by the continuous ambient loops.
  static const Curve breathe = Curves.easeInOut;
}

/// ==========================================================
/// STAGGERED ENTRANCE
/// ==========================================================

/// Fades, slides and optionally scales [child] in once.
///
/// Pass an increasing [delay] to siblings to make a group cascade in
/// instead of appearing all at once.
class RevealIn extends StatefulWidget {
  const RevealIn({
    super.key,
    required this.child,
    this.delay = Duration.zero,
    this.duration = const Duration(milliseconds: 700),
    this.offset = const Offset(0, 0.16),
    this.scale = 1.0,
    this.curve = QfCurves.entrance,
  });

  final Widget child;
  final Duration delay;
  final Duration duration;

  /// Slide distance as a fraction of the child's size.
  final Offset offset;

  /// Final scale; below 1.0 the child grows in.
  final double scale;
  final Curve curve;

  @override
  State<RevealIn> createState() => _RevealInState();
}

class _RevealInState extends State<RevealIn>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: widget.delay + widget.duration,
  );

  /// The delay is expressed as a leading [Interval] rather than a real
  /// timer, so the whole reveal is driven by the ticker and stays
  /// testable with `pump`.
  late final Animation<double> _value = CurvedAnimation(
    parent: _controller,
    curve: widget.delay <= Duration.zero
        ? widget.curve
        : Interval(
            widget.delay.inMicroseconds / _controller.duration!.inMicroseconds,
            1.0,
            curve: widget.curve,
          ),
  );

  @override
  void initState() {
    super.initState();
    _controller.forward();
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _value,
      builder: (BuildContext context, Widget? child) {
        final double t = _value.value;

        return Opacity(
          opacity: t.clamp(0.0, 1.0),
          child: Transform.translate(
            offset: Offset(
              widget.offset.dx * (1 - t) * 120,
              widget.offset.dy * (1 - t) * 120,
            ),
            child: Transform.scale(
              scale: widget.scale + (1 - widget.scale) * t,
              child: child,
            ),
          ),
        );
      },
      child: widget.child,
    );
  }
}

/// ==========================================================
/// PRESS MICRO-INTERACTION
/// ==========================================================

/// Scales its child down while pressed and springs back on release.
class PressScale extends StatefulWidget {
  const PressScale({
    super.key,
    required this.child,
    this.onTap,
    this.pressedScale = 0.96,
    this.enabled = true,
  });

  final Widget child;
  final VoidCallback? onTap;
  final double pressedScale;
  final bool enabled;

  @override
  State<PressScale> createState() => _PressScaleState();
}

class _PressScaleState extends State<PressScale> {
  bool _down = false;

  void _set(bool value) {
    if (_down != value && mounted) {
      setState(() {
        _down = value;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return GestureDetector(
      behavior: HitTestBehavior.opaque,
      onTapDown: (_) => _set(true),
      onTapUp: (_) => _set(false),
      onTapCancel: () => _set(false),
      onTap: widget.enabled ? widget.onTap : null,
      child: AnimatedScale(
        scale: _down ? widget.pressedScale : 1.0,
        duration: const Duration(milliseconds: 140),
        curve: QfCurves.smooth,
        child: AnimatedOpacity(
          opacity: widget.enabled ? 1.0 : 0.55,
          duration: const Duration(milliseconds: 200),
          child: widget.child,
        ),
      ),
    );
  }
}

// ==========================================================
// AMBIENT LOOPS
// ==========================================================

/// Drives one or more repeating ambient animations.
///
/// A single controller keeps the campus photograph background, glow pulses and
/// shimmers on the same clock, so they never drift out of sync.
class AmbientLoop extends StatefulWidget {
  const AmbientLoop({
    super.key,
    required this.builder,
    this.period = const Duration(seconds: 18),
    this.enabled = true,
  });

  /// Receives a normalised 0..1 cycle position.
  final Widget Function(BuildContext context, double t, double phase) builder;

  final Duration period;
  final bool enabled;

  @override
  State<AmbientLoop> createState() => _AmbientLoopState();
}

class _AmbientLoopState extends State<AmbientLoop>
    with SingleTickerProviderStateMixin {
  late final AnimationController _controller = AnimationController(
    vsync: this,
    duration: widget.period,
  );

  @override
  void initState() {
    super.initState();

    if (widget.enabled) {
      _controller.repeat();
    }
  }

  @override
  void dispose() {
    _controller.dispose();
    super.dispose();
  }

  @override
  Widget build(BuildContext context) {
    return AnimatedBuilder(
      animation: _controller,
      builder: (BuildContext context, Widget? child) {
        final double t = _controller.value;

        // A second, slower phase offset gives each blob its own drift.
        return widget.builder(context, t, (t * 2) % 1.0);
      },
    );
  }
}

/// A dot that pulses in and out forever — used for "live" status.
class BreathingDot extends StatelessWidget {
  const BreathingDot({super.key, required this.color, this.size = 8});

  final Color color;
  final double size;

  @override
  Widget build(BuildContext context) {
    return AmbientLoop(
      period: const Duration(milliseconds: 1600),
      builder: (BuildContext context, double t, double phase) {
        final double wave = (math.sin(t * 2 * math.pi) + 1) / 2;

        return Container(
          width: size,
          height: size,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            color: color,
            boxShadow: <BoxShadow>[
              BoxShadow(
                color: color.withValues(alpha: 0.25 + 0.5 * wave),
                blurRadius: 6 + 8 * wave,
                spreadRadius: 1 + 2 * wave,
              ),
            ],
          ),
        );
      },
    );
  }
}

/// ==========================================================
/// SHIMMER (loading placeholder)
/// ==========================================================

/// A sweeping highlight used while real data is still loading.
class Shimmer extends StatelessWidget {
  const Shimmer({
    super.key,
    required this.child,
    this.base = const Color(0x1AFFFFFF),
    this.highlight = const Color(0x40FFFFFF),
    this.period = const Duration(milliseconds: 1500),
    this.borderRadius,
  });

  final Widget child;
  final Color base;
  final Color highlight;
  final Duration period;
  final BorderRadius? borderRadius;

  @override
  Widget build(BuildContext context) {
    return ClipRRect(
      borderRadius: borderRadius ?? BorderRadius.zero,
      child: AmbientLoop(
        period: period,
        builder: (BuildContext context, double t, double phase) {
          // Sweep the highlight from off the left edge to off the right.
          final double travel = t * 2 - 1;

          return Stack(
            children: <Widget>[
              Container(color: base, child: child),
              Positioned.fill(
                child: DecoratedBox(
                  decoration: BoxDecoration(
                    gradient: LinearGradient(
                      begin: Alignment(-1 + travel * 2, -0.6),
                      end: Alignment(-0.4 + travel * 2, 0.6),
                      colors: <Color>[
                        Colors.transparent,
                        highlight,
                        Colors.transparent,
                      ],
                    ),
                  ),
                ),
              ),
            ],
          );
        },
      ),
    );
  }
}

/// ==========================================================
// ANIMATED COUNTER
// ==========================================================

/// Counts up from the previous value to [value] whenever it changes.
class AnimatedCounter extends StatelessWidget {
  const AnimatedCounter({
    super.key,
    required this.value,
    this.style,
    this.duration = const Duration(milliseconds: 900),
    this.prefix = '',
    this.suffix = '',
  });

  final int value;
  final TextStyle? style;
  final Duration duration;
  final String prefix;
  final String suffix;

  @override
  Widget build(BuildContext context) {
    return TweenAnimationBuilder<double>(
      tween: Tween<double>(begin: 0, end: value.toDouble()),
      duration: duration,
      curve: QfCurves.entrance,
      builder: (BuildContext context, double animated, Widget? child) {
        return Text('$prefix${animated.round()}$suffix', style: style);
      },
    );
  }
}

// ==========================================================
// GRADIENT TEXT
// ==========================================================

/// Text filled with an animated gradient sweep.
class GradientText extends StatelessWidget {
  const GradientText({
    super.key,
    required this.text,
    required this.colors,
    this.style,
    this.animate = true,
  });

  final String text;
  final List<Color> colors;
  final TextStyle? style;
  final bool animate;

  @override
  Widget build(BuildContext context) {
    if (!animate) {
      return ShaderMask(
        shaderCallback: (Rect bounds) =>
            LinearGradient(colors: colors).createShader(bounds),
        child: Text(text, style: style),
      );
    }

    // Stops must line up with the colour list, so derive them from it
    // rather than hard-coding a fixed-length array.
    final List<double>? stops = colors.length < 2
        ? null
        : List<double>.generate(
            colors.length,
            (int i) => i / (colors.length - 1),
          );

    return AmbientLoop(
      period: const Duration(milliseconds: 4200),
      builder: (BuildContext context, double t, double phase) {
        final double shift = t * 2 - 1;

        return ShaderMask(
          shaderCallback: (Rect bounds) => LinearGradient(
            colors: colors,
            stops: stops,
            begin: Alignment(-1.6 + shift, -0.3),
            end: Alignment(0.4 + shift, 0.3),
          ).createShader(bounds),
          child: Text(text, style: style),
        );
      },
    );
  }
}

// ==========================================================
// PAGE TRANSITION
// ==========================================================

/// A soft fade-through with a slight scale, used between the
/// login form and the dashboard.
class QfPageRoute<T> extends PageRouteBuilder<T> {
  QfPageRoute({required Widget page})
    : super(
        transitionDuration: const Duration(milliseconds: 520),
        reverseTransitionDuration: const Duration(milliseconds: 380),
        pageBuilder:
            (
              BuildContext context,
              Animation<double> animation,
              Animation<double> secondary,
            ) => page,
        transitionsBuilder:
            (
              BuildContext context,
              Animation<double> animation,
              Animation<double> secondary,
              Widget child,
            ) {
              final CurvedAnimation curve = CurvedAnimation(
                parent: animation,
                curve: QfCurves.entrance,
                reverseCurve: Curves.easeIn,
              );

              return FadeTransition(
                opacity: curve,
                child: ScaleTransition(
                  scale: Tween<double>(begin: 0.94, end: 1.0).animate(curve),
                  child: child,
                ),
              );
            },
      );
}
