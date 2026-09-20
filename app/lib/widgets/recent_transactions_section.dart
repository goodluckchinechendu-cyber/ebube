import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../config/theme.dart';
import '../screens/shell/main_shell.dart';
import '../screens/transactions/transactions_screen.dart';
import '../services/api_client.dart';
import '../state/auth_controller.dart';
import 'network_badge.dart';
import 'transaction_receipt_sheet.dart';

/// Dashboard strip of the 5 most recent agent transactions.
class RecentTransactionsSection extends StatefulWidget {
  const RecentTransactionsSection({
    super.key,
    this.onTap,
    this.limit = 5,
  });

  /// If set, called instead of opening the receipt sheet.
  final void Function(TransactionItem item)? onTap;
  final int limit;

  @override
  State<RecentTransactionsSection> createState() => _RecentTransactionsSectionState();
}

class _RecentTransactionsSectionState extends State<RecentTransactionsSection> {
  final _api = ApiClient();
  final _currency = NumberFormat.currency(symbol: '₦', decimalDigits: 2);
  final _dateFmt = DateFormat('MMM dd • hh:mm a');

  List<TransactionItem> _items = [];
  bool _loading = true;
  String? _error;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _api.close();
    super.dispose();
  }

  Future<void> _load() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final user = AuthScope.of(context).user;
      final userId = user?.id ?? 0;
      final res = await _api.post('agent_transactions.php', body: {
        'action': 'list',
        'user_id': userId,
        'actor_id': userId,
      });
      final list = (res['transactions'] as List? ?? [])
          .whereType<Map>()
          .map((e) => TransactionItem.fromJson(Map<String, dynamic>.from(e)))
          .take(widget.limit)
          .toList();
      if (!mounted) return;
      setState(() {
        _items = list;
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

  void _openReceipt(TransactionItem item) {
    if (widget.onTap != null) {
      widget.onTap!(item);
      return;
    }
    showTransactionReceiptSheet(context, item);
  }

  void _openAll() {
    final shell = MainShellScope.of(context);
    if (shell != null) {
      shell.selectRoute('transactions');
    }
  }

  @override
  Widget build(BuildContext context) {
    return Column(
      crossAxisAlignment: CrossAxisAlignment.stretch,
      children: [
        Row(
          children: [
            Container(
              width: 4,
              height: 20,
              decoration: BoxDecoration(
                color: EcColors.primary,
                borderRadius: BorderRadius.circular(2),
              ),
            ),
            const SizedBox(width: 10),
            const Expanded(
              child: Text(
                'Recent Transactions',
                style: TextStyle(
                  fontWeight: FontWeight.bold,
                  fontSize: 17,
                  color: EcColors.ink,
                ),
              ),
            ),
            if (!_loading)
              IconButton(
                onPressed: _load,
                tooltip: 'Refresh',
                icon: const Icon(Icons.refresh, size: 20, color: EcColors.muted),
                visualDensity: VisualDensity.compact,
              ),
          ],
        ),
        const SizedBox(height: 12),
        if (_loading)
          ...List.generate(
            3,
            (_) => Container(
              margin: const EdgeInsets.only(bottom: 8),
              height: 64,
              decoration: BoxDecoration(
                color: Colors.grey.shade100,
                borderRadius: BorderRadius.circular(12),
              ),
            ),
          )
        else if (_error != null)
          Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: Colors.black.withValues(alpha: 0.06)),
            ),
            child: Column(
              children: [
                Text(_error!, textAlign: TextAlign.center, style: const TextStyle(color: EcColors.muted)),
                const SizedBox(height: 8),
                TextButton(onPressed: _load, child: const Text('Retry')),
              ],
            ),
          )
        else if (_items.isEmpty)
          Container(
            padding: const EdgeInsets.all(24),
            decoration: BoxDecoration(
              color: Colors.white,
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: Colors.black.withValues(alpha: 0.06)),
            ),
            child: const Text(
              'No recent transactions',
              textAlign: TextAlign.center,
              style: TextStyle(color: EcColors.muted),
            ),
          )
        else ...[
          ..._items.map((item) {
            return Padding(
              padding: const EdgeInsets.only(bottom: 8),
              child: Material(
                color: Colors.white,
                borderRadius: BorderRadius.circular(12),
                child: ListTile(
                  onTap: () => _openReceipt(item),
                  shape: RoundedRectangleBorder(
                    borderRadius: BorderRadius.circular(12),
                    side: BorderSide(color: Colors.black.withValues(alpha: 0.06)),
                  ),
                  leading: NetworkBadge(network: item.network, size: 40, compact: true),
                  title: Text(
                    item.product,
                    maxLines: 1,
                    overflow: TextOverflow.ellipsis,
                    style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13),
                  ),
                  subtitle: Text(
                    '${item.phone.isNotEmpty ? item.phone : item.customerName}'
                    ' · ${_dateFmt.format(item.transactionAt)}',
                    style: const TextStyle(fontSize: 12, color: EcColors.muted),
                  ),
                  trailing: Text(
                    _currency.format(item.total),
                    style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 13),
                  ),
                ),
              ),
            );
          }),
          Align(
            alignment: Alignment.centerRight,
            child: TextButton(
              onPressed: _openAll,
              child: const Text('See all'),
            ),
          ),
        ],
      ],
    );
  }
}
