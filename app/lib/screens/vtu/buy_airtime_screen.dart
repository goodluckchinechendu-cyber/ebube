import 'dart:math';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';

import '../../config/theme.dart';
import '../../models/vtu_models.dart';
import '../../services/vtu_service.dart';
import '../../state/auth_controller.dart';
import '../../widgets/customer_phone_picker.dart';
import '../../widgets/network_badge.dart';
import '../../widgets/pricing_preview_card.dart';
import '../../widgets/transaction_pin_prompt.dart';
import '../../widgets/vtu_purchase_result.dart';
import '../../widgets/wallet_source_picker.dart';

class BuyAirtimeScreen extends StatefulWidget {
  const BuyAirtimeScreen({super.key});

  @override
  State<BuyAirtimeScreen> createState() => _BuyAirtimeScreenState();
}

class _BuyAirtimeScreenState extends State<BuyAirtimeScreen> {
  final _formKey = GlobalKey<FormState>();
  final _phoneCtrl = TextEditingController();
  final _amountCtrl = TextEditingController();
  late final _vtu = VtuService();
  final _rng = Random.secure();

  final Network _network = Network.mtn;
  String _walletProduct = 'vtu';
  bool _loading = false;
  double _faceAmount = 0;
  double? _walletCharge;

  static const _quickAmounts = [50, 100, 200, 500, 1000, 2000];
  final _money = NumberFormat.currency(symbol: '₦', decimalDigits: 0);

  String _newClientRequestId() {
    final ts = DateTime.now().microsecondsSinceEpoch;
    final n = _rng.nextInt(0x7fffffff);
    return 'air-$ts-$n';
  }

  @override
  void dispose() {
    _phoneCtrl.dispose();
    _amountCtrl.dispose();
    _vtu.dispose();
    super.dispose();
  }

  Future<VtuResponse> _finalize(VtuResponse res) async {
    if (res.isUncertain) return res;
    if (!res.isProcessing || res.reference == null || res.reference!.isEmpty) {
      return res;
    }
    return _vtu.waitForFinalStatus(res.reference!);
  }

