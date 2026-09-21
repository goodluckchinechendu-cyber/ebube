import '../models/user.dart';
import 'api_client.dart';
import 'session_store.dart';

class EmailVerifyChallenge {
  const EmailVerifyChallenge({
    required this.challengeId,
    required this.maskedEmail,
    required this.message,
    this.emailSent = true,
  });

  final String challengeId;
  final String maskedEmail;
  final String message;
  final bool emailSent;
}

class NeedsEmailVerificationException implements Exception {
  NeedsEmailVerificationException(this.challenge, {this.email = ''});

  final EmailVerifyChallenge challenge;
  final String email;

  @override
  String toString() => challenge.message;
}

class AuthService {
  AuthService({ApiClient? api, SessionStore? session})
      : _session = session ?? SessionStore(),
        _api = api ?? ApiClient();

  final ApiClient _api;
  final SessionStore _session;

  Future<AgentUser?> restoreSession() async {
    final token = await _session.loadToken();
    final user = await _session.loadUser();
    if (token == null || token.isEmpty || user == null) {
      await _session.clear();
      AuthTokenHolder.token = null;
      return null;
    }
    AuthTokenHolder.token = token;
    return user;
  }

  Future<AgentUser> login({
    required String login,
    required String pin,
  }) async {
    final data = await _api.post(
      'login.php',
      body: {
        'login': login.trim(),
        'password': pin.trim(),
      },
      throwOnFailure: false,
    );

    if (data['needs_email_verification'] == true) {
      throw NeedsEmailVerificationException(EmailVerifyChallenge(
        challengeId: '${data['challenge_id'] ?? ''}',
        maskedEmail: '${data['masked_email'] ?? ''}',
        message: '${data['message'] ?? 'Verify your email to continue'}',
        emailSent: data['email_sent'] != false,
      ), email: '${data['email'] ?? ''}');
    }

    if (!_isApiSuccess(data)) {
      throw ApiException(
        '${data['message'] ?? 'Login failed'}',
        statusCode: data['response_code'] is num
            ? (data['response_code'] as num).toInt()
            : null,
      );
    }

    final userMap = data['user'];
    if (userMap is! Map) {
      throw ApiException('Login succeeded but user payload was missing');
    }

    final token = '${data['session_token'] ?? ''}'.trim();
    if (token.isEmpty) {
      throw ApiException('Login succeeded but session token was missing');
    }

    final user = AgentUser.fromJson(Map<String, dynamic>.from(userMap));
    AuthTokenHolder.token = token;
    await _session.saveSession(user: user, token: token);
    return user;
  }

  Future<EmailVerifyChallenge> register({
    required String fullName,
    required String phone,
    required String location,
    required String gender,
    required String email,
    required String pin,
    String? referralCode,
  }) async {
    final data = await _api.post('register.php', body: {
      'full_name': fullName.trim(),
      'phone': phone.trim(),
      'location': location.trim(),
      'gender': gender.trim(),
      'email': email.trim(),
      'password': pin.trim(),
      if (referralCode != null && referralCode.trim().isNotEmpty)
        'referral_code': referralCode.trim().toUpperCase(),
    });

    return EmailVerifyChallenge(
      challengeId: '${data['challenge_id'] ?? ''}',
      maskedEmail: '${data['masked_email'] ?? email}',
      message: '${data['message'] ?? 'Check your email for a verification code'}',
      emailSent: data['email_sent'] != false,
    );
  }

  Future<void> verifyEmail({
    required String challengeId,
    required String otp,
  }) async {
    await _api.post('verify_email.php', body: {
      'challenge_id': challengeId,
      'otp': otp.trim(),
    });
  }

  Future<EmailVerifyChallenge> resendVerifyEmail({
    String? challengeId,
    String? email,
  }) async {
    final data = await _api.post('resend_verify_email.php', body: {
      if (challengeId != null && challengeId.isNotEmpty) 'challenge_id': challengeId,
      if (email != null && email.isNotEmpty) 'email': email.trim(),
    });
    return EmailVerifyChallenge(
      challengeId: '${data['challenge_id'] ?? challengeId ?? ''}',
      maskedEmail: '${data['masked_email'] ?? ''}',
      message: '${data['message'] ?? 'Verification code resent'}',
      emailSent: data['email_sent'] != false,
    );
  }

  Future<AgentUser> setTransactionPin({
    required AgentUser user,
    required String pin,
    required String confirmPin,
    String? currentPin,
  }) async {
    await _api.post('set_transaction_pin.php', body: {
      'action': user.hasTransactionPin ? 'change' : 'set',
      'pin': pin.trim(),
      'confirm_pin': confirmPin.trim(),
      if (currentPin != null && currentPin.isNotEmpty) 'current_pin': currentPin.trim(),
    });
    final updated = user.copyWithBalances(hasTransactionPin: true);
    await _session.saveUser(updated);
    return updated;
  }

  Future<void> verifyTransactionPin(String pin) async {
    await _api.post('verify_transaction_pin.php', body: {
      'pin': pin.trim(),
    });
  }

  Future<AgentUser> syncWallet(AgentUser user) async {
    final data = await _api.post('users_wallet.php', body: {
      'id': user.id,
      'action': 'sync',
    });

    final updated = user.copyWithBalances(
      momoBalance: (data['momo_balance'] as num?)?.toDouble(),
      vtuBalance: (data['vtu_balance'] as num?)?.toDouble(),
      logicalBalance: (data['logical_balance'] as num?)?.toDouble(),
      commissionBalance: (data['commission_balance'] as num?)?.toDouble(),
      hasTransactionPin: data.containsKey('has_transaction_pin')
          ? data['has_transaction_pin'] == true
          : null,
      emailVerified: data.containsKey('email_verified')
          ? data['email_verified'] != false
          : null,
      isExternal: data.containsKey('is_external')
          ? data['is_external'] == true || data['is_external'] == 1
          : null,
      walletId: data.containsKey('wallet_id')
          ? '${data['wallet_id'] ?? ''}'.trim()
          : null,
    );
    await _session.saveUser(updated);
    return updated;
  }

  Future<void> requestPinReset(String email) async {
    await _api.post('request_pin_reset.php', body: {
      'email': email.trim(),
    });
  }

  Future<void> logout() async {
    try {
      await _api.post('logout.php', body: {}, throwOnFailure: false);
    } catch (_) {}
    AuthTokenHolder.token = null;
    await _session.clear();
  }
}

bool _isApiSuccess(Map<String, dynamic> data) {
  final v = data['success'];
  if (v == true || v == 1) return true;
  final s = '$v'.trim().toLowerCase();
  return s == 'true' || s == '1';
}
