import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../config/theme.dart';
import '../models/user.dart';
import '../services/api_client.dart';
import '../state/auth_controller.dart';

/// Opens Register Customer. Super Admin creates a real app user (Manage Users);
/// others create a CRM directory contact only.
Future<bool> showRegisterCustomerDialog(BuildContext context) async {
  final actor = AuthScope.of(context).user;
  final result = await showDialog<bool>(
    context: context,
    builder: (ctx) => _RegisterCustomerDialog(actor: actor),
  );
  return result == true;
}

class _RegisterCustomerDialog extends StatefulWidget {
  const _RegisterCustomerDialog({this.actor});

  final AgentUser? actor;

  @override
  State<_RegisterCustomerDialog> createState() => _RegisterCustomerDialogState();
}

class _RegisterCustomerDialogState extends State<_RegisterCustomerDialog> {
  final _nameCtrl = TextEditingController();
  final _phoneCtrl = TextEditingController();
  final _addressCtrl = TextEditingController();
  final _emailCtrl = TextEditingController();
  final _pinCtrl = TextEditingController();
  final _api = ApiClient();

  String _gender = 'Male';
  bool _external = true; // SA default: external
  bool _saving = false;

  bool get _isSa => widget.actor?.isSuperAdmin == true;

  @override
  void dispose() {
    _nameCtrl.dispose();
    _phoneCtrl.dispose();
    _addressCtrl.dispose();
    _emailCtrl.dispose();
    _pinCtrl.dispose();
    _api.close();
    super.dispose();
  }

  Future<void> _save() async {
    final name = _nameCtrl.text.trim();
    final phone = _phoneCtrl.text.trim();
    final address = _addressCtrl.text.trim();
    final email = _emailCtrl.text.trim();
    final pin = _pinCtrl.text.trim();

    if (name.isEmpty || phone.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Name and phone are required.')),
      );
      return;
    }

    if (_isSa) {
      if (email.isEmpty || !email.contains('@')) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('A valid email is required for app login.')),
        );
        return;
      }
      if (!RegExp(r'^\d{4}$').hasMatch(pin)) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Login PIN must be exactly 4 digits.')),
        );
        return;
      }
    }

    setState(() => _saving = true);
    try {
      if (_isSa) {
        await _api.post('/users_create.php', body: {
          'full_name': name,
          'phone': phone,
          'location': address,
          'address': address,
          'email': email,
          'password': pin,
          'gender': _gender,
          'is_external': _external,
        });
      } else {
        final userId = widget.actor?.id ?? 0;
        await _api.post('/customers.php', body: {
          'action': 'create',
          'registered_by_user_id': userId,
          'full_name': name,
          'phone': phone,
          'address': address,
          'email': email,
          'gender': _gender,
        });
      }
      if (!mounted) return;
      Navigator.pop(context, true);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            _isSa
                ? (_external
                    ? 'External customer account created. They appear in Manage Users.'
                    : 'Internal customer account created. They appear in Manage Users.')
                : 'Customer registered successfully.',
          ),
        ),
      );
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Failed to save: $e')),
      );
    } finally {
      if (mounted) setState(() => _saving = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(_isSa ? 'Register Customer Account' : 'Register Customer'),
      content: SingleChildScrollView(
        child: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            if (_isSa) ...[
              const Text(
                'Creates a login account under you. Choose Internal or External, then they show in Manage Users & Roles.',
                style: TextStyle(fontSize: 13, color: EcColors.muted, height: 1.35),
              ),
              const SizedBox(height: 12),
              const Text('Register as', style: TextStyle(fontWeight: FontWeight.w700)),
              const SizedBox(height: 8),
              SegmentedButton<bool>(
                segments: const [
                  ButtonSegment(value: true, label: Text('External')),
                  ButtonSegment(value: false, label: Text('Internal')),
                ],
                selected: {_external},
                onSelectionChanged: _saving
                    ? null
                    : (s) => setState(() => _external = s.first),
              ),
              const SizedBox(height: 6),
              Text(
                _external
                    ? 'Hidden from Admin lists; funded by Wallet ID.'
                    : 'Visible to Admin under your hierarchy.',
                style: const TextStyle(fontSize: 12, color: EcColors.muted),
              ),
              const SizedBox(height: 12),
            ],
            TextField(
              controller: _nameCtrl,
              enabled: !_saving,
              textCapitalization: TextCapitalization.words,
              decoration: const InputDecoration(labelText: 'Full Name *'),
            ),
            const SizedBox(height: 10),
            TextField(
              controller: _phoneCtrl,
              enabled: !_saving,
              keyboardType: TextInputType.phone,
              decoration: const InputDecoration(labelText: 'Phone Number *'),
            ),
            const SizedBox(height: 10),
            if (_isSa) ...[
              TextField(
                controller: _emailCtrl,
                enabled: !_saving,
                keyboardType: TextInputType.emailAddress,
                decoration: const InputDecoration(labelText: 'Email (login) *'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: _pinCtrl,
                enabled: !_saving,
                obscureText: true,
                keyboardType: TextInputType.number,
                maxLength: 4,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                decoration: const InputDecoration(
                  labelText: 'Login PIN (4 digits) *',
                  counterText: '',
                ),
              ),
              const SizedBox(height: 10),
            ],
            TextField(
              controller: _addressCtrl,
              enabled: !_saving,
              decoration: InputDecoration(
                labelText: _isSa ? 'Location / Address' : 'Address / City',
              ),
            ),
            if (!_isSa) ...[
              const SizedBox(height: 10),
              TextField(
                controller: _emailCtrl,
                enabled: !_saving,
                keyboardType: TextInputType.emailAddress,
                decoration: const InputDecoration(labelText: 'Email (Optional)'),
              ),
            ],
            const SizedBox(height: 10),
            DropdownButtonFormField<String>(
              initialValue: _gender,
              decoration: const InputDecoration(labelText: 'Gender'),
              items: const [
                DropdownMenuItem(value: 'Male', child: Text('Male')),
                DropdownMenuItem(value: 'Female', child: Text('Female')),
              ],
              onChanged: _saving
                  ? null
                  : (v) => setState(() => _gender = v ?? 'Male'),
            ),
          ],
        ),
      ),
      actions: [
        TextButton(
          onPressed: _saving ? null : () => Navigator.pop(context, false),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: _saving ? null : _save,
          child: _saving
              ? const SizedBox(
                  width: 18,
                  height: 18,
                  child: CircularProgressIndicator(strokeWidth: 2),
                )
              : Text(_isSa ? 'Create Account' : 'Save Customer'),
        ),
      ],
    );
  }
}
