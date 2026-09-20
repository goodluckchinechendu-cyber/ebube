import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../config/theme.dart';
import '../../services/api_client.dart';

class WalletHoldsScreen extends StatefulWidget {
  const WalletHoldsScreen({super.key});

  @override
  State<WalletHoldsScreen> createState() => _WalletHoldsScreenState();
}

class _WalletHoldsScreenState extends State<WalletHoldsScreen> {
  final _api = ApiClient();
  final _money = NumberFormat.currency(symbol: '₦', decimalDigits: 2);
  final _refCtrl = TextEditingController();
  String _status = '';
  bool _loading = true;
  String? _error;
  List<_HoldRow> _holds = [];
  int _total = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _refCtrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await _api.post('wallet_holds.php', body: {
        'action': 'list',
        if (_status.isNotEmpty) 'status': _status,
        if (_refCtrl.text.trim().isNotEmpty) 'reference': _refCtrl.text.trim(),
        'limit': 50,
      });
      final list = (data['holds'] as List? ?? [])
          .whereType<Map>()
          .map((e) => _HoldRow.fromJson(Map<String, dynamic>.from(e)))
          .toList();
      if (!mounted) return;
      setState(() {
        _holds = list;
        _total = (data['total'] as num?)?.toInt() ?? list.length;
        _loading = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = '$e';
        _loading = false;
      });
    }
  }

  Color _statusColor(String s) {
    switch (s) {
      case 'completed':
        return EcColors.success;
      case 'refunded':
        return EcColors.danger;
      case 'held':
        return EcColors.primaryDark;
      default:
        return EcColors.muted;
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Wallet Holds'),
        actions: [
          IconButton(
            tooltip: 'Refresh',
            onPressed: _loading ? null : _load,
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: Row(
              children: [
                Expanded(
                  child: TextField(
                    controller: _refCtrl,
                    decoration: const InputDecoration(
                      labelText: 'Reference',
                      isDense: true,
                    ),
                    onSubmitted: (_) => _load(),
                  ),
                ),
                const SizedBox(width: 8),
                DropdownButton<String>(
                  value: _status,
                  items: const [
                    DropdownMenuItem(value: '', child: Text('All')),
                    DropdownMenuItem(value: 'held', child: Text('Held')),
                    DropdownMenuItem(value: 'completed', child: Text('Completed')),
                    DropdownMenuItem(value: 'refunded', child: Text('Refunded')),
                  ],
                  onChanged: (v) {
                    setState(() => _status = v ?? '');
                    _load();
                  },
                ),
                IconButton(
                  onPressed: _load,
                  icon: const Icon(Icons.search),
                ),
              ],
            ),
          ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Align(
              alignment: Alignment.centerLeft,
              child: Text(
                _loading ? 'Loading…' : '$_total hold${_total == 1 ? '' : 's'}',
                style: const TextStyle(color: EcColors.muted, fontSize: 13),
              ),
            ),
          ),
          const SizedBox(height: 8),
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : _error != null
                    ? Center(child: Text(_error!, textAlign: TextAlign.center))
                    : _holds.isEmpty
                        ? const Center(child: Text('No wallet holds found'))
                        : ListView.separated(
                            padding: const EdgeInsets.fromLTRB(16, 0, 16, 24),
                            itemCount: _holds.length,
                            separatorBuilder: (_, __) => const Divider(height: 1),
                            itemBuilder: (context, i) {
                              final h = _holds[i];
                              return ListTile(
                                contentPadding: const EdgeInsets.symmetric(vertical: 8),
                                title: Text(
                                  h.productLabel.isEmpty ? h.reference : h.productLabel,
                                  style: const TextStyle(fontWeight: FontWeight.w600),
                                ),
                                subtitle: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    const SizedBox(height: 4),
                                    Text('Ref: ${h.reference}'),
                                    Text(
                                      '${h.userName.isEmpty ? 'User #${h.userId}' : h.userName}'
                                      ' · ${h.walletProduct}'
                                      ' · charged ${_money.format(h.amount)}'
                                      '${h.faceAmount != h.amount ? ' (face ${_money.format(h.faceAmount)})' : ''}',
                                    ),
                                    if (h.commissionAmount != null && h.commissionAmount! > 0)
                                      Text(
                                        'Commission: ${_money.format(h.commissionAmount)}',
                                        style: const TextStyle(color: EcColors.success),
                                      ),
                                    Text(
                                      h.createdAt,
                                      style: const TextStyle(fontSize: 12, color: EcColors.muted),
                                    ),
                                  ],
                                ),
                                trailing: Text(
                                  h.status.toUpperCase(),
                                  style: TextStyle(
                                    color: _statusColor(h.status),
                                    fontWeight: FontWeight.w700,
                                    fontSize: 12,
                                  ),
                                ),
                              );
                            },
                          ),
          ),
        ],
      ),
    );
  }
}

class _HoldRow {
  _HoldRow({
    required this.reference,
    required this.userId,
    required this.userName,
    required this.walletProduct,
    required this.amount,
    required this.faceAmount,
    required this.productLabel,
    required this.status,
    required this.createdAt,
    this.commissionAmount,
  });

  final String reference;
  final int userId;
  final String userName;
  final String walletProduct;
  final double amount;
  final double faceAmount;
  final String productLabel;
  final String status;
  final String createdAt;
  final double? commissionAmount;

  factory _HoldRow.fromJson(Map<String, dynamic> json) {
    double money(dynamic v) {
      if (v is num) return v.toDouble();
      return double.tryParse('$v') ?? 0;
    }

    return _HoldRow(
      reference: '${json['reference'] ?? ''}',
      userId: (json['user_id'] as num?)?.toInt() ?? 0,
      userName: '${json['user_name'] ?? ''}',
      walletProduct: '${json['wallet_product'] ?? ''}',
      amount: money(json['amount']),
      faceAmount: money(json['face_amount'] ?? json['amount']),
      productLabel: '${json['product_label'] ?? ''}',
      status: '${json['status'] ?? ''}'.toLowerCase(),
      createdAt: '${json['created_at'] ?? ''}',
      commissionAmount: json['commission_amount'] == null
          ? null
          : money(json['commission_amount']),
    );
  }
}
