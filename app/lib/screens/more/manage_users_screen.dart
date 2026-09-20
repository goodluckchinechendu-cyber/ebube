import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

import '../../config/theme.dart';
import '../../models/user.dart';
import '../../services/api_client.dart';
import '../../state/auth_controller.dart';

class ManageUsersScreen extends StatefulWidget {
  const ManageUsersScreen({super.key});

  @override
  State<ManageUsersScreen> createState() => _ManageUsersScreenState();
}

class _ManageUsersScreenState extends State<ManageUsersScreen> {
  final _api = ApiClient();
  final _searchCtrl = TextEditingController();
  bool _loading = false;
  String? _error;
  List<_UserRow> _users = [];
  String _filterRole = 'All';
  String _filterVisibility = 'All'; // Super Admin only: All | Internal | External

  AgentUser? get _actor => AuthScope.of(context).user;

  @override
  void initState() {
    super.initState();
    _load();
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
      final res = await _api.get('/users_list.php');
      final list = (res['users'] as List? ?? [])
          .map((e) => _UserRow.fromJson(Map<String, dynamic>.from(e as Map)))
          .toList();
      if (mounted) {
        setState(() {
          _users = list;
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

  List<_UserRow> get _filtered {
    final q = _searchCtrl.text.trim().toLowerCase();
    final actor = _actor;
    return _users.where((u) {
      if (actor?.isAdmin == true && !actor!.isSuperAdmin && u.role >= AgentUser.roleSuperAdmin) {
        return false;
      }
      final matchSearch = q.isEmpty ||
          u.fullName.toLowerCase().contains(q) ||
          u.email.toLowerCase().contains(q) ||
          u.phone.toLowerCase().contains(q);
      final matchRole = _filterRole == 'All' || u.roleName == _filterRole;
      final matchVis = actor?.isSuperAdmin != true
          || _filterVisibility == 'All'
          || (_filterVisibility == 'External' && u.isExternal)
          || (_filterVisibility == 'Internal' && !u.isExternal);
      return matchSearch && matchRole && matchVis;
    }).toList();
  }

  Future<void> _openEdit(_UserRow user) async {
    final actor = _actor;
    if (actor == null) return;
    final target = user.toAgentUser();
    if (!actor.canEditUserProfile(target)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Admin can only edit Agents and Customers.'),
        ),
      );
      return;
    }

    final saved = await showModalBottomSheet<bool>(
      context: context,
      isScrollControlled: true,
      useSafeArea: true,
      builder: (ctx) => _EditUserSheet(
        user: user,
        actor: actor,
        api: _api,
        allUsers: _users,
      ),
    );
    if (saved == true && mounted) {
      _load();
    }
  }

  Future<void> _setRole(_UserRow user, int newRole) async {
    final actor = _actor;
    if (actor == null || !actor.canManageUsers) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('You do not have permission to change roles.')),
      );
      return;
    }

