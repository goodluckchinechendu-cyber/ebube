import 'package:flutter/material.dart';

import '../../config/theme.dart';
import '../../services/session_inactivity_guard.dart';
import '../../state/auth_controller.dart';
import '../../widgets/app_sidebar.dart';
import '../customers/customers_screen.dart';
import '../home/home_screen.dart';
import '../more/announcements_screen.dart';
import '../more/commission_tiers_screen.dart';
import '../more/fund_wallet_screen.dart';
import '../more/invite_link_screen.dart';
import '../more/manage_users_screen.dart';
import '../more/more_screen.dart';
import '../more/transaction_limits_screen.dart';
import '../more/wallet_holds_screen.dart';
import '../transactions/statement_screen.dart';
import '../transactions/transfer_history_screen.dart';
import '../transactions/transactions_screen.dart';
import '../vtu/buy_airtime_screen.dart';
import '../vtu/buy_data_screen.dart';
import '../wallet/wallet_history_screen.dart';
import '../wallet/wallet_screen.dart';
import '../wallet/wallet_transfer_screen.dart';

class MainShellScope extends InheritedWidget {
  const MainShellScope({
    super.key,
    required this.selectRoute,
    required this.selectIndex,
    required super.child,
  });

  final void Function(String routeId) selectRoute;
  final void Function(int index) selectIndex;

  static MainShellScope? of(BuildContext context) {
    return context.dependOnInheritedWidgetOfExactType<MainShellScope>();
  }

  @override
  bool updateShouldNotify(MainShellScope oldWidget) => false;
}

class MainShell extends StatefulWidget {
  const MainShell({super.key});

  @override
  State<MainShell> createState() => _MainShellState();
}

class _MainShellState extends State<MainShell> {
  int _index = 0;

  final _pages = const [
    HomeScreen(), // 0
    TransactionsScreen(), // 1
    CustomersScreen(), // 2
    WalletScreen(), // 3
    BuyAirtimeScreen(), // 4
    BuyDataScreen(), // 5
    ManageUsersScreen(), // 6
    MoreScreen(), // 7
    FundWalletScreen(), // 8
    CommissionTiersScreen(), // 9
    WalletHoldsScreen(), // 10
    StatementScreen(), // 11
    TransactionLimitsScreen(), // 12
    WalletHistoryScreen(), // 13
    WalletTransferScreen(), // 14
    TransferHistoryScreen(), // 15
    InviteLinkScreen(), // 16
    TransactionsScreen(allAccounts: true), // 17
  ];

  int _getIndexForRouteId(String routeId) {
    switch (routeId) {
      case 'dashboard':
        return 0;
      case 'transactions':
        return 1;
      case 'customers':
        return 2;
      case 'wallet':
        return 3;
      case 'buy_airtime':
        return 4;
      case 'buy_data':
        return 5;
      case 'manage_users':
        return 6;
      case 'more':
        return 7;
      case 'fund_wallet':
        return 8;
      case 'commission_tiers':
        return 9;
      case 'wallet_holds':
        return 10;
      case 'statement':
        return 11;
      case 'transaction_limits':
        return 12;
      case 'wallet_history':
        return 13;
      case 'wallet_transfer':
        return 14;
      case 'transfer_history':
        return 15;
      case 'invite_link':
        return 16;
      case 'all_transactions':
        return 17;
      default:
        return 0;
    }
  }

  String _getRouteIdForIndex(int index) {
    switch (index) {
      case 0:
        return 'dashboard';
      case 1:
        return 'transactions';
      case 2:
        return 'customers';
      case 3:
        return 'wallet';
      case 4:
        return 'buy_airtime';
      case 5:
        return 'buy_data';
      case 6:
        return 'manage_users';
      case 7:
        return 'more';
      case 8:
        return 'fund_wallet';
      case 9:
        return 'commission_tiers';
      case 10:
        return 'wallet_holds';
      case 11:
        return 'statement';
      case 12:
        return 'transaction_limits';
      case 13:
        return 'wallet_history';
      case 14:
        return 'wallet_transfer';
      case 15:
        return 'transfer_history';
      case 16:
        return 'invite_link';
      case 17:
        return 'all_transactions';
      default:
        return 'dashboard';
    }
  }

