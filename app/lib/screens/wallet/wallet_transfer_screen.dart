import 'dart:async';

import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';

import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../state/auth_controller.dart';

class WalletTransferScreen extends StatefulWidget {
  const WalletTransferScreen({super.key});

  @override
  State<WalletTransferScreen> createState() => _WalletTransferScreenState();
}

class _WalletTransferScreenState extends State<WalletTransferScreen> {
  final _api = ApiClient();
  final _money = NumberFormat.currency(symbol: '₦', decimalDigits: 2);
  final _amountCtrl = TextEditingController();
  final _searchCtrl = TextEditingController();
  Timer? _searchDebounce;
  int _searchGen = 0;

  bool _searching = false;
  bool _submitting = false;
  String _product = 'vtu';
  _Recipient? _selected;
  List<_Recipient> _recipients = [];

  static const _products = [
    ('vtu', 'VTU Airtime'),
    ('momo', 'MoMo Airtime'),
    ('logical', 'Logical Airtime'),
  ];

  @override
  void dispose() {
    _searchDebounce?.cancel();
    _amountCtrl.dispose();
    _searchCtrl.dispose();
    super.dispose();
  }

  double _available() {
    final u = AuthScope.of(context).user;
    if (u == null) return 0;
    switch (_product) {
      case 'momo':
        return u.momoBalance;
      case 'logical':
        return u.logicalBalance;
      default:
        return u.vtuBalance;
    }
  }

  void _onSearchChanged(String q) {
    setState(() {});
    _searchDebounce?.cancel();
    _searchDebounce = Timer(const Duration(milliseconds: 350), () {
      _search(q);
    });
  }

