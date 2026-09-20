class AgentUser {
  const AgentUser({
    required this.id,
    required this.fullName,
    required this.email,
    required this.phone,
    this.location = '',
    this.gender = '',
    this.role = 0,
    this.accountName = '',
    this.bankName = '',
    this.accountNumber = '',
    this.momoBalance = 0,
    this.vtuBalance = 0,
    this.logicalBalance = 0,
    this.commissionBalance = 0,
    this.emailVerified = true,
    this.hasTransactionPin = false,
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
  final String accountName;
  final String bankName;
  final String accountNumber;
  final double momoBalance;
  final double vtuBalance;
  final double logicalBalance;
  final double commissionBalance;
  final bool emailVerified;
  final bool hasTransactionPin;
  /// Own-account / Super-Admin metadata. Never shown to Admin as a label for others.
  final bool isExternal;
  final String walletId;

  // Role constants
  static const int roleCustomer = 0;
  static const int roleAgent = 1;
  static const int roleAdmin = 2;
  static const int roleSuperAdmin = 3;

  bool get isCustomer => role == roleCustomer;
  bool get isAgent => role == roleAgent;
  bool get isAdmin => role == roleAdmin;
  bool get isSuperAdmin => role == roleSuperAdmin;

  /// Admin or Super Admin — can open Manage Users / admin tools.
  bool get canManageUsers => role >= roleAdmin;

  /// Agents + admins + super admins (customers blocked from agent app).
  bool get canUseApp => role >= roleAgent;

  String get roleName {
    switch (role) {
      case roleSuperAdmin:
        return 'Super Admin';
      case roleAdmin:
        return 'Admin';
      case roleAgent:
        return 'Agent';
      default:
        return 'Customer';
    }
  }

  /// Roles this actor is allowed to assign.
  List<int> get assignableRoles {
    if (isSuperAdmin) {
      return const [roleCustomer, roleAgent, roleAdmin, roleSuperAdmin];
    }
    if (isAdmin) {
      return const [roleCustomer, roleAgent];
    }
    return const [];
  }

  /// Whether this actor may change [target]'s role / profile.
  bool canEditUserRole(AgentUser target) {
    if (isSuperAdmin) return true;
    if (isAdmin) {
      // Admin may only edit customers and agents (not other admins / super admins).
      return target.role <= roleAgent;
    }
    return false;
  }

  /// Alias — same rules as role edits (profile / PIN changes).
  bool canEditUserProfile(AgentUser target) => canEditUserRole(target);

  /// Whether this actor may permanently delete [target] (not self).
  bool canDeleteUser(AgentUser target) {
    if (target.id == id) return false;
    return canEditUserRole(target);
  }


  factory AgentUser.fromJson(Map<String, dynamic> json) {
    double money(dynamic v) => (v is num) ? v.toDouble() : double.tryParse('$v') ?? 0;
    int number(dynamic v) {
      if (v is num) return v.toInt();
      return int.tryParse('$v'.trim()) ?? 0;
    }

    bool flag(dynamic v, {bool defaultValue = false}) {
      if (v == null) return defaultValue;
      if (v == true || v == 1) return true;
      if (v == false || v == 0) return false;
      final s = '$v'.trim().toLowerCase();
      if (s == 'true' || s == '1' || s == 'yes') return true;
      if (s == 'false' || s == '0' || s == 'no') return false;
      return defaultValue;
    }

    return AgentUser(
      id: number(json['id']),
      fullName: '${json['full_name'] ?? ''}',
      email: '${json['email'] ?? ''}',
      phone: '${json['phone'] ?? ''}',
      location: '${json['location'] ?? ''}',
      gender: '${json['gender'] ?? ''}',
      role: number(json['role']),
      accountName: '${json['account_name'] ?? ''}',
      bankName: '${json['bank_name'] ?? ''}',
      accountNumber: '${json['account_number'] ?? ''}',
      momoBalance: money(json['momo_balance']),
      vtuBalance: money(json['vtu_balance']),
      logicalBalance: money(json['logical_balance']),
      commissionBalance: money(json['commission_balance']),
      emailVerified: flag(json['email_verified'], defaultValue: true),
      hasTransactionPin: flag(json['has_transaction_pin']),
      isExternal: flag(json['is_external']),
      walletId: '${json['wallet_id'] ?? ''}'.trim().toUpperCase(),
    );
  }

  Map<String, dynamic> toJson() {
    final map = <String, dynamic>{
      'id': id,
      'full_name': fullName,
      'email': email,
      'phone': phone,
      'location': location,
      'gender': gender,
      'role': role,
      'account_name': accountName,
      'bank_name': bankName,
      'account_number': accountNumber,
      'momo_balance': momoBalance,
      'vtu_balance': vtuBalance,
      'logical_balance': logicalBalance,
      'commission_balance': commissionBalance,
      'email_verified': emailVerified,
      'has_transaction_pin': hasTransactionPin,
    };
    // Never persist visibility labels for non–Super Admin sessions.
    if (isSuperAdmin) {
      map['is_external'] = isExternal;
      map['wallet_id'] = walletId;
    } else if (walletId.isNotEmpty) {
      map['wallet_id'] = walletId;
    }
    return map;
  }

  AgentUser copyWithBalances({
    double? momoBalance,
    double? vtuBalance,
    double? logicalBalance,
    double? commissionBalance,
    bool? hasTransactionPin,
    bool? emailVerified,
    bool? isExternal,
    String? walletId,
  }) {
    return AgentUser(
      id: id,
      fullName: fullName,
      email: email,
      phone: phone,
      location: location,
      gender: gender,
      role: role,
      accountName: accountName,
      bankName: bankName,
      accountNumber: accountNumber,
      momoBalance: momoBalance ?? this.momoBalance,
      vtuBalance: vtuBalance ?? this.vtuBalance,
      logicalBalance: logicalBalance ?? this.logicalBalance,
      commissionBalance: commissionBalance ?? this.commissionBalance,
      emailVerified: emailVerified ?? this.emailVerified,
      hasTransactionPin: hasTransactionPin ?? this.hasTransactionPin,
      isExternal: isExternal ?? this.isExternal,
      walletId: walletId ?? this.walletId,
    );
  }
}