    final target = user.toAgentUser();
    if (!actor.canEditUserRole(target)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(
          content: Text('Admin can only edit Agents and Customers.'),
        ),
      );
      return;
    }

    if (!actor.assignableRoles.contains(newRole)) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('You cannot assign that role.')),
      );
      return;
    }

    final roleNames = {
      0: 'Customer',
      1: 'Agent',
      2: 'Admin',
      3: 'Super Admin',
    };

    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Change Role'),
        content: Text(
          'Set ${user.fullName}\'s role to "${roleNames[newRole]}"?',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(onPressed: () => Navigator.pop(ctx, true), child: const Text('Confirm')),
        ],
      ),
    );
    if (confirm != true) return;

    try {
      await _api.post('/users_update.php', body: {
        'action': 'set_role',
        'id': user.id,
        'role': newRole,
        'actor_id': actor.id,
      });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('${user.fullName} is now a ${roleNames[newRole]}.')),
        );
        _load();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Failed to update role: $e')),
        );
      }
    }
  }

  Future<void> _setVisibility(_UserRow user, bool external) async {
    final actor = _actor;
    if (actor == null || !actor.isSuperAdmin) return;
    try {
      await _api.post('/users_update.php', body: {
        'action': 'set_visibility',
        'id': user.id,
        'is_external': external,
      });
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(
            content: Text(
              external
                  ? '${user.fullName} is now external.'
                  : '${user.fullName} is now internal.',
            ),
          ),
        );
        _load();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Failed to update: $e')),
        );
      }
    }
  }

  Future<void> _deleteUser(_UserRow user) async {
    final actor = _actor;
    if (actor == null || !actor.canManageUsers) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('You do not have permission to delete users.')),
      );
      return;
    }

    final target = user.toAgentUser();
    if (!actor.canDeleteUser(target)) {
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            target.id == actor.id
                ? 'You cannot delete your own account.'
                : 'Admin can only delete Agents and Customers.',
          ),
        ),
      );
      return;
    }

    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete user'),
        content: Text(
          'Permanently delete ${user.fullName} (${user.roleName})?\n\n'
          'This cannot be undone. Their login will stop working immediately.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: EcColors.danger,
              foregroundColor: Colors.white,
            ),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Delete'),
          ),
        ],
      ),
    );
    if (confirm != true) return;

    try {
      await _api.post(
        '/users_delete.php',
        body: {'id': user.id},
        retry: false,
      );
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('${user.fullName} deleted.')),
        );
        _load();
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Failed to delete user: $e')),
        );
      }
    }
  }

  @override
  Widget build(BuildContext context) {
    final filtered = _filtered;
    final actor = _actor;
    final filterLabels = actor?.isSuperAdmin == true
        ? const ['All', 'Customer', 'Agent', 'Admin', 'Super Admin']
        : const ['All', 'Customer', 'Agent', 'Admin'];
    final visLabels = actor?.isSuperAdmin == true
        ? const ['All', 'Internal', 'External']
        : const <String>[];

    return Scaffold(
      appBar: AppBar(
        title: const Text('Manage Users & Roles'),
        actions: [
          IconButton(onPressed: _load, icon: const Icon(Icons.refresh), tooltip: 'Refresh'),
        ],
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.fromLTRB(16, 16, 16, 8),
            child: TextField(
              controller: _searchCtrl,
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(
                hintText: 'Search name, email or phone...',
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
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
            child: SingleChildScrollView(
              scrollDirection: Axis.horizontal,
              child: Row(
                children: filterLabels.map((label) {
                  final selected = _filterRole == label;
                  return Padding(
                    padding: const EdgeInsets.only(right: 8),
                    child: FilterChip(
                      label: Text(label),
                      selected: selected,
                      onSelected: (_) => setState(() => _filterRole = label),
                      selectedColor: EcColors.primary.withValues(alpha: 0.2),
                      checkmarkColor: EcColors.ink,
                    ),
                  );
                }).toList(),
              ),
            ),
          ),
          if (visLabels.isNotEmpty)
            Padding(
              padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
              child: SingleChildScrollView(
                scrollDirection: Axis.horizontal,
                child: Row(
                  children: visLabels.map((label) {
                    final selected = _filterVisibility == label;
                    return Padding(
                      padding: const EdgeInsets.only(right: 8),
                      child: FilterChip(
                        label: Text(label),
                        selected: selected,
                        onSelected: (_) => setState(() => _filterVisibility = label),
                        selectedColor: Colors.indigo.withValues(alpha: 0.15),
                        checkmarkColor: Colors.indigo,
                      ),
                    );
                  }).toList(),
                ),
              ),
            ),
          Padding(
            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  '${filtered.length} user${filtered.length == 1 ? '' : 's'}',
                  style: const TextStyle(fontSize: 12, color: EcColors.muted, fontWeight: FontWeight.w600),
                ),
                Text(
                  actor?.isSuperAdmin == true
                      ? 'Tap a user to edit details'
                      : 'Admin: edit Agents & Customers',
                  style: const TextStyle(fontSize: 11, color: EcColors.muted),
                ),
              ],
            ),
          ),
          const SizedBox(height: 4),
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
                    : filtered.isEmpty
                        ? Center(
                            child: Column(
                              mainAxisSize: MainAxisSize.min,
                              children: [
                                Icon(Icons.people_outline, size: 56, color: Colors.grey.shade300),
                                const SizedBox(height: 12),
                                const Text(
                                  'No users found',
                                  style: TextStyle(
                                    fontSize: 16,
                                    fontWeight: FontWeight.w600,
                                    color: EcColors.muted,
                                  ),
                                ),
                              ],
                            ),
                          )
                        : ListView.separated(
                            padding: const EdgeInsets.symmetric(horizontal: 16, vertical: 4),
                            itemCount: filtered.length,
                            separatorBuilder: (_, index) => const SizedBox(height: 8),
                            itemBuilder: (context, i) {
                              final row = filtered[i];
                              final target = row.toAgentUser();
                              final canEdit = actor?.canEditUserProfile(target) == true;
                              final canDelete = actor?.canDeleteUser(target) == true;
                              return _UserCard(
                                user: row,
                                canEdit: canEdit,
                                canDelete: canDelete,
                                isSuperAdmin: actor?.isSuperAdmin == true,
                                assignableRoles: actor?.assignableRoles ?? const [],
                                onTap: () => _openEdit(row),
                                onSetRole: (newRole) => _setRole(row, newRole),
                                onDelete: () => _deleteUser(row),
                                onSetExternal: (ext) => _setVisibility(row, ext),
                              );
                            },
                          ),
          ),
        ],
      ),
    );
  }
}

