import 'package:shared_preferences/shared_preferences.dart';

/// Persists invite `ref` (+ optional Super Admin `vis`) from deep links.
class ReferralStore {
  static const _key = 'ec_invite_ref';
  static const _visKey = 'ec_invite_vis';

  static Future<void> captureFromUri(Uri uri) async {
    final ref = (uri.queryParameters['ref'] ?? uri.queryParameters['referral_code'] ?? '')
        .trim()
        .toUpperCase();
    if (ref.isEmpty) return;
    final prefs = await SharedPreferences.getInstance();
    await prefs.setString(_key, ref);

    final visRaw = (uri.queryParameters['vis'] ?? uri.queryParameters['visibility'] ?? '')
        .trim()
        .toLowerCase();
    if (visRaw == 'int' || visRaw == 'internal') {
      await prefs.setString(_visKey, 'int');
    } else if (visRaw == 'ext' || visRaw == 'external') {
      await prefs.setString(_visKey, 'ext');
    } else {
      // SA invite without vis defaults to external on the server; keep unset for non-SA links.
      await prefs.remove(_visKey);
    }
  }

  static Future<String?> load() async {
    final prefs = await SharedPreferences.getInstance();
    final v = (prefs.getString(_key) ?? '').trim().toUpperCase();
    return v.isEmpty ? null : v;
  }

  /// `ext`, `int`, or null when the link did not specify visibility.
  static Future<String?> loadVisibility() async {
    final prefs = await SharedPreferences.getInstance();
    final v = (prefs.getString(_visKey) ?? '').trim().toLowerCase();
    if (v == 'int' || v == 'ext') return v;
    return null;
  }

  static Future<void> clear() async {
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_key);
    await prefs.remove(_visKey);
  }
}
