import 'dart:typed_data';

import 'package:share_plus/share_plus.dart';

Future<XFile> writeReceiptPngFile(Uint8List bytes, String fileName) async {
  return XFile.fromData(
    bytes,
    mimeType: 'image/png',
    name: fileName,
  );
}
