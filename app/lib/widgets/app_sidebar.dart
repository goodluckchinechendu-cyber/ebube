import 'package:flutter/material.dart';

import '../config/theme.dart';
import '../models/user.dart';
import 'ec_logo.dart';

/// Navigation item model for the sidebar menu.
class SidebarItem {
  const SidebarItem({
    required this.routeId,
    required this.icon,
    required this.label,
    this.onTap,
    this.isSectionHeader = false,
  });

  factory SidebarItem.section(String title) {
    return SidebarItem(
      routeId: '_section_$title',
      icon: Icons.more_horiz_rounded,
      label: title,
      isSectionHeader: true,
    );
  }

  final String routeId;
  final IconData icon;
  final String label;
  final VoidCallback? onTap;
  final bool isSectionHeader;
}

/// Rich sidebar navigation panel modeled after modern Flutter web/mobile apps.
///
/// Can be rendered as a fixed desktop navigation sidebar or inside a [Scaffold.drawer].
class AppSidebar extends StatelessWidget {
  const AppSidebar({
    super.key,
    required this.items,
    this.selectedRouteId,
    this.user,
    this.onLogout,
  });

  final List<SidebarItem> items;
  final String? selectedRouteId;
  final AgentUser? user;
  final VoidCallback? onLogout;

  @override
  Widget build(BuildContext context) {
    return Container(
      width: 260,
      color: Colors.black, // Pure black background
      child: Column(
        children: [
          _SidebarHeader(),
          const Divider(color: Color(0xFF1E293B), height: 1),
          const SizedBox(height: 8),
          Expanded(
            child: ListView.builder(
              padding: const EdgeInsets.symmetric(horizontal: 12),
              itemCount: items.length,
              itemBuilder: (context, index) {
                final item = items[index];
                if (item.isSectionHeader) {
                  return Padding(
                    padding: EdgeInsets.fromLTRB(14, index == 0 ? 8 : 20, 14, 6),
                    child: Text(
                      item.label.toUpperCase(),
                      style: const TextStyle(
                        color: Color(0xFF64748B),
                        fontSize: 11,
                        fontWeight: FontWeight.w700,
                        letterSpacing: 1.0,
                      ),
                    ),
                  );
                }
                final isSelected = selectedRouteId != null && item.routeId == selectedRouteId;
                return _SidebarTile(
                  item: item,
                  selected: isSelected,
                  onTap: () {
                    if (Navigator.of(context).canPop()) {
                      Navigator.of(context).pop(); // Close mobile drawer
                    }
                    item.onTap?.call();
                  },
                );
              },
            ),
          ),
          const Divider(color: Color(0xFF1E293B), height: 1),
          _SidebarFooter(user: user, onLogout: onLogout),
        ],
      ),
    );
  }
}

class _SidebarHeader extends StatelessWidget {
  @override
  Widget build(BuildContext context) {
    final canPop = Navigator.of(context).canPop();

    return Container(
      width: double.infinity,
      padding: EdgeInsets.fromLTRB(
        20,
        MediaQuery.of(context).padding.top + 16,
        canPop ? 8 : 20,
        16,
      ),
      decoration: const BoxDecoration(
        gradient: LinearGradient(
          begin: Alignment.topLeft,
          end: Alignment.bottomRight,
          colors: [Colors.black, Color(0xFF111111)],
        ),
      ),
      child: Row(
        children: [
          const EcLogo(size: 42, isDarkBackground: true),
          const SizedBox(width: 14),
          Expanded(
            child: Column(
              crossAxisAlignment: CrossAxisAlignment.start,
              children: const [
                Text(
                  'EbubeConnect',
                  style: TextStyle(
                    color: Colors.white,
                    fontSize: 17,
                    fontWeight: FontWeight.w800,
                    letterSpacing: -0.3,
                  ),
                ),
                SizedBox(height: 2),
                Text(
                  'VTU & Telecom Portal',
                  style: TextStyle(
                    color: Color(0xFF94A3B8),
                    fontSize: 11,
                    fontWeight: FontWeight.w500,
                  ),
                ),
              ],
            ),
          ),
          if (canPop)
            IconButton(
              icon: const Icon(Icons.close_rounded, color: Colors.white70, size: 24),
              tooltip: 'Close menu',
              onPressed: () => Navigator.of(context).pop(),
            ),
        ],
      ),
    );
  }
}

