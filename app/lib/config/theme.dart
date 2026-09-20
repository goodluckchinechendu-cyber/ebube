import 'package:flutter/material.dart';

/// Brand colors for EbubeConnect (yellow accent).
class EcColors {
  static const Color primary = Color(0xFFEAB308);
  static const Color primaryDark = Color(0xFFCA8A04);
  static const Color ink = Color(0xFF111827);
  static const Color muted = Color(0xFF6B7280);
  static const Color surface = Color(0xFFF8FAFC);
  static const Color card = Color(0xFFFFFFFF);
  static const Color success = Color(0xFF059669);
  static const Color danger = Color(0xFFDC2626);
}

ThemeData buildEcTheme() {
  final base = ColorScheme.fromSeed(
    seedColor: EcColors.primary,
    primary: EcColors.primary,
    brightness: Brightness.light,
  );

  return ThemeData(
    useMaterial3: true,
    colorScheme: base.copyWith(
      primary: EcColors.primary,
      onPrimary: EcColors.ink,
      surface: EcColors.surface,
    ),
    scaffoldBackgroundColor: EcColors.surface,
    appBarTheme: const AppBarTheme(
      centerTitle: false,
      backgroundColor: EcColors.card,
      foregroundColor: EcColors.ink,
      elevation: 0,
      scrolledUnderElevation: 0.5,
    ),
    inputDecorationTheme: InputDecorationTheme(
      filled: true,
      fillColor: EcColors.card,
      hintStyle: TextStyle(
        color: EcColors.muted.withValues(alpha: 0.55),
        fontWeight: FontWeight.w400,
      ),
      border: OutlineInputBorder(borderRadius: BorderRadius.circular(12)),
      enabledBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: BorderSide(color: Colors.grey.shade300),
      ),
      focusedBorder: OutlineInputBorder(
        borderRadius: BorderRadius.circular(12),
        borderSide: const BorderSide(color: EcColors.primary, width: 2),
      ),
    ),
    filledButtonTheme: FilledButtonThemeData(
      style: FilledButton.styleFrom(
        backgroundColor: EcColors.primary,
        foregroundColor: EcColors.ink,
        minimumSize: const Size.fromHeight(48),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
        textStyle: const TextStyle(fontWeight: FontWeight.w700, fontSize: 16),
      ),
    ),
    outlinedButtonTheme: OutlinedButtonThemeData(
      style: OutlinedButton.styleFrom(
        foregroundColor: EcColors.ink,
        minimumSize: const Size.fromHeight(48),
        shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(12)),
      ),
    ),
    snackBarTheme: SnackBarThemeData(
      behavior: SnackBarBehavior.floating,
      shape: RoundedRectangleBorder(borderRadius: BorderRadius.circular(10)),
    ),
  );
}
