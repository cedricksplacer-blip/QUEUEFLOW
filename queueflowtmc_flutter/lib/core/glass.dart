import 'dart:ui';

import 'package:flutter/material.dart';

import 'app_colors.dart';
import 'motion.dart';

/// ============================================================
/// QUEUEFLOW TMC — GLASS SURFACES
///
/// Frosted panels built on BackdropFilter so the campus photograph
/// behind them stays visible and softly blurred. Blur is expensive,
/// so keep the number of live glass panels on screen small.
/// ============================================================

class GlassCard extends StatelessWidget {
  const GlassCard({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(20),
    this.radius = 26,
    this.blur = 22,
    this.tint,
    this.borderOpacity = 0.16,
    this.glow,
    this.onTap,
  });

  /// A near-opaque white panel: the "document" surface of the design.
  ///
  /// The frosted [GlassCard] reads as a card floating over the campus
  /// photograph; this reads as a sheet of paper lying on it. Anything holding a
  /// form, a login, a long block of body copy or a table of rows uses this,
  /// because it is the only one of the two with enough opacity behind it for
  /// dark navy text to clear AA contrast.
  const GlassCard.sheet({
    super.key,
    required this.child,
    this.padding = const EdgeInsets.all(20),
    this.radius = 26,
    this.blur = 26,
    this.glow,
    this.onTap,
  }) : tint = const Color(0xF7FFFFFF),
       borderOpacity = 0.85;

  final Widget child;
  final EdgeInsetsGeometry padding;
  final double radius;
  final double blur;

  /// Panel fill. Defaults to a cool white wash.
  final Color? tint;

  /// Strength of the hairline around the panel.
  final double borderOpacity;

  /// Optional coloured bloom cast behind the panel edge.
  final Color? glow;

  final VoidCallback? onTap;

  @override
  Widget build(BuildContext context) {
    final bool isDark = Theme.of(context).brightness == Brightness.dark;
    final Color fill =
        tint ??
        (isDark
            ? const Color(0x14FFFFFF)
            : Colors.white.withValues(alpha: 0.55));

    Widget panel = Container(
      padding: padding,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(radius),
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: <Color>[
            fill,
            fill.withValues(alpha: fill.a * 0.55),
          ],
        ),
        border: Border.all(
          color: Colors.white.withValues(alpha: borderOpacity),
          width: 1.2,
        ),
        boxShadow: <BoxShadow>[
          BoxShadow(
            color: Colors.black.withValues(alpha: isDark ? 0.34 : 0.10),
            blurRadius: 30,
            offset: const Offset(0, 14),
          ),
          if (glow != null)
            BoxShadow(
              color: glow!.withValues(alpha: 0.30),
              blurRadius: 34,
              spreadRadius: -6,
            ),
        ],
      ),
      child: child,
    );

    // Frost the campus photograph showing through the panel.
    panel = ClipRRect(
      borderRadius: BorderRadius.circular(radius),
      child: BackdropFilter(
        filter: ImageFilter.blur(sigmaX: blur, sigmaY: blur),
        child: panel,
      ),
    );

    // A bright hairline along the top edge reads as a light source.
    panel = Stack(
      children: <Widget>[
        panel,
        Positioned(
          top: 0,
          left: radius * 0.6,
          right: radius * 0.6,
          child: Container(
            height: 1.2,
            decoration: BoxDecoration(
              gradient: LinearGradient(
                colors: <Color>[
                  Colors.transparent,
                  Colors.white.withValues(alpha: 0.55),
                  Colors.transparent,
                ],
              ),
            ),
          ),
        ),
      ],
    );

    if (onTap != null) {
      panel = PressScale(onTap: onTap, child: panel);
    }

    return RepaintBoundary(child: panel);
  }
}

/// A frosted circular avatar with a rotating conic highlight.
///
/// Shows the account's own photograph — [photo] — cropped to the circle by the
/// ring's own shape, and falls back to [initials] on the brand disc when that
/// file is missing or cannot be decoded. The fallback is deliberate: a portrait
/// path that fails must leave a card that still reads as somebody's face rather
/// than a hole in the panel.
class GlassAvatar extends StatelessWidget {
  const GlassAvatar({
    super.key,
    required this.initials,
    this.photo,
    this.radius = 30,
    this.gradient = AppColors.brandGradient,
  });

  final String initials;

  /// The photograph to show in place of the initials, if there is one.
  final String? photo;

  final double radius;
  final Gradient gradient;

  @override
  Widget build(BuildContext context) {
    return AmbientLoop(
      period: const Duration(seconds: 9),
      builder: (BuildContext context, double t, double phase) {
        return Container(
          width: radius * 2,
          height: radius * 2,
          decoration: BoxDecoration(
            shape: BoxShape.circle,
            gradient: SweepGradient(
              startAngle: t * 2 * 3.14159,
              endAngle: t * 2 * 3.14159 + 4.2,
              colors: <Color>[...gradient.colors, gradient.colors.first],
            ),
            boxShadow: <BoxShadow>[
              BoxShadow(
                color: gradient.colors.first.withValues(alpha: 0.45),
                blurRadius: 26,
                spreadRadius: -4,
              ),
            ],
          ),
          padding: EdgeInsets.all(2.5),
          child: Container(
            decoration: BoxDecoration(
              shape: BoxShape.circle,
              color: AppColors.primary,
            ),
            alignment: Alignment.center,
            clipBehavior: Clip.antiAlias,
            child: photo == null ? _initials() : _portrait(),
          ),
        );
      },
    );
  }