class _SidebarTile extends StatelessWidget {
  const _SidebarTile({
    required this.item,
    required this.selected,
    required this.onTap,
  });

  final SidebarItem item;
  final bool selected;
  final VoidCallback onTap;

  @override
  Widget build(BuildContext context) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Material(
        color: selected ? EcColors.primary.withValues(alpha: 0.2) : Colors.transparent,
        borderRadius: BorderRadius.circular(12),
        child: InkWell(
          borderRadius: BorderRadius.circular(12),
          onTap: onTap,
          splashColor: EcColors.primary.withValues(alpha: 0.15),
          child: Padding(
            padding: const EdgeInsets.symmetric(horizontal: 14, vertical: 12),
            child: Row(
              children: [
                Icon(
                  item.icon,
                  size: 20,
                  color: selected ? EcColors.primary : const Color(0xFF94A3B8),
                ),
                const SizedBox(width: 14),
                Expanded(
                  child: Text(
                    item.label,
                    style: TextStyle(
                      color: selected ? Colors.white : const Color(0xFFCBD5E1),
                      fontSize: 14,
                      fontWeight: selected ? FontWeight.w700 : FontWeight.w500,
                    ),
                  ),
                ),
                if (selected)
                  Container(
                    width: 6,
                    height: 6,
                    decoration: const BoxDecoration(
                      color: EcColors.primary,
                      shape: BoxShape.circle,
                    ),
                  ),
              ],
            ),
          ),
        ),
      ),
    );
  }
}

class _SidebarFooter extends StatelessWidget {
  const _SidebarFooter({
    this.user,
    this.onLogout,
  });

  final AgentUser? user;
  final VoidCallback? onLogout;

  Color _getRoleColor(AgentUser? u) {
    if (u?.isSuperAdmin == true) return const Color(0xFFB45309);
    if (u?.isAdmin == true) return const Color(0xFFEF4444);
    if (u?.isAgent == true) return EcColors.primary;
    return const Color(0xFF3B82F6);
  }

  @override
  Widget build(BuildContext context) {
    final roleColor = _getRoleColor(user);
    final roleName = user?.roleName.toUpperCase() ?? 'CUSTOMER';

    return SafeArea(
      top: false,
      child: Padding(
        padding: const EdgeInsets.all(14),
        child: Row(
          children: [
            CircleAvatar(
              radius: 18,
              backgroundColor: EcColors.primary.withValues(alpha: 0.2),
              child: Text(
                user?.fullName.isNotEmpty == true ? user!.fullName[0].toUpperCase() : 'U',
                style: const TextStyle(
                  fontWeight: FontWeight.bold,
                  color: EcColors.primary,
                  fontSize: 14,
                ),
              ),
            ),
            const SizedBox(width: 10),
            Expanded(
              child: Column(
                crossAxisAlignment: CrossAxisAlignment.start,
                mainAxisSize: MainAxisSize.min,
                children: [
                  Text(
                    user?.fullName.isNotEmpty == true ? user!.fullName : 'User Account',
                    style: const TextStyle(
                      color: Colors.white,
                      fontSize: 13,
                      fontWeight: FontWeight.bold,
                    ),
                    overflow: TextOverflow.ellipsis,
                  ),
                  const SizedBox(height: 2),
                  Container(
                    padding: const EdgeInsets.symmetric(horizontal: 6, vertical: 1.5),
                    decoration: BoxDecoration(
                      color: roleColor.withValues(alpha: 0.2),
                      borderRadius: BorderRadius.circular(6),
                      border: Border.all(color: roleColor.withValues(alpha: 0.4), width: 0.8),
                    ),
                    child: Text(
                      roleName,
                      style: TextStyle(
                        color: roleColor,
                        fontSize: 9,
                        fontWeight: FontWeight.w800,
                        letterSpacing: 0.5,
                      ),
                    ),
                  ),
                ],
              ),
            ),
            if (onLogout != null)
              IconButton(
                icon: const Icon(Icons.logout_rounded, size: 20, color: Color(0xFFF87171)),
                tooltip: 'Log out',
                onPressed: () {
                  if (Navigator.of(context).canPop()) {
                    Navigator.of(context).pop();
                  }
                  onLogout!();
                },
              ),
          ],
        ),
      ),
    );
  }
}
