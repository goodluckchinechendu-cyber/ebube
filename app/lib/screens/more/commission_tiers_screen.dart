import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';

import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../state/auth_controller.dart';

class CommissionTiersScreen extends StatefulWidget {
  const CommissionTiersScreen({super.key});

  @override
  State<CommissionTiersScreen> createState() => _CommissionTiersScreenState();
}

class _CommissionTiersScreenState extends State<CommissionTiersScreen> {
  final _api = ApiClient();
  final _money = NumberFormat.currency(symbol: '₦', decimalDigits: 0);
  bool _loading = true;
  String? _error;
  List<_Tier> _tiers = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await _api.post('commission_tiers.php', body: {'action': 'list'});
      final raw = data['tiers'];
      final list = <_Tier>[];
      if (raw is List) {
        for (final item in raw) {
          if (item is Map) {
            list.add(_Tier.fromJson(Map<String, dynamic>.from(item)));
          }
        }
      }
      if (mounted) {
        setState(() {
          _tiers = list;
          _loading = false;
        });
      }
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = '$e';
          _loading = false;
        });
      }
    }
  }

  Future<void> _delete(_Tier tier) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete rule?'),
        content: Text('Remove this ${tier.productType} commission/discount rule?'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Delete')),
        ],
      ),
    );
    if (ok != true || !mounted) return;
    try {
      await _api.post('commission_tiers.php', body: {'action': 'delete', 'id': tier.id});
      await _load();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      }
    }
  }

  Future<void> _edit([_Tier? existing]) async {
    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => _TierEditorDialog(existing: existing),
    );
    if (saved == true) await _load();
  }

  String _moneyOrPct(String type, double value) {
    if (value <= 0) return 'None';
    return type == 'percent' ? '${value}%' : _money.format(value);
  }

  @override
  Widget build(BuildContext context) {
    final user = AuthScope.of(context).user;
    if (user == null || !user.canManageUsers) {
      return Scaffold(
        appBar: AppBar(title: const Text('Commission & Discount')),
        body: const Center(child: Text('Admin access required')),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: const Text('Commission & Discount'),
        actions: [
          IconButton(onPressed: _loading ? null : _load, icon: const Icon(Icons.refresh)),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: () => _edit(),
        icon: const Icon(Icons.add),
        label: const Text('Add rule'),
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Padding(padding: const EdgeInsets.all(24), child: Text(_error!, textAlign: TextAlign.center)))
              : ListView(
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 96),
                  children: [
                    const Text(
                      'These rules control what is taken from injected wallets and what commission is earned. '
                      'They override SMobile’s billed amount for wallet charging.',
                      style: TextStyle(color: EcColors.muted, height: 1.35),
                    ),
                    const SizedBox(height: 16),
                    if (_tiers.isEmpty)
                      const Padding(
                        padding: EdgeInsets.only(top: 40),
                        child: Text(
                          'No rules yet.\nExample: Airtime ₦50–₦999 → ₦1 discount + ₦1 commission.',
                          textAlign: TextAlign.center,
                        ),
                      )
                    else
                      ..._tiers.map((t) {
                        final range = t.maxAmount == null
                            ? '${_money.format(t.minAmount)}+'
                            : '${_money.format(t.minAmount)} – ${_money.format(t.maxAmount)}';
                        return Padding(
                          padding: const EdgeInsets.only(bottom: 10),
                          child: Material(
                            color: Colors.white,
                            borderRadius: BorderRadius.circular(12),
                            child: ListTile(
                              shape: RoundedRectangleBorder(
                                borderRadius: BorderRadius.circular(12),
                                side: BorderSide(color: Colors.black.withValues(alpha: 0.06)),
                              ),
                              title: Text(
                                '${t.productType.toUpperCase()} · $range',
                                style: const TextStyle(fontWeight: FontWeight.w700),
                              ),
                              subtitle: Text(
                                '${t.appliesToLabel}\n'
                                'Discount: ${_moneyOrPct(t.discountType, t.discountValue)} · '
                                'Commission: ${_moneyOrPct(t.commissionType, t.commissionValue)}'
                                '${t.isActive ? '' : ' · inactive'}',
                              ),
                              isThreeLine: true,
                              trailing: Row(
                                mainAxisSize: MainAxisSize.min,
                                children: [
                                  IconButton(onPressed: () => _edit(t), icon: const Icon(Icons.edit_outlined)),
                                  IconButton(
                                    onPressed: () => _delete(t),
                                    icon: const Icon(Icons.delete_outline, color: EcColors.danger),
                                  ),
                                ],
                              ),
                            ),
                          ),
                        );
                      }),
                  ],
                ),
    );
  }
}

