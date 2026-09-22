import 'dart:math';

import 'package:flutter/material.dart';
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

class BuyDataScreen extends StatefulWidget {
  const BuyDataScreen({super.key});

  @override
  State<BuyDataScreen> createState() => _BuyDataScreenState();
}

class _BuyDataScreenState extends State<BuyDataScreen> {
  final _formKey = GlobalKey<FormState>();
  final _phoneCtrl = TextEditingController();
  late final _vtu = VtuService();
  final _rng = Random.secure();

  final Network _network = Network.mtn;
  String _walletProduct = 'vtu';
  DataPlan? _selectedPlan;
  List<DataPlan> _plans = const [];
  bool _loadingPlans = false;
  bool _loading = false;
  String? _plansError;
  double? _walletCharge;

  final _money = NumberFormat.currency(symbol: '₦', decimalDigits: 0);

  String _newClientRequestId() {
    final ts = DateTime.now().microsecondsSinceEpoch;
    final n = _rng.nextInt(0x7fffffff);
    return 'data-$ts-$n';
  }

  @override
  void initState() {
    super.initState();
    _loadPlans();
  }

  @override
  void dispose() {
    _phoneCtrl.dispose();
    _vtu.dispose();
    super.dispose();
  }

  Future<void> _loadPlans() async {
    setState(() {
      _loadingPlans = true;
      _plansError = null;
      _selectedPlan = null;
      _plans = const [];
      _walletCharge = null;
    });
    try {
      final plans = await _vtu.fetchPlans(network: _network);
      if (!mounted) return;
      setState(() {
        _plans = plans;
        _selectedPlan = plans.isNotEmpty ? plans.first : null;
        _loadingPlans = false;
        if (plans.isEmpty) {
          _plansError = 'No plans returned for ${_network.label}.';
        }
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loadingPlans = false;
        _plansError = e.toString();
      });
    }
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
    if (_selectedPlan == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Please select a data plan')),
      );
      return;
    }

    final user = AuthScope.of(context).user;
    if (user == null) return;

    final plan = _selectedPlan!;
    final walletBal = switch (_walletProduct) {
      'momo' => user.momoBalance,
      'logical' => user.logicalBalance,
      _ => user.vtuBalance,
    };
    final requiredCharge = _walletCharge ?? plan.price;
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
            _ConfirmRow('Plan', plan.label),
            if (plan.validity.isNotEmpty) _ConfirmRow('Validity', plan.validity),
            _ConfirmRow('Amount', _money.format(plan.price)),
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
      var res = await _vtu.buyData(DataRequest(
        phone: _phoneCtrl.text.trim(),
        network: _network,
        planId: plan.id,
        planAmount: plan.price,
        planName: plan.label,
        userId: user.id,
        walletProduct: _walletProduct,
        transactionPin: txnPin,
        clientRequestId: clientRequestId,
      ));

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
        productLabel: plan.label,
        phone: _phoneCtrl.text.trim(),
        network: 'MTN',
        faceAmount: plan.price,
      );
      if (mounted && res.success) _phoneCtrl.clear();
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
          productLabel: plan.label,
          phone: _phoneCtrl.text.trim(),
          network: 'MTN',
          faceAmount: plan.price,
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
        title: const Text('Subscribe Data'),
        actions: [
          IconButton(
            tooltip: 'Refresh plans',
            onPressed: _loadingPlans ? null : _loadPlans,
            icon: const Icon(Icons.refresh),
          ),
        ],
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
              if (_selectedPlan != null)
                PricingPreviewCard(
                  faceAmount: _selectedPlan!.price,
                  productType: 'data',
                  onChargeResolved: (charge) {
                    if (!mounted) return;
                    setState(() => _walletCharge = charge);
                  },
                ),
              const SizedBox(height: 24),
              const Text('Select Plan',
                  style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
              const SizedBox(height: 12),
              if (_loadingPlans)
                const Padding(
                  padding: EdgeInsets.symmetric(vertical: 24),
                  child: Center(child: CircularProgressIndicator()),
                )
              else if (_plansError != null)
                Container(
                  width: double.infinity,
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: EcColors.danger.withValues(alpha: 0.08),
                    borderRadius: BorderRadius.circular(12),
                  ),
                  child: Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Text(_plansError!, style: const TextStyle(color: EcColors.danger)),
                      TextButton(
                        onPressed: _loadPlans,
                        child: const Text('Retry'),
                      ),
                    ],
                  ),
                )
              else
                ListView.separated(
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  itemCount: _plans.length,
                  separatorBuilder: (context, index) => const SizedBox(height: 8),
                  itemBuilder: (context, i) {
                    final plan = _plans[i];
                    final selected = _selectedPlan?.id == plan.id;
                    return GestureDetector(
                      onTap: () => setState(() => _selectedPlan = plan),
                      child: AnimatedContainer(
                        duration: const Duration(milliseconds: 180),
                        padding: const EdgeInsets.all(14),
                        decoration: BoxDecoration(
                          color: selected
                              ? EcColors.primary.withValues(alpha: 0.12)
                              : EcColors.card,
                          borderRadius: BorderRadius.circular(12),
                          border: Border.all(
                            color: selected
                                ? EcColors.primaryDark
                                : Colors.grey.shade200,
                            width: selected ? 2 : 1,
                          ),
                        ),
                        child: Row(
                          children: [
                            Container(
                              padding: const EdgeInsets.all(8),
                              decoration: BoxDecoration(
                                color: selected
                                    ? EcColors.primary
                                    : Colors.grey.shade100,
                                borderRadius: BorderRadius.circular(8),
                              ),
                              child: Icon(
                                Icons.wifi,
                                size: 20,
                                color: selected ? EcColors.ink : EcColors.muted,
                              ),
                            ),
                            const SizedBox(width: 12),
                            Expanded(
                              child: Column(
                                crossAxisAlignment: CrossAxisAlignment.start,
                                children: [
                                  Text(plan.label,
                                      style: const TextStyle(
                                          fontWeight: FontWeight.w700,
                                          fontSize: 15)),
                                  Text(
                                    plan.validity.isEmpty
                                        ? 'Plan ID: ${plan.id}'
                                        : plan.validity,
                                    style: const TextStyle(
                                        color: EcColors.muted, fontSize: 13),
                                  ),
                                ],
                              ),
                            ),
                            Text(
                              _money.format(plan.price),
                              style: TextStyle(
                                fontWeight: FontWeight.w800,
                                fontSize: 16,
                                color: selected
                                    ? EcColors.primaryDark
                                    : EcColors.ink,
                              ),
                            ),
                            const SizedBox(width: 8),
                            Icon(
                              selected
                                  ? Icons.radio_button_checked
                                  : Icons.radio_button_unchecked,
                              color: selected
                                  ? EcColors.primaryDark
                                  : EcColors.muted,
                              size: 20,
                            ),
                          ],
                        ),
                      ),
                    );
                  },
                ),
              const SizedBox(height: 32),
              FilledButton.icon(
                onPressed: (_loading || _loadingPlans || _selectedPlan == null)
                    ? null
                    : _submit,
                icon: _loading
                    ? const SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(
                            strokeWidth: 2, color: EcColors.ink),
                      )
                    : const Icon(Icons.wifi),
                label: Text(_loading
                    ? 'Processing…'
                    : _selectedPlan != null
                        ? 'Buy ${_selectedPlan!.label} — ${_money.format(_selectedPlan!.price)}'
                        : 'Subscribe Data'),
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
                        'Plans load live. Purchases debit your selected injected wallet (VTU Airtime / MoMo Airtime / Logical Airtime).',
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
