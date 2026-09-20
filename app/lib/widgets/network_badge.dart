import 'package:flutter/material.dart';

import '../config/theme.dart';

/// Network badge — MTN uses the official logo asset; others keep brand colors.
class NetworkBadge extends StatelessWidget {
  const NetworkBadge({
    super.key,
    required this.network,
    this.size = 40,
    this.compact = false,
    this.showLabel = true,
  });

  final String network;
  final double size;
  final bool compact;
  /// When false, only the logo/badge is shown (no network name under it).
  final bool showLabel;

  static const _mtnYellow = Color(0xFFFFCC00);
  static const _mtnAsset = 'assets/images/mtn.png';

  static String normalize(String raw) {
    final n = raw.trim().toUpperCase();
    if (n.contains('MTN')) return 'MTN';
    if (n.contains('AIRTEL')) return 'Airtel';
    if (n.contains('GLO')) return 'Glo';
    if (n.contains('9MOBILE') || n.contains('ETISALAT')) return '9mobile';
    return raw.trim().isEmpty ? 'MTN' : raw.trim();
  }

  static Color colorFor(String network) {
    switch (normalize(network)) {
      case 'MTN':
        return _mtnYellow;
      case 'Airtel':
        return const Color(0xFFE40000);
      case 'Glo':
        return const Color(0xFF00A651);
      case '9mobile':
        return const Color(0xFF006C35);
      default:
        return EcColors.primary;
    }
  }

  static String labelFor(String network) => normalize(network);

  static String initialFor(String network) {
    final label = normalize(network);
    if (label == 'MTN') return 'M';
    if (label == 'Airtel') return 'A';
    if (label == 'Glo') return 'G';
    if (label == '9mobile') return '9';
    return label.isNotEmpty ? label[0].toUpperCase() : '?';
  }

  static String? parseFromProduct(String product) {
    final p = product.toUpperCase();
    if (p.contains('MTN')) return 'MTN';
    if (p.contains('AIRTEL')) return 'Airtel';
    if (p.contains('GLO')) return 'Glo';
    if (p.contains('9MOBILE')) return '9mobile';
    return 'MTN';
  }

  Widget _mtnLogo({required double side, required bool clipCircle}) {
    final image = Image.asset(
      _mtnAsset,
      width: side,
      height: side,
      fit: BoxFit.cover,
      errorBuilder: (_, error, stackTrace) => Container(
        width: side,
        height: side,
        color: _mtnYellow,
        alignment: Alignment.center,
        child: Text(
          'MTN',
          style: TextStyle(
            color: Colors.black,
            fontWeight: FontWeight.w800,
            fontSize: side * 0.28,
          ),
        ),
      ),
    );
    if (!clipCircle) return image;
    return ClipOval(child: image);
  }

  @override
  Widget build(BuildContext context) {
    final label = labelFor(network);
    final isMtn = label == 'MTN';
    final bg = colorFor(network);
    final fg = bg.computeLuminance() > 0.55 ? Colors.black : Colors.white;

    if (compact) {
      if (isMtn) {
        return SizedBox(
          width: size,
          height: size,
          child: _mtnLogo(side: size, clipCircle: true),
        );
      }
      return Container(
        width: size,
        height: size,
        decoration: BoxDecoration(color: bg, shape: BoxShape.circle),
        alignment: Alignment.center,
        child: Text(
          initialFor(network),
          style: TextStyle(
            color: fg,
            fontWeight: FontWeight.w800,
            fontSize: 12,
          ),
        ),
      );
    }

    final logo = isMtn
        ? ClipRRect(
            borderRadius: BorderRadius.circular(10),
            child: _mtnLogo(side: size, clipCircle: false),
          )
        : Container(
            width: size,
            height: size,
            decoration: BoxDecoration(
              color: bg,
              shape: BoxShape.circle,
              border: Border.all(color: EcColors.primaryDark.withValues(alpha: 0.15)),
            ),
            alignment: Alignment.center,
            child: Text(
              initialFor(network),
              style: TextStyle(
                color: fg,
                fontWeight: FontWeight.bold,
                fontSize: size * 0.42,
              ),
            ),
          );

    if (!showLabel) return logo;

    return Column(
      mainAxisSize: MainAxisSize.min,
      children: [
        logo,
        const SizedBox(height: 6),
        Text(
          label,
          style: const TextStyle(
            fontSize: 12,
            fontWeight: FontWeight.w700,
            color: EcColors.ink,
          ),
        ),
      ],
    );
  }
}
