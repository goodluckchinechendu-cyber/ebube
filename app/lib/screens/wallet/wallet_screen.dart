import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../state/auth_controller.dart';

class WalletScreen extends StatefulWidget {
  const WalletScreen({super.key});

  @override
  State<WalletScreen> createState() => _WalletScreenState();
}

class _WalletScreenState extends State<WalletScreen> {
  final _amountCtrl = TextEditingController();
  final _bankCtrl = TextEditingController();
  final _acctNoCtrl = TextEditingController();
  final _acctNameCtrl = TextEditingController();
  final _api = ApiClient();
  bool _busy = false;

  @override
  void didChangeDependencies() {
    super.didChangeDependencies();
    final user = AuthScope.of(context).user;
    if (user != null && _bankCtrl.text.isEmpty) {
      _bankCtrl.text = user.bankName;
      _acctNoCtrl.text = user.accountNumber;
      _acctNameCtrl.text = user.accountName;
    }
  }

  @override
  void dispose() {
    _amountCtrl.dispose();
    _bankCtrl.dispose();
    _acctNoCtrl.dispose();
    _acctNameCtrl.dispose();
    super.dispose();
  }

  Future<void> _submitWithdrawal() async {
    final user = AuthScope.of(context).user;
    final amount = double.tryParse(_amountCtrl.text) ?? 0;
    if (amount <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please enter a valid withdrawal amount.')),
      );
      return;
    }
    if (user != null && amount > user.commissionBalance) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Amount exceeds available commission balance.')),
      );
      return;
    }
    if (_bankCtrl.text.trim().isEmpty ||
        _acctNoCtrl.text.trim().isEmpty ||
        _acctNameCtrl.text.trim().isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please fill in complete bank account payout details.')),
      );
      return;
    }

    setState(() => _busy = true);
    try {
      await _api.post('/withdrawals.php', body: {
        'action': 'request',
        'user_id': user?.id ?? 0,
        'amount': amount,
        'bank_name': _bankCtrl.text.trim(),
        'account_number': _acctNoCtrl.text.trim(),
        'account_name': _acctNameCtrl.text.trim(),
      });
      if (mounted) {
        await AuthScope.of(context).refreshWallet();
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Withdrawal requested successfully. Agent will be credited shortly.'),
            ),
          );
          _amountCtrl.clear();
        }
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Withdrawal request failed: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = AuthScope.of(context).user;
    final currency = NumberFormat.currency(symbol: '₦', decimalDigits: 2);

    return Scaffold(
      appBar: AppBar(
        title: const Text('Wallet & Commission'),
        actions: [
          IconButton(
            onPressed: () => AuthScope.of(context).refreshWallet(),
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh',
          ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
        children: [
          // Wallet balances first (MD: balances up, commission under)
          const Text(
            'Wallet Balances',
            style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: EcColors.muted),
          ),
          const SizedBox(height: 10),
          _balanceRow('VTU Airtime', currency.format(user?.vtuBalance ?? 0)),
          _balanceRow('MoMo Airtime', currency.format(user?.momoBalance ?? 0)),
          _balanceRow('Logical Airtime', currency.format(user?.logicalBalance ?? 0)),
          const SizedBox(height: 16),

          // Commission under wallet balances
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              gradient: const LinearGradient(
                begin: Alignment.topLeft,
                end: Alignment.bottomRight,
                colors: [EcColors.primary, EcColors.primaryDark],
              ),
              borderRadius: BorderRadius.circular(16),
              boxShadow: [
                BoxShadow(
                  color: EcColors.primary.withValues(alpha: 0.28),
                  blurRadius: 12,
                  offset: const Offset(0, 5),
                ),
              ],
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Row(
                  mainAxisAlignment: MainAxisAlignment.spaceBetween,
                  children: [
                    Text(
                      'Available Commission',
                      style: TextStyle(
                        fontSize: 13,
                        fontWeight: FontWeight.bold,
                        color: EcColors.ink,
                      ),
                    ),
                    Icon(Icons.payments, color: EcColors.ink, size: 22),
                  ],
                ),
                const SizedBox(height: 8),
                Text(
                  currency.format(user?.commissionBalance ?? 0),
                  style: const TextStyle(
                    fontSize: 24,
                    fontWeight: FontWeight.w900,
                    color: EcColors.ink,
                  ),
                ),
                const SizedBox(height: 4),
                const Text(
                  'Withdraw earned commissions to your bank account.',
                  style: TextStyle(fontSize: 12, color: EcColors.ink),
                ),
              ],
            ),
          ),
          const SizedBox(height: 16),

          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  const Text(
                    'Withdraw Commission',
                    style: TextStyle(fontSize: 16, fontWeight: FontWeight.bold),
                  ),
                  const SizedBox(height: 4),
                  const Text(
                    'Enter your payout account details below.',
                    style: TextStyle(fontSize: 12, color: EcColors.muted),
                  ),
                  const SizedBox(height: 16),
                  TextField(
                    controller: _amountCtrl,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                      labelText: 'Amount to withdraw (NGN)',
                      hintText: 'e.g. 5000',
                      prefixText: '₦ ',
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _bankCtrl,
                    decoration: const InputDecoration(
                      labelText: 'Bank Name',
                      hintText: 'e.g. Access Bank',
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _acctNoCtrl,
                    keyboardType: TextInputType.number,
                    decoration: const InputDecoration(
                      labelText: 'Account Number',
                      hintText: 'e.g. 0123456789',
                    ),
                  ),
                  const SizedBox(height: 12),
                  TextField(
                    controller: _acctNameCtrl,
                    decoration: const InputDecoration(
                      labelText: 'Account Name',
                      hintText: 'e.g. John Doe',
                    ),
                  ),
                  const SizedBox(height: 18),
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton(
                      onPressed: _busy ? null : _submitWithdrawal,
                      child: _busy
                          ? const SizedBox(
                              width: 20,
                              height: 20,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Text('Submit Withdrawal Request'),
                    ),
                  ),
                ],
              ),
            ),
          ),
        ],
      ),
    );
  }

  Widget _balanceRow(String label, String value) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 8),
      child: Card(
        margin: EdgeInsets.zero,
        child: Padding(
          padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
          child: Row(
            mainAxisAlignment: MainAxisAlignment.spaceBetween,
            children: [
              Flexible(
                child: Text(
                  label,
                  style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
                ),
              ),
              const SizedBox(width: 8),
              Text(
                value,
                style: const TextStyle(
                  fontWeight: FontWeight.w800,
                  fontSize: 14,
                  color: EcColors.ink,
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}
