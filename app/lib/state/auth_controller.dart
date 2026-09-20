import 'package:flutter/material.dart';

import '../models/user.dart';
import '../services/api_client.dart';
import '../services/auth_service.dart';

class AuthScope extends InheritedNotifier<AuthController> {
  const AuthScope({
    super.key,
    required AuthController controller,
    required super.child,
  }) : super(notifier: controller);

  static AuthController of(BuildContext context) {
    final scope = context.dependOnInheritedWidgetOfExactType<AuthScope>();
    assert(scope != null, 'AuthScope not found');
    return scope!.notifier!;
  }
}

class AuthController extends ChangeNotifier {
  AuthController({AuthService? authService})
      : _auth = authService ?? AuthService();

  final AuthService _auth;

  AgentUser? user;
  bool ready = false;
  String? error;

  Future<void> bootstrap() async {
    user = await _auth.restoreSession();
    if (user != null) {
      try {
        user = await _auth.syncWallet(user!);
      } catch (_) {
        // Keep cached session; flags refresh on next successful sync.
      }
    }
    ready = true;
    notifyListeners();
  }

  Future<AgentUser> login(String login, String pin) async {
    user = await _auth.login(login: login, pin: pin);
    notifyListeners();
    return user!;
  }

  Future<EmailVerifyChallenge> register(Map<String, String> fields) {
    return _auth.register(
      fullName: fields['full_name']!,
      phone: fields['phone']!,
      location: fields['location']!,
      gender: fields['gender']!,
      email: fields['email']!,
      pin: fields['password']!,
      referralCode: fields['referral_code'],
    );
  }

  Future<void> verifyEmail(String challengeId, String otp) {
    return _auth.verifyEmail(challengeId: challengeId, otp: otp);
  }

  Future<EmailVerifyChallenge> resendVerifyEmail({
    String? challengeId,
    String? email,
  }) {
    return _auth.resendVerifyEmail(challengeId: challengeId, email: email);
  }

  Future<void> setTransactionPin({
    required String pin,
    required String confirmPin,
    String? currentPin,
  }) async {
    final current = user;
    if (current == null) {
      throw ApiException('Session expired. Please sign in again.', statusCode: 401);
    }
    user = await _auth.setTransactionPin(
      user: current,
      pin: pin,
      confirmPin: confirmPin,
      currentPin: currentPin,
    );
    notifyListeners();
  }

  Future<void> verifyTransactionPin(String pin) {
    return _auth.verifyTransactionPin(pin);
  }

  Future<void> refreshWallet() async {
    final current = user;
    if (current == null) return;
    user = await _auth.syncWallet(current);
    notifyListeners();
  }

  Future<void> requestPinReset(String email) => _auth.requestPinReset(email);

  Future<void> logout() async {
    await _auth.logout();
    user = null;
    notifyListeners();
  }
}
