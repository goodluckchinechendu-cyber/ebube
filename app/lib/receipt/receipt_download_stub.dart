import 'dart:typed_data';

void saveFileOnWeb(
  Uint8List bytes,
  String fileName, {
  String mimeType = 'application/octet-stream',
}) {
  throw UnsupportedError('saveFileOnWeb is only supported on web');
}
