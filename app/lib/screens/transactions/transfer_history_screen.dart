import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../state/auth_controller.dart';

class TransferHistoryScreen extends StatefulWidget {
  const TransferHistoryScreen({super.key});

  @override
  State<TransferHistoryScreen> createState() => _TransferHistoryScreenState();
}

class _TransferHistoryScreenState extends State<TransferHistoryScreen> {
  final _api = ApiClient();
  final _money = NumberFormat.currency(symbol: '₦', decimalDigits: 0);
  final _searchCtrl = TextEditingController();

  bool _loading = true;
  String? _error;
  List<_TransferRow> _rows = [];

  bool get _canSeeAll {
    final user = AuthScope.of(context).user;
    return user?.canManageUsers == true;
  }

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) => _load());
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final data = await _api.post('wallet_transfer.php', body: {
        'action': 'history',
        'scope': _canSeeAll ? 'all' : 'mine',
        'q': _searchCtrl.text.trim(),
        'page': 1,
        'per_page': 100,
      });
      final list = (data['transfers'] as List? ?? [])
          .whereType<Map>()
          .map((e) => _TransferRow.fromJson(Map<String, dynamic>.from(e)))
          .toList();
      if (!mounted) return;
      setState(() {
        _rows = list;
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

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Transfer History'),
        actions: [
          IconButton(onPressed: _loading ? null : _load, icon: const Icon(Icons.refresh)),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 12, 16, 8),
            child: TextField(
              controller: _searchCtrl,
              onSubmitted: (_) => _load(),
              decoration: InputDecoration(
                hintText: 'Search name or reference…',
                prefixIcon: const Icon(Icons.search),
                suffixIcon: IconButton(
                  onPressed: _load,
                  icon: const Icon(Icons.arrow_forward),
                ),
              ),
            ),
          ),
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : _error != null
                    ? Center(child: Text(_error!, textAlign: TextAlign.center))
                    : _rows.isEmpty
                        ? const Center(
                            child: Text(
                              'No transfers yet.',
                              style: TextStyle(color: EcColors.muted),
                            ),
                          )
                        : RefreshIndicator(
                            onRefresh: _load,
                            child: ListView.separated(
                              padding: const EdgeInsets.fromLTRB(16, 8, 16, 24),
                              itemCount: _rows.length,
                              separatorBuilder: (_, __) => const SizedBox(height: 8),
                              itemBuilder: (context, i) {
                                final t = _rows[i];
                                final Color color;
                                if (t.direction == 'out') {
                                  color = Colors.red.shade800;
                                } else if (t.direction == 'in') {
                                  color = Colors.green.shade800;
                                } else {
                                  color = EcColors.primaryDark;
                                }
                                return Material(
                                  color: Colors.white,
                                  borderRadius: BorderRadius.circular(12),
                                  child: Container(
                                    padding: const EdgeInsets.all(14),
                                    decoration: BoxDecoration(
                                      borderRadius: BorderRadius.circular(12),
                                      border: Border.all(
                                        color: Colors.black.withValues(alpha: 0.06),
                                      ),
                                    ),
                                    child: Column(
                                      crossAxisAlignment: CrossAxisAlignment.start,
                                      children: [
                                        Row(
                                          children: [
                                            Expanded(
                                              child: Text(
                                                '${t.fromName} → ${t.toName}',
                                                style: const TextStyle(fontWeight: FontWeight.w700),
                                              ),
                                            ),
                                            Text(
                                              _money.format(t.amount),
                                              style: TextStyle(
                                                fontWeight: FontWeight.w800,
                                                color: color,
                                              ),
                                            ),
                                          ],
                                        ),
                                        const SizedBox(height: 6),
                                        Text(
                                          '${t.walletName} · ${t.status} · ${t.when}',
                                          style: const TextStyle(fontSize: 12, color: EcColors.muted),
                                        ),
                                        if (t.reference.isNotEmpty)
                                          Text(
                                            'Ref: ${t.reference}',
                                            style: const TextStyle(fontSize: 11, color: EcColors.muted),
                                          ),
                                      ],
                                    ),
                                  ),
                                );
                              },
                            ),
                          ),
          ),
        ],
      ),
    );
  }
}

class _TransferRow {
  _TransferRow({
    required this.fromName,
    required this.toName,
    required this.amount,
    required this.walletName,
    required this.status,
    required this.reference,
    required this.direction,
    required this.when,
  });

  final String fromName;
  final String toName;
  final double amount;
  final String walletName;
  final String status;
  final String reference;
  final String direction;
  final String when;

  factory _TransferRow.fromJson(Map<String, dynamic> json) {
    String fmt(String raw) {
      if (raw.isEmpty) return '';
      try {
        final dt = DateTime.parse(raw.contains('T') ? raw : raw.replaceFirst(' ', 'T'));
        return DateFormat('MMM d, yyyy · h:mm a').format(dt.toLocal());
      } catch (_) {
        return raw;
      }
    }

    return _TransferRow(
      fromName: '${json['from_user_name'] ?? 'User'}',
      toName: '${json['to_user_name'] ?? 'User'}',
      amount: (json['amount'] is num)
          ? (json['amount'] as num).toDouble()
          : double.tryParse('${json['amount']}') ?? 0,
      walletName: '${json['wallet_name'] ?? json['wallet_product'] ?? ''}',
      status: '${json['status'] ?? 'completed'}',
      reference: '${json['reference'] ?? ''}',
      direction: '${json['direction'] ?? ''}',
      when: fmt('${json['created_at'] ?? ''}'),
    );
  }
}
