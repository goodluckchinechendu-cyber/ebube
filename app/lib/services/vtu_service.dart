import '../models/vtu_models.dart';
import 'api_client.dart';

/// Airtime/data via Ebube backend → SMobile Agent Developer API.
class VtuService {
  VtuService({ApiClient? client}) : _client = client ?? ApiClient();

  final ApiClient _client;

  Future<VtuResponse> balance() async {
    final data = await _client.get(
      'vtu_balance.php',
      throwOnFailure: false,
    );
    return VtuResponse.fromJson(data);
  }

  /// Live plans from GET /v1/plans (proxied). Refresh regularly — do not hardcode.
  Future<List<DataPlan>> fetchPlans({Network? network}) async {
    final query = <String, String>{};
    if (network != null) {
      query['network'] = network.apiName;
    }

    final data = await _client.get(
      'vtu_plans.php',
      query: query,
      throwOnFailure: false,
    );

    final plans = extractPlanMaps(data, network: network);
    final failed = data['success'] == false;
    if (failed && plans.isEmpty) {
      throw ApiException(
        '${data['message'] ?? data['error'] ?? 'Could not load data plans'}',
        statusCode: _asInt(data['response_code']),
      );
    }

    return plans
        .map((e) => DataPlan.fromJson(e, fallbackNetwork: network))
        .where((p) => p.id.isNotEmpty)
        .toList();
  }

  Future<VtuResponse> buyAirtime(AirtimeRequest req) async {
    final phone = req.phone.replaceAll(RegExp(r'\D'), '');
    final data = await _client.post(
      'vtu_purchase.php',
      body: {
        'type': 'airtime',
        'network': req.network.apiName,
        'phone': phone,
        'amount': req.amount,
        'user_id': req.userId,
        'wallet_product': req.walletProduct,
        'transaction_pin': req.transactionPin,
        if (req.clientRequestId.isNotEmpty)
          'client_request_id': req.clientRequestId,
      },
      throwOnFailure: false,
    );
    return VtuResponse.fromJson(data);
  }

  Future<VtuResponse> buyData(DataRequest req) async {
    final phone = req.phone.replaceAll(RegExp(r'\D'), '');
    final data = await _client.post(
      'vtu_purchase.php',
      body: {
        'type': 'data',
        'network': req.network.apiName,
        'phone': phone,
        'plan_id': req.planId.trim(),
        'plan_amount': req.planAmount,
        'plan_name': req.planName,
        'user_id': req.userId,
        'wallet_product': req.walletProduct,
        'transaction_pin': req.transactionPin,
        if (req.clientRequestId.isNotEmpty)
          'client_request_id': req.clientRequestId,
      },
      throwOnFailure: false,
    );
    return VtuResponse.fromJson(data);
  }

  Future<VtuResponse> checkStatus(String reference) async {
    final ref = reference.trim();
    if (ref.isEmpty) {
      return const VtuResponse(
        success: false,
        message: 'Missing transaction reference',
        status: 'failed',
      );
    }
    final data = await _client.get(
      'vtu_transaction.php',
      query: {'reference': ref},
      throwOnFailure: false,
    );
    return VtuResponse.fromJson(data);
  }

  /// Poll until success/failed or attempts exhausted.
  /// Longer window so "processing" resolves automatically (webhook + poll settle holds).
  Future<VtuResponse> waitForFinalStatus(
    String reference, {
    int maxAttempts = 20,
    Duration interval = const Duration(seconds: 2),
  }) async {
    var latest = await checkStatus(reference);
    var attempts = 0;
    while (latest.isProcessing && attempts < maxAttempts) {
      await Future<void>.delayed(interval);
      latest = await checkStatus(reference);
      attempts++;
    }

    if (latest.isProcessing) {
      final msg = latest.message.isNotEmpty
          ? latest.message
          : 'Still processing. Your injected wallet stays held; we will auto-complete or auto-refund when the provider finishes (ref ${latest.reference ?? reference}).';
      return VtuResponse(
        success: false,
        message: msg,
        status: 'processing',
        responseCode: latest.responseCode,
        reference: latest.reference ?? reference,
        customerReference: latest.customerReference,
        type: latest.type,
        networkId: latest.networkId,
        phone: latest.phone,
        faceValue: latest.faceValue,
        amountCharged: latest.amountCharged,
        commissionEarned: latest.commissionEarned,
        balance: latest.balance,
        currency: latest.currency,
        raw: latest.raw,
      );
    }
    return latest;
  }

  void dispose() => _client.close();
}

int? _asInt(dynamic v) {
  if (v == null) return null;
  if (v is num) return v.toInt();
  return int.tryParse('$v'.trim());
}
