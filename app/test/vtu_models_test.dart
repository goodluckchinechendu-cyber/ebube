import 'package:ebube_connect/models/vtu_models.dart';
import 'package:flutter_test/flutter_test.dart';

void main() {
  group('Network.apiName', () {
    test('matches SMobile docs casing', () {
      expect(Network.mtn.apiName, 'MTN');
      expect(Network.airtel.apiName, 'Airtel');
      expect(Network.glo.apiName, 'GLO');
      expect(Network.nineMobile.apiName, '9mobile');
    });
  });

  group('VtuResponse.fromJson', () {
    test('success status', () {
      final r = VtuResponse.fromJson({
        'response_code': 200,
        'success': true,
        'status': 'success',
        'message': 'ok',
        'reference': 'SM-1',
      });
      expect(r.success, isTrue);
      expect(r.isProcessing, isFalse);
      expect(r.reference, 'SM-1');
    });

    test('processing even if success false', () {
      final r = VtuResponse.fromJson({
        'response_code': 200,
        'success': false,
        'status': 'processing',
        'message': 'working',
        'reference': 'SM-2',
      });
      expect(r.success, isFalse);
      expect(r.isProcessing, isTrue);
      expect(r.isFailed, isFalse);
    });

    test('failed without status', () {
      final r = VtuResponse.fromJson({
        'response_code': 400,
        'success': false,
        'message': 'insufficient balance',
      });
      expect(r.success, isFalse);
      expect(r.status, 'failed');
      expect(r.message, 'insufficient balance');
    });
  });

  group('extractPlanMaps', () {
    test('reads plan_list', () {
      final plans = extractPlanMaps({
        'success': true,
        'plan_list': [
          {'plan_id': '20002', 'name': '1GB', 'amount': 350, 'network_id': 1},
        ],
      });
      expect(plans.length, 1);
      expect(DataPlan.fromJson(plans.first).id, '20002');
      expect(DataPlan.fromJson(plans.first).price, 350);
    });

    test('reads nested data.plans', () {
      final plans = extractPlanMaps({
        'data': {
          'plans': [
            {'plan_id': 9, 'label': '500MB', 'price': '150'},
          ],
        },
      });
      expect(plans.length, 1);
      expect(DataPlan.fromJson(plans.first).id, '9');
      expect(DataPlan.fromJson(plans.first).price, 150);
    });
  });
}
