import 'package:flutter/material.dart';
import 'package:flutter/services.dart';
import 'package:intl/intl.dart';

import '../../config/theme.dart';
import '../../models/user.dart';
import '../../services/api_client.dart';
import '../../state/auth_controller.dart';

class FundWalletScreen extends StatefulWidget {
  const FundWalletScreen({super.key});

  @override
  State<FundWalletScreen> createState() => _FundWalletScreenState();
}

class _FundWalletScreenState extends State<FundWalletScreen> {
  final _api = ApiClient();
  final _searchCtrl = TextEditingController();
  final _amountCtrl = TextEditingController();
  final _walletIdCtrl = TextEditingController();

  bool _loadingUsers = false;
  bool _loadingPool = false;
  bool _funding = false;
  bool _resolvingWallet = false;
  String? _error;
  String? _resolvedWalletLabel;
  String? _resolvedWalletName;
  List<_FundUser> _users = [];
  _FundUser? _selected;
  String _product = 'vtu';
  bool _showSmobilePool = false;
  double _smobileBalance = 0;
  double _injectedTotal = 0;
  double _available = 0;
  double _myMomo = 0;
  double _myVtu = 0;
  double _myLogical = 0;

  final _money = NumberFormat.currency(symbol: '₦', decimalDigits: 2);

  String get _walletIdInput => _walletIdCtrl.text.trim().replaceAll(RegExp(r'\s+'), '');

  double get _availableForProduct {
    if (_showSmobilePool) return _available;
    switch (_product) {
      case 'momo':
        return _myMomo;
      case 'logical':
        return _myLogical;
      default:
        return _myVtu;
    }
  }

  static const _products = [
    ('vtu', 'VTU Airtime', Icons.sim_card_outlined),
    ('momo', 'MoMo Airtime', Icons.phone_android_outlined),
    ('logical', 'Logical Airtime', Icons.memory_outlined),
  ];

  @override
  void initState() {
    super.initState();
    WidgetsBinding.instance.addPostFrameCallback((_) {
      _loadPool();
      final actor = AuthScope.of(context).user;
      if (actor?.canManageUsers == true) {
        _loadUsers();
      }
    });
  }

