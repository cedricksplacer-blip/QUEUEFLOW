import 'dart:math' as math;

import 'package:flutter/material.dart';

import 'app_colors.dart';
import 'app_constants.dart';
import 'motion.dart';

/// ============================================================
/// QUEUEFLOW TMC — CAMPUS BACKDROP
///
/// The reference design sits a frosted blue-and-white interface on top of a
/// photograph of the campus, washed with navy so white text clears WCAG AA.
/// This widget is that photograph: [AppConstants.backgroundImage] fitted to
/// cover, the navy veil from [AppColors.campusVeil] over it, and one slow
/// drifting bloom so the screen is never completely static.
///
/// The bloom is a single [CustomPaint] driven by the shared [AmbientLoop]
/// clock, so the whole field still costs one repaint per frame rather than an
/// animation per card. Blur is deliberately not used here — the panels frost
/// their own backdrop, and stacking a second full-screen blur on top of that is
/// the most expensive thing this app could do.
/// ============================================================

class CampusBackdrop extends StatelessWidget {
  const CampusBackdrop({
    super.key,
    required this.child,
    this.intensity = 1.0,
    this.animate = true,
  });

  final Widget child;

  /// Scales how far the bloom lifts the image.
  ///
  /// Lower it on dense screens so a wall of glass over a bright sky still
  /// leaves the numbers legible.
  final double intensity;

  /// Disabled in widget tests so a `pumpAndSettle` is not waiting on a loop
  /// that never ends.
  final bool animate;

  @override
  Widget build(BuildContext context) {
    return Stack(
      fit: StackFit.expand,
      children: <Widget>[
        Positioned.fill(
          child: Image.asset(
            AppConstants.backgroundImage,
            fit: BoxFit.cover,
            filterQuality: FilterQuality.medium,
            // The veil alone is not enough to be readable on, so if the photo
            // cannot be decoded the backdrop falls back to the brand gradient
            // rather than leaving raw black behind the text.
            errorBuilder: (BuildContext context, Object error, StackTrace? st) =>
                const DecoratedBox(
                  decoration: BoxDecoration(gradient: AppColors.brandGradient),
                ),
          ),
        ),
        Positioned.fill(
          child: DecoratedBox(
            decoration: BoxDecoration(gradient: AppColors.campusVeil),
          ),
        ),
        if (intensity > 0)
          Positioned.fill(
            child: RepaintBoundary(
              child: animate
                  ? AmbientLoop(
                      period: const Duration(seconds: 26),
                      builder: (BuildContext context, double t, double phase) =>
                          CustomPaint(
                        painter: _BloomPainter(t: t, intensity: intensity),
                      ),
                    )
                  : CustomPaint(
                      painter: _BloomPainter(t: 0, intensity: intensity),
                    ),
            ),
          ),
        Positioned.fill(child: child),
      ],
    );
  }
}

class _BloomPainter extends CustomPainter {
  _BloomPainter({required this.t, required this.intensity});

  final double t;
  final double intensity;

  @override
  void paint(Canvas canvas, Size size) {
    final Paint paint = Paint()
      ..blendMode = BlendMode.plus
      ..style = PaintingStyle.fill;

    // Two blooms on different periods so they never visibly sync, one warm from
    // the upper right where the sun is in the photograph and one cool from the
    // lower left to keep the bottom of a long scroll from going flat.
    _blob(
      canvas,
      paint,
      size,
      center: Offset(
        size.width * (0.78 + 0.05 * math.sin(t * 2 * math.pi)),
        size.height * (0.18 + 0.03 * math.cos(t * 2 * math.pi * 0.8)),
      ),
      radius: size.longestSide * 0.55,
      color: AppColors.sky,
      alpha: 0.16,
    );

    _blob(
      canvas,
      paint,
      size,
      center: Offset(
        size.width * (0.20 + 0.04 * math.cos(t * 2 * math.pi * 1.1)),
        size.height * (0.74 + 0.03 * math.sin(t * 2 * math.pi * 0.7)),
      ),
      radius: size.longestSide * 0.45,
      color: AppColors.blue,
      alpha: 0.12,
    );
  }