class _EditUserSheet extends StatefulWidget {
  const _EditUserSheet({
    required this.user,
    required this.actor,
    required this.api,
    required this.allUsers,
  });

  final _UserRow user;
  final AgentUser actor;
  final ApiClient api;
  final List<_UserRow> allUsers;

  @override
  State<_EditUserSheet> createState() => _EditUserSheetState();
}

class _EditUserSheetState extends State<_EditUserSheet> {
  final _formKey = GlobalKey<FormState>();
  late final TextEditingController _nameCtrl;
  late final TextEditingController _phoneCtrl;
  late final TextEditingController _emailCtrl;
  late final TextEditingController _locationCtrl;
  late final TextEditingController _loginPinCtrl;
  late final TextEditingController _txnPinCtrl;
  late String _gender;
  late int _role;
  late int _registeredBy;
  bool _registeredByTouched = false;
  bool _busy = false;
  bool _obscureLogin = true;
  bool _obscureTxn = true;

  @override
  void initState() {
    super.initState();
    final u = widget.user;
    _nameCtrl = TextEditingController(text: u.fullName);
    _phoneCtrl = TextEditingController(text: u.phone);
    _emailCtrl = TextEditingController(text: u.email);
    _locationCtrl = TextEditingController(text: u.location);
    _loginPinCtrl = TextEditingController();
    _txnPinCtrl = TextEditingController();
    _gender = u.gender.isNotEmpty ? u.gender : 'Male';
    if (_gender != 'Male' && _gender != 'Female' && _gender != 'Other') {
      _gender = 'Male';
    }
    _role = u.role;
    final visibleIds = widget.allUsers
        .where((x) => x.id != widget.user.id)
        .map((x) => x.id)
        .toSet();
    // If referrer is hidden (e.g. elevated account for Admin), treat as unassigned in UI
    // and do not rewrite registered_by on save unless the admin changes it.
    _registeredBy = visibleIds.contains(u.registeredBy) ? u.registeredBy : 0;
  }