  Future<void> _loadPool() async {
    final actor = AuthScope.of(context).user;
    if (actor == null || !actor.canManageUsers) return;
    setState(() => _loadingPool = true);
    try {
      final res = await _api.post(
        'wallet_pool_status.php',
        body: {'actor_id': actor.id},
        throwOnFailure: false,
      );
      if (!mounted) return;
      if (res['success'] != true) {
        setState(() => _loadingPool = false);
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('${res['message'] ?? 'Could not load wallet status'}')),
        );
        return;
      }
      final showPool = res['show_smobile_pool'] == true || res['mode'] == 'pool';
      setState(() {
        _showSmobilePool = showPool;
        if (showPool) {
          _smobileBalance = (res['smobile_balance'] as num?)?.toDouble() ?? 0;
          _injectedTotal = (res['injected_total'] as num?)?.toDouble() ?? 0;
          _available = (res['available_to_inject'] as num?)?.toDouble() ?? 0;
        } else {
          _myMomo = (res['momo_balance'] as num?)?.toDouble() ?? actor.momoBalance;
          _myVtu = (res['vtu_balance'] as num?)?.toDouble() ?? actor.vtuBalance;
          _myLogical = (res['logical_balance'] as num?)?.toDouble() ?? actor.logicalBalance;
        }
        _loadingPool = false;
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _loadingPool = false;
      });
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('Wallet status: $e')),
      );
    }
  }

  @override
  void dispose() {
    _searchCtrl.dispose();
    _amountCtrl.dispose();
    _walletIdCtrl.dispose();
    _api.close();
    super.dispose();
  }

  Future<void> _loadUsers() async {
    setState(() {
      _loadingUsers = true;
      _error = null;
    });
    try {
      final res = await _api.get('users_list.php');
      final list = (res['users'] as List? ?? [])
          .map((e) => _FundUser.fromJson(Map<String, dynamic>.from(e as Map)))
          .toList();
      if (!mounted) return;
      setState(() {
        _users = list;
        _loadingUsers = false;
        if (_selected != null) {
          _FundUser? match;
          for (final u in list) {
            if (u.id == _selected!.id) {
              match = u;
              break;
            }
          }
          _selected = match;
        }
      });
    } catch (e) {
      if (!mounted) return;
      setState(() {
        _error = '$e';
        _loadingUsers = false;
      });
    }
  }

  List<_FundUser> get _filtered {
    final actor = AuthScope.of(context).user;
    final q = _searchCtrl.text.trim().toLowerCase();
    return _users.where((u) {
      if (actor?.isAdmin == true && !actor!.isSuperAdmin) {
        // Admin can fund self, other Admins, Agents, Customers — never Super Admins.
        // Externals are already omitted by the API.
        if (u.role >= AgentUser.roleSuperAdmin) return false;
      }
      if (q.isEmpty) return true;
      return u.fullName.toLowerCase().contains(q) ||
          u.email.toLowerCase().contains(q) ||
          u.phone.toLowerCase().contains(q) ||
          (actor?.isSuperAdmin == true && u.walletId.toLowerCase().contains(q));
    }).toList();
  }

  Future<void> _resolveWalletId() async {
    final wid = _walletIdInput;
    if (wid.isEmpty) {
      setState(() {
        _resolvedWalletLabel = null;
        _resolvedWalletName = null;
        _resolvingWallet = false;
      });
      return;
    }
    setState(() => _resolvingWallet = true);
    try {
      final res = await _api.post(
        'users_wallet.php',
        body: {'action': 'resolve_wallet', 'wallet_id': wid},
        throwOnFailure: false,
      );
      if (!mounted) return;
      if (res['success'] == true) {
        setState(() {
          _resolvedWalletName = '${res['full_name'] ?? ''}'.trim();
          _resolvedWalletLabel = '${res['display_name'] ?? ''}'.trim();
          if (_resolvedWalletLabel == null || _resolvedWalletLabel!.isEmpty) {
            final name = _resolvedWalletName ?? '';
            _resolvedWalletLabel = name.isEmpty ? 'Wallet $wid' : '$name · Wallet $wid';
          }
          _resolvingWallet = false;
        });
      } else {
        setState(() {
          _resolvedWalletName = null;
          _resolvedWalletLabel = null;
          _resolvingWallet = false;
        });
      }
    } catch (_) {
      if (!mounted) return;
      setState(() {
        _resolvedWalletName = null;
        _resolvedWalletLabel = null;
        _resolvingWallet = false;
      });
    }
  }

  Future<void> _submit() async {
    final actor = AuthScope.of(context).user;
    if (actor == null || !actor.canManageUsers) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Admin access required to fund wallets.')),
      );
      return;
    }
    final byWalletId = !actor.isSuperAdmin && _walletIdInput.isNotEmpty;
    if (!byWalletId && _selected == null) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            actor.isSuperAdmin
                ? 'Select a user to fund.'
                : 'Select a user, or enter a Wallet ID.',
          ),
        ),
      );
      return;
    }
    final amount = double.tryParse(_amountCtrl.text.trim()) ?? 0;
    if (amount <= 0) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Enter a valid amount greater than zero.')),
      );
      return;
    }
    final available = _availableForProduct;
    final fundingSelf = !actor.isSuperAdmin && !byWalletId && _selected!.id == actor.id;
    if (!fundingSelf && amount > available + 0.001) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            _showSmobilePool
                ? 'Amount exceeds available pool (${_money.format(available)}). '
                    'Total funded cannot exceed master wallet.'
                : 'Amount exceeds your funded balance (${_money.format(available)}) for this wallet.',
          ),
        ),
      );
      return;
    }

    final productLabel = _products.firstWhere((p) => p.$1 == _product).$2;
    final targetLabel = byWalletId
        ? (_resolvedWalletLabel?.isNotEmpty == true
            ? _resolvedWalletLabel!
            : (_resolvedWalletName?.isNotEmpty == true
                ? '$_resolvedWalletName · Wallet $_walletIdInput'
                : 'Wallet ID $_walletIdInput'))
        : _selected!.fullName;
    final ok = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: Text(
          _showSmobilePool
              ? 'Confirm fund wallet'
              : (fundingSelf ? 'Confirm top up' : 'Confirm transfer'),
        ),
        content: Text(
          _showSmobilePool
              ? 'Add ${_money.format(amount)} to $targetLabel\'s $productLabel wallet?'
              : fundingSelf
                  ? 'Top up your $productLabel wallet by ${_money.format(amount)}?'
                  : 'Transfer ${_money.format(amount)} from your $productLabel balance to $targetLabel?',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(
            onPressed: () => Navigator.pop(ctx, true),
            child: Text(
              _showSmobilePool
                  ? 'Fund Wallet'
                  : (fundingSelf ? 'Top Up' : 'Transfer'),
            ),
          ),
        ],
      ),
    );
    if (ok != true || !mounted) return;

    setState(() => _funding = true);
    final clientRequestId =
        'fund-${DateTime.now().millisecondsSinceEpoch}-${actor.id}-${byWalletId ? _walletIdInput : _selected!.id}';
    try {
      await _api.post('users_wallet.php', body: {
        'action': 'fund',
        if (byWalletId) 'wallet_id': _walletIdInput else 'id': _selected!.id,
        'actor_id': actor.id,
        'product_id': _product,
        'amount': amount,
        'client_request_id': clientRequestId,
      });

      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            _showSmobilePool
                ? 'Funded ${_money.format(amount)} $productLabel into $targetLabel.'
                : 'Transferred ${_money.format(amount)} $productLabel to $targetLabel.',
          ),
        ),
      );
      _amountCtrl.clear();
      if (byWalletId) _walletIdCtrl.clear();
      await Future.wait([_loadUsers(), _loadPool()]);
      if (mounted) {
        await AuthScope.of(context).refreshWallet();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('$e')),
        );
      }
    } finally {
      if (mounted) setState(() => _funding = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final actor = AuthScope.of(context).user;
    final canViewPool = actor?.canManageUsers == true;
    final canInject = actor?.canManageUsers == true;
    final isSuperAdmin = actor?.isSuperAdmin == true;
    final available = _availableForProduct;

    if (!canViewPool) {
      return Scaffold(
        appBar: AppBar(title: const Text('Fund Wallet')),
        body: const Center(
          child: Padding(
            padding: EdgeInsets.all(24),
            child: Text(
              'Admin access required to fund wallets.',
              textAlign: TextAlign.center,
            ),
          ),
        ),
      );
    }

    final filtered = _filtered;

    return Scaffold(
      appBar: AppBar(
        title: const Text('Fund Wallet'),
        actions: [
          IconButton(
            onPressed: (_loadingUsers || _loadingPool)
                ? null
                : () {
                    _loadUsers();
                    _loadPool();
                  },
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh',
          ),
        ],
      ),
      body: Column(
        children: [
          Expanded(
            child: ListView(
              padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
              children: [
                Container(
                  padding: const EdgeInsets.all(14),
                  decoration: BoxDecoration(
                    color: EcColors.card,
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: Colors.grey.shade300),
                  ),
                  child: _loadingPool
                      ? const Padding(
                          padding: EdgeInsets.symmetric(vertical: 12),
                          child: Center(child: CircularProgressIndicator(strokeWidth: 2)),
                        )
                      : Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              isSuperAdmin ? 'Wallet pool' : 'Your funded balance',
                              style: const TextStyle(fontWeight: FontWeight.w800, fontSize: 14),
                            ),
                            if (isSuperAdmin) ...[
                              const SizedBox(height: 6),
                              const Text(
                                'Fund wallets from SMobile capacity. Total funded across all users cannot exceed the main wallet. SMobile only reduces when airtime/data is purchased.',
                                style: TextStyle(fontSize: 12, color: EcColors.muted),
                              ),
                            ],
                            const SizedBox(height: 12),
                            if (isSuperAdmin) ...[
                              _PoolStat(label: 'Main Wallet:', value: _money.format(_smobileBalance)),
                              const SizedBox(height: 6),
                              _PoolStat(label: 'Amount Funded:', value: _money.format(_injectedTotal)),
                              const SizedBox(height: 6),
                              _PoolStat(
                                label: 'Available balance:',
                                value: _money.format(available),
                                emphasize: true,
                              ),
                            ] else ...[
                              _PoolStat(label: 'VTU Airtime:', value: _money.format(_myVtu)),
                              const SizedBox(height: 6),
                              _PoolStat(label: 'MoMo Airtime:', value: _money.format(_myMomo)),
                              const SizedBox(height: 6),
                              _PoolStat(label: 'Logical Airtime:', value: _money.format(_myLogical)),
                              const SizedBox(height: 6),
                              _PoolStat(
                                label: 'Available to transfer:',
                                value: _money.format(available),
                                emphasize: true,
                              ),
                            ],
                          ],
                        ),
                ),
                if (canInject) ...[
                  const SizedBox(height: 20),
                  const Text('Wallet type', style: TextStyle(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 10),
                  Row(
                    children: [
                      for (var i = 0; i < _products.length; i++) ...[
                        if (i > 0) const SizedBox(width: 8),
                        Expanded(
                          child: _ProductChip(
                            label: _products[i].$2,
                            icon: _products[i].$3,
                            selected: _product == _products[i].$1,
                            onTap: () => setState(() => _product = _products[i].$1),
                          ),
                        ),
                      ],
                    ],
                  ),
                  const SizedBox(height: 20),
                  const Text('Amount (₦)', style: TextStyle(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 8),
                  TextField(
                    controller: _amountCtrl,
                    keyboardType: const TextInputType.numberWithOptions(decimal: true),
                    inputFormatters: [
                      FilteringTextInputFormatter.allow(RegExp(r'[0-9.]')),
                    ],
                    decoration: InputDecoration(
                      hintText: '5000',
                      prefixText: '₦ ',
                      helperText: isSuperAdmin
                          ? 'Available pool: ${_money.format(available)}'
                          : 'Your ${_products.firstWhere((p) => p.$1 == _product).$2} balance: ${_money.format(available)}',
                    ),
                  ),
                  const SizedBox(height: 20),
                  if (!isSuperAdmin) ...[
                    const Text('Transfer by Wallet ID', style: TextStyle(fontWeight: FontWeight.w700)),
                    const SizedBox(height: 6),
                    const Text(
                      'Enter a Wallet ID to transfer without selecting a user from the list.',
                      style: TextStyle(fontSize: 12, color: EcColors.muted),
                    ),
                    const SizedBox(height: 8),
                    TextField(
                      controller: _walletIdCtrl,
                      textCapitalization: TextCapitalization.none,
                      onChanged: (_) {
                        if (_walletIdCtrl.text.trim().isNotEmpty && _selected != null) {
                          setState(() => _selected = null);
                        } else {
                          setState(() {});
                        }
                        _resolveWalletId();
                      },
                      decoration: const InputDecoration(
                        hintText: 'e.g. 7k2M-83914X or WMX482917',
                        prefixIcon: Icon(Icons.qr_code_2_outlined),
                      ),
                    ),
                    if (_walletIdInput.isNotEmpty) ...[
                      const SizedBox(height: 8),
                      Container(
                        width: double.infinity,
                        padding: const EdgeInsets.all(12),
                        decoration: BoxDecoration(
                          color: Colors.indigo.withValues(alpha: 0.08),
                          borderRadius: BorderRadius.circular(12),
                        ),
                        child: _resolvingWallet
                            ? const Text(
                                'Looking up Wallet ID…',
                                style: TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
                              )
                            : Text(
                                _resolvedWalletLabel?.isNotEmpty == true
                                    ? 'Will transfer to: $_resolvedWalletLabel'
                                    : 'Will transfer to Wallet ID: $_walletIdInput',
                                style: const TextStyle(fontWeight: FontWeight.w700, fontSize: 13),
                              ),
                      ),
                    ],
                    const SizedBox(height: 16),
                    const Text('Or select a user', style: TextStyle(fontWeight: FontWeight.w700)),
                  ] else
                    const Text('Select user', style: TextStyle(fontWeight: FontWeight.w700)),
                  const SizedBox(height: 8),
                  TextField(
                    controller: _searchCtrl,
                    onChanged: (_) => setState(() {}),
                    decoration: InputDecoration(
                      hintText: isSuperAdmin
                          ? 'Search name, email, phone, wallet ID…'
                          : 'Search name, email, phone…',
                      prefixIcon: const Icon(Icons.search),
                    ),
                  ),
                  const SizedBox(height: 12),
                  if (_selected != null && _walletIdInput.isEmpty)
                    Container(
                      width: double.infinity,
                      padding: const EdgeInsets.all(12),
                      margin: const EdgeInsets.only(bottom: 12),
                      decoration: BoxDecoration(
                        color: EcColors.primary.withValues(alpha: 0.12),
                        borderRadius: BorderRadius.circular(12),
                      ),
                      child: Text(
                        isSuperAdmin && _selected!.isExternal
                            ? 'Selected: ${_selected!.fullName} · Wallet ${_selected!.walletId} · VTU ${_money.format(_selected!.vtu)}'
                            : 'Selected: ${_selected!.fullName} · VTU Airtime ${_money.format(_selected!.vtu)} · MoMo Airtime ${_money.format(_selected!.momo)} · Logical Airtime ${_money.format(_selected!.logical)}',
                        style: const TextStyle(fontWeight: FontWeight.w600, fontSize: 13),
                      ),
                    ),
                  Text(
                    'Users',
                    style: TextStyle(
                      fontWeight: FontWeight.w700,
                      color: Colors.grey.shade700,
                      fontSize: 13,
                    ),
                  ),
                  const SizedBox(height: 8),
                  SizedBox(
                    height: 280,
                    child: _loadingUsers
                        ? const Center(child: CircularProgressIndicator())
                        : _error != null
                            ? Center(child: Text(_error!, style: const TextStyle(color: EcColors.danger)))
                            : filtered.isEmpty
                                ? const Center(child: Text('No users found'))
                                : ListView.separated(
                                    itemCount: filtered.length,
                                    separatorBuilder: (_, _) => const SizedBox(height: 6),
                                    itemBuilder: (context, i) {
                                      final u = filtered[i];
                                      final selected = _selected?.id == u.id && _walletIdInput.isEmpty;
                                      return ListTile(
                                        selected: selected,
                                        selectedTileColor: EcColors.primary.withValues(alpha: 0.12),
                                        shape: RoundedRectangleBorder(
                                          borderRadius: BorderRadius.circular(10),
                                        ),
                                        leading: CircleAvatar(
                                          child: Text(
                                            u.fullName.isNotEmpty ? u.fullName[0].toUpperCase() : '?',
                                          ),
                                        ),
                                        title: Text(u.fullName, style: const TextStyle(fontWeight: FontWeight.w700)),
                                        subtitle: Text(
                                          isSuperAdmin && u.isExternal
                                              ? '${u.roleName} · Wallet ${u.walletId}\nVTU ${_money.format(u.vtu)} · MoMo ${_money.format(u.momo)}'
                                              : '${u.roleName} · ${u.email}\nVTU Airtime ${_money.format(u.vtu)} · MoMo Airtime ${_money.format(u.momo)}',
                                        ),
                                        isThreeLine: true,
                                        onTap: () => setState(() {
                                          _selected = u;
                                          _walletIdCtrl.clear();
                                        }),
                                      );
                                    },
                                  ),
                  ),
                ],
              ],
            ),
          ),
          if (canInject)
            SafeArea(
              top: false,
              child: Material(
                elevation: 8,
                color: Theme.of(context).scaffoldBackgroundColor,
                child: Padding(
                  padding: const EdgeInsets.fromLTRB(16, 10, 16, 12),
                  child: SizedBox(
                    width: double.infinity,
                    child: FilledButton.icon(
                      onPressed: _funding ? null : _submit,
                      icon: _funding
                          ? const SizedBox(
                              width: 18,
                              height: 18,
                              child: CircularProgressIndicator(strokeWidth: 2, color: EcColors.ink),
                            )
                          : Icon(isSuperAdmin ? Icons.add_card : Icons.swap_horiz),
                      label: Text(
                        _funding
                            ? (isSuperAdmin ? 'Funding…' : 'Transferring…')
                            : (isSuperAdmin ? 'Fund Wallet' : 'Transfer Balance'),
                      ),
                    ),
                  ),
                ),
              ),
            ),
        ],
      ),
    );
  }
}

