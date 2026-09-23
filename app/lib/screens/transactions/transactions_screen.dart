import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../state/auth_controller.dart';
import '../../widgets/network_badge.dart';
import '../../widgets/transaction_receipt_sheet.dart';
import '../shell/main_shell.dart';

class TransactionItem {
  const TransactionItem({
    required this.id,
    required this.receiptId,
    required this.customerName,
    required this.product,
    required this.qty,
    required this.total,
    required this.status,
    required this.servedBy,
    required this.transactionAt,
    this.phone = '',
    this.network = 'MTN',
    this.accountLabel = '',
    this.isExternalAccount = false,
  });

  final int id;
  final String receiptId;
  final String customerName;
  final String product;
  final int qty;
  final double total;
  final String status;
  final String servedBy;
  final DateTime transactionAt;
  final String phone;
  final String network;
  final String accountLabel;
  final bool isExternalAccount;

  factory TransactionItem.fromJson(Map<String, dynamic> json) {
    double money(dynamic v) => (v is num) ? v.toDouble() : double.tryParse('$v') ?? 0;
    DateTime parseDate(dynamic v) {
      if (v == null) return DateTime.now();
      final text = '$v'.trim();
      final normalized = text.contains('T') ? text : text.replaceFirst(' ', 'T');
      try {
        return DateTime.parse(normalized);
      } catch (_) {
        return DateTime.now();
      }
    }

    final product = '${json['product'] ?? 'Airtime/Data'}';
    final networkRaw = '${json['network'] ?? ''}'.trim();
    final network = networkRaw.isNotEmpty
        ? networkRaw
        : (NetworkBadge.parseFromProduct(product) ?? 'MTN');

    return TransactionItem(
      id: (json['id'] as num?)?.toInt() ?? 0,
      receiptId: '${json['receipt_id'] ?? ''}',
      customerName: '${json['customer_name'] ?? 'Walk-in Customer'}',
      product: product,
      qty: (json['qty'] as num?)?.toInt() ?? 1,
      total: money(json['total'] ?? json['amount']),
      status: '${json['status'] ?? 'Completed'}',
      servedBy: '${json['served_by'] ?? json['funded_by'] ?? 'Agent'}',
      transactionAt: parseDate(json['transaction_at'] ?? json['created_at'] ?? json['funded_at']),
      phone: '${json['phone'] ?? ''}',
      network: network,
      accountLabel: '${json['account_label'] ?? ''}'.trim(),
      isExternalAccount: json['is_external_account'] == true || json['is_external_account'] == 1,
    );
  }

  bool get isAirtime => product.toLowerCase().contains('airtime');
  bool get isData => product.toLowerCase().contains('data');
  bool get isWalletFunding =>
      product.toLowerCase().contains('wallet') ||
      product.toLowerCase().contains('fund');

  String get productBadge {
    if (isAirtime) return 'Airtime';
    if (isData) return 'Data';
    if (isWalletFunding) return 'Funding';
    return 'Txn';
  }

  String get subtitlePrimary {
    if (accountLabel.isNotEmpty) {
      if (phone.isNotEmpty && !isWalletFunding) {
        return '$accountLabel · $phone';
      }
      return accountLabel;
    }
    if (isExternalAccount && customerName.isNotEmpty) return customerName;
    if (phone.isNotEmpty) return phone;
    return customerName;
  }
}

Color _statusBg(String status) {
  final s = status.toLowerCase();
  if (s == 'completed' || s == 'success' || s == 'successful') {
    return EcColors.success.withValues(alpha: 0.15);
  }
  if (s == 'failed' || s == 'failure' || s == 'refunded' || s == 'cancelled' || s == 'canceled') {
    return EcColors.danger.withValues(alpha: 0.15);
  }
  return Colors.orange.withValues(alpha: 0.15);
}

Color _statusFg(String status) {
  final s = status.toLowerCase();
  if (s == 'completed' || s == 'success' || s == 'successful') {
    return EcColors.success;
  }
  if (s == 'failed' || s == 'failure' || s == 'refunded' || s == 'cancelled' || s == 'canceled') {
    return EcColors.danger;
  }
  return Colors.orange.shade800;
}