  @override
  void dispose() {
    _nameCtrl.dispose();
    _phoneCtrl.dispose();
    _emailCtrl.dispose();
    _locationCtrl.dispose();
    _loginPinCtrl.dispose();
    _txnPinCtrl.dispose();
    super.dispose();
  }

  Future<void> _save() async {
    if (!_formKey.currentState!.validate()) return;
    setState(() => _busy = true);
    try {
      await widget.api.post('/users_update.php', body: {
        'action': 'update',
        'id': widget.user.id,
        'full_name': _nameCtrl.text.trim(),
        'phone': _phoneCtrl.text.trim(),
        'email': _emailCtrl.text.trim(),
        'location': _locationCtrl.text.trim(),
        'gender': _gender,
        'role': _role,
        if (_registeredByTouched) 'registered_by': _registeredBy,
        if (_loginPinCtrl.text.trim().isNotEmpty) 'password': _loginPinCtrl.text.trim(),
        if (_txnPinCtrl.text.trim().isNotEmpty) 'transaction_pin': _txnPinCtrl.text.trim(),
      });
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('User details saved')),
      );
      Navigator.pop(context, true);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final bottom = MediaQuery.of(context).viewInsets.bottom;
    final roles = widget.actor.assignableRoles;

    return Padding(
      padding: EdgeInsets.only(bottom: bottom),
      child: DraggableScrollableSheet(
        expand: false,
        initialChildSize: 0.92,
        minChildSize: 0.55,
        maxChildSize: 0.98,
        builder: (context, scrollCtrl) {
          return Material(
            color: Theme.of(context).scaffoldBackgroundColor,
            borderRadius: const BorderRadius.vertical(top: Radius.circular(16)),
            child: Form(
              key: _formKey,
              child: ListView(
                controller: scrollCtrl,
                padding: const EdgeInsets.fromLTRB(20, 12, 20, 28),
                children: [
                  Center(
                    child: Container(
                      width: 40,
                      height: 4,
                      decoration: BoxDecoration(
                        color: Colors.grey.shade400,
                        borderRadius: BorderRadius.circular(4),
                      ),
                    ),
                  ),
                  const SizedBox(height: 16),
                  Text(
                    'Edit ${widget.user.fullName}',
                    style: const TextStyle(fontSize: 18, fontWeight: FontWeight.w800),
                  ),
                  const SizedBox(height: 4),
                  const Text(
                    'Update registration details. Leave PIN fields blank to keep current values.',
                    style: TextStyle(color: EcColors.muted, fontSize: 13, height: 1.35),
                  ),
                  const SizedBox(height: 18),
                  TextFormField(
                    controller: _nameCtrl,
                    textCapitalization: TextCapitalization.words,
                    decoration: const InputDecoration(labelText: 'Full name'),
                    validator: (v) =>
                        (v == null || v.trim().isEmpty) ? 'Required' : null,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _phoneCtrl,
                    keyboardType: TextInputType.phone,
                    decoration: const InputDecoration(labelText: 'Phone'),
                    validator: (v) =>
                        (v == null || v.trim().length < 7) ? 'Enter a valid phone' : null,
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _emailCtrl,
                    keyboardType: TextInputType.emailAddress,
                    decoration: const InputDecoration(labelText: 'Email'),
                    validator: (v) {
                      if (v == null || !v.contains('@')) return 'Enter a valid email';
                      return null;
                    },
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _locationCtrl,
                    decoration: const InputDecoration(labelText: 'Location'),
                    validator: (v) =>
                        (v == null || v.trim().isEmpty) ? 'Required' : null,
                  ),
                  const SizedBox(height: 12),
                  DropdownButtonFormField<String>(
                    initialValue: _gender,
                    decoration: const InputDecoration(labelText: 'Gender'),
                    items: const [
                      DropdownMenuItem(value: 'Male', child: Text('Male')),
                      DropdownMenuItem(value: 'Female', child: Text('Female')),
                      DropdownMenuItem(value: 'Other', child: Text('Other')),
                    ],
                    onChanged: _busy ? null : (v) => setState(() => _gender = v ?? 'Male'),
                  ),
                  const SizedBox(height: 12),
                  DropdownButtonFormField<int>(
                    initialValue: roles.contains(_role) ? _role : roles.first,
                    decoration: const InputDecoration(labelText: 'Role'),
                    items: [
                      if (roles.contains(0))
                        const DropdownMenuItem(value: 0, child: Text('Customer')),
                      if (roles.contains(1))
                        const DropdownMenuItem(value: 1, child: Text('Agent')),
                      if (roles.contains(2))
                        const DropdownMenuItem(value: 2, child: Text('Admin')),
                      if (roles.contains(3))
                        const DropdownMenuItem(value: 3, child: Text('Super Admin')),
                    ],
                    onChanged: _busy
                        ? null
                        : (v) {
                            if (v != null) setState(() => _role = v);
                          },
                  ),
                  const SizedBox(height: 12),
                  DropdownButtonFormField<int>(
                    initialValue: () {
                      final ids = widget.allUsers
                          .where((u) => u.id != widget.user.id)
                          .map((u) => u.id)
                          .toSet();
                      return ids.contains(_registeredBy) ? _registeredBy : 0;
                    }(),
                    decoration: const InputDecoration(
                      labelText: 'Registered by',
                      helperText: 'Manually place this account under another user',
                    ),
                    items: [
                      const DropdownMenuItem(value: 0, child: Text('Nobody (unassigned)')),
                      ...widget.allUsers
                          .where((u) => u.id != widget.user.id)
                          .map(
                            (u) => DropdownMenuItem(
                              value: u.id,
                              child: Text('${u.fullName} · ${u.roleName}'),
                            ),
                          ),
                    ],
                    onChanged: _busy
                        ? null
                        : (v) {
                            if (v != null) {
                              setState(() {
                                _registeredBy = v;
                                _registeredByTouched = true;
                              });
                            }
                          },
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _loginPinCtrl,
                    obscureText: _obscureLogin,
                    keyboardType: TextInputType.number,
                    maxLength: 4,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    decoration: InputDecoration(
                      labelText: 'New login PIN (optional)',
                      counterText: '',
                      suffixIcon: IconButton(
                        onPressed: () => setState(() => _obscureLogin = !_obscureLogin),
                        icon: Icon(_obscureLogin ? Icons.visibility : Icons.visibility_off),
                      ),
                    ),
                    validator: (v) {
                      if (v == null || v.isEmpty) return null;
                      if (!RegExp(r'^\d{4}$').hasMatch(v)) {
                        return 'Must be exactly 4 digits';
                      }
                      return null;
                    },
                  ),
                  const SizedBox(height: 12),
                  TextFormField(
                    controller: _txnPinCtrl,
                    obscureText: _obscureTxn,
                    keyboardType: TextInputType.number,
                    maxLength: 4,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    decoration: InputDecoration(
                      labelText: 'New transaction PIN (optional)',
                      counterText: '',
                      suffixIcon: IconButton(
                        onPressed: () => setState(() => _obscureTxn = !_obscureTxn),
                        icon: Icon(_obscureTxn ? Icons.visibility : Icons.visibility_off),
                      ),
                    ),
                    validator: (v) {
                      if (v == null || v.isEmpty) return null;
                      if (!RegExp(r'^\d{4}$').hasMatch(v)) {
                        return 'Must be exactly 4 digits';
                      }
                      final login = _loginPinCtrl.text.trim();
                      if (login.isNotEmpty && v == login) {
                        return 'Must differ from login PIN';
                      }
                      return null;
                    },
                  ),
                  const SizedBox(height: 22),
                  FilledButton(
                    onPressed: _busy ? null : _save,
                    child: _busy
                        ? const SizedBox(
                            width: 22,
                            height: 22,
                            child: CircularProgressIndicator(strokeWidth: 2),
                          )
                        : const Text('Save changes'),
                  ),
                  const SizedBox(height: 8),
                  TextButton(
                    onPressed: _busy ? null : () => Navigator.pop(context, false),
                    child: const Text('Cancel'),
                  ),
                  if (widget.actor.canDeleteUser(widget.user.toAgentUser())) ...[
                    const SizedBox(height: 16),
                    OutlinedButton.icon(
                      onPressed: _busy ? null : _confirmDelete,
                      style: OutlinedButton.styleFrom(
                        foregroundColor: EcColors.danger,
                        side: const BorderSide(color: EcColors.danger),
                      ),
                      icon: const Icon(Icons.delete_outline),
                      label: const Text('Delete user'),
                    ),
                  ],
                ],
              ),
            ),
          );
        },
      ),
    );
  }

  Future<void> _confirmDelete() async {
    if (!widget.actor.canDeleteUser(widget.user.toAgentUser())) {
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(
          content: Text(
            widget.user.id == widget.actor.id
                ? 'You cannot delete your own account.'
                : 'You do not have permission to delete this user.',
          ),
        ),
      );
      return;
    }

    final confirm = await showDialog<bool>(
      context: context,
      builder: (ctx) => AlertDialog(
        title: const Text('Delete user'),
        content: Text(
          'Permanently delete ${widget.user.fullName}?\n\nThis cannot be undone.',
        ),
        actions: [
          TextButton(onPressed: () => Navigator.pop(ctx, false), child: const Text('Cancel')),
          FilledButton(
            style: FilledButton.styleFrom(
              backgroundColor: EcColors.danger,
              foregroundColor: Colors.white,
            ),
            onPressed: () => Navigator.pop(ctx, true),
            child: const Text('Delete'),
          ),
        ],
      ),
    );
    if (confirm != true || !mounted) return;

    setState(() => _busy = true);
    try {
      await widget.api.post(
        '/users_delete.php',
        body: {'id': widget.user.id},
        retry: false,
      );
      if (!mounted) return;
      ScaffoldMessenger.of(context).showSnackBar(
        SnackBar(content: Text('${widget.user.fullName} deleted.')),
      );
      Navigator.pop(context, true);
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(SnackBar(content: Text('$e')));
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }
}

