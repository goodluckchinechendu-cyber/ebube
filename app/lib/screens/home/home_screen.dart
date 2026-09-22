import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../../config/theme.dart';
import '../../state/auth_controller.dart';
import '../../widgets/recent_transactions_section.dart';
import '../../widgets/register_customer_dialog.dart';
import '../shell/main_shell.dart';
import '../vtu/buy_airtime_screen.dart';
import '../vtu/buy_data_screen.dart';

class HomeScreen extends StatelessWidget {
  const HomeScreen({super.key});

  @override
  Widget build(BuildContext context) {
    final user = AuthScope.of(context).user;
    final currency = NumberFormat.currency(symbol: '₦', decimalDigits: 2);

    return Scaffold(
      appBar: AppBar(
        titleSpacing: 16,
        title: const Text(
          'Dashboard',
          style: TextStyle(fontSize: 18, fontWeight: FontWeight.bold, color: EcColors.ink),
        ),
        actions: [
          IconButton(
            onPressed: () async {
              await AuthScope.of(context).refreshWallet();
              if (context.mounted) {
                ScaffoldMessenger.of(context).showSnackBar(
                  const SnackBar(content: Text('All data refreshed from server')),
                );
              }
            },
            icon: const Icon(Icons.refresh),
            tooltip: 'Refresh Balances',
          ),
          const SizedBox(width: 8),
        ],
      ),
      body: RefreshIndicator(
        onRefresh: () => AuthScope.of(context).refreshWallet(),
        child: LayoutBuilder(
          builder: (context, constraints) {
            final screenWidth = constraints.maxWidth;
            final isDesktop = screenWidth >= 900;
            final isTablet = screenWidth >= 600;

            // Quick action columns: 6 on desktop, 4 on tablet, 3 on mobile
            final actionCols = isDesktop ? 6 : (isTablet ? 4 : 3);
            // Action tile aspect ratio: taller on desktop so icons don't get cramped
            final actionAspect = isDesktop ? 1.1 : (isTablet ? 1.15 : 1.2);

            return ListView(
              padding: EdgeInsets.all(isDesktop ? 24 : 16),
              children: [
                // ── Welcome banner ─────────────────────────────────────────
                Container(
                  padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
                  decoration: BoxDecoration(
                    color: EcColors.primary.withValues(alpha: 0.12),
                    borderRadius: BorderRadius.circular(12),
                    border: Border.all(color: EcColors.primary.withValues(alpha: 0.3)),
                  ),
                  child: const Row(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      Icon(Icons.waving_hand, color: EcColors.primaryDark, size: 20),
                      SizedBox(width: 10),
                      Expanded(
                        child: Column(
                          crossAxisAlignment: CrossAxisAlignment.start,
                          children: [
                            Text(
                              'Welcome',
                              style: TextStyle(
                                fontSize: 15,
                                fontWeight: FontWeight.w800,
                                color: EcColors.ink,
                              ),
                            ),
                            SizedBox(height: 2),
                            Text(
                              'Welcome to EbubeConnect!. Your trusted Telecom Partner.',
                              style: TextStyle(
                                fontSize: 12,
                                fontWeight: FontWeight.w600,
                                color: EcColors.ink,
                              ),
                            ),
                          ],
                        ),
                      ),
                    ],
                  ),
                ),
                const SizedBox(height: 20),

                // ── Quick Actions Grid (BEFORE wallet) ─────────────────────
                const Text(
                  'Quick Actions',
                  style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: EcColors.muted),
                ),
                const SizedBox(height: 12),
                GridView.count(
                  crossAxisCount: actionCols,
                  shrinkWrap: true,
                  physics: const NeverScrollableScrollPhysics(),
                  mainAxisSpacing: 12,
                  crossAxisSpacing: 12,
                  childAspectRatio: actionAspect,
                  children: [
                    _ActionTile(
                      icon: Icons.phone_in_talk,
                      label: 'Top Up Airtime',
                      color: Colors.blue,
                      onTap: () {
                        final shell = MainShellScope.of(context);
                        if (shell != null) {
                          shell.selectRoute('buy_airtime');
                        } else {
                          Navigator.push(
                            context,
                            MaterialPageRoute(builder: (_) => const BuyAirtimeScreen()),
                          );
                        }
                      },
                    ),
                    _ActionTile(
                      icon: Icons.wifi,
                      label: 'Subscribe Data',
                      color: Colors.indigo,
                      onTap: () {
                        final shell = MainShellScope.of(context);
                        if (shell != null) {
                          shell.selectRoute('buy_data');
                        } else {
                          Navigator.push(
                            context,
                            MaterialPageRoute(builder: (_) => const BuyDataScreen()),
                          );
                        }
                      },
                    ),
                    _ActionTile(
                      icon: Icons.person_add_alt_1,
                      label: 'Register Customer',
                      color: Colors.green,
                      onTap: () => _showRegisterCustomerDialog(context),
                    ),
                    _ActionTile(
                      icon: Icons.payments,
                      label: 'Withdraw Commission',
                      color: Colors.teal,
                      onTap: () {
                        final shell = MainShellScope.of(context);
                        if (shell != null) {
                          shell.selectRoute('wallet');
                        }
                      },
                    ),
                    _ActionTile(
                      icon: Icons.receipt_long,
                      label: 'Transactions',
                      color: Colors.orange,
                      onTap: () {
                        final shell = MainShellScope.of(context);
                        if (shell != null) {
                          shell.selectRoute('transactions');
                        }
                      },
                    ),
                    if (user?.canManageUsers == true)
                      _ActionTile(
                        icon: Icons.add_card_rounded,
                        label: 'Fund Wallet',
                        color: Colors.deepOrange,
                        onTap: () {
                          final shell = MainShellScope.of(context);
                          if (shell != null) {
                            shell.selectRoute('fund_wallet');
                          }
                        },
                      ),
                  ],
                ),
                const SizedBox(height: 24),

                // ── Wallet Balances (AFTER quick actions) ──────────────────
                // Wallet ID for accounts that have one (no "external" label).
                if ((user?.walletId ?? '').isNotEmpty) ...[
                  Container(
                    width: double.infinity,
                    padding: const EdgeInsets.all(14),
                    decoration: BoxDecoration(
                      color: Colors.indigo.withValues(alpha: 0.08),
                      borderRadius: BorderRadius.circular(12),
                      border: Border.all(color: Colors.indigo.withValues(alpha: 0.25)),
                    ),
                    child: Column(
                      crossAxisAlignment: CrossAxisAlignment.start,
                      children: [
                        const Text(
                          'Wallet ID',
                          style: TextStyle(
                            fontSize: 12,
                            fontWeight: FontWeight.w700,
                            color: EcColors.muted,
                          ),
                        ),
                        const SizedBox(height: 4),
                        SelectableText(
                          user!.walletId,
                          style: const TextStyle(
                            fontSize: 22,
                            fontWeight: FontWeight.w900,
                            letterSpacing: 1.2,
                            color: Colors.indigo,
                          ),
                        ),
                        const SizedBox(height: 4),
                        const Text(
                          'Share this ID when someone needs to fund your wallet.',
                          style: TextStyle(fontSize: 12, color: EcColors.muted),
                        ),
                      ],
                    ),
                  ),
                  const SizedBox(height: 16),
                ],
                const Text(
                  'Wallet Balances',
                  style: TextStyle(fontSize: 14, fontWeight: FontWeight.w700, color: EcColors.muted),
                ),
                const SizedBox(height: 10),
                // On desktop/tablet: responsive grid fills full width
                // On mobile: horizontal scroll list
                if (isTablet)
                  GridView.count(
                    crossAxisCount: isDesktop ? 4 : 2,
                    shrinkWrap: true,
                    physics: const NeverScrollableScrollPhysics(),
                    mainAxisSpacing: 12,
                    crossAxisSpacing: 12,
                    childAspectRatio: isDesktop ? 1.6 : 1.4,
                    children: [
                      _WalletCard(
                        title: 'VTU Airtime',
                        amount: currency.format(user?.vtuBalance ?? 0),
                        icon: Icons.phone_android,
                        color: Colors.blue,
                      ),
                      _WalletCard(
                        title: 'MoMo Airtime',
                        amount: currency.format(user?.momoBalance ?? 0),
                        icon: Icons.account_balance_wallet,
                        color: Colors.purple,
                      ),
                      _WalletCard(
                        title: 'Logical Airtime',
                        amount: currency.format(user?.logicalBalance ?? 0),
                        icon: Icons.swap_horiz,
                        color: Colors.teal,
                      ),
                      _WalletCard(
                        title: 'Commission',
                        amount: currency.format(user?.commissionBalance ?? 0),
                        icon: Icons.payments,
                        color: Colors.green.shade700,
                      ),
                    ],
                  )
                else
                  _MobileWalletSlider(
                    items: [
                      _WalletItemData(
                        title: 'VTU Airtime',
                        amount: currency.format(user?.vtuBalance ?? 0),
                        icon: Icons.phone_android,
                        color: Colors.blue,
                      ),
                      _WalletItemData(
                        title: 'MoMo Airtime',
                        amount: currency.format(user?.momoBalance ?? 0),
                        icon: Icons.account_balance_wallet,
                        color: Colors.purple,
                      ),
                      _WalletItemData(
                        title: 'Logical Airtime',
                        amount: currency.format(user?.logicalBalance ?? 0),
                        icon: Icons.swap_horiz,
                        color: Colors.teal,
                      ),
                      _WalletItemData(
                        title: 'Commission',
                        amount: currency.format(user?.commissionBalance ?? 0),
                        icon: Icons.payments,
                        color: Colors.green.shade700,
                      ),
                    ],
                  ),
                const SizedBox(height: 24),
                const RecentTransactionsSection(),
                const SizedBox(height: 16),
              ],
            );
          },
        ),
      ),
    );
  }

  void _showRegisterCustomerDialog(BuildContext context) {
    showRegisterCustomerDialog(context);
  }
}