  void _blob(
    Canvas canvas,
    Paint paint,
    Size size, {
    required Offset center,
    required double radius,
    required Color color,
    required double alpha,
  }) {
    final Rect rect = Rect.fromCircle(center: center, radius: radius);

    paint.shader = RadialGradient(
      colors: <Color>[
        color.withValues(alpha: alpha * intensity),
        color.withValues(alpha: 0.0),
      ],
      stops: const <double>[0.0, 1.0],
    ).createShader(rect);

    canvas.drawCircle(center, radius, paint);
  }

  @override
  bool shouldRepaint(_BloomPainter old) =>
      old.t != t || old.intensity != intensity;
}

/// The QueueFlow mark.
///
/// No container, no plate, no border — just the mark itself on the campus
/// photograph with a soft halo behind it. Motion is layered so each transform
/// has its own period and they never visibly sync:
///
/// * entrance  – spring scale-up, run by the caller via [RevealIn]
/// * float     – slow vertical drift, the "weightless" cue
/// * breathe   – gentle scale pulse
/// * sway      – a few degrees of rotation, pendulum-paced
/// * halo      – coloured glow that swells and fades with the float
class CampusLogo extends StatelessWidget {
  const CampusLogo({super.key, this.size = 108, this.showGlow = true});

  final double size;
  final bool showGlow;

  @override
  Widget build(BuildContext context) {
    final Widget mark = Image.asset(
      AppConstants.logoImage,
      width: size,
      height: size,
      fit: BoxFit.contain,
      filterQuality: FilterQuality.high,
      errorBuilder: (BuildContext context, Object error, StackTrace? st) =>
          Icon(Icons.queue, size: size * 0.72, color: Colors.white),
    );

    return AmbientLoop(
      period: const Duration(milliseconds: 5200),
      builder: (BuildContext context, double t, double phase) {
        const double turn = 2 * math.pi;

        // float: 1 full rise-and-fall per loop
        final double drift = math.sin(turn * t);

        // breathe: 2 cycles per loop, so it beats against the float
        final double pulse = math.sin(turn * 2 * t);

        // sway: a quarter cycle per loop — slower, heavier, pendulum-like
        final double swing = math.sin(turn * t * 0.25 + 0.6);

        final double lift = drift * size * 0.045;
        final double scale = 1 + pulse * 0.030;
        final double angle = swing * 0.055; // ~3.2 degrees at peak

        // Glow tracks the float so the mark brightens as it rises.
        final double glow = (drift + 1) / 2;

        return Transform.translate(
          offset: Offset(0, lift),
          child: Transform.rotate(
            angle: angle,
            child: Transform.scale(
              scale: scale,
              child: Stack(
                alignment: Alignment.center,
                clipBehavior: Clip.none,
                children: <Widget>[
                  // A halo behind the mark, not a container around it.
                  if (showGlow)
                    IgnorePointer(
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 200),
                        width: size * (1.05 + 0.10 * glow),
                        height: size * (1.05 + 0.10 * glow),
                        decoration: BoxDecoration(
                          shape: BoxShape.circle,
                          gradient: RadialGradient(
                            colors: <Color>[
                              AppColors.blue.withValues(
                                alpha: 0.22 + 0.26 * glow,
                              ),
                              AppColors.sky.withValues(
                                alpha: 0.10 + 0.14 * glow,
                              ),
                              Colors.transparent,
                            ],
                            stops: const <double>[0.0, 0.45, 1.0],
                          ),
                        ),
                      ),
                    ),

                  // The mark, bare.
                  mark,
                ],
              ),
            ),
          ),
        );
      },
    );
  }
}
