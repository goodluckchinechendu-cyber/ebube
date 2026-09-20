import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../config/theme.dart';
import '../../services/api_client.dart';

class WalletHistoryScreen extends StatefulWidget {
  const WalletHistoryScreen({super.key});

  @override
  State<WalletHistoryScreen> createState() => _WalletHistoryScreenState();
}

class _WalletHistoryScreenState extends State<WalletHistoryScreen> {
  final _api = ApiClient();
  final _money = NumberFormat.currency(symbol: '₦', decimalDigits: 0);
  final _scroll = ScrollController();

  final List<_WhEntry> _entries = [];
  bool _loading = true;
  bool _loadingMore = false;
  bool _hasMore = false;
  int _page = 1;
  String? _error;

  @override
  void initState() {
    super.initState();
    _scroll.addListener(_onScroll);
    _load(refresh: true);
  }

  @override
  void dispose() {
    _scroll.dispose();
    super.dispose();
  }

  void _onScroll() {
    if (_scroll.position.pixels >= _scroll.position.maxScrollExtent - 200 &&
        !_loading &&
        !_loadingMore &&
        _hasMore) {
      _load();
    }
  }

  Future<void> _load({bool refresh = false}) async {
    if (refresh) {
      setState(() {
        _loading = true;
        _error = null;
        _page = 1;
      });
    } else {
      if (_loadingMore) return;
      setState(() => _loadingMore = true);
    }

    try {
      final nextPage = refresh ? 1 : _page + 1;
      final data = await _api.get(
        'wallet_history.php',
        query: {'page': '$nextPage', 'per_page': '25'},
      );
      final list = (data['entries'] as List? ?? [])
          .whereType<Map>()
          .map((e) => _WhEntry.fromJson(Map<String, dynamic>.from(e)))
          .toList();
      final meta = data['meta'] is Map ? Map<String, dynamic>.from(data['meta'] as Map) : <String, dynamic>{};
      if (!mounted) return;
      setState(() {
        if (refresh) {
          _entries
            ..clear()
            ..addAll(list);
        } else {
          _entries.addAll(list);
        }
        _page = nextPage;
        _hasMore = meta['has_more'] == true;
        _loading = false;
        _loadingMore = false;
        _error = null;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = '$e';
        _loading = false;
        _loadingMore = false;
      });
    }
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Wallet History'),
        actions: [
          IconButton(
            onPressed: _loading ? null : () => _load(refresh: true),
            icon: const Icon(Icons.refresh),
          ),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => _load(refresh: true),
        child: _buildBody(),
      ),
    );
  }

  Widget _buildBody() {
    if (_loading && _entries.isEmpty) {
      return const Center(child: CircularProgressIndicator());
    }
    if (_error != null && _entries.isEmpty) {
      return ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: [
          const SizedBox(height: 120),
          Padding(
            padding: const EdgeInsets.all(24),
            child: Column(
              children: [
                Text(_error!, textAlign: TextAlign.center, style: const TextStyle(color: EcColors.danger)),
                const SizedBox(height: 12),
                FilledButton(onPressed: () => _load(refresh: true), child: const Text('Retry')),
              ],
            ),
          ),
        ],
      );
    }
    if (_entries.isEmpty) {
      return ListView(
        physics: const AlwaysScrollableScrollPhysics(),
        children: const [
          SizedBox(height: 120),
          Center(
            child: Text(
              'No wallet history yet.\nFunding, transfers, and purchases will show here.',
              textAlign: TextAlign.center,
              style: TextStyle(color: EcColors.muted),
            ),
          ),
        ],
      );
    }

    return ListView.builder(
      controller: _scroll,
      physics: const AlwaysScrollableScrollPhysics(),
      padding: const EdgeInsets.fromLTRB(16, 12, 16, 24),
      itemCount: _entries.length + (_loadingMore ? 1 : 0),
      itemBuilder: (context, index) {
        if (index >= _entries.length) {
          return const Padding(
            padding: EdgeInsets.all(16),
            child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
          );
        }
        return _card(_entries[index]);
      },
    );
  }

  Widget _card(_WhEntry e) {
    final color = e.isCredit ? Colors.green.shade800 : Colors.red.shade800;
    final when = e.createdAt != null
        ? DateFormat('MMM d, yyyy · h:mm a').format(e.createdAt!.toLocal())
        : '';
    return Padding(
      padding: const EdgeInsets.only(bottom: 10),
      child: Material(
        color: Colors.white,
        borderRadius: BorderRadius.circular(12),
        child: Container(
          padding: const EdgeInsets.all(14),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(12),
            border: Border.all(color: Colors.black.withValues(alpha: 0.06)),
          ),
          child: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              Row(
                children: [
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 8, vertical: 3),
                    decoration: BoxDecoration(
                      color: e.isCredit ? Colors.green.shade50 : Colors.red.shade50,
                      borderRadius: BorderRadius.circular(6),
                    ),
                    child: Text(
                      e.isCredit ? 'CREDIT' : 'DEBIT',
                      style: TextStyle(fontSize: 10, fontWeight: FontWeight.w800, color: color),
                    ),
                  ),
                  const Spacer(),
                  Text(
                    '${e.isCredit ? '+' : '-'}${_money.format(e.amount.abs())}',
                    style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: color),
                  ),
                ],
              ),
              const SizedBox(height: 8),
              Text(e.label, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 14)),
              const SizedBox(height: 4),
              Text(
                [
                  if (e.source.isNotEmpty) e.source,
                  if (e.performedBy.isNotEmpty) e.performedBy,
                  if (when.isNotEmpty) when,
                ].join(' · '),
                style: const TextStyle(fontSize: 12, color: EcColors.muted, height: 1.35),
              ),
              if (e.reference.isNotEmpty) ...[
                const SizedBox(height: 4),
                Text('Ref: ${e.reference}', style: const TextStyle(fontSize: 11, color: EcColors.muted)),
              ],
            ],
          ),
        ),
      ),
    );
  }
}

class _WhEntry {
  _WhEntry({
    required this.label,
    required this.amount,
    required this.isCredit,
    required this.source,
    required this.performedBy,
    required this.reference,
    required this.createdAt,
  });

  final String label;
  final double amount;
  final bool isCredit;
  final String source;
  final String performedBy;
  final String reference;
  final DateTime? createdAt;

  factory _WhEntry.fromJson(Map<String, dynamic> json) {
    DateTime? dt;
    final raw = '${json['created_at'] ?? ''}'.trim();
    if (raw.isNotEmpty) {
      try {
        dt = DateTime.parse(raw.contains('T') ? raw : raw.replaceFirst(' ', 'T'));
      } catch (_) {}
    }
    return _WhEntry(
      label: '${json['label'] ?? 'Wallet movement'}',
      amount: (json['amount'] is num)
          ? (json['amount'] as num).toDouble()
          : double.tryParse('${json['amount']}') ?? 0,
      isCredit: json['is_credit'] == true,
      source: '${json['source'] ?? ''}',
      performedBy: '${json['performed_by_name'] ?? ''}',
      reference: '${json['reference'] ?? ''}',
      createdAt: dt,
    );
  }
}