  Widget _initials() => Text(
    initials,
    style: TextStyle(
      color: Colors.white,
      fontSize: radius * 0.72,
      fontWeight: FontWeight.w800,
      letterSpacing: 0.5,
    ),
  );

  /// The photograph, cover-fitted and already clipped to the disc by the
  /// parent's [BoxShape.circle]. [errorBuilder] hands the same card back its
  /// initials, so a bad path costs a portrait and not an avatar.
  Widget _portrait() => Image.asset(
    photo!,
    width: radius * 2,
    height: radius * 2,
    fit: BoxFit.cover,
    filterQuality: FilterQuality.medium,
    errorBuilder:
        (BuildContext context, Object error, StackTrace? st) => _initials(),
  );
}

/// Primary action button: animated gradient with a sweeping shine.
class PrimaryButton extends StatefulWidget {
  const PrimaryButton({
    super.key,
    required this.label,
    this.icon,
    this.onPressed,
    this.busy = false,
    this.height = 58,
    this.gradient = AppColors.brandGradient,
  });

  /// The gold action.
  ///
  /// Gold is reserved in this palette for "your turn" — the ticket that is
  /// being served, and the one control that acts on it. Using it for anything
  /// else would spend the only colour the interface has for the moment the
  /// student is actually waiting for.
  const PrimaryButton.gold({
    super.key,
    required this.label,
    this.icon,
    this.onPressed,
    this.busy = false,
    this.height = 58,
  }) : gradient = AppColors.goldGradient;

  final String label;
  final IconData? icon;
  final VoidCallback? onPressed;
  final bool busy;
  final double height;
  final Gradient gradient;

  /// The colour the glow is cast in. A gold button glowing blue would read as a
  /// mistake, so the shadow follows the gradient's own first stop.
  Color get _glowColor => gradient.colors.first;

  @override
  State<PrimaryButton> createState() => _PrimaryButtonState();
}

class _PrimaryButtonState extends State<PrimaryButton> {
  bool _hover = false;

  @override
  Widget build(BuildContext context) {
    final bool enabled = widget.onPressed != null && !widget.busy;

    return PressScale(
      enabled: enabled,
      onTap: widget.onPressed,
      pressedScale: 0.97,
      child: MouseRegion(
        onEnter: (_) => setState(() => _hover = true),
        onExit: (_) => setState(() => _hover = false),
        child: AnimatedContainer(
          duration: const Duration(milliseconds: 260),
          curve: QfCurves.smooth,
          height: widget.height,
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(20),
            gradient: widget.gradient,
            boxShadow: <BoxShadow>[
              BoxShadow(
                color: widget._glowColor.withValues(
                  alpha: enabled ? (_hover ? 0.55 : 0.38) : 0.18,
                ),
                blurRadius: _hover ? 34 : 24,
                offset: const Offset(0, 10),
                spreadRadius: _hover ? 2 : 0,
              ),
            ],
          ),
          clipBehavior: Clip.antiAlias,
          child: Stack(
            fit: StackFit.expand,
            children: <Widget>[
              // Diagonal shine that drifts across the face of the button.
              AmbientLoop(
                period: const Duration(milliseconds: 2600),
                builder: (BuildContext context, double t, double phase) {
                  final double x = t * 2.4 - 0.7;

                  return DecoratedBox(
                    decoration: BoxDecoration(
                      gradient: LinearGradient(
                        begin: Alignment(x - 0.6, -1),
                        end: Alignment(x + 0.2, 1),
                        colors: <Color>[
                          Colors.transparent,
                          Colors.white.withValues(alpha: 0.30),
                          Colors.transparent,
                        ],
                      ),
                    ),
                  );
                },
              ),
              Center(
                child: widget.busy
                    ? const SizedBox(
                        width: 24,
                        height: 24,
                        child: CircularProgressIndicator(
                          strokeWidth: 2.6,
                          color: Colors.white,
                        ),
                      )
                    : Row(
                        mainAxisSize: MainAxisSize.min,
                        children: <Widget>[
                          if (widget.icon != null) ...<Widget>[
                            Icon(widget.icon, color: Colors.white, size: 20),
                            const SizedBox(width: 10),
                          ],
                          // Flexible so a long label wraps to a second
                          // line instead of spilling past the button.
                          Flexible(
                            child: Text(
                              widget.label,
                              textAlign: TextAlign.center,
                              style: const TextStyle(
                                color: Colors.white,
                                fontSize: 15.5,
                                fontWeight: FontWeight.w800,
                                letterSpacing: 1.6,
                              ),
                            ),
                          ),
                        ],
                      ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

/// Section heading with a small glowing accent bar.
class SectionTitle extends StatelessWidget {
  const SectionTitle({super.key, required this.text, this.trailing});

  final String text;
  final Widget? trailing;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.fromLTRB(4, 4, 4, 12),
      child: Row(
        children: <Widget>[
          Container(
            width: 4,
            height: 18,
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(999),
              gradient: AppColors.brandGradient,
              boxShadow: <BoxShadow>[
                BoxShadow(
                  color: AppColors.primary.withValues(alpha: 0.6),
                  blurRadius: 10,
                ),
              ],
            ),
          ),
          const SizedBox(width: 10),
          Expanded(
            child: Text(
              text.toUpperCase(),
              style: const TextStyle(
                color: Colors.white,
                fontSize: 12.5,
                fontWeight: FontWeight.w800,
                letterSpacing: 1.5,
              ),
            ),
          ),
          ?trailing,
        ],
      ),
    );
  }
}