  void _selectRoute(String routeId) {
    final user = AuthScope.of(context).user;
    if (routeId == 'transaction_limits' && user?.isSuperAdmin != true) {
      return;
    }
    if (routeId == 'all_transactions' && user?.canManageUsers != true) {
      return;
    }
    setState(() {
      _index = _getIndexForRouteId(routeId);
    });
  }

  void _selectIndex(int index) {
    final user = AuthScope.of(context).user;
    if (index == 12 && user?.isSuperAdmin != true) {
      return;
    }
    if (index == 17 && user?.canManageUsers != true) {
      return;
    }
    setState(() {
      _index = index;
    });
  }

  List<SidebarItem> _buildSidebarItems(BuildContext context) {
    final user = AuthScope.of(context).user;

    final items = <SidebarItem>[
      SidebarItem.section('Services'),
      SidebarItem(
        routeId: 'buy_airtime',
        icon: Icons.phone_android_rounded,
        label: 'Top Up Airtime',
        onTap: () => _selectIndex(4),
      ),
      SidebarItem(
        routeId: 'buy_data',
        icon: Icons.wifi_rounded,
        label: 'Subscribe Data',
        onTap: () => _selectIndex(5),
      ),
      SidebarItem.section('Main'),
      SidebarItem(
        routeId: 'dashboard',
        icon: Icons.dashboard_rounded,
        label: 'Dashboard',
        onTap: () => _selectIndex(0),
      ),
      SidebarItem(
        routeId: 'transactions',
        icon: Icons.receipt_long_rounded,
        label: 'Transactions',
        onTap: () => _selectIndex(1),
      ),
      SidebarItem(
        routeId: 'statement',
        icon: Icons.summarize_outlined,
        label: 'Statement of Account',
        onTap: () => _selectIndex(11),
      ),
      SidebarItem(
        routeId: 'customers',
        icon: Icons.people_alt_rounded,
        label: 'Customers',
        onTap: () => _selectIndex(2),
      ),
      SidebarItem(
        routeId: 'wallet',
        icon: Icons.account_balance_wallet_rounded,
        label: 'Wallet',
        onTap: () => _selectIndex(3),
      ),
      SidebarItem(
        routeId: 'wallet_history',
        icon: Icons.history_rounded,
        label: 'Wallet History',
        onTap: () => _selectIndex(13),
      ),
      SidebarItem(
        routeId: 'wallet_transfer',
        icon: Icons.swap_horiz_rounded,
        label: 'Wallet Transfer',
        onTap: () => _selectIndex(14),
      ),
      SidebarItem(
        routeId: 'transfer_history',
        icon: Icons.sync_alt_rounded,
        label: 'Transfer History',
        onTap: () => _selectIndex(15),
      ),
      SidebarItem(
        routeId: 'invite_link',
        icon: Icons.link_rounded,
        label: 'Invite Link',
        onTap: () => _selectIndex(16),
      ),
    ];

    if (user?.canManageUsers == true) {
      items.add(SidebarItem.section('Administration'));
      items.add(
        SidebarItem(
          routeId: 'all_transactions',
          icon: Icons.list_alt_rounded,
          label: 'All Transactions',
          onTap: () => _selectIndex(17),
        ),
      );
      items.add(
        SidebarItem(
          routeId: 'manage_users',
          icon: Icons.manage_accounts_rounded,
          label: 'Manage Users & Roles',
          onTap: () => _selectIndex(6),
        ),
      );
      items.add(
        SidebarItem(
          routeId: 'commission_tiers',
          icon: Icons.percent_rounded,
          label: 'Commission & Discount',
          onTap: () => _selectIndex(9),
        ),
      );
      if (user?.isSuperAdmin == true) {
        items.add(
          SidebarItem(
            routeId: 'transaction_limits',
            icon: Icons.tune_rounded,
            label: 'Transaction Limit Setting',
            onTap: () {
              if (AuthScope.of(context).user?.isSuperAdmin != true) {
                return;
              }
              _selectIndex(12);
            },
          ),
        );
      }
      items.add(
        SidebarItem(
          routeId: 'wallet_holds',
          icon: Icons.inventory_2_outlined,
          label: 'Wallet Holds',
          onTap: () => _selectIndex(10),
        ),
      );
      items.add(
        SidebarItem(
          routeId: 'fund_wallet',
          icon: Icons.add_card_rounded,
          label: 'Fund Wallet',
          onTap: () => _selectIndex(8),
        ),
      );
    }

    items.add(SidebarItem.section('Account'));
    items.add(
      SidebarItem(
        routeId: 'more',
        icon: Icons.tune_rounded,
        label: 'Settings & Profile',
        onTap: () => _selectIndex(7),
      ),
    );

    return items;
  }

