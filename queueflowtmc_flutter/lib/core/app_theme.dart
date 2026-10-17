import 'package:flutter/material.dart';

import 'app_colors.dart';

/// ============================================================
/// QUEUEFLOW TMC — THEME
///
/// The reference look is blue and white glass over the campus photograph:
/// a navy veil behind, frosted translucent panels in front, and gold held
/// back for the one moment that matters — the number being called.
///
/// The scheme is declared dark because the campus photograph sits under a navy
/// veil, so the surface behind the glass is deep blue rather than white. Text
/// and hairlines are therefore near-white, and every panel that needs to read
/// as a document rather than as glass gets an explicit [AppColors.surfaceTint]
/// fill from the widget that owns it.
/// ============================================================

class AppTheme {
  AppTheme._();

  /// Deepest tone in the app, matched by `CampusBackdrop`'s solid fallback.
  ///
  /// This is the colour a frame shows while the campus photograph decodes, so
  /// it has to be the top of the veil rather than black.
  static const Color backdrop = AppColors.navy;

  static ThemeData dark() {
    const ColorScheme scheme = ColorScheme.dark(
      primary: AppColors.primary,
      onPrimary: Colors.white,
      secondary: AppColors.blue,
      onSecondary: Colors.white,
      surface: AppColors.navy,
      onSurface: Colors.white,
      error: AppColors.danger,
      onError: Colors.white,
    );

    return ThemeData(
      useMaterial3: true,
      brightness: Brightness.dark,
      colorScheme: scheme,
      scaffoldBackgroundColor: Colors.transparent,
      canvasColor: AppColors.navy,
      splashFactory: InkSparkle.splashFactory,

      appBarTheme: AppBarTheme(
        elevation: 0,
        scrolledUnderElevation: 0,
        centerTitle: false,
        backgroundColor: Colors.transparent,
        surfaceTintColor: Colors.transparent,
        foregroundColor: Colors.white,
        titleTextStyle: const TextStyle(
          color: Colors.white,
          fontSize: 19,
          fontWeight: FontWeight.w800,
          letterSpacing: -0.2,
        ),
        iconTheme: const IconThemeData(color: Colors.white),
      ),

      inputDecorationTheme: InputDecorationTheme(
        filled: true,
        fillColor: Colors.white.withValues(alpha: 0.10),
        contentPadding: const EdgeInsets.symmetric(
          horizontal: 18,
          vertical: 19,
        ),
        hintStyle: TextStyle(color: Colors.white.withValues(alpha: 0.38)),
        labelStyle: TextStyle(color: Colors.white.withValues(alpha: 0.60)),
        floatingLabelStyle: const TextStyle(
          color: AppColors.blue,
          fontWeight: FontWeight.w700,
        ),
        prefixIconColor: Colors.white.withValues(alpha: 0.50),
        suffixIconColor: Colors.white.withValues(alpha: 0.50),
        border: _border(Colors.white.withValues(alpha: 0.14)),
        enabledBorder: _border(Colors.white.withValues(alpha: 0.16)),
        focusedBorder: _border(AppColors.blue, width: 1.6),
        errorBorder: _border(AppColors.danger.withValues(alpha: 0.75)),
        focusedErrorBorder: _border(AppColors.danger, width: 1.6),
        errorStyle: const TextStyle(color: AppColors.danger, fontSize: 12),
      ),

      textButtonTheme: TextButtonThemeData(
        style: TextButton.styleFrom(
          foregroundColor: AppColors.blue,
          textStyle: const TextStyle(fontWeight: FontWeight.w700),
        ),
      ),

      dialogTheme: DialogThemeData(
        backgroundColor: AppColors.primary,
        surfaceTintColor: Colors.transparent,
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(24)),
        titleTextStyle: const TextStyle(
          color: Colors.white,
          fontSize: 19,
          fontWeight: FontWeight.w800,
        ),
        contentTextStyle: TextStyle(
          color: Colors.white.withValues(alpha: 0.82),
          height: 1.45,
        ),
      ),

      bottomSheetTheme: const BottomSheetThemeData(
        backgroundColor: AppColors.primary,
        surfaceTintColor: Colors.transparent,
      ),

      progressIndicatorTheme: const ProgressIndicatorThemeData(
        color: AppColors.blue,
        linearTrackColor: Color(0x1FFFFFFF),
      ),

      snackBarTheme: SnackBarThemeData(
        behavior: SnackBarBehavior.floating,
        backgroundColor: AppColors.primary,
        contentTextStyle: const TextStyle(color: Colors.white),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(16)),
      ),

      dividerTheme: DividerThemeData(
        color: Colors.white.withValues(alpha: 0.10),
        space: 1,
        thickness: 1,
      ),

      textTheme: const TextTheme(
        displaySmall: TextStyle(
          color: Colors.white,
          fontWeight: FontWeight.w800,
          letterSpacing: -0.8,
        ),
        headlineSmall: TextStyle(
          color: Colors.white,
          fontWeight: FontWeight.w800,
          letterSpacing: -0.4,
        ),
        titleLarge: TextStyle(color: Colors.white, fontWeight: FontWeight.w800),
        titleMedium: TextStyle(
          color: Colors.white,
          fontWeight: FontWeight.w700,
        ),
        titleSmall: TextStyle(color: Colors.white, fontWeight: FontWeight.w700),
        bodyLarge: TextStyle(color: Color(0xFFEAF4FF)),
        bodyMedium: TextStyle(color: Color(0xFFD7E7FB)),
        bodySmall: TextStyle(color: Color(0xFFA9C4E4)),
        labelSmall: TextStyle(
          color: Color(0xFFA9C4E4),
          fontWeight: FontWeight.w700,
          letterSpacing: 1.1,
        ),
      ),
    );
  }

  static OutlineInputBorder _border(Color color, {double width = 1}) =>
      OutlineInputBorder(
        borderRadius: BorderRadius.circular(18),
        borderSide: BorderSide(color: color, width: width),
      );
}
