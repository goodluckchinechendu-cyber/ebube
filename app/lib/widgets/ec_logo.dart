import 'package:flutter/material.dart';

import '../config/app_config.dart';
import '../config/theme.dart';

/// Brand mark using the official [assets/images/ebube.png] logo.
class EcLogo extends StatelessWidget {
  const EcLogo({
    super.key,
    this.size = 72,
    this.showWordmark = false,
    this.isDarkBackground,
  });

  final double size;
  final bool showWordmark;

  /// Optional override: force dark logo variant (`true`) or light logo variant (`false`).
  /// If null, automatically inspects [Theme.of(context).brightness].
  final bool? isDarkBackground;

  @override
  Widget build(BuildContext context) {
    final isDark = isDarkBackground ?? (Theme.of(context).brightness == Brightness.dark);
    final primaryAssetPath = isDark ? 'assets/images/ebube_dark.png' : 'assets/images/ebube_white.png';

    final mark = Container(
      width: size,
      height: size,
      decoration: BoxDecoration(
        borderRadius: BorderRadius.circular(size * 0.2),
        boxShadow: [
          BoxShadow(
            color: EcColors.primary.withValues(alpha: 0.25),
            blurRadius: 16,
            offset: const Offset(0, 6),
          ),
        ],
      ),
      child: ClipRRect(
        borderRadius: BorderRadius.circular(size * 0.2),
        child: Image.asset(
          primaryAssetPath,
          width: size,
          height: size,
          fit: BoxFit.cover,
          errorBuilder: (context, error, stackTrace) {
            return Image.asset(
              'assets/images/ebube.png',
              width: size,
              height: size,
              fit: BoxFit.cover,
              errorBuilder: (context, error2, stackTrace2) {
                return Container(
                  width: size,
                  height: size,
                  decoration: BoxDecoration(
                    gradient: const LinearGradient(
                      colors: [EcColors.primary, EcColors.primaryDark],
                    ),
                    borderRadius: BorderRadius.circular(size * 0.22),
                  ),
                  alignment: Alignment.center,
                  child: Text(
                    AppConfig.brandShort,
                    style: TextStyle(
                      color: EcColors.ink,
                      fontWeight: FontWeight.w900,
                      fontSize: size * 0.38,
                    ),
                  ),
                );
              },
            );
          },
        ),
      ),
    );

    if (!showWordmark) return mark;

    final textColor = isDark ? Colors.white : EcColors.ink;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        mark,
        SizedBox(height: size * 0.14),
        Text(
          AppConfig.appName,
          style: TextStyle(
            fontSize: size * 0.28,
            fontWeight: FontWeight.w800,
            color: textColor,
            letterSpacing: -0.4,
          ),
        ),
      ],
    );
  }
}
