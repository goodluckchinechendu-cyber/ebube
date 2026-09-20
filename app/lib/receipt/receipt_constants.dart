import 'dart:math' as math;

import 'package:flutter/material.dart';

/// Layout constants for bank-style receipt PNG capture.
abstract final class ReceiptConstants {
  static const shareSubject = 'EbubeConnect transaction receipt';
  static const capturePixelRatio = 3.0;
  static const slipWidthPx = 384.0;

  /// Receipt slip width for the current viewport (avoids overflow on narrow phones).
  static double slipWidthFor(BuildContext context) {
    return math.min(
      slipWidthPx,
      MediaQuery.sizeOf(context).width - 32,
    );
  }
}

String receiptImageFileName(String receiptId) {
  final safeId = receiptId.replaceAll(RegExp(r'[^\w\-]+'), '_');
  return 'EbubeConnect-Receipt-$safeId.png';
}