class _PoolStat extends StatelessWidget {
  const _PoolStat({
    required this.label,
    required this.value,
    this.emphasize = false,
  });

  final String label;
  final String value;
  final bool emphasize;

  @override
  Widget build(BuildContext context) {
    return Row(
      children: [
        Expanded(
          child: Text(
            label,
            style: TextStyle(
              fontSize: 13,
              color: emphasize ? EcColors.ink : EcColors.muted,
              fontWeight: emphasize ? FontWeight.w700 : FontWeight.w500,
            ),
          ),
        ),
        Text(
          value,
          style: TextStyle(
            fontSize: 13,
            fontWeight: FontWeight.w800,
            color: emphasize ? EcColors.primaryDark : EcColors.ink,
          ),
        ),
      ],
    );
  }
}

class _ProductChip extends StatelessWidget {
  const _ProductChip({
    required this.label,
    required this.icon,
    required this.selected,
    required this.onTap,
  });

  final String label;
  final IconData icon;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return InkWell(
      onTap: onTap,
      borderRadius: BorderRadius.circular(12),
      child: AnimatedContainer(
        duration: const Duration(milliseconds: 160),
        padding: const EdgeInsets.symmetric(vertical: 12),
        decoration: BoxDecoration(
          color: selected ? EcColors.primary : EcColors.card,
          borderRadius: BorderRadius.circular(12),
          border: Border.all(
            color: selected ? EcColors.primaryDark : Colors.grey.shade300,
            width: selected ? 2 : 1,
          ),
        ),
        child: Column(
          children: [
            Icon(icon, color: selected ? EcColors.ink : EcColors.muted),
            const SizedBox(height: 4),
            Text(
              label,
              textAlign: TextAlign.center,
              maxLines: 2,
              overflow: TextOverflow.ellipsis,
              style: TextStyle(
                fontWeight: FontWeight.w700,
                fontSize: 10,
                height: 1.1,
                color: selected ? EcColors.ink : EcColors.muted,
              ),
            ),
          ],
        ),
      ),
    );
  }
}