  @override
  Widget build(BuildContext context) {
    final screenWidth = MediaQuery.of(context).size.width;
    final isDesktop = screenWidth >= 640;
    final user = AuthScope.of(context).user;
    final sidebarItems = _buildSidebarItems(context);
    final selectedRouteId = _getRouteIdForIndex(_index);

    return MainShellScope(
      selectRoute: _selectRoute,
      selectIndex: _selectIndex,
      child: isDesktop
          ? Scaffold(
              body: Row(
                children: [
                  AppSidebar(
                    items: sidebarItems,
                    selectedRouteId: selectedRouteId,
                    user: user,
                    onLogout: () async {
                      await SessionInactivityGuard.instance.disable();
                      if (context.mounted) {
                        await AuthScope.of(context).logout();
                      }
                    },
                  ),
                  Expanded(
                    child: IndexedStack(
                      index: _index,
                      children: _pages,
                    ),
                  ),
                ],
              ),
            )
          : Scaffold(
              appBar: AppBar(
                leading: Builder(
                  builder: (ctx) => IconButton(
                    icon: const Icon(Icons.menu_rounded),
                    tooltip: 'Open menu',
                    onPressed: () => Scaffold.of(ctx).openDrawer(),
                  ),
                ),
                titleSpacing: 8,
                title: Column(
                  crossAxisAlignment: CrossAxisAlignment.start,
                  mainAxisSize: MainAxisSize.min,
                  children: [
                    const Text(
                      'Welcome',
                      style: TextStyle(
                        fontSize: 12,
                        fontWeight: FontWeight.w400,
                        color: EcColors.muted,
                        height: 1.0,
                      ),
                    ),
                    Text(
                      user?.fullName.isNotEmpty == true
                          ? user!.fullName.split(' ').first
                          : 'Agent',
                      style: const TextStyle(
                        fontSize: 16,
                        fontWeight: FontWeight.w800,
                        color: EcColors.ink,
                        height: 1.2,
                      ),
                    ),
                  ],
                ),
                actions: [
                  IconButton(
                    icon: const Icon(Icons.notifications_none_rounded),
                    tooltip: 'Announcements',
                    onPressed: () {
                      Navigator.of(context).push(
                        MaterialPageRoute(builder: (_) => const AnnouncementsScreen()),
                      );
                    },
                  ),
                  const SizedBox(width: 4),
                ],
              ),
              drawer: SizedBox(
                width: 260,
                child: Drawer(
                  backgroundColor: Colors.black,
                  elevation: 16,
                  shape: const RoundedRectangleBorder(
                    borderRadius: BorderRadius.horizontal(right: Radius.circular(16)),
                  ),
                  child: AppSidebar(
                    items: sidebarItems,
                    selectedRouteId: selectedRouteId,
                    user: user,
                    onLogout: () async {
                      await SessionInactivityGuard.instance.disable();
                      if (context.mounted) {
                        await AuthScope.of(context).logout();
                      }
                    },
                  ),
                ),
              ),
              body: IndexedStack(
                index: _index,
                children: _pages,
              ),
            ),
    );
  }
}