class TransactionsScreen extends StatefulWidget {
  const TransactionsScreen({super.key, this.allAccounts = false});

  /// When true (Admin+ only), loads transactions across accounts.
  final bool allAccounts;

  @override
  State<TransactionsScreen> createState() => _TransactionsScreenState();
}

class _TransactionsScreenState extends State<TransactionsScreen> {
  final _searchCtrl = TextEditingController();
  final _api = ApiClient();
  bool _loading = false;
  String? _error;
  List<TransactionItem> _all = [];

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
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
      final res = await _api.post('/agent_transactions.php', body: {
        'action': widget.allAccounts ? 'list_all' : 'list',
        // Never pass the actor id as a filter for All Transactions —
        // that would restrict the list to one wallet (and hide elevated accounts incorrectly).
        if (!widget.allAccounts) ...{
          'user_id': userId,
          'actor_id': userId,
        } else ...{
          'actor_id': userId,
        },
      });
      final list = (res['transactions'] as List? ?? [])
          .map((e) => TransactionItem.fromJson(Map<String, dynamic>.from(e as Map)))
          .toList();

      // Admin+ All Transactions also includes wallet fund history.
      if (widget.allAccounts && user?.canManageUsers == true) {
        try {
          final fundRes = await _api.post('/wallet_funding.php', body: {
            'action': 'list',
            'admin': true,
          }, throwOnFailure: false);
          if (fundRes['success'] == true) {
            final funding = (fundRes['funding'] as List? ?? [])
                .whereType<Map>()
                .map((e) {
                  final m = Map<String, dynamic>.from(e);
                  m['product'] = m['product'] ?? m['wallet_name'] ?? 'Wallet Funding';
                  m['phone'] = '';
                  m['network'] = 'MTN';
                  m['account_label'] = '${m['customer_name'] ?? ''}'.trim();
                  return TransactionItem.fromJson(m);
                })
                .toList();
            list.addAll(funding);
            list.sort((a, b) => b.transactionAt.compareTo(a.transactionAt));
          }
        } catch (_) {
          // Purchases still show if funding history fails.
        }
      }

