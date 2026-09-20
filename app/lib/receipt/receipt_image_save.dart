import 'dart:ui' show Rect;

import 'package:flutter/foundation.dart';
import 'package:share_plus/share_plus.dart';

import 'receipt_constants.dart';
import 'receipt_download_stub.dart'
    if (dart.library.html) 'receipt_download_web.dart';
import 'receipt_file_writer_stub.dart'
    if (dart.library.io) 'receipt_file_writer_io.dart';

Future<XFile> _receiptXFile(Uint8List pngBytes, String fileName) async {
  if (kIsWeb) {
    return XFile.fromData(
      pngBytes,
      name: fileName,
      mimeType: 'image/png',
    );
  }
  return writeReceiptPngFile(pngBytes, fileName);
}

/// Save receipt PNG — downloads on web, share-to-save sheet on mobile.
Future<ShareResult> saveReceiptImage({
  required Uint8List pngBytes,
  required String fileName,
  String shareSubject = ReceiptConstants.shareSubject,
  String? shareText,
  Rect? sharePositionOrigin,
}) async {
  if (kIsWeb) {
    saveFileOnWeb(pngBytes, fileName, mimeType: 'image/png');
    return ShareResult('', ShareResultStatus.unavailable);
  }

  final xFile = await _receiptXFile(pngBytes, fileName);
  return SharePlus.instance.share(
    ShareParams(
      files: [xFile],
      subject: shareSubject,
      text: shareText ?? 'Save this EbubeConnect receipt image',
      sharePositionOrigin: sharePositionOrigin,
    ),
  );
}

/// Share receipt PNG via the platform share sheet.
Future<ShareResult> shareReceiptImage({
  required Uint8List pngBytes,
  required String fileName,
  String shareSubject = ReceiptConstants.shareSubject,
  String? shareText,
  Rect? sharePositionOrigin,
}) async {
  final xFile = await _receiptXFile(pngBytes, fileName);
  return SharePlus.instance.share(
    ShareParams(
      files: [xFile],
      subject: shareSubject,
      text: shareText ?? '',
      sharePositionOrigin: sharePositionOrigin,
    ),
  );
}
