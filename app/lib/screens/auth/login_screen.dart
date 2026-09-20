import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:url_launcher/url_launcher.dart';

import '../../config/app_config.dart';
import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../services/auth_service.dart';
import '../../services/biometric_auth_service.dart';
import '../../services/referral_store.dart';
import '../../services/session_inactivity_guard.dart';
import '../../state/auth_controller.dart';
import '../../widgets/ec_logo.dart';
import 'register_screen.dart';
import 'verify_email_screen.dart';

class LoginScreen extends StatefulWidget {
  const LoginScreen({super.key});

  @override
  State<LoginScreen> createState() => _LoginScreenState();
}

class _LoginScreenState extends State<LoginScreen> {
  final _formKey = GlobalKey<FormState>();
  final _loginCtrl = TextEditingController();
  final _pinCtrl = TextEditingController();
  bool _obscure = true;
  bool _busy = false;

  @override
  void initState() {
    super.initState();
    // Persist invite ref before user navigates to Register.
    // ignore: unawaited_futures
    ReferralStore.captureFromUri(Uri.base);
  }

  @override
  void dispose() {
    _loginCtrl.dispose();
    _pinCtrl.dispose();
    super.dispose();
  }

  Future<void> _openVerifyEmail(EmailVerifyChallenge challenge, {String email = ''}) async {
    await Navigator.of(context).push(
      MaterialPageRoute(
        builder: (_) => VerifyEmailScreen(
          challengeId: challenge.challengeId,
          maskedEmail: challenge.maskedEmail,
          message: challenge.message,
          email: email,
        ),
      ),
    );
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      await AuthScope.of(context).login(_loginCtrl.text, _pinCtrl.text);
      SessionInactivityGuard.instance.enable();
      return;
    } on NeedsEmailVerificationException catch (e) {
      if (!mounted) return;
      final emailHint = e.email.isNotEmpty
          ? e.email
          : (_loginCtrl.text.contains('@') ? _loginCtrl.text.trim() : '');
      await _openVerifyEmail(e.challenge, email: emailHint);
    } on ApiException catch (e) {
      final msg = e.message.trim();
      if (msg.toLowerCase() == 'login successful') return;
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text(e.message)));
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _biometricLogin() async {
    setState(() => _busy = true);
    try {
      final creds = await BiometricAuthService.instance.tryBiometricLogin();
      if (creds == null) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(content: Text('Biometric login cancelled or unavailable.')),
          );
        }
        return;
      }
      await AuthScope.of(context).login(creds.login, creds.pin);
      SessionInactivityGuard.instance.enable();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _downloadApk() async {
    final uri = Uri.parse(AppConfig.apkDownloadUrl);
    try {
      final ok = await launchUrl(uri, mode: LaunchMode.externalApplication);
      if (!ok && mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Could not open the APK download link')),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Download failed: $e')),
        );
      }
    }
  }

  Future<void> _forgotPin() async {
    final emailCtrl = TextEditingController(text: _loginCtrl.text.contains('@') ? _loginCtrl.text : '');
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Reset login PIN'),
        content: TextField(
          controller: emailCtrl,
          keyboardType: TextInputType.emailAddress,
          decoration: const InputDecoration(
            labelText: 'Account email',
            hintText: 'you@example.com',
          ),
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Send link')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await AuthScope.of(context).requestPinReset(emailCtrl.text);
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('If the account exists, a PIN reset email has been sent.'),
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      }
    } finally {
      emailCtrl.dispose();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 420),
            child: SingleChildScrollView(
              padding: const EdgeInsets.all(24),
              child: Form(
                key: _formKey,
                child: Column(
                  crossAxisAlignment: CrossAxisAlignment.stretch,
                  children: [
                    const SizedBox(height: 24),
                    const EcLogo(size: 88, showWordmark: true),
                    const SizedBox(height: 12),
                    const Text(
                      'Sign in to manage wallets, customers, and commissions.',
                      textAlign: TextAlign.center,
                      style: TextStyle(color: EcColors.muted, height: 1.4),
                    ),
                    const SizedBox(height: 32),
                    TextFormField(
                      controller: _loginCtrl,
                      keyboardType: TextInputType.emailAddress,
                      textInputAction: TextInputAction.next,
                      decoration: const InputDecoration(
                        labelText: 'Email or phone',
                      ),
                      validator: (v) =>
                          (v == null || v.trim().isEmpty) ? 'Required' : null,
                    ),
                    const SizedBox(height: 14),
                    TextFormField(
                      controller: _pinCtrl,
                      obscureText: _obscure,
                      keyboardType: TextInputType.number,
                      maxLength: 4,
                      inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                      decoration: InputDecoration(
                        labelText: '4-digit login PIN',
                        counterText: '',
                        suffixIcon: IconButton(
                          onPressed: () => setState(() => _obscure = !_obscure),
                          icon: Icon(_obscure ? Icons.visibility : Icons.visibility_off),
                        ),
                      ),
                      validator: (v) {
                        if (v == null || !RegExp(r'^\d{4}$').hasMatch(v)) {
                          return 'PIN must be exactly 4 digits';
                        }
                        return null;
                      },
                    ),
                    Align(
                      alignment: Alignment.centerRight,
                      child: TextButton(
                        onPressed: _busy ? null : _forgotPin,
                        child: const Text('Forgot login PIN?'),
                      ),
                    ),
                    const SizedBox(height: 8),
                    FilledButton(
                      onPressed: _busy ? null : _submit,
                      child: _busy
                          ? const SizedBox(
                              width: 22,
                              height: 22,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Text('Sign in'),
                    ),
                    const SizedBox(height: 12),
                    OutlinedButton.icon(
                      onPressed: _busy ? null : _biometricLogin,
                      icon: const Icon(Icons.fingerprint),
                      label: const Text('Sign in with fingerprint'),
                    ),
                    const SizedBox(height: 12),
                    OutlinedButton(
                      onPressed: _busy
                          ? null
                          : () {
                              Navigator.of(context).push(
                                MaterialPageRoute(
                                  builder: (_) => const RegisterScreen(),
                                ),
                              );
                            },
                      child: const Text('Register Now'),
                    ),
                    const SizedBox(height: 20),
                    TextButton.icon(
                      onPressed: _busy ? null : _downloadApk,
                      icon: const Icon(Icons.android, size: 20),
                      label: const Text('Download Android APK'),
                    ),
                  ],
                ),
              ),
            ),
          ),
        ),
      ),
    );
  }
}
