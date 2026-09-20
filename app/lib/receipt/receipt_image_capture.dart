import 'dart:typed_data';
import 'dart:ui' as ui;

import 'package:flutter/rendering.dart';
import 'package:flutter/widgets.dart';

/// Renders [key]'s [RepaintBoundary] to a PNG receipt image.
///
/// Do not use [RenderRepaintBoundary.debugNeedsPaint] here — in release
/// builds that getter throws LateInitializationError (assert-only init).
Future<Uint8List> captureReceiptPng(
  GlobalKey key, {
  double pixelRatio = 3,
}) async {
  final context = key.currentContext;
  if (context == null) {
    throw StateError('Receipt is not ready to capture');
  }

  final boundary = context.findRenderObject();
  if (boundary is! RenderRepaintBoundary) {
    throw StateError('Receipt must be wrapped in RepaintBoundary');
  }

  // Let the slip finish laying out / painting before capture.
  await WidgetsBinding.instance.endOfFrame;
  await Future<void>.delayed(const Duration(milliseconds: 50));

  ui.Image? image;
  try {
    image = await boundary.toImage(pixelRatio: pixelRatio);
    final bytes = await image.toByteData(format: ui.ImageByteFormat.png);
    if (bytes == null) {
      throw StateError('Could not encode receipt image');
    }
    return bytes.buffer.asUint8List();
  } finally {
    image?.dispose();
  }
}