class _Tier {
  _Tier({
    required this.id,
    required this.productType,
    required this.appliesTo,
    required this.appliesToLabel,
    required this.minAmount,
    required this.maxAmount,
    required this.commissionType,
    required this.commissionValue,
    required this.discountType,
    required this.discountValue,
    required this.isActive,
  });

  final int id;
  final String productType;
  final String appliesTo;
  final String appliesToLabel;
  final double minAmount;
  final double? maxAmount;
  final String commissionType;
  final double commissionValue;
  final String discountType;
  final double discountValue;
  final bool isActive;

  factory _Tier.fromJson(Map<String, dynamic> json) {
    double numOf(dynamic v) =>
        v is num ? v.toDouble() : double.tryParse('$v') ?? 0;
    double? max;
    final rawMax = json['max_amount'];
    if (rawMax != null && '$rawMax'.trim().isNotEmpty) {
      max = numOf(rawMax);
    }
    return _Tier(
      id: (json['id'] as num?)?.toInt() ?? 0,
      productType: '${json['product_type'] ?? 'airtime'}',
      appliesTo: '${json['applies_to'] ?? 'all'}',
      appliesToLabel: '${json['applies_to_label'] ?? 'All accounts'}',
      minAmount: numOf(json['min_amount']),
      maxAmount: max,
      commissionType: '${json['commission_type'] ?? 'fixed'}',
      commissionValue: numOf(json['commission_value']),
      discountType: '${json['discount_type'] ?? 'fixed'}',
      discountValue: numOf(json['discount_value']),
      isActive: json['is_active'] != false && json['is_active'] != 0,
    );
  }
}

class _TierEditorDialog extends StatefulWidget {
  const _TierEditorDialog({this.existing});
  final _Tier? existing;

  @override
  State<_TierEditorDialog> createState() => _TierEditorDialogState();
}

class _TierEditorDialogState extends State<_TierEditorDialog> {
  final _api = ApiClient();
  final _minCtrl = TextEditingController();
  final _maxCtrl = TextEditingController();
  final _commissionCtrl = TextEditingController();
  final _discountCtrl = TextEditingController();
  String _product = 'airtime';
  String _appliesTo = 'all';
  String _commissionType = 'fixed';
  String _discountType = 'fixed';
  bool _active = true;
  bool _busy = false;
  bool _noMax = true;

  @override
  void initState() {
    super.initState();
    final e = widget.existing;
    if (e != null) {
      _product = e.productType;
      _appliesTo = e.appliesTo;
      _commissionType = e.commissionType;
      _discountType = e.discountType;
      _active = e.isActive;
      _minCtrl.text = e.minAmount.toStringAsFixed(0);
      if (e.maxAmount != null) {
        _noMax = false;
        _maxCtrl.text = e.maxAmount!.toStringAsFixed(0);
      }
      _commissionCtrl.text = e.commissionValue.toString();
      _discountCtrl.text = e.discountValue.toString();
    } else {
      _minCtrl.text = '50';
      _commissionCtrl.text = '0';
      _discountCtrl.text = '0';
    }
  }

