import 'package:flutter/material.dart';
import 'package:package_info_plus/package_info_plus.dart';

import '../../config/theme.dart';
import '../../services/biometric_auth_service.dart';
import '../../services/session_inactivity_guard.dart';
import '../../state/auth_controller.dart';
import '../shell/main_shell.dart';
import 'announcements_screen.dart';
import 'approve_withdrawals_screen.dart';
import '../auth/change_transaction_pin_screen.dart';

class MoreScreen extends StatelessWidget {
  const MoreScreen({super.key});

  Future<void> _resetLoginPin(BuildContext context) async {
    final user = AuthScope.of(context).user;
    final email = (user?.email ?? '').trim();
    if (email.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('No email on your account for PIN reset.')),
      );
      return;
    }
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Reset login PIN'),
        content: Text(
          'Send a PIN reset link to $email?',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Send')),
        ],
      ),
    );
    if (ok != true || !context.mounted) return;
    try {
      await AuthScope.of(context).requestPinReset(email);
      if (!context.mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('If the account exists, a PIN reset email has been sent.'),
        ),
      );
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      }
    }
  }

  Future<void> _showAbout(BuildContext context) async {
    var version = '1.0.9';
    try {
      final info = await PackageInfo.fromPlatform();
      version = '${info.version}+${info.buildNumber}';
    } catch (_) {}
    if (!context.mounted) return;
    showAboutDialog(
      context: context,
      applicationName: 'EbubeConnect',
      applicationVersion: version,
      applicationLegalese: '© 2026 EbubeConnect. All rights reserved.',
    );
  }

  Future<void> _toggleBiometrics(BuildContext context) async {
    final bio = BiometricAuthService.instance;
    if (!await bio.isDeviceSupported()) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Biometrics are not available on this device.')),
        );
      }
      return;
    }
    if (await bio.isLoginEnabled()) {
      await bio.disableLoginBiometrics();
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Fingerprint login disabled.')),
        );
      }
      return;
    }
    if (!context.mounted) return;
    final loginCtrl = TextEditingController(text: AuthScope.of(context).user?.email ?? '');
    final pinCtrl = TextEditingController();
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Enable fingerprint login'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          children: [
            TextField(
              controller: loginCtrl,
              decoration: const InputDecoration(labelText: 'Email or phone'),
            ),
            TextField(
              controller: pinCtrl,
              obscureText: true,
              maxLength: 4,
              keyboardType: TextInputType.number,
              decoration: const InputDecoration(labelText: 'Login PIN', counterText: ''),
            ),
          ],
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Enable')),
        ],
      ),
    );
    if (ok != true) {
      loginCtrl.dispose();
      pinCtrl.dispose();
      return;
    }
    final enabled = await bio.enableLoginBiometrics(
      loginCtrl.text.trim(),
      pinCtrl.text.trim(),
    );
    loginCtrl.dispose();
    pinCtrl.dispose();
    if (!context.mounted) return;
    ScaffoldMessenger.of(context).showSnackBar(
      SnackBar(
        content: Text(
          enabled
              ? 'Fingerprint login enabled.'
              : 'Could not enable fingerprint login.',
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    final auth = AuthScope.of(context);
    final user = auth.user;

    return Scaffold(
      appBar: AppBar(
        title: const Text('More & Admin'),
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Row(
                children: [
                  CircleAvatar(
                    radius: 28,
                    backgroundColor: EcColors.primary.withValues(alpha: 0.15),
                    child: Text(
                      user?.fullName.isNotEmpty == true ? user!.fullName[0].toUpperCase() : 'A',
                      style: const TextStyle(
                        fontSize: 22,
                        fontWeight: FontWeight.w900,
                        color: EcColors.primaryDark,
                      ),
                    ),
                  ),
                  const SizedBox(width: 14),
                  Expanded(
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        Text(
                          user?.fullName.isNotEmpty == true ? user!.fullName : 'Sales Agent',
                          style: const TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                        ),
                        const SizedBox(height: 2),
                        Text(user?.email ?? '', style: const TextStyle(fontSize: 12, color: EcColors.muted)),
                        Text(user?.phone ?? '', style: const TextStyle(fontSize: 12, color: EcColors.muted)),
                      ],
                    ),
                  ),
                ],
              ),
            ),
          ),
          const SizedBox(height: 20),
          const Text(
            'Account & Security',
            style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: EcColors.muted),
          ),
          const SizedBox(height: 8),
          Card(
            child: Column(
              children: [
                ListTile(
                  leading: const Icon(Icons.lock_reset, color: EcColors.primaryDark),
                  title: const Text('Reset Login PIN'),
                  subtitle: const Text('Email a login PIN reset link'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => _resetLoginPin(context),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.pin_outlined, color: Colors.deepPurple),
                  title: const Text('Change Transaction PIN'),
                  subtitle: const Text('Update your 4-digit purchase PIN'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () {
                    Navigator.push(
                      context,
                      MaterialPageRoute(builder: (_) => const ChangeTransactionPinScreen()),
                    );
                  },
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.fingerprint, color: Colors.teal),
                  title: const Text('Fingerprint Login'),
                  subtitle: const Text('Enable or disable biometric sign-in'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => _toggleBiometrics(context),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.summarize_outlined, color: Colors.teal),
                  title: const Text('Statement of Account'),
                  subtitle: const Text('Generate wallet activity statement'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => MainShellScope.of(context)?.selectRoute('statement'),
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.campaign_outlined, color: Colors.orange),
                  title: const Text('Announcements'),
                  subtitle: const Text('Company notices and updates'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () {
                    Navigator.push(
                      context,
                      MaterialPageRoute(builder: (_) => const AnnouncementsScreen()),
                    );
                  },
                ),
                const Divider(height: 1),
                ListTile(
                  leading: const Icon(Icons.help_outline, color: Colors.blue),
                  title: const Text('Help & Support'),
                  subtitle: const Text('App version and legalese'),
                  trailing: const Icon(Icons.chevron_right),
                  onTap: () => _showAbout(context),
                ),
              ],
            ),
          ),
          if (user?.canManageUsers == true) ...[
            const SizedBox(height: 20),
            const Text(
              'Admin Tools',
              style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: EcColors.muted),
            ),
            const SizedBox(height: 8),
            Card(
              child: Column(
                children: [
                  ListTile(
                    leading: const Icon(Icons.manage_accounts, color: Colors.indigo),
                    title: const Text('Manage Users & Roles'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => MainShellScope.of(context)?.selectRoute('manage_users'),
                  ),
                  const Divider(height: 1),
                  ListTile(
                    leading: const Icon(Icons.add_card, color: Color(0xFFB45309)),
                    title: const Text('Fund Wallet'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => MainShellScope.of(context)?.selectRoute('fund_wallet'),
                  ),
                  const Divider(height: 1),
                  ListTile(
                    leading: const Icon(Icons.percent, color: Colors.indigo),
                    title: const Text('Commission & Discount'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => MainShellScope.of(context)?.selectRoute('commission_tiers'),
                  ),
                  if (user?.isSuperAdmin == true) ...[
                    const Divider(height: 1),
                    ListTile(
                      leading: const Icon(Icons.tune, color: Color(0xFF7C3AED)),
                      title: const Text('Transaction Limit Setting'),
                      subtitle: const Text('Per buy, wallet, SIM & user caps'),
                      trailing: const Icon(Icons.chevron_right),
                      onTap: () {
                        if (AuthScope.of(context).user?.isSuperAdmin != true) {
                          return;
                        }
                        MainShellScope.of(context)?.selectRoute('transaction_limits');
                      },
                    ),
                  ],
                  const Divider(height: 1),
                  ListTile(
                    leading: const Icon(Icons.inventory_2_outlined, color: Color(0xFF0F766E)),
                    title: const Text('Wallet Holds'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () => MainShellScope.of(context)?.selectRoute('wallet_holds'),
                  ),
                  const Divider(height: 1),
                  ListTile(
                    leading: const Icon(Icons.approval, color: Colors.teal),
                    title: const Text('Approve Commission Withdrawals'),
                    trailing: const Icon(Icons.chevron_right),
                    onTap: () {
                      Navigator.push(
                        context,
                        MaterialPageRoute(builder: (_) => const ApproveWithdrawalsScreen()),
                      );
                    },
                  ),
                ],
              ),
            ),
          ],
          const SizedBox(height: 28),
          OutlinedButton.icon(
            style: OutlinedButton.styleFrom(
              foregroundColor: EcColors.danger,
              side: const BorderSide(color: EcColors.danger),
              padding: const EdgeInsets.symmetric(vertical: 14),
            ),
            onPressed: () async {
              final ok = await showDialog<bool>(
                context: context,
                builder: (ctx) => AlertDialog(
                  title: const Text('Sign Out'),
                  content: const Text('Are you sure you want to sign out?'),
                  actions: [
                    TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
                    FilledButton(
                      style: FilledButton.styleFrom(backgroundColor: EcColors.danger),
                      onPressed: () => Navigator.pop(ctx, true),
                      child: const Text('Sign Out'),
                    ),
                  ],
                ),
              );
              if (ok == true) {
                await SessionInactivityGuard.instance.disable();
                await auth.logout();
              }
            },
            icon: const Icon(Icons.logout),
            label: const Text('Sign Out', style: TextStyle(fontWeight: FontWeight.bold)),
          ),
        ],
      ),
    );
  }
}