class _WalletCard extends StatelessWidget {
  const _WalletCard({
    required this.title,
    required this.amount,
    required this.icon,
    required this.color,
  });

  final String title;
  final String amount;
  final IconData icon;
  final Color color;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 160,
      padding: const EdgeInsets.all(14),
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.04),
            blurRadius: 10,
            offset: const Offset(0, 4),
          ),
        ],
        border: Border.all(color: Colors.grey.shade200),
      ),
      child: Column(
        crossAxisAlignment: CrossAxisAlignment.start,
        mainAxisAlignment: MainAxisAlignment.spaceBetween,
        children: [
          Row(
            children: [
              Icon(icon, size: 18, color: color),
              const SizedBox(width: 6),
              Expanded(
                child: Text(
                  title,
                  style: const TextStyle(fontSize: 11, fontWeight: FontWeight.w600, color: EcColors.muted),
                  overflow: TextOverflow.ellipsis,
                ),
              ),
            ],
          ),
          Text(
            amount,
            style: const TextStyle(fontSize: 16, fontWeight: FontWeight.w800, color: EcColors.ink),
          ),
        ],
      ),
    );
  }
}

class _ActionTile extends StatelessWidget {
  const _ActionTile({
    required this.icon,
    required this.label,
    required this.color,
    required this.onTap,
  });