  Future<void> _submit() async {
    if (!_formKey.currentState!.validate()) return;

    final user = AuthScope.of(context).user;
    if (user == null) return;

    final amount = int.tryParse(_amountCtrl.text.trim()) ?? 0;
    final walletBal = switch (_walletProduct) {
      'momo' => user.momoBalance,
      'logical' => user.logicalBalance,
      _ => user.vtuBalance,
    };
    final requiredCharge = _walletCharge ?? amount.toDouble();
    if (requiredCharge > walletBal + 0.00001) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            'Insufficient ${_walletProduct.toUpperCase()} balance (${_money.format(walletBal)}).',
          ),
        ),
      );
      return;
    }

    final confirmed = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Confirm purchase'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            _ConfirmRow('Network', _network.label),
            _ConfirmRow('Phone', _phoneCtrl.text.trim()),
            _ConfirmRow('Amount', _money.format(amount)),
            _ConfirmRow('Wallet', _walletProduct.toUpperCase()),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx, false),
            child: const Text('Cancel'),
          ),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Buy Now'),
          ),
        ],
      ),
    );

    if (confirmed != true || !mounted) return;

    final txnPin = await promptTransactionPin(context);
    if (txnPin == null || !mounted) return;

    final clientRequestId = _newClientRequestId();
    setState(() => _loading = true);
    try {
      var res = await _vtu.buyAirtime(AirtimeRequest(
        phone: _phoneCtrl.text.trim(),
        network: _network,
        amount: amount,
        userId: user.id,
        walletProduct: _walletProduct,
        transactionPin: txnPin,
        clientRequestId: clientRequestId,
      ));

      // Auto-poll only while provider is still processing.
      if (res.isProcessing && !res.isUncertain) {
        if (mounted) {
          ScaffoldMessenger.of(context).showSnackBar(
            const SnackBar(
              content: Text('Processing… waiting for provider confirmation'),
              duration: Duration(seconds: 2),
            ),
          );
        }
        res = await _finalize(res);
      }

      if (!mounted) return;
      await AuthScope.of(context).refreshWallet();
      if (!mounted) return;
      await showVtuPurchaseResult(
        context,
        res: res,
        productLabel: 'MTN Airtime',
        phone: _phoneCtrl.text.trim(),
        network: 'MTN',
        faceAmount: amount.toDouble(),
      );
      if (mounted && res.success) {
        _phoneCtrl.clear();
        _amountCtrl.clear();
        setState(() {
          _faceAmount = 0;
          _walletCharge = null;
        });
      }
    } catch (e) {
      if (mounted) {
        final msg = '$e';
        final lower = msg.toLowerCase();
        final uncertain = lower.contains('timeout') ||
            lower.contains('timed out') ||
            lower.contains('uncertain') ||
            lower.contains('do not buy again') ||
            lower.contains('no confirmed response');
        await showVtuPurchaseResult(
          context,
          res: VtuResponse(
            success: false,
            message: msg.contains('Session') || msg.contains('sign in')
                ? '$msg Please log out and sign in again.'
                : uncertain
                    ? 'No confirmed response yet. Do not retry — open Transactions; delivery may still complete.'
                    : msg,
            status: uncertain ? 'uncertain' : 'failed',
          ),
          productLabel: 'MTN Airtime',
          phone: _phoneCtrl.text.trim(),
          network: 'MTN',
          faceAmount: amount.toDouble(),
        );
      }
    } finally {
      if (mounted) setState(() => _loading = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Top Up Airtime'),
      ),
      body: SingleChildScrollView(
        padding: const EdgeInsets.fromLTRB(20, 24, 20, 40),
        child: Form(
          key: _formKey,
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const Text('Network',
                  style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
              const SizedBox(height: 12),
              Container(
                width: double.infinity,
                padding: const EdgeInsets.symmetric(vertical: 16),
                decoration: BoxDecoration(
                  color: EcColors.card,
                  borderRadius: BorderRadius.circular(14),
                ),
                child: const Center(
                  child: NetworkBadge(network: 'MTN', size: 96, showLabel: false),
                ),
              ),
              const SizedBox(height: 24),
              WalletSourcePicker(
                user: AuthScope.of(context).user,
                selected: _walletProduct,
                onChanged: (v) => setState(() => _walletProduct = v),
              ),
              const SizedBox(height: 24),
              const Text('Phone Number',
                  style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
              const SizedBox(height: 8),
              CustomerPhonePicker(controller: _phoneCtrl),
              const SizedBox(height: 24),
              const Text('Amount (₦)',
                  style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
              const SizedBox(height: 8),
              TextFormField(
                controller: _amountCtrl,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                decoration: InputDecoration(
                  hintText: 'e.g. 1000',
                  hintStyle: TextStyle(
                    color: EcColors.muted.withValues(alpha: 0.55),
                    fontWeight: FontWeight.w400,
                  ),
                  prefixIcon: const Icon(Icons.payments_outlined),
                  prefixText: '₦ ',
                ),
                onChanged: (v) {
                  setState(() => _faceAmount = double.tryParse(v.trim()) ?? 0);
                },
                validator: (v) {
                  final val = int.tryParse(v?.trim() ?? '');
                  if (val == null || val <= 0) return 'Enter a valid amount';
                  if (val < 50) return 'Minimum airtime is ₦50';
                  if (val > 500000) return 'Amount is too high';
                  return null;
                },
              ),
              PricingPreviewCard(
                faceAmount: _faceAmount,
                productType: 'airtime',
                onChargeResolved: (charge) {
                  if (!mounted) return;
                  setState(() => _walletCharge = charge);
                },
              ),
              const SizedBox(height: 16),
              Wrap(
                spacing: 8,
                runSpacing: 8,
                children: _quickAmounts.map((a) {
                  return ActionChip(
                    label: Text(_money.format(a)),
                    onPressed: () {
                      _amountCtrl.text = '$a';
                      setState(() => _faceAmount = a.toDouble());
                      _formKey.currentState?.validate();
                    },
                  );
                }).toList(),
              ),
              const SizedBox(height: 32),
              FilledButton.icon(
                onPressed: _loading ? null : _submit,
                icon: _loading
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: EcColors.ink),
                      )
                    : const Icon(Icons.flash_on),
                label: Text(_loading ? 'Processing…' : 'Top Up Airtime'),
              ),
              const SizedBox(height: 20),
              Container(
                padding: const EdgeInsets.all(14),
                decoration: BoxDecoration(
                  color: EcColors.primary.withValues(alpha: 0.1),
                  borderRadius: BorderRadius.circular(12),
                  border: Border.all(
                      color: EcColors.primary.withValues(alpha: 0.3)),
                ),
                child: const Row(
                  children: [
                    Icon(Icons.info_outline,
                        color: EcColors.primaryDark, size: 18),
                    SizedBox(width: 10),
                    Expanded(
                      child: Text(
                        'Balances shown are your injected VTU Airtime / MoMo Airtime / Logical Airtime wallets. Purchases debit the wallet you select.',
                        style: TextStyle(fontSize: 13, color: EcColors.ink),
                      ),
                    ),
                  ],
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _ConfirmRow extends StatelessWidget {
  const _ConfirmRow(this.label, this.value);
  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 4),
      child: Row(
        children: [
          SizedBox(
            width: 70,
            child: Text(label,
                style: const TextStyle(color: EcColors.muted, fontSize: 13)),
          ),
          Expanded(
            child: Text(value,
                style: const TextStyle(fontWeight: FontWeight.w700)),
          ),
        ],
      ),
    );
  }
}