class _FundUser {
  _FundUser({
    required this.id,
    required this.fullName,
    required this.email,
    required this.phone,
    required this.role,
    required this.momo,
    required this.vtu,
    required this.logical,
    this.isExternal = false,
    this.walletId = '',
  });

  final int id;
  final String fullName;
  final String email;
  final String phone;
  final int role;
  final double momo;
  final double vtu;
  final double logical;
  final bool isExternal;
  final String walletId;

  String get roleName {
    switch (role) {
      case AgentUser.roleSuperAdmin:
        return 'Super Admin';
      case AgentUser.roleAdmin:
        return 'Admin';
      case AgentUser.roleAgent:
        return 'Agent';
      default:
        return 'Customer';
    }
  }

  factory _FundUser.fromJson(Map<String, dynamic> json) {
    double money(dynamic v) => (v is num) ? v.toDouble() : double.tryParse('$v') ?? 0;
    return _FundUser(
      id: (json['id'] as num?)?.toInt() ?? 0,
      fullName: '${json['full_name'] ?? ''}',
      email: '${json['email'] ?? ''}',
      phone: '${json['phone'] ?? ''}',
      role: (json['role'] as num?)?.toInt() ?? 0,
      momo: money(json['momo_balance']),
      vtu: money(json['vtu_balance']),
      logical: money(json['logical_balance']),
      isExternal: json['is_external'] == true || json['is_external'] == 1,
      walletId: '${json['wallet_id'] ?? ''}'.trim(),
    );
  }
}