      if (mounted) {
        setState(() {
          _all = list;
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

  List<TransactionItem> get _filtered {
    final q = _searchCtrl.text.trim().toLowerCase();
    if (q.isEmpty) return _all;
    return _all.where((t) {
      return t.receiptId.toLowerCase().contains(q) ||
          t.customerName.toLowerCase().contains(q) ||
          t.accountLabel.toLowerCase().contains(q) ||
          t.product.toLowerCase().contains(q) ||
          t.phone.toLowerCase().contains(q) ||
          t.status.toLowerCase().contains(q) ||
          t.servedBy.toLowerCase().contains(q);
    }).toList();
  }

  void _openStatement() {
    final shell = MainShellScope.of(context);
    if (shell != null) {
      shell.selectRoute('statement');
    }
  }

  void _showReceipt(TransactionItem item) {
    showTransactionReceiptSheet(context, item);
  }

  @override
  Widget build(BuildContext context) {
    final user = AuthScope.of(context).user;
    if (widget.allAccounts && user?.canManageUsers != true) {
      return Scaffold(
        appBar: AppBar(title: const Text('All Transactions')),
        body: const Center(child: Text('Access denied.')),
      );
    }

    final currency = NumberFormat.currency(symbol: '₦', decimalDigits: 2);
    final dateFormat = DateFormat('MMM dd, yyyy • hh:mm a');

    final subtitle = widget.allAccounts
        ? 'Purchases & funding · externals show as name + Wallet ID for Admin'
        : 'Your transactions';

    return Scaffold(
      appBar: AppBar(
        title: Text(widget.allAccounts ? 'All Transactions' : 'Transaction History'),
        bottom: PreferredSize(
          preferredSize: const Size.fromHeight(22),
          child: Padding(
            padding: const EdgeInsets.only(bottom: 8),
            child: Text(
              subtitle,
              style: const TextStyle(fontSize: 12, color: EcColors.muted),
            ),
          ),
        ),
        actions: [
          if (!widget.allAccounts)
            IconButton(
              onPressed: _openStatement,
              icon: const Icon(Icons.summarize_outlined),
              tooltip: 'Statement of Account',
            ),
          IconButton(
            onPressed: _load,
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh history',
          ),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(16),
            child: TextField(
              controller: _searchCtrl,
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(
                hintText: 'Search receipt, phone, product...',
                prefixIcon: const Icon(Icons.search),
                suffixIcon: _searchCtrl.text.isNotEmpty
                    ? IconButton(
                        icon: const Icon(Icons.clear),
                        onPressed: () {
                          _searchCtrl.clear();
                          setState(() {});
                        },
                      )
                    : null,
                contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 12),
              ),
            ),
          ),
          Expanded(
            child: _loading
                ? const Center(child: CircularProgressIndicator())
                : _error != null
                    ? Center(
                        child: Column(
                          mainAxisSize: MainAxisSize.min,
                          children: [
                            const Icon(Icons.error_outline, size: 48, color: EcColors.danger),
                            const SizedBox(height: 12),
                            Text('Error: $_error', style: const TextStyle(color: EcColors.muted)),
                            const SizedBox(height: 16),
                            FilledButton(onPressed: _load, child: const Text('Retry')),
                          ],
                        ),
                      )
                    : _filtered.isEmpty
                        ? Center(
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(Icons.receipt_long_outlined, size: 56, color: Colors.grey.shade400),
                                const SizedBox(height: 12),
                                const Text(
                                  'No transactions found',
                                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600, color: EcColors.muted),
                                ),
                              ],
                            ),
                          )
                        : ListView.separated(
                            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
                            itemCount: _filtered.length,
                            separatorBuilder: (context, index) => const SizedBox(height: 10),
                            itemBuilder: (context, index) {
                              final item = _filtered[index];
                              return Card(
                                margin: EdgeInsets.zero,
                                child: ListTile(
                                  onTap: () => _showReceipt(item),
                                  leading: NetworkBadge(
                                    network: item.network,
                                    size: 40,
                                    compact: true,
                                  ),
                                  title: Row(
                                    children: [
                                      Expanded(
                                        child: Text(
                                          item.product,
                                          style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13),
                                          maxLines: 1,
                                          overflow: TextOverflow.ellipsis,
                                        ),
                                      ),
                                      const SizedBox(width: 6),
                                      Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                        decoration: BoxDecoration(
                                          color: item.isAirtime
                                              ? Colors.blue.withValues(alpha: 0.12)
                                              : item.isData
                                                  ? Colors.indigo.withValues(alpha: 0.12)
                                                  : Colors.grey.withValues(alpha: 0.12),
                                          borderRadius: BorderRadius.circular(4),
                                        ),
                                        child: Text(
                                          item.productBadge,
                                          style: TextStyle(
                                            fontSize: 9,
                                            fontWeight: FontWeight.bold,
                                            color: item.isAirtime
                                                ? Colors.blue.shade800
                                                : item.isData
                                                    ? Colors.indigo.shade800
                                                    : Colors.grey.shade800,
                                          ),
                                        ),
                                      ),
                                    ],
                                  ),
                                  subtitle: Text(
                                    '${item.subtitlePrimary} • ${dateFormat.format(item.transactionAt)}',
                                    style: const TextStyle(fontSize: 12, color: EcColors.muted),
                                  ),
                                  trailing: Column(
                                    mainAxisAlignment: MainAxisAlignment.center,
                                    crossAxisAlignment: CrossAxisAlignment.end,
                                    children: [
                                      Text(
                                        currency.format(item.total),
                                        style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14),
                                      ),
                                      const SizedBox(height: 2),
                                      Container(
                                        padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 2),
                                        decoration: BoxDecoration(
                                          color: _statusBg(item.status),
                                          borderRadius: BorderRadius.circular(4),
                                        ),
                                        child: Text(
                                          item.status,
                                          style: TextStyle(
                                            fontSize: 10,
                                            fontWeight: FontWeight.bold,
                                            color: _statusFg(item.status),
                                          ),
                                        ),
                                      ),
                                    ],
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
