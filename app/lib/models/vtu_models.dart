// VTU models aligned with SMobile Agent Developer API
// https://smobileagent.com/api

enum Network {
  mtn('MTN', 'MTN', 1),
  airtel('Airtel', 'Airtel', 2),
  glo('Glo', 'GLO', 3),
  nineMobile('9mobile', '9mobile', 4);

  const Network(this.label, this.apiName, this.id);

  /// UI label
  final String label;

  /// Value sent as `network` (docs: "MTN", "GLO", "Airtel", "9mobile")
  final String apiName;

  final int id;
}

class DataPlan {
  const DataPlan({
    required this.id,
    required this.network,
    required this.label,
    required this.price,
    this.validity = '',
    this.raw = const {},
  });

  final String id;
  final Network network;
  final String label;
  final double price;
  final String validity;
  final Map<String, dynamic> raw;

  factory DataPlan.fromJson(Map<String, dynamic> json, {Network? fallbackNetwork}) {
    final network = _networkFromJson(json) ?? fallbackNetwork ?? Network.mtn;
    final id = '${json['plan_id'] ?? json['id'] ?? json['planId'] ?? ''}'.trim();
    final label =
        '${json['name'] ?? json['plan_name'] ?? json['label'] ?? json['data'] ?? json['description'] ?? 'Plan $id'}'
            .trim();
    final price = _money(
      json['amount'] ??
          json['price'] ??
          json['plan_amount'] ??
          json['selling_price'] ??
          json['amount_naira'] ??
          0,
    );
    final validityRaw =
        json['validity'] ?? json['duration'] ?? json['validity_days'] ?? json['plan_validity'];
    final validity = validityRaw == null ? '' : '$validityRaw'.trim();

    return DataPlan(
      id: id,
      network: network,
      label: label.isEmpty ? 'Plan $id' : label,
      price: price,
      validity: validity,
      raw: Map<String, dynamic>.from(json),
    );
  }

  @override
  String toString() {
    final v = validity.isEmpty ? '' : ' ($validity)';
    return '$label — ₦${price.toStringAsFixed(0)}$v';
  }
}

Network? _networkFromJson(Map<String, dynamic> json) {
  final idRaw = json['network_id'] ?? json['networkId'];
  final id = idRaw is num ? idRaw.toInt() : int.tryParse('$idRaw'.trim());
  if (id != null) {
    for (final n in Network.values) {
      if (n.id == id) return n;
    }
  }

  final name = '${json['network'] ?? json['network_name'] ?? ''}'.trim().toLowerCase();
  if (name.isEmpty) return null;
  if (name == '1' || name.contains('mtn')) return Network.mtn;
  if (name == '2' || name.contains('airtel')) return Network.airtel;
  if (name == '3' || name == 'glo' || name.contains('glo')) return Network.glo;
  if (name == '4' ||
      name.contains('9mobile') ||
      name.contains('9 mobile') ||
      name.contains('eti')) {
    return Network.nineMobile;
  }
  return null;
}

double _money(dynamic v) {
  if (v is num) return v.toDouble();
  final s = '$v'.replaceAll(',', '').replaceAll('₦', '').trim();
  return double.tryParse(s) ?? 0;
}

class AirtimeRequest {
  const AirtimeRequest({
    required this.phone,
    required this.network,
    required this.amount,
    required this.userId,
    required this.walletProduct,
    this.transactionPin = '',
    this.clientRequestId = '',
  });

  final String phone;
  final Network network;
  final int amount;
  final int userId;
  /// momo | vtu | logical
  final String walletProduct;
  final String transactionPin;
  final String clientRequestId;
}

class DataRequest {
  const DataRequest({
    required this.phone,
    required this.network,
    required this.planId,
    required this.planAmount,
    required this.userId,
    required this.walletProduct,
    this.planName = '',
    this.transactionPin = '',
    this.clientRequestId = '',
  });

  final String phone;
  final Network network;
  final String planId;
  final double planAmount;
  final int userId;
  final String walletProduct;
  final String planName;
  final String transactionPin;
  final String clientRequestId;
}

class VtuResponse {
  const VtuResponse({
    required this.success,
    required this.message,
    required this.status,
    this.responseCode,
    this.reference,
    this.customerReference,
    this.type,
    this.networkId,
    this.phone,
    this.faceValue,
    this.amountCharged,
    this.commissionEarned,
    this.balance,
    this.currency,
    this.raw = const {},
  });

  final bool success;
  final String message;
  final String status; // success | processing | failed | …
  final int? responseCode;
  final String? reference;
  final String? customerReference;
  final String? type;
  final int? networkId;
  final String? phone;
  final double? faceValue;
  final double? amountCharged;
  final double? commissionEarned;
  final double? balance;
  final String? currency;
  final Map<String, dynamic> raw;

  bool get isProcessing {
    final s = status.toLowerCase();
    return s == 'processing' ||
        s == 'pending' ||
        s == 'queued' ||
        s == 'uncertain';
  }

  bool get isUncertain => status.toLowerCase() == 'uncertain';

  bool get isFailed =>
      !success && !isProcessing && status.toLowerCase() != 'success';

  factory VtuResponse.fromJson(Map<String, dynamic> json) {
    final statusRaw = '${json['status'] ?? ''}'.trim().toLowerCase();
    final flaggedSuccess = json['success'] == true;

    // Docs: success is true only when status is "success".
    late final String status;
    if (statusRaw.isNotEmpty) {
      status = statusRaw;
    } else if (flaggedSuccess) {
      status = 'success';
    } else {
      status = 'failed';
    }

    final success = status == 'success' || (statusRaw.isEmpty && flaggedSuccess);

    final message = '${json['message'] ?? json['error'] ?? json['detail'] ?? ''}'.trim();

    return VtuResponse(
      success: success,
      message: message,
      status: status,
      responseCode: _asInt(json['response_code'] ?? json['status_code']),
      reference: _asNonEmptyString(json['reference']),
      customerReference: _asNonEmptyString(json['customer_reference']),
      type: json['type']?.toString(),
      networkId: _asInt(json['network_id']),
      phone: json['phone']?.toString(),
      faceValue: _moneyOrNull(json['face_value']),
      amountCharged: _moneyOrNull(json['amount_charged']),
      commissionEarned: _moneyOrNull(json['commission_earned']),
      balance: _moneyOrNull(json['balance']),
      currency: json['currency']?.toString(),
      raw: Map<String, dynamic>.from(json),
    );
  }
}

int? _asInt(dynamic v) {
  if (v == null) return null;
  if (v is num) return v.toInt();
  return int.tryParse('$v'.trim());
}

String? _asNonEmptyString(dynamic v) {
  if (v == null) return null;
  final s = '$v'.trim();
  return s.isEmpty ? null : s;
}

double? _moneyOrNull(dynamic v) {
  if (v == null) return null;
  return _money(v);
}

/// Pull a plan list out of common response shapes.
List<Map<String, dynamic>> extractPlanMaps(Map<String, dynamic> data) {
  dynamic raw = data['plans'] ?? data['plan_list'];
  if (raw == null && data['data'] is List) {
    raw = data['data'];
  }
  if (raw == null && data['data'] is Map) {
    final nested = Map<String, dynamic>.from(data['data'] as Map);
    raw = nested['plans'] ?? nested['plan_list'] ?? nested['data'];
  }
  if (raw is! List) return const [];

  return raw
      .whereType<Map>()
      .map((e) => Map<String, dynamic>.from(e))
      .toList();
}
