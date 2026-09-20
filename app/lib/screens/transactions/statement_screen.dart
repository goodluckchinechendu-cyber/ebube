import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';

import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../state/auth_controller.dart';
import '../../widgets/network_badge.dart';
import '../../widgets/vtu_purchase_result.dart';

class StatementScreen extends StatefulWidget {
  const StatementScreen({super.key});

  @override
  State<StatementScreen> createState() => _StatementScreenState();
}

class _StatementScreenState extends State<StatementScreen> {
  final _api = ApiClient();
  DateTime _from = DateTime.now().subtract(const Duration(days: 30));
  DateTime _to = DateTime.now();
  bool _loading = false;
  bool _emailing = false;
  String? _error;
  Map<String, dynamic>? _statement;

  final _money = NumberFormat.currency(symbol: '₦', decimalDigits: 2);
  final _dateFmt = DateFormat('MMM dd, yyyy');
  final _dateTimeFmt = DateFormat('MMM dd, yyyy • hh:mm a');

  Future<void> _pickFrom() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _from,
      firstDate: DateTime(2020),
      lastDate: _to,
    );
    if (picked != null) setState(() => _from = picked);
  }

  Future<void> _pickTo() async {
    final picked = await showDatePicker(
      context: context,
      initialDate: _to,
      firstDate: _from,
      lastDate: DateTime.now(),
    );
    if (picked != null) setState(() => _to = picked);
  }

  double _balance(Map<String, dynamic> statement, String key) {
    final balances = statement['balances'];
    if (balances is! Map) return 0;
    final value = balances[key];
    return (value is num) ? value.toDouble() : double.tryParse('$value') ?? 0;
  }

  DateTime _parseDate(dynamic raw) {
    final text = '$raw'.trim();
    if (text.isEmpty) return DateTime.now();
    final normalized = text.contains('T') ? text : text.replaceFirst(' ', 'T');
    return DateTime.tryParse(normalized) ?? DateTime.now();
  }

  Future<void> _generate() async {
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final user = AuthScope.of(context).user;
      final res = await _api.post('/statement.php', body: {
        'action': 'generate',
        'from': DateFormat('yyyy-MM-dd').format(_from),
        'to': DateFormat('yyyy-MM-dd').format(_to),
        'user_id': user?.id ?? 0,
      });
      if (!mounted) return;
      final rawStatement = res['statement'];
      if (rawStatement is! Map) {
        throw StateError('Statement data was missing from the server response');
      }
      setState(() {
        _statement = Map<String, dynamic>.from(rawStatement);
        _loading = false;
      });
    } catch (e) {
      if (mounted) {
        setState(() {
          _error = '$e';
          _loading = false;
        });
      }
    }
  }

  Future<void> _promptAndEmailStatement() async {
    final user = AuthScope.of(context).user;
    final emailCtrl = TextEditingController(text: user?.email ?? '');
    final email = await showDialog<String>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Email Statement'),
        content: Column(
          mainAxisSize: MainAxisSize.min,
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            const Text(
              'Generate an official itemized statement and send it by email.',
              style: TextStyle(fontSize: 13, color: EcColors.muted),
            ),
            const SizedBox(height: 12),
            Text(
              'Period: ${_dateFmt.format(_from)} — ${_dateFmt.format(_to)}',
              style: const TextStyle(fontSize: 13, fontWeight: FontWeight.w600),
            ),
            const SizedBox(height: 16),
            const Text(
              'Recipient Email',
              style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
            ),
            const SizedBox(height: 8),
            TextField(
              controller: emailCtrl,
              keyboardType: TextInputType.emailAddress,
              autofocus: true,
              decoration: const InputDecoration(
                hintText: 'name@example.com',
                prefixIcon: Icon(Icons.email_outlined),
              ),
            ),
          ],
        ),
        actions: [
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Cancel'),
          ),
          FilledButton.icon(
            onPressed: () => Navigator.pop(ctx, emailCtrl.text.trim()),
            icon: const Icon(Icons.send_outlined, size: 18),
            label: const Text('Send Email'),
          ),
        ],
      ),
    );
    emailCtrl.dispose();
    if (email == null || !mounted) return;

    final trimmed = email.trim();
    if (trimmed.isEmpty || !trimmed.contains('@')) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Enter a valid recipient email')),
      );
      return;
    }

    setState(() {
      _emailing = true;
      _error = null;
    });
    try {
      final res = await _api.post('/statement.php', body: {
        'action': 'email',
        'from': DateFormat('yyyy-MM-dd').format(_from),
        'to': DateFormat('yyyy-MM-dd').format(_to),
        'user_id': user?.id ?? 0,
        'email': trimmed,
      });
      if (!mounted) return;
      final rawStatement = res['statement'];
      if (rawStatement is Map) {
        _statement = Map<String, dynamic>.from(rawStatement);
      }
      final sent = res['email_sent'] == true || res['success'] == true;
      final recipient = '${res['recipient_email'] ?? trimmed}';
      setState(() => _emailing = false);
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            sent
                ? 'Statement emailed to $recipient. If missing, check Spam/Junk.'
                : '${res['message'] ?? 'Could not send statement email'}',
          ),
          duration: const Duration(seconds: 5),
        ),
      );
    } catch (e) {
      if (mounted) {
        setState(() {
          _emailing = false;
          _error = '$e';
        });
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Could not email statement: $e')),
        );
      }
    }
  }

  Future<void> _copyStatement() async {
    final s = _statement;
    if (s == null) return;
    final entries = (s['entries'] as List? ?? []);
    final buf = StringBuffer()
      ..writeln('EbubeConnect — Statement of Account')
      ..writeln('Account: ${s['full_name']}')
      ..writeln('Period: ${s['period_from']} to ${s['period_to']}')
      ..writeln('Total credits: ${_money.format((s['total_credits'] as num?)?.toDouble() ?? 0)}')
      ..writeln('Total debits: ${_money.format((s['total_debits'] as num?)?.toDouble() ?? 0)}')
      ..writeln('Net: ${_money.format((s['net_movement'] as num?)?.toDouble() ?? 0)}')
      ..writeln('')
      ..writeln('Entries:');
    for (final e in entries) {
      final m = Map<String, dynamic>.from(e as Map);
      buf.writeln(
        '${m['date']} | ${m['type']} | ${m['description']} | ${_money.format((m['amount'] as num?)?.toDouble() ?? 0)}',
      );
    }
    try {
      await Clipboard.setData(ClipboardData(text: buf.toString()));
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Statement copied to clipboard')),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Could not copy statement: $e')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = AuthScope.of(context).user;
    final s = _statement;
    final busy = _loading || _emailing;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Statement of Account'),
        actions: [
          IconButton(
            onPressed: busy ? null : _promptAndEmailStatement,
            icon: const Icon(Icons.mark_email_read_outlined),
            tooltip: 'Email Statement',
          ),
          if (s != null)
            IconButton(
              onPressed: _copyStatement,
              icon: const Icon(Icons.copy),
              tooltip: 'Copy statement',
            ),
        ],
      ),
      body: ListView(
        padding: const EdgeInsets.all(16),
        children: [
          Card(
            child: Padding(
              padding: const EdgeInsets.all(16),
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                children: [
                  Text(
                    user?.fullName ?? 'Account',
                    style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
                  ),
                  Text(
                    user?.email ?? '',
                    style: const TextStyle(color: EcColors.muted, fontSize: 12),
                  ),
                  const SizedBox(height: 16),
                  Row(
                    children: [
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: busy ? null : _pickFrom,
                          icon: const Icon(Icons.calendar_today, size: 16),
                          label: Text('From ${_dateFmt.format(_from)}'),
                        ),
                      ),
                      const SizedBox(width: 8),
                      Expanded(
                        child: OutlinedButton.icon(
                          onPressed: busy ? null : _pickTo,
                          icon: const Icon(Icons.calendar_today, size: 16),
                          label: Text('To ${_dateFmt.format(_to)}'),
                        ),
                      ),
                    ],
                  ),
                  const SizedBox(height: 12),
                  SizedBox(
                    width: double.infinity,
                    child: FilledButton.icon(
                      onPressed: busy ? null : _generate,
                      icon: _loading
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.summarize_outlined),
                      label: Text(_loading ? 'Generating…' : 'Generate Statement'),
                    ),
                  ),
                  const SizedBox(height: 8),
                  SizedBox(
                    width: double.infinity,
                    child: OutlinedButton.icon(
                      onPressed: busy ? null : _promptAndEmailStatement,
                      icon: _emailing
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2),
                            )
                          : const Icon(Icons.email_outlined),
                      label: Text(_emailing ? 'Sending…' : 'Email Statement'),
                    ),
                  ),
                ],
              ),
            ),
          ),
          if (_error != null) ...[
            const SizedBox(height: 12),
            Text(_error!, style: const TextStyle(color: EcColors.danger)),
          ],
          if (s != null) ...[
            const SizedBox(height: 16),
            _SummaryCard(
              title: 'Summary',
              rows: [
                ('Funds received', _money.format((s['funding_received'] as num?)?.toDouble() ?? 0)),
                ('Funds sent', _money.format((s['funding_sent'] as num?)?.toDouble() ?? 0)),
                ('Airtime sales', _money.format((s['sales_airtime'] as num?)?.toDouble() ?? 0)),
                ('Data sales', _money.format((s['sales_data'] as num?)?.toDouble() ?? 0)),
                ('Total sales', _money.format((s['sales_total'] as num?)?.toDouble() ?? 0)),
                ('Profit earned', _money.format((s['profit_earned'] as num?)?.toDouble() ?? 0)),
                ('Total credits', _money.format((s['total_credits'] as num?)?.toDouble() ?? 0)),
                ('Total debits', _money.format((s['total_debits'] as num?)?.toDouble() ?? 0)),
                ('Net movement', _money.format((s['net_movement'] as num?)?.toDouble() ?? 0)),
                ('Entries', '${s['entry_count'] ?? 0}'),
              ],
            ),
            if (s['balances'] is Map) ...[
              const SizedBox(height: 12),
              _SummaryCard(
                title: 'Current balances',
                rows: [
                  ('MoMo Airtime', _money.format(_balance(s, 'momo_balance'))),
                  ('VTU Airtime', _money.format(_balance(s, 'vtu_balance'))),
                  ('Logical Airtime', _money.format(_balance(s, 'logical_balance'))),
                  ('Commission', _money.format(_balance(s, 'commission_balance'))),
                ],
              ),
            ],
            const SizedBox(height: 12),
            const Text(
              'Ledger',
              style: TextStyle(fontWeight: FontWeight.w700, color: EcColors.muted),
            ),
            const SizedBox(height: 8),
            ...((s['entries'] as List? ?? []).map((raw) {
              final e = Map<String, dynamic>.from(raw as Map);
              final isCredit = '${e['type']}' == 'credit';
              final network = '${e['network'] ?? 'MTN'}';
              return Card(
                margin: const EdgeInsets.only(bottom: 8),
                child: ListTile(
                  onTap: () {
                    if (isCredit) return;
                    final ref = '${e['reference'] ?? ''}'.trim();
                    if (ref.isEmpty) return;
                    final amount = (e['amount'] as num?)?.toDouble() ?? 0;
                    openPurchaseReceipt(
                      context,
                      receiptId: ref,
                      product: '${e['description'] ?? 'Transaction'}',
                      amount: amount,
                      status: '${e['status'] ?? 'Completed'}',
                      phone: '${e['phone'] ?? ''}',
                      network: network.isEmpty ? 'MTN' : network,
                      servedBy: '${e['served_by'] ?? ''}',
                      transactionAt: _parseDate(e['date']),
                    );
                  },
                  leading: isCredit
                      ? const CircleAvatar(
                          backgroundColor: EcColors.success,
                          child: Icon(Icons.add, color: Colors.white, size: 18),
                        )
                      : NetworkBadge(network: network, size: 36, compact: true),
                  title: Text(
                    '${e['description'] ?? ''}',
                    style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
                  ),
                  subtitle: Text(
                    [
                      _dateTimeFmt.format(_parseDate(e['date'])),
                      [
                        if ('${e['funded_by'] ?? e['served_by'] ?? ''}'.trim().isNotEmpty)
                          '${e['funded_by'] ?? e['served_by']}'.trim(),
                        'Ref: ${e['reference'] ?? ''}',
                      ].join(' · '),
                    ].join('\n'),
                    style: const TextStyle(fontSize: 11, color: EcColors.muted),
                  ),
                  isThreeLine: true,
                  trailing: Text(
                    '${isCredit ? '+' : '-'}${_money.format((e['amount'] as num?)?.toDouble() ?? 0)}',
                    style: TextStyle(
                      fontWeight: FontWeight.w800,
                      color: isCredit ? EcColors.success : EcColors.ink,
                    ),
                  ),
                ),
              );
            })),
          ],
        ],
      ),
    );
  }
}

class _SummaryCard extends StatelessWidget {
  const _SummaryCard({required this.title, required this.rows});

  final String title;
  final List<(String, String)> rows;

  @override
  Widget build(BuildContext context) {
    return Card(
      child: Padding(
        padding: const EdgeInsets.all(16),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Text(title, style: const TextStyle(fontWeight: FontWeight.w800)),
            const SizedBox(height: 10),
            for (final (label, value) in rows)
              Padding(
                padding: const EdgeInsets.only(bottom: 6),
                child: Row(
                  children: [
                    Expanded(child: Text(label, style: const TextStyle(color: EcColors.muted))),
                    Text(value, style: const TextStyle(fontWeight: FontWeight.w800)),
                  ],
                ),
              ),
          ],
        ),
      ),
    );
  }
}