  Future<void> _search(String q) async {
    final query = q.trim();
    final gen = ++_searchGen;
    if (query.isEmpty) {
      setState(() => _recipients = []);
      return;
    }
    setState(() => _searching = true);
    try {
      final data = await _api.post('wallet_transfer.php', body: {
        'action': 'recipients',
        'q': query,
      });
      if (!mounted || gen != _searchGen) return;
      final list = (data['recipients'] as List? ?? [])
          .whereType<Map>()
          .map((e) => _Recipient.fromJson(Map<String, dynamic>.from(e)))
          .toList();
      setState(() => _recipients = list);
    } catch (e) {
      if (mounted && gen == _searchGen) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      }
    } finally {
      if (mounted && gen == _searchGen) setState(() => _searching = false);
    }
  }

  Future<void> _submit() async {
    final selected = _selected;
    final amount = double.tryParse(_amountCtrl.text.trim()) ?? 0;
    if (selected == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Select a recipient')),
      );
      return;
    }
    if (!RegExp(r'^\d+(\.\d{1,2})?$').hasMatch(_amountCtrl.text.trim()) || amount < 1) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Enter a valid amount of at least ₦1')),
      );
      return;
    }
    if (amount > _available()) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Insufficient balance')),
      );
      return;
    }

    setState(() => _submitting = true);
    try {
      final data = await _api.post('wallet_transfer.php', body: {
        'action': 'transfer',
        'to_user_id': selected.id,
        'amount': amount,
        'wallet_product': _product,
        'client_request_id': 'wt-${DateTime.now().millisecondsSinceEpoch}',
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('${data['message'] ?? 'Transfer completed'}')),
      );
      setState(() {
        _selected = null;
        _amountCtrl.clear();
      });
      await AuthScope.of(context).refreshWallet();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      }
    } finally {
      if (mounted) setState(() => _submitting = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = AuthScope.of(context).user;
    final available = _available();

    return Scaffold(
      appBar: AppBar(title: const Text('Wallet Transfer')),
      body: ListView(
        padding: const EdgeInsets.fromLTRB(16, 16, 16, 32),
        children: [
          Container(
            padding: const EdgeInsets.all(14),
            decoration: BoxDecoration(
              color: EcColors.card,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: Colors.grey.shade300),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                const Text('Your balances', style: TextStyle(fontWeight: FontWeight.w800)),
                const SizedBox(height: 8),
                Text('VTU ${_money.format(user?.vtuBalance ?? 0)} · '
                    'MoMo ${_money.format(user?.momoBalance ?? 0)} · '
                    'Logical ${_money.format(user?.logicalBalance ?? 0)}'),
                const SizedBox(height: 8),
                const Text(
                  'Transfer from your wallet to accounts registered under you '
                  '(Admins can also transfer to other Admins).',
                  style: TextStyle(fontSize: 12, color: EcColors.muted, height: 1.35),
                ),
              ],
            ),
          ),
          const SizedBox(height: 18),
          const Text('Wallet type', style: TextStyle(fontWeight: FontWeight.w700)),
          const SizedBox(height: 8),
          Wrap(
            spacing: 8,
            children: _products.map((p) {
              final selected = _product == p.$1;
              return ChoiceChip(
                label: Text(p.$2),
                selected: selected,
                onSelected: (_) => setState(() => _product = p.$1),
              );
            }).toList(),
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _amountCtrl,
            keyboardType: const TextInputType.numberWithOptions(decimal: true),
            inputFormatters: [FilteringTextInputFormatter.allow(RegExp(r'[0-9.]'))],
            decoration: InputDecoration(
              labelText: 'Amount',
              prefixText: '₦ ',
              helperText: 'Available: ${_money.format(available)}',
            ),
          ),
          const SizedBox(height: 16),
          TextField(
            controller: _searchCtrl,
            onChanged: _onSearchChanged,
            decoration: InputDecoration(
              labelText: 'Find recipient',
              hintText: 'Search name, email, or phone',
              prefixIcon: const Icon(Icons.search),
              suffixIcon: _searching
                  ? const Padding(
                      padding: EdgeInsets.all(12),
                      child: SizedBox(
                        width: 18,
                        height: 18,
                        child: CircularProgressIndicator(strokeWidth: 2),
                      ),
                    )
                  : null,
            ),
          ),
          if (_selected != null) ...[
            const SizedBox(height: 12),
            Container(
              width: double.infinity,
              padding: const EdgeInsets.all(12),
              decoration: BoxDecoration(
                color: EcColors.primary.withValues(alpha: 0.12),
                borderRadius: BorderRadius.circular(12),
              ),
              child: Text(
                'Selected: ${_selected!.fullName} · ${_selected!.roleLabel}',
                style: const TextStyle(fontWeight: FontWeight.w600),
              ),
            ),
          ],
          const SizedBox(height: 12),
          ..._recipients.map((r) {
            final isSel = _selected?.id == r.id;
            return ListTile(
              contentPadding: EdgeInsets.zero,
              selected: isSel,
              title: Text(r.fullName, style: const TextStyle(fontWeight: FontWeight.w600)),
              subtitle: Text('${r.roleLabel} · ${r.email}'),
              trailing: isSel ? const Icon(Icons.check_circle, color: EcColors.primary) : null,
              onTap: () => setState(() => _selected = r),
            );
          }),
          const SizedBox(height: 20),
          FilledButton.icon(
            onPressed: _submitting ? null : _submit,
            icon: _submitting
                ? const SizedBox(
                    width: 18,
                    height: 18,
                    child: CircularProgressIndicator(strokeWidth: 2, color: Colors.white),
                  )
                : const Icon(Icons.swap_horiz),
            label: Text(_submitting ? 'Transferring…' : 'Transfer'),
          ),
        ],
      ),
    );
  }
}

class _Recipient {
  _Recipient({
    required this.id,
    required this.fullName,
    required this.email,
    required this.roleLabel,
  });

  final int id;
  final String fullName;
  final String email;
  final String roleLabel;

  factory _Recipient.fromJson(Map<String, dynamic> json) {
    return _Recipient(
      id: (json['id'] as num?)?.toInt() ?? 0,
      fullName: '${json['full_name'] ?? ''}',
      email: '${json['email'] ?? ''}',
      roleLabel: '${json['role_label'] ?? ''}',
    );
  }
}
