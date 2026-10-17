import 'package:flutter/material.dart';

/// ============================================================
/// QUEUEFLOW TMC — BRAND PALETTE
///
/// The reference design is blue and white: a navy-to-bright-blue family for
/// structure, a near-white tint for the frosted panels, gold reserved for the
/// "your turn" moment, and green/red for outcomes only.
///
/// The legacy member names (`primary`, `blue`, `violet`, `accent`) are kept so
/// the widgets that already reference them pick up the new colours rather than
/// needing a rename; new code should prefer the descriptive ones.
/// ============================================================

class AppColors {
  AppColors._();

  // -------------------------------------------------------------- blues
  /// Deepest navy. Page titles, the wordmark, and the top of every gradient.
  static const Color navy = Color(0xFF032B68);

  /// The brand blue. Buttons, active states, the app bar wash.
  static const Color primary = Color(0xFF073B91);

  /// Bright blue. Links, focus rings, the "called" accent.
  static const Color blue = Color(0xFF1264D8);

  /// Mid blue used where [blue] would shout — progress tracks, chips.
  static const Color sky = Color(0xFF4C8FE0);

  // ------------------------------------------------------- neutrals / tint
  /// Pale blue tint behind the glass panels and the empty states.
  static const Color surfaceTint = Color(0xFFEAF4FF);

  /// Panel white. Cards, sheets and the app bar.
  static const Color white = Color(0xFFFFFFFF);

  /// Body copy on a white panel. Navy rather than grey, so it stays on-brand.
  static const Color ink = Color(0xFF0A2A55);

  /// Secondary copy on a white panel.
  static const Color inkMuted = Color(0xFF5A6E8C);

  /// Hairlines and dividers on a white panel.
  static const Color hairline = Color(0xFFD5E3F4);

  // ------------------------------------------------------------ semantics
  /// Gold. "Now serving", your turn, the ticket that is about to be called.
  static const Color accent = Color(0xFFFFB300);
  static const Color warning = Color(0xFFFFB300);

  /// Green. Served, online, everything that worked.
  static const Color success = Color(0xFF18C99A);

  /// Red. Cancelled, failed, refused.
  static const Color danger = Color(0xFFFF5C69);

  /// Blue for informational banners — reuses [blue] so the app has one
  /// informational hue rather than a second one.
  static const Color info = Color(0xFF1264D8);

  // ------------------------------------------------------------- aliases
  /// Historical name for the deep blue; kept so existing widgets read navy.
  static const Color violet = navy;

  // ----------------------------------------------------------- gradients
  /// Navy → blue. Used for the app bar, primary buttons and the hero card.
  static const LinearGradient brandGradient = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: <Color>[navy, blue],
  );

  /// Flatter blue wash for the login hero, so the form above it stays legible.
  static const LinearGradient loginGradient = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: <Color>[primary, blue],
  );

  /// Gold wash for the "called / now serving" surface.
  static const LinearGradient goldGradient = LinearGradient(
    begin: Alignment.topLeft,
    end: Alignment.bottomRight,
    colors: <Color>[Color(0xFFFFC94D), accent],
  );

  /// The navy veil laid over the campus photograph.
  ///
  /// Deliberately not opaque: the reference design wants the campus visible
  /// through the blur, with enough navy for white text to clear WCAG AA.
  static const LinearGradient campusVeil = LinearGradient(
    begin: Alignment.topCenter,
    end: Alignment.bottomCenter,
    colors: <Color>[
      Color(0xF2032B68),
      Color(0xE6073B91),
      Color(0xFF032B68),
    ],
    stops: <double>[0.0, 0.45, 1.0],
  );
}
