import 'package:flutter/foundation.dart';

/// EbubeConnect app + API configuration.
class AppConfig {
  static const String appName = 'EbubeConnect';
  static const String brandSlug = 'ebubeconnect';
  static const String brandShort = 'EC';

  /// Production Android APK downloads (hosted under /downloads on the live site).
  static const String apkDownloadUrl =
      'https://ebubeconnect.com/downloads/EbubeConnect.apk';
  static const String apkDownloadPageUrl =
      'https://ebubeconnect.com/downloads/';

  /// Production API (folder is `backend/` in this repo).
  static const String productionApiBase = 'https://ebubeconnect.com/backend';

  /// Local XAMPP path when serving this project under htdocs/Ebube.
  static const String localWebApiBase = 'http://localhost/Ebube/backend';

  /// Android emulator → host machine localhost.
  static const String androidEmulatorApiBase = 'http://10.0.2.2/Ebube/backend';

  /// Override at build time (preferred — keeps URL out of source):
  /// `flutter run --dart-define-from-file=../private/flutter.env`
  /// or `flutter run --dart-define=API_BASE=https://ebubeconnect.com/backend`
  /// See `private/flutter.env.example`. The app never ships DB/SMTP/API keys.
  static String get apiBaseUrl {
    const fromEnv = String.fromEnvironment('API_BASE');
    if (fromEnv.isNotEmpty) return fromEnv.replaceAll(RegExp(r'/+$'), '');

    if (kIsWeb) {
      final host = Uri.base.host.toLowerCase();
      if (host == 'localhost' || host == '127.0.0.1') {
        return localWebApiBase;
      }
      if (host.contains('ebubeconnect.com')) {
        return '${Uri.base.origin}/backend';
      }
      return productionApiBase;
    }

    switch (defaultTargetPlatform) {
      case TargetPlatform.android:
        // Release APKs must hit production; emulator loopback only for debug/profile.
        if (kReleaseMode) {
          return productionApiBase;
        }
        return androidEmulatorApiBase;
      default:
        return productionApiBase;
    }
  }

  static const Map<String, String> brandHeaders = {
    'X-SMobile-Brand-Slug': brandSlug,
    'X-SMobile-App-Name': appName,
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  };
}
