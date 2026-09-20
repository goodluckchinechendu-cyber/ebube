import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../config/theme.dart';
import '../models/user.dart';

/// Lets the buyer choose which injected wallet to debit: momo / vtu / logical.
class WalletSourcePicker extends StatelessWidget {
  const WalletSourcePicker({
    super.key,
    required this.user,
    required this.selected,
    required this.onChanged,
  });

  final AgentUser? user;
  final String selected;
  final ValueChanged<String> onChanged;

  static const options = [
    ('vtu', 'VTU Airtime', Icons.sim_card_outlined),
    ('momo', 'MoMo Airtime', Icons.phone_android_outlined),
    ('logical', 'Logical Airtime', Icons.memory_outlined),
  ];

  double _balanceFor(String key) {
    if (user == null) return 0;
    switch (key) {
      case 'momo':
        return user!.momoBalance;
      case 'logical':
        return user!.logicalBalance;
      case 'vtu':
      default:
        return user!.vtuBalance;
    }
  }

  @override
  Widget build(BuildContext context) {
    final money = NumberFormat.currency(symbol: '₦', decimalDigits: 2);
    return Column(
      crossAxisAlignment: CrossAxisAlignment.start,
      children: [
        const Text('Pay from wallet', style: TextStyle(fontWeight: FontWeight.w700, fontSize: 15)),
        const SizedBox(height: 8),
        const Text(
          'Choose which injected wallet to debit for this purchase.',
          style: TextStyle(color: EcColors.muted, fontSize: 12),
        ),
        const SizedBox(height: 10),
        Row(
          children: [
            for (var i = 0; i < options.length; i++) ...[
              if (i > 0) const SizedBox(width: 8),
              Expanded(
                child: InkWell(
                  onTap: () => onChanged(options[i].$1),
                  borderRadius: BorderRadius.circular(12),
                  child: AnimatedContainer(
                    duration: const Duration(milliseconds: 160),
                    padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 6),
                    decoration: BoxDecoration(
                      color: selected == options[i].$1 ? EcColors.primary : EcColors.card,
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(
                        color: selected == options[i].$1
                            ? EcColors.primaryDark
                            : Colors.grey.shade300,
                        width: selected == options[i].$1 ? 2 : 1,
                      ),
                    ),
                    child: Column(
                      children: [
                        Icon(
                          options[i].$3,
                          size: 20,
                          color: selected == options[i].$1 ? EcColors.ink : EcColors.muted,
                        ),
                        const SizedBox(height: 4),
                        Text(
                          options[i].$2,
                          textAlign: TextAlign.center,
                          maxLines: 2,
                          overflow: TextOverflow.ellipsis,
                          style: TextStyle(
                            fontWeight: FontWeight.w700,
                            fontSize: 10,
                            height: 1.1,
                            color: selected == options[i].$1 ? EcColors.ink : EcColors.muted,
                          ),
                        ),
                        const SizedBox(height: 2),
                        Text(
                          money.format(_balanceFor(options[i].$1)),
                          style: TextStyle(
                            fontSize: 10,
                            fontWeight: FontWeight.w600,
                            color: selected == options[i].$1 ? EcColors.ink : EcColors.muted,
                          ),
                          textAlign: TextAlign.center,
                        ),
                      ],
                    ),
                  ),
                ),
              ),
            ],
          ],
        ),
      ],
    );
  }
}