class _UserCard extends StatelessWidget {
  const _UserCard({
    required this.user,
    required this.canEdit,
    required this.canDelete,
    required this.isSuperAdmin,
    required this.assignableRoles,
    required this.onTap,
    required this.onSetRole,
    required this.onDelete,
    required this.onSetExternal,
  });

  final _UserRow user;
  final bool canEdit;
  final bool canDelete;
  final bool isSuperAdmin;
  final List<int> assignableRoles;
  final VoidCallback onTap;
  final void Function(int role) onSetRole;
  final VoidCallback onDelete;
  final void Function(bool external) onSetExternal;

  @override
  Widget build(BuildContext context) {
    return Card(
      margin: EdgeInsets.zero,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.start,
          children: [
            Row(
              children: [
                Expanded(
                  child: InkWell(
                    borderRadius: BorderRadius.circular(12),
                    onTap: canEdit ? onTap : null,
                    child: Row(
                      children: [
                        CircleAvatar(
                          radius: 22,
                          backgroundColor: _roleColor(user.role).withValues(alpha: 0.15),
                          child: Text(
                            user.fullName.isNotEmpty ? user.fullName[0].toUpperCase() : '?',
                            style: TextStyle(fontWeight: FontWeight.bold, color: _roleColor(user.role)),
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: Column(
                            crossAxisAlignment: CrossAxisAlignment.start,
                            children: [
                              Text(
                                user.fullName,
                                style: const TextStyle(fontWeight: FontWeight.bold, fontSize: 14),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                              const SizedBox(height: 2),
                              Text(
                                user.email,
                                style: const TextStyle(fontSize: 11, color: EcColors.muted),
                                maxLines: 1,
                                overflow: TextOverflow.ellipsis,
                              ),
                              Text(
                                user.phone,
                                style: const TextStyle(fontSize: 11, color: EcColors.muted),
                              ),
                              if (user.registeredByName.isNotEmpty)
                                Text(
                                  'Under: ${user.registeredByName}',
                                  style: const TextStyle(fontSize: 11, color: EcColors.muted),
                                ),
                              if (isSuperAdmin && user.isExternal && user.walletId.isNotEmpty)
                                Text(
                                  'Wallet ID: ${user.walletId}',
                                  style: const TextStyle(
                                    fontSize: 11,
                                    fontWeight: FontWeight.w700,
                                    color: Colors.indigo,
                                  ),
                                ),
                              if (canEdit)
                                const Text(
                                  'Tap to edit details',
                                  style: TextStyle(fontSize: 11, color: EcColors.primaryDark),
                                ),
                            ],
                          ),
                        ),
                      ],
                    ),
                  ),
                ),
                const SizedBox(width: 4),
                if (canEdit)
                  _RoleSelector(
                    currentRole: user.role,
                    assignableRoles: assignableRoles,
                    onChanged: onSetRole,
                  )
                else
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
                    decoration: BoxDecoration(
                      color: _roleColor(user.role).withValues(alpha: 0.12),
                      borderRadius: BorderRadius.circular(20),
                    ),
                    child: Text(
                      user.roleName,
                      style: TextStyle(
                        fontSize: 11,
                        fontWeight: FontWeight.bold,
                        color: _roleColor(user.role),
                      ),
                    ),
                  ),
                if (canDelete) ...[
                  const SizedBox(width: 4),
                  TextButton(
                    onPressed: onDelete,
                    style: TextButton.styleFrom(
                      foregroundColor: EcColors.danger,
                      padding: const EdgeInsets.symmetric(horizontal: 8),
                      minimumSize: const Size(0, 36),
                      tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                    ),
                    child: const Text(
                      'Delete',
                      style: TextStyle(fontWeight: FontWeight.w800, fontSize: 12),
                    ),
                  ),
                ],
              ],
            ),
            if (isSuperAdmin) ...[
              const SizedBox(height: 10),
              Row(
                children: [
                  Expanded(
                    child: SegmentedButton<bool>(
                      segments: const [
                        ButtonSegment(value: false, label: Text('Internal')),
                        ButtonSegment(value: true, label: Text('External')),
                      ],
                      selected: {user.isExternal},
                      onSelectionChanged: (s) {
                        final next = s.first;
                        if (next != user.isExternal) onSetExternal(next);
                      },
                      style: const ButtonStyle(
                        visualDensity: VisualDensity.compact,
                        tapTargetSize: MaterialTapTargetSize.shrinkWrap,
                      ),
                    ),
                  ),
                ],
              ),
            ],
          ],
        ),
      ),
    );
  }

  Color _roleColor(int role) {
    switch (role) {
      case AgentUser.roleSuperAdmin:
        return const Color(0xFFB45309);
      case AgentUser.roleAdmin:
        return Colors.purple;
      case AgentUser.roleAgent:
        return EcColors.primaryDark;
      default:
        return Colors.grey.shade600;
    }
  }
}

