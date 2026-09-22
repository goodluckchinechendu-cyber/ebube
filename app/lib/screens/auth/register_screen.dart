import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../services/referral_store.dart';
import '../../state/auth_controller.dart';
import 'verify_email_screen.dart';

class RegisterScreen extends StatefulWidget {
  const RegisterScreen({super.key});

  @override
  State<RegisterScreen> createState() => _RegisterScreenState();
}

class _RegisterScreenState extends State<RegisterScreen> {
  final _formKey = GlobalKey<FormState>();
  final _fields = <String, TextEditingController>{
    'full_name': TextEditingController(),
    'phone': TextEditingController(),
    'location': TextEditingController(),
    'email': TextEditingController(),
    'password': TextEditingController(),
    'confirm': TextEditingController(),
  };
  String _gender = 'Male';
  bool _busy = false;
  String? _inviteRef;

  @override
  void initState() {
    super.initState();
    _captureInviteRef();
  }

  Future<void> _captureInviteRef() async {
    await ReferralStore.captureFromUri(Uri.base);
    final ref = await ReferralStore.load();
    if (mounted) setState(() => _inviteRef = ref);
  }

  @override
  void dispose() {
    for (final c in _fields.values) {
      c.dispose();
    }
    super.dispose();
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      final body = <String, String>{
        'full_name': _fields['full_name']!.text,
        'phone': _fields['phone']!.text,
        'location': _fields['location']!.text,
        'gender': _gender,
        'email': _fields['email']!.text,
        'password': _fields['password']!.text,
      };
      final ref = _inviteRef;
      if (ref != null && ref.isNotEmpty) {
        body['referral_code'] = ref;
      }
      final vis = await ReferralStore.loadVisibility();
      if (vis != null && vis.isNotEmpty) {
        body['visibility'] = vis;
      }
      final challenge = await AuthScope.of(context).register(body);
      await ReferralStore.clear();
      if (!mounted) return;
      await Navigator.of(context).pushReplacement(
        MaterialPageRoute(
          builder: (_) => VerifyEmailScreen(
            challengeId: challenge.challengeId,
            maskedEmail: challenge.maskedEmail,
            message: challenge.message,
            email: _fields['email']!.text.trim(),
          ),
        ),
      );
    } on ApiException catch (e) {
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(),
      body: SafeArea(
        child: Center(
          child: ConstrainedBox(
            constraints: const BoxConstraints(maxWidth: 520),
            child: Form(
              key: _formKey,
              child: ListView(
                padding: const EdgeInsets.all(24),
                children: [
                  const Text(
                    'Create your EbubeConnect account',
                    style: TextStyle(fontSize: 18, fontWeight: FontWeight.w700),
                  ),
                  const SizedBox(height: 6),
                  const Text(
                    'PIN must be exactly 4 digits.',
                    style: TextStyle(color: EcColors.muted, height: 1.4),
                  ),
                  if (_inviteRef != null && _inviteRef!.isNotEmpty) ...[
                    const SizedBox(height: 12),
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(12),
                      decoration: BoxDecoration(
                        color: EcColors.primary.withValues(alpha: 0.1),
                        borderRadius: BorderRadius.circular(10),
                      ),
                      child: Text(
                        'Joining via invite code $_inviteRef',
                        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
                      ),
                    ),
                  ],
                  const SizedBox(height: 20),
                  _field('full_name', 'Full name'),
                  _field('phone', 'Phone', keyboard: TextInputType.phone),
                  _field('location', 'City or area'),
                  _field('email', 'Email', keyboard: TextInputType.emailAddress),
                  const SizedBox(height: 8),
                  DropdownButtonFormField<String>(
                    // ignore: deprecated_member_use
                    value: _gender,
                    decoration: const InputDecoration(labelText: 'Gender'),
                    items: const [
                      DropdownMenuItem(value: 'Male', child: Text('Male')),
                      DropdownMenuItem(value: 'Female', child: Text('Female')),
                    ],
                    onChanged: (v) => setState(() => _gender = v ?? 'Male'),
                  ),
                  const SizedBox(height: 14),
                  _field(
                    'password',
                    '4-digit PIN',
                    obscure: true,
                    keyboard: TextInputType.number,
                    maxLength: 4,
                    digitsOnly: true,
                    validator: (v) =>
                        (v != null && RegExp(r'^\d{4}$').hasMatch(v))
                            ? null
                            : 'PIN must be exactly 4 digits',
                  ),
                  _field(
                    'confirm',
                    'Confirm PIN',
                    obscure: true,
                    keyboard: TextInputType.number,
                    maxLength: 4,
                    digitsOnly: true,
                    validator: (v) =>
                        v == _fields['password']!.text ? null : 'PINs do not match',
                  ),
                  const SizedBox(height: 16),
                  FilledButton(
                    onPressed: _busy ? null : _submit,
                    child: _busy
                        ? const SizedBox(
                            width: 22,
                            height: 22,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Text('Create account'),
                  ),
                ],
              ),
            ),
          ),
        ),
      ),
    );
  }

  Widget _field(
    String key,
    String label, {
    TextInputType? keyboard,
    bool obscure = false,
    int? maxLength,
    bool digitsOnly = false,
    String? Function(String?)? validator,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 14),
      child: TextFormField(
        controller: _fields[key],
        keyboardType: keyboard,
        obscureText: obscure,
        maxLength: maxLength,
        inputFormatters: digitsOnly ? [FilteringTextInputFormatter.digitsOnly] : null,
        decoration: InputDecoration(
          labelText: label,
          counterText: maxLength == null ? null : '',
        ),
        validator: validator ??
            (v) => (v == null || v.trim().isEmpty) ? 'Required' : null,
      ),
    );
  }
}
