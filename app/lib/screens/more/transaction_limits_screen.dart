import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';

import '../../config/theme.dart';
import '../../models/user.dart';
import '../../services/api_client.dart';
import '../../state/auth_controller.dart';

/// Super Admin: default + account-type / user transaction limit settings.
class TransactionLimitsScreen extends StatefulWidget {
  const TransactionLimitsScreen({super.key});

  @override
  State<TransactionLimitsScreen> createState() => _TransactionLimitsScreenState();
}

class _TransactionLimitsScreenState extends State<TransactionLimitsScreen> {
  final _api = ApiClient();
  final _money = NumberFormat.currency(symbol: '₦', decimalDigits: 0);
  bool _loading = true;
  String? _error;
  List<_ProductLimit> _limits = [];
  List<_LimitRule> _rules = [];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      final user = AuthScope.of(context).user;
      if (user == null || !user.isSuperAdmin) {
        if (mounted) {
          setState(() {
            _loading = false;
            _error = null;
          });
        }
        return;
      }
      _load();
    });
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
      final data = await _api.post('transaction_limits.php', body: {'action': 'list'});
      final limits = <_ProductLimit>[];
      final rawLimits = data['limits'];
      if (rawLimits is List) {
        for (final item in rawLimits) {
          if (item is Map) {
            limits.add(_ProductLimit.fromJson(Map<String, dynamic>.from(item)));
          }
        }
      }
      final rules = <_LimitRule>[];
      final rawRules = data['rules'];
      if (rawRules is List) {
        for (final item in rawRules) {
          if (item is Map) {
            rules.add(_LimitRule.fromJson(Map<String, dynamic>.from(item)));
          }
        }
      }
      if (!mounted) return;
      setState(() {
        _limits = limits;
        _rules = rules;
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

  String _periodLabel(int days) => days <= 1 ? '1 day' : '$days days';

  Future<void> _editDefault(_ProductLimit limit) async {
    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => _LimitEditorDialog(
        title: 'Default — ${limit.label}',
        initial: _LimitFormValues(
          productId: limit.productId,
          maxPerTransaction: limit.maxPerTransaction,
          dailyLimit: limit.dailyLimit,
          maxPerSim: limit.maxPerSim,
          limitDays: limit.limitDays,
        ),
        mode: _LimitEditorMode.defaultProduct,
      ),
    );
    if (saved == true) await _load();
  }

  Future<void> _addOrEditRule({_LimitRule? existing}) async {
    final saved = await showDialog<bool>(
      context: context,
      builder: (ctx) => _LimitEditorDialog(
        title: existing == null ? 'Add custom rule' : 'Edit custom rule',
        initial: _LimitFormValues(
          ruleId: existing?.id ?? 0,
          productId: existing?.productId ?? 'vtu',
          maxPerTransaction: existing?.maxPerTransaction ?? 50000,
          dailyLimit: existing?.dailyLimit ?? 1000000,
          maxPerSim: existing?.maxPerSim ?? 100000,
          limitDays: existing?.limitDays ?? 1,
          scopeType: existing?.scopeType ?? 'role',
          scopeRole: existing?.scopeRole ?? AgentUser.roleAgent,
          scopeUserId: existing?.scopeUserId,
          scopeLabel: existing?.scopeLabel,
        ),
        mode: _LimitEditorMode.rule,
      ),
    );
    if (saved == true) await _load();
  }

  Future<void> _deleteRule(_LimitRule rule) async {
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete rule?'),
        content: Text('${rule.productLabel} · ${rule.scopeLabel}'),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Delete')),
        ],
      ),
    );
    if (ok != true) return;
    try {
      await _api.post('transaction_limits.php', body: {
        'action': 'delete_rule',
        'id': rule.id,
      });
      await _load();
    } catch (e) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
    }
  }

  @override
  Widget build(BuildContext context) {
    final user = AuthScope.of(context).user;
    if (user == null || !user.isSuperAdmin) {
      return Scaffold(
        appBar: AppBar(title: const Text('Transaction Limit Setting')),
        body: const Center(
          child: Padding(
            padding: EdgeInsets.all(24),
            child: Text(
              'Access denied.',
              textAlign: TextAlign.center,
            ),
          ),
        ),
      );
    }

    return Scaffold(
      appBar: AppBar(
        title: const Text('Transaction Limit Setting'),
        actions: [
          IconButton(
            onPressed: _loading ? null : _load,
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh',
          ),
        ],
      ),
      floatingActionButton: _loading
          ? null
          : FloatingActionButton.extended(
              onPressed: () => _addOrEditRule(),
              icon: const Icon(Icons.add),
              label: const Text('Add rule'),
            ),
      body: _loading
          ? const Center(child: CircularProgressIndicator())
          : _error != null
              ? Center(child: Text(_error!, style: const TextStyle(color: EcColors.danger)))
              : ListView(
                  padding: const EdgeInsets.fromLTRB(16, 16, 16, 100),
                  children: [
                    _infoCard(
                      'Defaults apply to everyone. '
                      'Custom rules can target an account type (Customer / Agent / Admin) '
                      'or one user, for VTU, MoMo, or Logical. '
                      'Priority: user rule → account type → default. '
                      'Your account is not subject to these limits.',
                    ),
                    const SizedBox(height: 18),
                    const Text(
                      'Default limits',
                      style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15),
                    ),
                    const SizedBox(height: 10),
                    ..._limits.map((limit) => _limitCard(
                          title: limit.label,
                          subtitle: 'All users (unless a rule overrides)',
                          limit: limit,
                          onTap: () => _editDefault(limit),
                        )),
                    const SizedBox(height: 18),
                    const Text(
                      'Custom rules',
                      style: TextStyle(fontWeight: FontWeight.w800, fontSize: 15),
                    ),
                    const SizedBox(height: 10),
                    if (_rules.isEmpty)
                      _infoCard('No custom rules yet. Tap Add rule to set limits for an account type or a specific user.')
                    else
                      ..._rules.map((rule) {
                        return Padding(
                          padding: const EdgeInsets.only(bottom: 12),
                          child: Material(
                            color: EcColors.card,
                            borderRadius: BorderRadius.circular(12),
                            child: InkWell(
                              borderRadius: BorderRadius.circular(12),
                              onTap: () => _addOrEditRule(existing: rule),
                              child: Container(
                                padding: const EdgeInsets.all(16),
                                decoration: BoxDecoration(
                                  borderRadius: BorderRadius.circular(12),
                                  border: Border.all(color: Colors.grey.shade300),
                                ),
                                child: Column(
                                  crossAxisAlignment: CrossAxisAlignment.start,
                                  children: [
                                    Row(
                                      children: [
                                        Expanded(
                                          child: Text(
                                            '${rule.productLabel} · ${rule.scopeLabel}',
                                            style: const TextStyle(
                                              fontWeight: FontWeight.w800,
                                              fontSize: 15,
                                            ),
                                          ),
                                        ),
                                        IconButton(
                                          onPressed: () => _deleteRule(rule),
                                          icon: const Icon(Icons.delete_outline, color: EcColors.danger),
                                          tooltip: 'Delete',
                                        ),
                                      ],
                                    ),
                                    Text(
                                      rule.scopeType == 'user'
                                          ? 'Specific user${rule.userEmail.isNotEmpty ? ' · ${rule.userEmail}' : ''}'
                                          : 'Account type',
                                      style: const TextStyle(fontSize: 12, color: EcColors.muted),
                                    ),
                                    const SizedBox(height: 10),
                                    _LimitRow(label: 'Max per buy', value: _money.format(rule.maxPerTransaction)),
                                    const SizedBox(height: 6),
                                    _LimitRow(
                                      label: 'Wallet limit',
                                      value: '${_money.format(rule.dailyLimit)} (${_periodLabel(rule.limitDays)})',
                                    ),
                                    const SizedBox(height: 6),
                                    _LimitRow(
                                      label: 'Max per SIM',
                                      value: '${_money.format(rule.maxPerSim)} (${_periodLabel(rule.limitDays)})',
                                    ),
                                  ],
                                ),
                              ),
                            ),
                          ),
                        );
                      }),
                  ],
                ),
    );
  }

  Widget _infoCard(String text) {
    return Container(
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: EcColors.card,
        borderRadius: BorderRadius.circular(12),
        border: Border.all(color: Colors.grey.shade300),
      ),
      child: Text(text, style: const TextStyle(fontSize: 13, color: EcColors.muted, height: 1.35)),
    );
  }

  Widget _limitCard({
    required String title,
    required String subtitle,
    required _ProductLimit limit,
    required VoidCallback onTap,
  }) {
    return Padding(
      padding: const EdgeInsets.only(bottom: 12),
      child: Material(
        color: EcColors.card,
        borderRadius: BorderRadius.circular(12),
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: onTap,
          child: Container(
            padding: const EdgeInsets.all(16),
            decoration: BoxDecoration(
              borderRadius: BorderRadius.circular(12),
              border: Border.all(color: Colors.grey.shade300),
            ),
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: [
                Row(
                  children: [
                    Expanded(
                      child: Text(title, style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 16)),
                    ),
                    Icon(Icons.edit_outlined, size: 18, color: Colors.grey.shade600),
                  ],
                ),
                const SizedBox(height: 4),
                Text(subtitle, style: const TextStyle(fontSize: 12, color: EcColors.muted)),
                const SizedBox(height: 12),
                _LimitRow(label: 'Max per buy', value: _money.format(limit.maxPerTransaction)),
                const SizedBox(height: 8),
                _LimitRow(
                  label: 'Wallet limit',
                  value: '${_money.format(limit.dailyLimit)} (${_periodLabel(limit.limitDays)})',
                ),
                const SizedBox(height: 8),
                _LimitRow(
                  label: 'Max per SIM',
                  value: '${_money.format(limit.maxPerSim)} (${_periodLabel(limit.limitDays)})',
                ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _LimitRow extends StatelessWidget {
  const _LimitRow({required this.label, required this.value});

  final String label;
  final String value;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(child: Text(label, style: const TextStyle(fontSize: 13, color: EcColors.muted))),
        Text(value, style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13)),
      ],
    );
  }
}

enum _LimitEditorMode { defaultProduct, rule }

class _LimitFormValues {
  _LimitFormValues({
    this.ruleId = 0,
    required this.productId,
    required this.maxPerTransaction,
    required this.dailyLimit,
    required this.maxPerSim,
    required this.limitDays,
    this.scopeType = 'role',
    this.scopeRole = AgentUser.roleAgent,
    this.scopeUserId,
    this.scopeLabel,
  });

  final int ruleId;
  final String productId;
  final double maxPerTransaction;
  final double dailyLimit;
  final double maxPerSim;
  final int limitDays;
  final String scopeType;
  final int scopeRole;
  final int? scopeUserId;
  final String? scopeLabel;
}

class _LimitEditorDialog extends StatefulWidget {
  const _LimitEditorDialog({
    required this.title,
    required this.initial,
    required this.mode,
  });

  final String title;
  final _LimitFormValues initial;
  final _LimitEditorMode mode;

  @override
  State<_LimitEditorDialog> createState() => _LimitEditorDialogState();
}

class _LimitEditorDialogState extends State<_LimitEditorDialog> {
  final _api = ApiClient();
  late final TextEditingController _maxCtrl;
  late final TextEditingController _dailyCtrl;
  late final TextEditingController _simCtrl;
  late String _productId;
  late String _scopeType;
  late int _scopeRole;
  late int _days;
  int? _scopeUserId;
  String? _scopeUserLabel;
  bool _saving = false;
  List<_UserOption> _users = [];
  bool _loadingUsers = false;

  @override
  void initState() {
    super.initState();
    final i = widget.initial;
    _maxCtrl = TextEditingController(text: i.maxPerTransaction.toStringAsFixed(0));
    _dailyCtrl = TextEditingController(text: i.dailyLimit.toStringAsFixed(0));
    _simCtrl = TextEditingController(text: i.maxPerSim.toStringAsFixed(0));
    _productId = i.productId;
    _scopeType = i.scopeType;
    _scopeRole = i.scopeRole;
    _scopeUserId = i.scopeUserId;
    _scopeUserLabel = i.scopeLabel;
    // Dropdown only offers 1/7/14/30 — snap unknown stored values to avoid crash.
    const allowedDays = [1, 7, 14, 30];
    final rawDays = i.limitDays.clamp(1, 30);
    _days = allowedDays.contains(rawDays)
        ? rawDays
        : allowedDays.reduce((a, b) => (rawDays - a).abs() <= (rawDays - b).abs() ? a : b);
    if (widget.mode == _LimitEditorMode.rule) {
      _loadUsers();
    }
  }

  @override
  void dispose() {
    _maxCtrl.dispose();
    _dailyCtrl.dispose();
    _simCtrl.dispose();
    _api.close();
    super.dispose();
  }

  Future<void> _loadUsers() async {
    setState(() => _loadingUsers = true);
    try {
      final res = await _api.get('users_list.php');
      final list = <_UserOption>[];
      for (final item in (res['users'] as List? ?? [])) {
        if (item is! Map) continue;
        final m = Map<String, dynamic>.from(item);
        final role = (m['role'] as num?)?.toInt() ?? 0;
        if (role >= AgentUser.roleSuperAdmin) continue;
        list.add(_UserOption(
          id: (m['id'] as num?)?.toInt() ?? 0,
          name: '${m['full_name'] ?? ''}',
          email: '${m['email'] ?? ''}',
          role: role,
        ));
      }
      if (!mounted) return;
      setState(() {
        _users = list;
        _loadingUsers = false;
      });
    } catch (_) {
      if (!mounted) return;
      setState(() => _loadingUsers = false);
    }
  }

  Future<void> _save() async {
    final maxOnce = double.tryParse(_maxCtrl.text.trim()) ?? -1;
    final daily = double.tryParse(_dailyCtrl.text.trim()) ?? -1;
    final maxSim = double.tryParse(_simCtrl.text.trim()) ?? -1;
    if (maxOnce < 0 || daily < 0 || maxSim < 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Enter valid amounts (0 or greater).')),
      );
      return;
    }
    if (widget.mode == _LimitEditorMode.rule && _scopeType == 'user' && (_scopeUserId == null || _scopeUserId! <= 0)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Pick a user for this rule.')),
      );
      return;
    }

    setState(() => _saving = true);
    try {
      if (widget.mode == _LimitEditorMode.defaultProduct) {
        await _api.post('transaction_limits.php', body: {
          'action': 'save',
          'product_id': _productId,
          'max_per_transaction': maxOnce,
          'daily_limit': daily,
          'max_per_sim': maxSim,
          'limit_days': _days,
        });
      } else {
        await _api.post('transaction_limits.php', body: {
          'action': 'save_rule',
          'id': widget.initial.ruleId,
          'product_id': _productId,
          'scope_type': _scopeType,
          'scope_role': _scopeType == 'role' ? _scopeRole : null,
          'scope_user_id': _scopeType == 'user' ? _scopeUserId : null,
          'max_per_transaction': maxOnce,
          'daily_limit': daily,
          'max_per_sim': maxSim,
          'limit_days': _days,
        });
      }
      if (!mounted) return;
      Navigator.pop(context, true);
    } catch (e) {
      if (!mounted) return;
      setState(() => _saving = false);
      ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
    }
  }

  @override
  Widget build(BuildContext context) {
    return AlertDialog(
      title: Text(widget.title),
      content: SizedBox(
        width: 420,
        child: SingleChildScrollView(
          child: Column(
            mainAxisSize: MainAxisSize.min,
            children: [
              if (widget.mode == _LimitEditorMode.rule) ...[
                DropdownButtonFormField<String>(
                  initialValue: _productId,
                  decoration: const InputDecoration(labelText: 'Wallet'),
                  items: const [
                    DropdownMenuItem(value: 'vtu', child: Text('VTU Airtime')),
                    DropdownMenuItem(value: 'momo', child: Text('MoMo Airtime')),
                    DropdownMenuItem(value: 'logical', child: Text('Logical Airtime')),
                  ],
                  onChanged: _saving ? null : (v) {
                    if (v != null) setState(() => _productId = v);
                  },
                ),
                const SizedBox(height: 12),
                DropdownButtonFormField<String>(
                  initialValue: _scopeType,
                  decoration: const InputDecoration(labelText: 'Apply to'),
                  items: const [
                    DropdownMenuItem(value: 'role', child: Text('Account type')),
                    DropdownMenuItem(value: 'user', child: Text('Particular user')),
                  ],
                  onChanged: _saving ? null : (v) {
                    if (v != null) setState(() => _scopeType = v);
                  },
                ),
                const SizedBox(height: 12),
                if (_scopeType == 'role')
                  DropdownButtonFormField<int>(
                    initialValue: _scopeRole,
                    decoration: const InputDecoration(labelText: 'Account type'),
                    items: const [
                      DropdownMenuItem(value: AgentUser.roleCustomer, child: Text('Customer')),
                      DropdownMenuItem(value: AgentUser.roleAgent, child: Text('Agent')),
                      DropdownMenuItem(value: AgentUser.roleAdmin, child: Text('Admin')),
                    ],
                    onChanged: _saving ? null : (v) {
                      if (v != null) setState(() => _scopeRole = v);
                    },
                  )
                else if (_loadingUsers)
                  const Padding(
                    padding: EdgeInsets.all(12),
                    child: Center(child: CircularProgressIndicator()),
                  )
                else
                  DropdownButtonFormField<int>(
                    initialValue: _users.any((u) => u.id == _scopeUserId) ? _scopeUserId : null,
                    decoration: InputDecoration(
                      labelText: 'User',
                      helperText: _scopeUserLabel,
                    ),
                    items: _users
                        .map(
                          (u) => DropdownMenuItem(
                            value: u.id,
                            child: Text('${u.name} (${u.roleName})', overflow: TextOverflow.ellipsis),
                          ),
                        )
                        .toList(),
                    onChanged: _saving
                        ? null
                        : (v) {
                            if (v == null) return;
                            final u = _users.firstWhere((e) => e.id == v);
                            setState(() {
                              _scopeUserId = v;
                              _scopeUserLabel = u.name;
                            });
                          },
                  ),
                const SizedBox(height: 12),
              ],
              TextField(
                controller: _maxCtrl,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                decoration: const InputDecoration(
                  labelText: 'Max per buy (₦)',
                  helperText: '0 = no limit for this cap',
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _dailyCtrl,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                decoration: const InputDecoration(
                  labelText: 'Wallet limit (₦)',
                  helperText: '0 = no limit · total over selected days',
                ),
              ),
              const SizedBox(height: 12),
              TextField(
                controller: _simCtrl,
                keyboardType: TextInputType.number,
                inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                decoration: const InputDecoration(
                  labelText: 'Max per SIM (₦)',
                  helperText: '0 = no limit · one phone over selected days',
                ),
              ),
              const SizedBox(height: 12),
              DropdownButtonFormField<int>(
                initialValue: _days,
                decoration: const InputDecoration(
                  labelText: 'Duration',
                  helperText: 'Applies to wallet limit and max per SIM',
                ),
                items: const [
                  DropdownMenuItem(value: 1, child: Text('1 Day')),
                  DropdownMenuItem(value: 7, child: Text('7 Days')),
                  DropdownMenuItem(value: 14, child: Text('14 Days')),
                  DropdownMenuItem(value: 30, child: Text('30 Days')),
                ],
                onChanged: _saving
                    ? null
                    : (v) {
                        if (v != null) setState(() => _days = v);
                      },
              ),
            ],
          ),
        ),
      ),
      actions: [
        TextButton(
          onPressed: _saving ? null : () => Navigator.pop(context, false),
          child: const Text('Cancel'),
        ),
        FilledButton(
          onPressed: _saving ? null : _save,
          child: Text(_saving ? 'Saving…' : 'Save'),
        ),
      ],
    );
  }
}

