import 'package:flutter/material.dart';

import '../config/theme.dart';
import '../screens/customers/customers_screen.dart';
import '../services/api_client.dart';
import '../state/auth_controller.dart';

/// Phone field with optional pick-from-customers button.
class CustomerPhonePicker extends StatelessWidget {
  const CustomerPhonePicker({
    super.key,
    required this.controller,
    this.onChanged,
  });

  final TextEditingController controller;
  final VoidCallback? onChanged;

  Future<void> _pick(BuildContext context) async {
    final userId = AuthScope.of(context).user?.id ?? 0;
    final api = ApiClient();
    List<CustomerItem> customers = [];
    try {
      final res = await api.post('/customers.php', body: {
        'action': 'list',
        'user_id': userId,
      });
      customers = (res['customers'] as List? ?? [])
          .map((e) => CustomerItem.fromJson(Map<String, dynamic>.from(e as Map)))
          .toList();
    } catch (e) {
      if (context.mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Could not load customers: $e')),
        );
      }
      return;
    }
    if (!context.mounted) return;
    if (customers.isEmpty) {
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('No customers yet. Register one first.')),
      );
      return;
    }

    final selected = await showModalBottomSheet<CustomerItem>(
      context: context,
      isScrollControlled: true,
      builder: (ctx) {
        var query = '';
        return StatefulBuilder(
          builder: (ctx, setModal) {
            final filtered = customers.where((c) {
              final q = query.toLowerCase();
              if (q.isEmpty) return true;
              return c.fullName.toLowerCase().contains(q) ||
                  c.phone.contains(q);
            }).toList();
            return SafeArea(
              child: SizedBox(
                height: MediaQuery.sizeOf(ctx).height * 0.65,
                child: Column(
                  children: [
                    const Padding(
                      padding: EdgeInsets.all(16),
                      child: Text(
                        'Select customer',
                        style: TextStyle(fontWeight: FontWeight.w800, fontSize: 16),
                      ),
                    ),
                    Padding(
                      padding: const EdgeInsets.symmetric(horizontal: 16),
                      child: TextField(
                        decoration: const InputDecoration(
                          hintText: 'Search name or phone',
                          prefixIcon: Icon(Icons.search),
                        ),
                        onChanged: (v) => setModal(() => query = v),
                      ),
                    ),
                    const SizedBox(height: 8),
                    Expanded(
                      child: ListView.separated(
                        itemCount: filtered.length,
                        separatorBuilder: (_, _) => const Divider(height: 1),
                        itemBuilder: (_, i) {
                          final c = filtered[i];
                          return ListTile(
                            leading: CircleAvatar(
                              backgroundColor: EcColors.primary.withValues(alpha: 0.2),
                              child: Text(
                                c.fullName.isNotEmpty ? c.fullName[0].toUpperCase() : '?',
                                style: const TextStyle(fontWeight: FontWeight.bold),
                              ),
                            ),
                            title: Text(c.fullName),
                            subtitle: Text(c.phone),
                            onTap: () => Navigator.pop(ctx, c),
                          );
                        },
                      ),
                    ),
                  ],
                ),
              ),
            );
          },
        );
      },
    );

    if (selected != null && context.mounted) {
      controller.text = selected.phone;
      onChanged?.call();
    }
  }

  @override
  Widget build(BuildContext context) {
    return Row(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        Expanded(
          child: TextFormField(
            controller: controller,
            keyboardType: TextInputType.phone,
            maxLength: 15,
            decoration: InputDecoration(
              hintText: 'e.g. 08031234567',
              hintStyle: TextStyle(
                color: EcColors.muted.withValues(alpha: 0.55),
                fontWeight: FontWeight.w400,
              ),
              prefixIcon: const Icon(Icons.phone_outlined),
              counterText: '',
            ),
            onChanged: (_) => onChanged?.call(),
            validator: (v) {
              final val = v?.trim() ?? '';
              if (val.isEmpty) return 'Phone number is required';
              final digits = val.replaceAll(RegExp(r'\D'), '');
              if (digits.length < 10 || digits.length > 15) {
                return 'Enter 10–15 digits';
              }
              return null;
            },
          ),
        ),
        const SizedBox(width: 8),
        Padding(
          padding: const EdgeInsets.only(top: 4),
          child: IconButton.filledTonal(
            tooltip: 'Pick customer',
            onPressed: () => _pick(context),
            icon: const Icon(Icons.contacts_outlined),
          ),
        ),
      ],
    );
  }
}
