import 'package:flutter/material.dart';

import '../../config/theme.dart';
import '../../services/api_client.dart';
import '../../state/auth_controller.dart';

class CustomerItem {
  const CustomerItem({
    required this.id,
    required this.fullName,
    required this.phone,
    required this.address,
    required this.email,
    required this.gender,
  });

  final int id;
  final String fullName;
  final String phone;
  final String address;
  final String email;
  final String gender;

  factory CustomerItem.fromJson(Map<String, dynamic> json) {
    return CustomerItem(
      id: (json['id'] as num?)?.toInt() ?? 0,
      fullName: '${json['full_name'] ?? 'Customer'}',
      phone: '${json['phone'] ?? ''}',
      address: '${json['address'] ?? ''}',
      email: '${json['email'] ?? ''}',
      gender: '${json['gender'] ?? 'Male'}',
    );
  }
}

class CustomersScreen extends StatefulWidget {
  const CustomersScreen({super.key});

  @override
  State<CustomersScreen> createState() => _CustomersScreenState();
}

class _CustomersScreenState extends State<CustomersScreen> {
  final _searchCtrl = TextEditingController();
  final _api = ApiClient();
  bool _loading = false;
  String? _error;
  List<CustomerItem> _customers = [];

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
      final userId = AuthScope.of(context).user?.id ?? 0;
      final res = await _api.post('/customers.php', body: {
        'action': 'list',
        'user_id': userId,
      });
      final list = (res['customers'] as List? ?? [])
          .map((e) => CustomerItem.fromJson(Map<String, dynamic>.from(e as Map)))
          .toList();
      if (mounted) {
        setState(() {
          _customers = list;
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

  List<CustomerItem> get _filtered {
    final q = _searchCtrl.text.trim().toLowerCase();
    if (q.isEmpty) return _customers;
    return _customers.where((c) {
      return c.fullName.toLowerCase().contains(q) ||
          c.phone.toLowerCase().contains(q) ||
          c.address.toLowerCase().contains(q) ||
          c.email.toLowerCase().contains(q);
    }).toList();
  }

  void _showAddDialog() {
    final nameCtrl = TextEditingController();
    final phoneCtrl = TextEditingController();
    final addressCtrl = TextEditingController();
    final emailCtrl = TextEditingController();
    String gender = 'Male';

    showDialog(
      context: context,
      builder: (ctx) => StatefulBuilder(
        builder: (context, setDlgState) => AlertDialog(
          title: const Text('Register Customer'),
          content: SingleChildScrollView(
            child: Column(
              mainAxisSize: MainAxisSize.min,
              children: [
                TextField(
                  controller: nameCtrl,
                  decoration: const InputDecoration(labelText: 'Full Name *'),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: phoneCtrl,
                  keyboardType: TextInputType.phone,
                  decoration: const InputDecoration(labelText: 'Phone Number *'),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: addressCtrl,
                  decoration: const InputDecoration(labelText: 'Address / City'),
                ),
                const SizedBox(height: 10),
                TextField(
                  controller: emailCtrl,
                  keyboardType: TextInputType.emailAddress,
                  decoration: const InputDecoration(labelText: 'Email (Optional)'),
                ),
                const SizedBox(height: 10),
                DropdownButtonFormField<String>(
                  initialValue: gender,
                  decoration: const InputDecoration(labelText: 'Gender'),
                  items: const [
                    DropdownMenuItem(value: 'Male', child: Text('Male')),
                    DropdownMenuItem(value: 'Female', child: Text('Female')),
                  ],
                  onChanged: (v) => setDlgState(() => gender = v ?? 'Male'),
                ),
              ],
            ),
          ),
          actions: [
            TextButton(
              onPressed: () => Navigator.pop(ctx),
              child: const Text('Cancel'),
            ),
            FilledButton(
              onPressed: () async {
                final name = nameCtrl.text.trim();
                final phone = phoneCtrl.text.trim();
                final address = addressCtrl.text.trim();
                final email = emailCtrl.text.trim();

                if (name.isEmpty || phone.isEmpty) {
                  ScaffoldMessenger.of(ctx).showSnackBar(
                    const SnackBar(content: Text('Name and phone are required.')),
                  );
                  return;
                }
                final messenger = ScaffoldMessenger.of(context);
                final userId = AuthScope.of(context).user?.id ?? 0;
                Navigator.pop(ctx);
                try {
                  await _api.post('/customers.php', body: {
                    'action': 'create',
                    'registered_by_user_id': userId,
                    'full_name': name,
                    'phone': phone,
                    'address': address,
                    'email': email,
                    'gender': gender,
                  });
                  messenger.showSnackBar(
                    const SnackBar(content: Text('Customer registered successfully.')),
                  );
                  _load();
                } catch (e) {
                  messenger.showSnackBar(
                    SnackBar(content: Text('Failed to save customer: $e')),
                  );
                }
              },
              child: const Text('Save Customer'),
            ),
          ],
        ),
      ),
    );
  }

  @override
  Widget build(BuildContext context) {
    return Scaffold(
      appBar: AppBar(
        title: const Text('Customer Directory'),
        actions: [
          IconButton(
            onPressed: _load,
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh customers',
          ),
        ],
      ),
      floatingActionButton: FloatingActionButton.extended(
        onPressed: _showAddDialog,
        backgroundColor: EcColors.primary,
        foregroundColor: EcColors.ink,
        icon: const Icon(Icons.person_add),
        label: const Text('Register'),
      ),
      body: Column(
        children: [
          Padding(
            padding: const EdgeInsets.all(16),
            child: TextField(
              controller: _searchCtrl,
              onChanged: (_) => setState(() {}),
              decoration: InputDecoration(
                hintText: 'Search customers by name, phone, email...',
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
            padding: const EdgeInsets.symmetric(horizontal: 16),
            child: Row(
              mainAxisAlignment: MainAxisAlignment.spaceBetween,
              children: [
                Text(
                  'Total Customers: ${_filtered.length}',
                  style: const TextStyle(fontWeight: FontWeight.bold, color: EcColors.muted),
                ),
              ],
            ),
          ),
          const SizedBox(height: 8),
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
                                Icon(Icons.people_outline, size: 56, color: Colors.grey.shade400),
                                const SizedBox(height: 12),
                                const Text(
                                  'No customers registered yet',
                                  style: TextStyle(fontSize: 16, fontWeight: FontWeight.w600, color: EcColors.muted),
                                ),
                                const SizedBox(height: 16),
                                FilledButton.icon(
                                  onPressed: _showAddDialog,
                                  icon: const Icon(Icons.person_add),
                                  label: const Text('Register First Customer'),
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
                                  leading: CircleAvatar(
                                    backgroundColor: EcColors.primary.withValues(alpha: 0.15),
                                    child: Text(
                                      item.fullName.isNotEmpty ? item.fullName[0].toUpperCase() : 'C',
                                      style: const TextStyle(fontWeight: FontWeight.bold, color: EcColors.ink),
                                    ),
                                  ),
                                  title: Text(
                                    item.fullName,
                                    style: const TextStyle(fontWeight: FontWeight.bold),
                                  ),
                                  subtitle: Text(
                                    '${item.phone}${item.address.isNotEmpty ? ' • ${item.address}' : ''}',
                                    style: const TextStyle(fontSize: 12, color: EcColors.muted),
                                  ),
                                  trailing: Icon(Icons.chevron_right, color: Colors.grey.shade400),
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