  final IconData icon;
  final String label;
  final Color color;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Material(
      color: Colors.white,
      borderRadius: BorderRadius.circular(16),
      child: InkWell(
        onTap: onTap,
        borderRadius: BorderRadius.circular(16),
        child: Container(
          padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 8),
          decoration: BoxDecoration(
            borderRadius: BorderRadius.circular(16),
            border: Border.all(color: Colors.grey.shade200),
            boxShadow: [
              BoxShadow(
                color: Colors.black.withValues(alpha: 0.02),
                blurRadius: 6,
                offset: const Offset(0, 2),
              ),
            ],
          ),
          child: Column(
            mainAxisAlignment: MainAxisAlignment.center,
            children: [
              CircleAvatar(
                radius: 18,
                backgroundColor: color.withValues(alpha: 0.12),
                child: Icon(icon, color: color, size: 20),
              ),
              const SizedBox(height: 6),
              Expanded(
                child: Center(
                  child: Text(
                    label,
                    textAlign: TextAlign.center,
                    style: const TextStyle(
                      fontSize: 11,
                      fontWeight: FontWeight.w700,
                      color: EcColors.ink,
                      height: 1.15,
                    ),
                    maxLines: 2,
                    overflow: TextOverflow.ellipsis,
                  ),
                ),
              ),
            ],
          ),
        ),
      ),
    );
  }
}

