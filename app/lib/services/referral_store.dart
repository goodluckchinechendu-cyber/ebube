import 'package:shared_preferences/shared_preferences.dart';

/// Persists invite `ref` from deep links / query params across register.
class ReferralStore {
  static const _key = 'ec_invite_ref';

  static Future<void> captureFromUri(Uri uri) async {
    final ref = (uri.queryParameters['ref'] ?? uri.queryParameters['referral_code'] ?? '')
        .trim()
        .toUpperCase();
    if (ref.isEmpty) return;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_key, ref);
  }

  static Future<String?> load() async {
    final prefs = await SharedPreferences.getInstance();
    final v = (prefs.getString(_key) ?? '').trim().toUpperCase();
    return v.isEmpty ? null : v;
  }

  static Future<void> clear() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_key);
  }
}
