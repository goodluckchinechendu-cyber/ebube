import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../state/auth_controller.dart';

class ApproveWithdrawalsScreen extends StatefulWidget {
  const ApproveWithdrawalsScreen({super.key});

  @override
  State<ApproveWithdrawalsScreen> createState() => _ApproveWithdrawalsScreenState();
}

class _ApproveWithdrawalsScreenState extends State<ApproveWithdrawalsScreen> {
  final _api = ApiClient();
  final _money = NumberFormat.currency(symbol: '₦', decimalDigits: 2);
  bool _loading = true;
  String? _error;
  List<_WithdrawalRow> _items = [];

  static const _creditHoursOptions = [1, 2, 6, 12, 24, 48, 72];

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
      final data = await _api.post('withdrawals.php', body: {'action': 'list'});
      final list = (data['requests'] as List? ?? [])
          .whereType<Map>()
          .map((e) => _WithdrawalRow.fromJson(Map<String, dynamic>.from(e)))
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

  Future<void> _approve(_WithdrawalRow row) async {
    final hours = await showDialog<int>(
      context: context,
      builder: (ctx) => _ApproveDialog(
        agentLabel: '${row.agentName}\n${_money.format(row.amount)}',
        options: _creditHoursOptions,
      ),
    );
    if (hours == null || !mounted) return;

    try {
      await _api.post('withdrawals.php', body: {
        'action': 'approve',
        'request_id': row.id,
        'credit_hours': hours,
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Approved — credit within $hours hours')),
      );
      await _load();
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      }
    }
  }

  Color _statusColor(String s) {
    switch (s) {
      case 'approved':
        return EcColors.success;
      case 'pending':
        return EcColors.primaryDark;
      case 'rejected':
        return EcColors.danger;
      default:
        return EcColors.muted;
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = AuthScope.of(context).user;
    if (user == null || !user.canManageUsers) {
      return Scaffold(
        appBar: AppBar(title: const Text('Approve Withdrawals')),
        body: const Center(child: Text('Admin access required')),
      );
    }

    final pending = _items.where((e) => e.status == 'pending').toList();
    final others = _items.where((e) => e.status != 'pending').toList();

    return Scaffold(
      appBar: AppBar(
        title: const Text('Approve Withdrawals'),
        actions: [
          IconButton(onPressed: _loading ? null : _load, icon: const Icon(Icons.refresh)),
        ],
      ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(
                  child: Padding(
                    padding: const EdgeInsets.all(24),
                    child: Column(
                      mainAxisSize: MainAxisSize.min,
                      children: [
                        Text(_error!, textAlign: TextAlign.center, style: const TextStyle(color: EcColors.danger)),
                        const SizedBox(height: 16),
                        FilledButton(onPressed: _load, child: const Text('Retry')),
                      ],
                    ),
                  ),
                )
              : _items.isEmpty
                  ? const Center(child: Text('No withdrawal requests'))
                  : RefreshIndicator(
                      onRefresh: _load,
                      child: ListView(
                        padding: const EdgeInsets.fromLTRB(16, 16, 16, 24),
                        children: [
                          if (pending.isNotEmpty) ...[
                            Text(
                              'Pending (${pending.length})',
                              style: const TextStyle(
                                fontWeight: FontWeight.w700,
                                color: EcColors.muted,
                                fontSize: 13,
                              ),
                            ),
                            const SizedBox(height: 8),
                            ...pending.map(_buildTile),
                            const SizedBox(height: 16),
                          ],
                          if (others.isNotEmpty) ...[
                            Text(
                              'History (${others.length})',
                              style: const TextStyle(
                                fontWeight: FontWeight.w700,
                                color: EcColors.muted,
                                fontSize: 13,
                              ),
                            ),
                            const SizedBox(height: 8),
                            ...others.map(_buildTile),
                          ],
                        ],
                      ),
                    ),
    );
  }

  Widget _buildTile(_WithdrawalRow row) {
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
          contentPadding: const EdgeInsets.symmetric(horizontal: 16, vertical: 8),
          title: Text(
            row.agentName.isEmpty ? 'Agent #${row.userId}' : row.agentName,
            style: const TextStyle(fontWeight: FontWeight.w700),
          ),
          subtitle: Column(
            crossAxisAlignment: CrossAxisAlignment.start,
            children: [
              const SizedBox(height: 4),
              Text(
                _money.format(row.amount),
                style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 15, color: EcColors.ink),
              ),
              const SizedBox(height: 4),
              Text(
                '${row.bankName} · ${row.accountNumber}',
                style: const TextStyle(fontSize: 13),
              ),
              Text(
                row.accountName,
                style: const TextStyle(fontSize: 12, color: EcColors.muted),
              ),
              if (row.createdAt.isNotEmpty)
                Text(
                  row.createdAt,
                  style: const TextStyle(fontSize: 11, color: EcColors.muted),
                ),
            ],
          ),
          trailing: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            crossAxisAlignment: CrossAxisAlignment.end,
            children: [
              Text(
                row.status.toUpperCase(),
                style: TextStyle(
                  color: _statusColor(row.status),
                  fontWeight: FontWeight.w700,
                  fontSize: 11,
                ),
              ),
              if (row.status == 'pending') ...[
                const SizedBox(height: 6),
                FilledButton(
                  onPressed: () => _approve(row),
                  style: FilledButton.styleFrom(
                    minimumSize: const Size(0, 32),
                    padding: const EdgeInsets.symmetric(horizontal: 12),
                    textStyle: const TextStyle(fontSize: 12, fontWeight: FontWeight.w700),
                  ),
                  child: const Text('Approve'),
                ),
              ],
            ],
          ),
          isThreeLine: true,
        ),
      ),
    );
  }
}