class _WalletItemData {
  final String title;
  final String amount;
  final IconData icon;
  final Color color;

  const _WalletItemData({
    required this.title,
    required this.amount,
    required this.icon,
    required this.color,
  });
}

class _MobileWalletSlider extends StatefulWidget {
  const _MobileWalletSlider({required this.items});

  final List<_WalletItemData> items;

  @override
  State<_MobileWalletSlider> createState() => _MobileWalletSliderState();
}

class _MobileWalletSliderState extends State<_MobileWalletSlider> {
  late final PageController _pageController;
  int _currentIndex = 0;

  @override
  void initState() {
    super.initState();
    _pageController = PageController();
  }

  @override
  void dispose() {
    _pageController.dispose();
    super.dispose();
  }

  void _nextPage() {
    if (_currentIndex < widget.items.length - 1) {
      _pageController.nextPage(
        duration: const Duration(milliseconds: 250),
        curve: Curves.easeInOut,
      );
    } else {
      _pageController.animateToPage(
        0,
        duration: const Duration(milliseconds: 300),
        curve: Curves.easeInOut,
      );
    }
  }

  void _prevPage() {
    if (_currentIndex > 0) {
      _pageController.previousPage(
        duration: const Duration(milliseconds: 250),
        curve: Curves.easeInOut,
      );
    } else {
      _pageController.animateToPage(
        widget.items.length - 1,
        duration: const Duration(milliseconds: 300),
        curve: Curves.easeInOut,
      );
    }
  }

  @override
  Widget build(BuildContext context) {
    return Container(
      decoration: BoxDecoration(
        color: Colors.white,
        borderRadius: BorderRadius.circular(16),
        boxShadow: [
          BoxShadow(
            color: Colors.black.withValues(alpha: 0.04),
            blurRadius: 10,
            offset: const Offset(0, 4),
          ),
        ],
        border: Border.all(color: Colors.grey.shade200),
      ),
      padding: const EdgeInsets.symmetric(vertical: 12, horizontal: 8),
      child: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Row(
            children: [
              IconButton(
                icon: const Icon(Icons.chevron_left_rounded, size: 28, color: EcColors.ink),
                onPressed: _prevPage,
                tooltip: 'Previous wallet',
              ),
              Expanded(
                child: SizedBox(
                  height: 64,
                  child: PageView.builder(
                    controller: _pageController,
                    itemCount: widget.items.length,
                    onPageChanged: (idx) => setState(() => _currentIndex = idx),
                    itemBuilder: (context, index) {
                      final item = widget.items[index];
                      return Column(
                        mainAxisAlignment: MainAxisAlignment.center,
                        children: [
                          Row(
                            mainAxisAlignment: MainAxisAlignment.center,
                            children: [
                              Icon(item.icon, size: 16, color: item.color),
                              const SizedBox(width: 6),
                              Text(
                                item.title,
                                style: const TextStyle(
                                  fontSize: 12,
                                  fontWeight: FontWeight.w600,
                                  color: EcColors.muted,
                                ),
                              ),
                            ],
                          ),
                          const SizedBox(height: 4),
                          Text(
                            item.amount,
                            style: const TextStyle(
                              fontSize: 18,
                              fontWeight: FontWeight.w800,
                              color: EcColors.ink,
                            ),
                          ),
                        ],
                      );
                    },
                  ),
                ),
              ),
              IconButton(
                icon: const Icon(Icons.chevron_right_rounded, size: 28, color: EcColors.ink),
                onPressed: _nextPage,
                tooltip: 'Next wallet',
              ),
            ],
          ),
          const SizedBox(height: 4),
          Row(
            mainAxisAlignment: MainAxisAlignment.center,
            children: List.generate(widget.items.length, (index) {
              final isActive = index == _currentIndex;
              return AnimatedContainer(
                duration: const Duration(milliseconds: 200),
                margin: const EdgeInsets.symmetric(horizontal: 3),
                width: isActive ? 16 : 6,
                height: 6,
                decoration: BoxDecoration(
                  color: isActive ? EcColors.primary : Colors.grey.shade300,
                  borderRadius: BorderRadius.circular(3),
                ),
              );
            }),
          ),
        ],
      ),
    );
  }
}