  @override
  void dispose() {
    _minCtrl.dispose();
    _maxCtrl.dispose();
    _commissionCtrl.dispose();
    _discountCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    final min = double.tryParse(_minCtrl.text.trim()) ?? -1;
    final commission = double.tryParse(_commissionCtrl.text.trim()) ?? -1;
    final discount = double.tryParse(_discountCtrl.text.trim()) ?? -1;
    double? max;
    if (!_noMax) {
      max = double.tryParse(_maxCtrl.text.trim());
      if (max == null) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Enter a valid max amount, or enable “No upper limit”')),
        );
        return;
      }
    }
    if (min < 0 || commission < 0 || discount < 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Enter valid amounts')),
      );
      return;
    }
    if (commission <= 0 && discount <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Set a commission and/or a discount above zero')),
      );
      return;
    }

    setState(() => _busy = true);
    try {
      await _api.post('commission_tiers.php', body: {
        'action': 'save',
        if (widget.existing != null) 'id': widget.existing!.id,
        'product_type': _product,
        'applies_to': _appliesTo,
        'min_amount': min,
        'max_amount': max,
        'commission_type': _commissionType,
        'commission_value': commission,
        'discount_type': _discountType,
        'discount_value': discount,
        'is_active': _active,
      });
      if (mounted) Navigator.pop(context, true);
    } on ApiException catch (e) {
      if (e.statusCode == 409 && e.body?['code'] == 'tier_overlap') {
        final overlaps = (e.body?['overlaps'] as List? ?? []).length;
        final ok = await showDialog<bool>(
          context: context,
          builder: (ctx) => AlertDialog(
            title: const Text('Overlapping rule'),
            content: Text(
              '${e.message}\n\n'
              '${overlaps > 0 ? 'Found $overlaps overlapping rule(s). ' : ''}'
              'Save anyway?',
            ),
            actions: [
              TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
              FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Save anyway')),
            ],
          ),
        );
        if (ok == true && mounted) {
          try {
            await _api.post('commission_tiers.php', body: {
              'action': 'save',
              if (widget.existing != null) 'id': widget.existing!.id,
              'product_type': _product,
              'applies_to': _appliesTo,
              'min_amount': min,
              'max_amount': max,
              'commission_type': _commissionType,
              'commission_value': commission,
              'discount_type': _discountType,
              'discount_value': discount,
              'is_active': _active,
              'confirm_overlap': true,
            });
            if (mounted) Navigator.pop(context, true);
          } catch (e2) {
            if (mounted) {
              ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e2')));
            }
          }
        }
      } else if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
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
    return AlertDialog(
      title: Text(widget.existing == null ? 'Add commission & discount' : 'Edit rule'),
      content: SingleChildScrollView(
        child: SizedBox(
          width: 380,
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              DropdownButtonFormField<String>(
                initialValue: _product,
                decoration: const InputDecoration(labelText: 'Product'),
                items: const [
                  DropdownMenuItem(value: 'airtime', child: Text('Airtime')),
                  DropdownMenuItem(value: 'data', child: Text('Data')),
                ],
                onChanged: _busy ? null : (v) => setState(() => _product = v ?? 'airtime'),
              ),
              const SizedBox(height: 10),
              DropdownButtonFormField<String>(
                initialValue: _appliesTo == 'super_admin' &&
                        AuthScope.of(context).user?.isSuperAdmin != true
                    ? 'all'
                    : _appliesTo,
                decoration: const InputDecoration(labelText: 'Account type'),
                items: [
                  const DropdownMenuItem(value: 'all', child: Text('All accounts')),
                  const DropdownMenuItem(value: 'agent', child: Text('Agents only')),
                  const DropdownMenuItem(value: 'admin', child: Text('Admins only')),
                  if (AuthScope.of(context).user?.isSuperAdmin == true)
                    const DropdownMenuItem(
                      value: 'super_admin',
                      child: Text('Super Admins only'),
                    ),
                ],
                onChanged: _busy ? null : (v) => setState(() => _appliesTo = v ?? 'all'),
              ),
              const SizedBox(height: 10),
              TextField(
                controller: _minCtrl,
                enabled: !_busy,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                decoration: const InputDecoration(labelText: 'Min sale amount (₦)'),
              ),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('No upper limit'),
                value: _noMax,
                onChanged: _busy ? null : (v) => setState(() => _noMax = v),
              ),
              if (!_noMax)
                TextField(
                  controller: _maxCtrl,
                  enabled: !_busy,
                  keyboardType: TextInputType.number,
                  inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                  decoration: const InputDecoration(labelText: 'Max sale amount (₦)'),
                ),
              const SizedBox(height: 8),
              DropdownButtonFormField<String>(
                initialValue: _discountType,
                decoration: const InputDecoration(labelText: 'Discount type'),
                items: const [
                  DropdownMenuItem(value: 'fixed', child: Text('Fixed ₦ off wallet charge')),
                  DropdownMenuItem(value: 'percent', child: Text('% off wallet charge')),
                ],
                onChanged: _busy ? null : (v) => setState(() => _discountType = v ?? 'fixed'),
              ),
              TextField(
                controller: _discountCtrl,
                enabled: !_busy,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(
                  labelText: _discountType == 'percent' ? 'Discount %' : 'Discount ₦',
                ),
              ),
              const SizedBox(height: 10),
              DropdownButtonFormField<String>(
                initialValue: _commissionType,
                decoration: const InputDecoration(labelText: 'Commission type'),
                items: const [
                  DropdownMenuItem(value: 'fixed', child: Text('Fixed ₦ to commission wallet')),
                  DropdownMenuItem(value: 'percent', child: Text('% of sale to commission wallet')),
                ],
                onChanged: _busy ? null : (v) => setState(() => _commissionType = v ?? 'fixed'),
              ),
              TextField(
                controller: _commissionCtrl,
                enabled: !_busy,
                keyboardType: const TextInputType.numberWithOptions(decimal: true),
                decoration: InputDecoration(
                  labelText: _commissionType == 'percent' ? 'Commission %' : 'Commission ₦',
                ),
              ),
              SwitchListTile(
                contentPadding: EdgeInsets.zero,
                title: const Text('Active'),
                value: _active,
                onChanged: _busy ? null : (v) => setState(() => _active = v),
              ),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(onPressed: _busy ? null : () => Navigator.pop(context), child: const Text('Cancel')),
        FilledButton(
          onPressed: _busy ? null : _save,
          child: _busy
              ? const SizedBox(width: 18, height: 18, child: CircularProgressIndicator(strokeWidth: 2))
              : const Text('Save'),
        ),
      ],
    );
  }
}