class _ApproveDialog extends StatefulWidget {
  const _ApproveDialog({required this.agentLabel, required this.options});

  final String agentLabel;
  final List<int> options;

  @override
  State<_ApproveDialog> createState() => _ApproveDialogState();
}

class _ApproveDialogState extends State<_ApproveDialog> {
  int _hours = 24;

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: const Text('Approve withdrawal'),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        crossAxisAlignment: CrossAxisAlignment.stretch,
        children: [
          Text(
            widget.agentLabel,
            style: const TextStyle(fontWeight: FontWeight.w600, height: 1.35),
          ),
          const SizedBox(height: 16),
          DropdownButtonFormField<int>(
            initialValue: _hours,
            decoration: const InputDecoration(labelText: 'Credit within (hours)'),
            items: widget.options
                .map((h) => DropdownMenuItem(value: h, child: Text('$h hours')))
                .toList(),
            onChanged: (v) => setState(() => _hours = v ?? 24),
          ),
        ],
      ),
      actions: [
        TextButton(onPressed: () => Navigator.pop(context), child: const Text('Cancel')),
        FilledButton(onPressed: () => Navigator.pop(context, _hours), child: const Text('Approve')),
      ],
    );
  }
}

class _WithdrawalRow {
  _WithdrawalRow({
    required this.id,
    required this.userId,
    required this.agentName,
    required this.amount,
    required this.accountName,
    required this.bankName,
    required this.accountNumber,
    required this.status,
    required this.createdAt,
  });

  final int id;
  final int userId;
  final String agentName;
  final double amount;
  final String accountName;
  final String bankName;
  final String accountNumber;
  final String status;
  final String createdAt;

  factory _WithdrawalRow.fromJson(Map<String, dynamic> json) {
    double money(dynamic v) => v is num ? v.toDouble() : double.tryParse('$v') ?? 0;
    return _WithdrawalRow(
      id: (json['id'] as num?)?.toInt() ?? 0,
      userId: (json['user_id'] as num?)?.toInt() ?? 0,
      agentName: '${json['agent_name'] ?? ''}',
      amount: money(json['amount']),
      accountName: '${json['account_name'] ?? ''}',
      bankName: '${json['bank_name'] ?? ''}',
      accountNumber: '${json['account_number'] ?? ''}',
      status: '${json['status'] ?? ''}'.toLowerCase(),
      createdAt: '${json['created_at'] ?? ''}',
    );
  }
}