class _UserOption {
  _UserOption({
    required this.id,
    required this.name,
    required this.email,
    required this.role,
  });

  final int id;
  final String name;
  final String email;
  final int role;

  String get roleName {
    switch (role) {
      case AgentUser.roleAdmin:
        return 'Admin';
      case AgentUser.roleAgent:
        return 'Agent';
      default:
        return 'Customer';
    }
  }
}

class _ProductLimit {
  _ProductLimit({
    required this.productId,
    required this.label,
    required this.maxPerTransaction,
    required this.dailyLimit,
    required this.maxPerSim,
    required this.limitDays,
  });

  final String productId;
  final String label;
  final double maxPerTransaction;
  final double dailyLimit;
  final double maxPerSim;
  final int limitDays;

  factory _ProductLimit.fromJson(Map<String, dynamic> json) {
    double n(dynamic v) => (v is num) ? v.toDouble() : double.tryParse('$v') ?? 0;
    return _ProductLimit(
      productId: '${json['product_id'] ?? ''}',
      label: '${json['label'] ?? json['product_id'] ?? ''}',
      maxPerTransaction: n(json['max_per_transaction']),
      dailyLimit: n(json['daily_limit']),
      maxPerSim: n(json['max_per_sim']),
      limitDays: (json['limit_days'] as num?)?.toInt() ?? 1,
    );
  }
}

