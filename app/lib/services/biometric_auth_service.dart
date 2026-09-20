import 'package:flutter/foundation.dart';
import 'package:flutter_secure_storage/flutter_secure_storage.dart';
import 'package:local_auth/local_auth.dart';
import 'package:shared_preferences/shared_preferences.dart';

/// Fingerprint / Face ID shortcuts for login.
///
/// Credentials are stored in [FlutterSecureStorage]. Web is unsupported.
class BiometricAuthService {
  BiometricAuthService._();

  static final BiometricAuthService instance = BiometricAuthService._();

  final LocalAuthentication _auth = LocalAuthentication();
  final FlutterSecureStorage _secure = const FlutterSecureStorage();

  static const _loginIdKey = 'biometric_login_id';
  static const _loginPinKey = 'biometric_login_pin';
  static const _loginOptInKey = 'biometric_login_opt_in';

  Future<bool> isDeviceSupported() async {
    if (kIsWeb) return false;
    try {
      if (!await _auth.isDeviceSupported()) return false;
      if (await _auth.canCheckBiometrics) return true;
      final types = await _auth.getAvailableBiometrics();
      return types.isNotEmpty;
    } catch (e) {
      if (kDebugMode) {
        debugPrint('BiometricAuthService.isDeviceSupported: $e');
      }
      return false;
    }
  }

  Future<bool> authenticate({
    required String reason,
    bool biometricOnly = true,
  }) async {
    if (kIsWeb) return false;
    try {
      return await _auth.authenticate(
        localizedReason: reason,
        options: AuthenticationOptions(
          stickyAuth: true,
          biometricOnly: biometricOnly,
        ),
      );
    } catch (e) {
      if (kDebugMode) {
        debugPrint('BiometricAuthService.authenticate: $e');
      }
      return false;
    }
  }

  Future<bool> isLoginEnabled() async {
    final prefs = await SharedPreferences.getInstance();
    if (!(prefs.getBool(_loginOptInKey) ?? false)) return false;
    final login = await _secure.read(key: _loginIdKey) ?? '';
    final pin = await _secure.read(key: _loginPinKey) ?? '';
    return login.isNotEmpty && pin.length == 4;
  }

  Future<bool> enableLoginBiometrics(String login, String pin) async {
    final trimmed = login.trim();
    if (trimmed.isEmpty || !RegExp(r'^\d{4}$').hasMatch(pin)) return false;
    if (!await isDeviceSupported()) return false;

    final ok = await authenticate(
      reason: _isApple
          ? 'Enable Face ID sign-in on this device'
          : 'Enable fingerprint sign-in on this device',
    );
    if (!ok) return false;

    await _secure.write(key: _loginIdKey, value: trimmed);
    await _secure.write(key: _loginPinKey, value: pin);
    final prefs = await SharedPreferences.getInstance();
    await prefs.setBool(_loginOptInKey, true);
    // Clear any legacy plaintext prefs from older builds.
    await prefs.remove(_loginIdKey);
    await prefs.remove(_loginPinKey);
    return true;
  }

  Future<void> disableLoginBiometrics() async {
    await _secure.delete(key: _loginIdKey);
    await _secure.delete(key: _loginPinKey);
    final prefs = await SharedPreferences.getInstance();
    await prefs.remove(_loginIdKey);
    await prefs.remove(_loginPinKey);
    await prefs.setBool(_loginOptInKey, false);
  }

  Future<({String login, String pin})?> tryBiometricLogin() async {
    if (!await isLoginEnabled()) return null;
    if (!await isDeviceSupported()) return null;

    final ok = await authenticate(
      reason: _isApple ? 'Sign in with Face ID' : 'Sign in with biometric',
    );
    if (!ok) return null;

    final login = await _secure.read(key: _loginIdKey) ?? '';
    final pin = await _secure.read(key: _loginPinKey) ?? '';
    if (login.isEmpty || pin.length != 4) return null;
    return (login: login, pin: pin);
  }

  bool get _isApple =>
      !kIsWeb &&
      (defaultTargetPlatform == TargetPlatform.iOS ||
          defaultTargetPlatform == TargetPlatform.macOS);
}