class _RoleSelector extends StatelessWidget {
  const _RoleSelector({
    required this.currentRole,
    required this.assignableRoles,
    required this.onChanged,
  });

  final int currentRole;
  final List<int> assignableRoles;
  final void Function(int) onChanged;

  @override
  Widget build(BuildContext context) {
    final allRoles = [
      {'label': 'Customer', 'value': AgentUser.roleCustomer, 'color': Colors.grey.shade600},
      {'label': 'Agent', 'value': AgentUser.roleAgent, 'color': EcColors.primaryDark},
      {'label': 'Admin', 'value': AgentUser.roleAdmin, 'color': Colors.purple},
      {
        'label': 'Super Admin',
        'value': AgentUser.roleSuperAdmin,
        'color': const Color(0xFFB45309),
      },
    ];
    final roles = allRoles.where((r) => assignableRoles.contains(r['value'] as int)).toList();

    return PopupMenuButton<int>(
      tooltip: 'Change role',
      onSelected: onChanged,
      child: Container(
        padding: const EdgeInsets.symmetric(horizontal: 10, vertical: 5),
        decoration: BoxDecoration(
          color: _chipColor(currentRole).withValues(alpha: 0.12),
          borderRadius: BorderRadius.circular(20),
          border: Border.all(color: _chipColor(currentRole).withValues(alpha: 0.4)),
        ),
        child: Row(
          mainAxisSize: MainAxisSize.min,
          children: [
            Text(
              _roleName(currentRole),
              style: TextStyle(
                fontSize: 11,
                fontWeight: FontWeight.bold,
                color: _chipColor(currentRole),
              ),
            ),
            const SizedBox(width: 4),
            Icon(Icons.arrow_drop_down, size: 16, color: _chipColor(currentRole)),
          ],
        ),
      ),
      itemBuilder: (context) => roles.map((r) {
        final val = r['value'] as int;
        final isSelected = val == currentRole;
        return PopupMenuItem<int>(
          value: val,
          child: Row(
            children: [
              Icon(
                isSelected ? Icons.radio_button_checked : Icons.radio_button_unchecked,
                size: 16,
                color: r['color'] as Color,
              ),
              const SizedBox(width: 8),
              Text(
                r['label'] as String,
                style: TextStyle(
                  fontWeight: isSelected ? FontWeight.bold : FontWeight.normal,
                  color: r['color'] as Color,
                ),
              ),
            ],
          ),
        );
      }).toList(),
    );
  }