class _LimitRule {
  _LimitRule({
    required this.id,
    required this.productId,
    required this.productLabel,
    required this.scopeType,
    required this.scopeRole,
    required this.scopeUserId,
    required this.scopeLabel,
    required this.userEmail,
    required this.maxPerTransaction,
    required this.dailyLimit,
    required this.maxPerSim,
    required this.limitDays,
  });

  final int id;
  final String productId;
  final String productLabel;
  final String scopeType;
  final int? scopeRole;
  final int? scopeUserId;
  final String scopeLabel;
  final String userEmail;
  final double maxPerTransaction;
  final double dailyLimit;
  final double maxPerSim;
  final int limitDays;

  factory _LimitRule.fromJson(Map<String, dynamic> json) {
    double n(dynamic v) => (v is num) ? v.toDouble() : double.tryParse('$v') ?? 0;
    return _LimitRule(
      id: (json['id'] as num?)?.toInt() ?? 0,
      productId: '${json['product_id'] ?? ''}',
      productLabel: '${json['product_label'] ?? json['product_id'] ?? ''}',
      scopeType: '${json['scope_type'] ?? ''}',
      scopeRole: (json['scope_role'] as num?)?.toInt(),
      scopeUserId: (json['scope_user_id'] as num?)?.toInt(),
      scopeLabel: '${json['scope_label'] ?? ''}',
      userEmail: '${json['user_email'] ?? ''}',
      maxPerTransaction: n(json['max_per_transaction']),
      dailyLimit: n(json['daily_limit']),
      maxPerSim: n(json['max_per_sim']),
      limitDays: (json['limit_days'] as num?)?.toInt() ?? 1,
    );
  }
}