  String _roleName(int role) {
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

  Color _chipColor(int role) {
    switch (role) {
      case AgentUser.roleSuperAdmin:
        return const Color(0xFFB45309);
      case AgentUser.roleAdmin:
        return Colors.purple;
      case AgentUser.roleAgent:
        return EcColors.primaryDark;
      default:
        return Colors.grey.shade600;
    }
  }
}

class _UserRow {
  _UserRow({
    required this.id,
    required this.fullName,
    required this.email,
    required this.phone,
    required this.location,
    required this.gender,
    required this.role,
    this.registeredBy = 0,
    this.registeredByName = '',
    this.isExternal = false,
    this.walletId = '',
  });

  final int id;
  final String fullName;
  final String email;
  final String phone;
  final String location;
  final String gender;
  final int role;
  final int registeredBy;
  final String registeredByName;
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

  AgentUser toAgentUser() => AgentUser(
        id: id,
        fullName: fullName,
        email: email,
        phone: phone,
        location: location,
        gender: gender,
        role: role,
        isExternal: isExternal,
        walletId: walletId,
      );

  factory _UserRow.fromJson(Map<String, dynamic> json) => _UserRow(
        id: (json['id'] as num?)?.toInt() ?? 0,
        fullName: '${json['full_name'] ?? ''}',
        email: '${json['email'] ?? ''}',
        phone: '${json['phone'] ?? ''}',
        location: '${json['location'] ?? ''}',
        gender: '${json['gender'] ?? ''}',
        role: (json['role'] as num?)?.toInt() ?? 0,
        registeredBy: (json['registered_by'] as num?)?.toInt() ?? 0,
        registeredByName: '${json['registered_by_name'] ?? ''}',
        isExternal: json['is_external'] == true || json['is_external'] == 1,
        walletId: '${json['wallet_id'] ?? ''}'.trim().toUpperCase(),
      );
}
