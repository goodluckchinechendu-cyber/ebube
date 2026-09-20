import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../config/theme.dart';
import '../services/api_client.dart';

/// Live discount / wallet-charge preview for airtime & data purchases.
class PricingPreviewCard extends StatefulWidget {
  const PricingPreviewCard({
    super.key,
    required this.faceAmount,
    required this.productType,
    this.onChargeResolved,
  });

  final double faceAmount;
  final String productType; // airtime | data
  final ValueChanged<double?>? onChargeResolved;

  @override
  State<PricingPreviewCard> createState() => _PricingPreviewCardState();
}

class _PricingPreviewCardState extends State<PricingPreviewCard> {
  final _api = ApiClient();
  final _money = NumberFormat.currency(symbol: '₦', decimalDigits: 2);
  bool _loading = false;
  String? _error;
  double? _pay;
  double? _discount;
  double? _commission;
  int _requestId = 0;

  @override
  void initState() {
    super.initState();
    _load();
  }

  @override
  void didUpdateWidget(covariant PricingPreviewCard oldWidget) {
    super.didUpdateWidget(oldWidget);
    if (oldWidget.faceAmount != widget.faceAmount ||
        oldWidget.productType != widget.productType) {
      _load();
    }
  }

  Future<void> _load() async {
    final requestId = ++_requestId;
    if (widget.faceAmount <= 0) {
      setState(() {
        _pay = null;
        _discount = null;
        _commission = null;
        _error = null;
        _loading = false;
      });
      widget.onChargeResolved?.call(null);
      return;
    }
    setState(() {
      _loading = true;
      _error = null;
    });
    try {
      final face = widget.faceAmount;
      final res = await _api.post(
        'pricing_preview.php',
        body: {
          'product_type': widget.productType,
          'face_amount': face,
        },
        throwOnFailure: false,
      );
      if (!mounted || requestId != _requestId) return;
      if (res['success'] != true) {
        setState(() {
          _loading = false;
          _error = '${res['message'] ?? 'Preview unavailable'}';
        });
        widget.onChargeResolved?.call(null);
        return;
      }
      final pay = (res['wallet_charge'] as num?)?.toDouble() ?? face;
      setState(() {
        _pay = pay;
        _discount = (res['discount'] as num?)?.toDouble() ?? 0;
        _commission = (res['commission'] as num?)?.toDouble() ?? 0;
        _loading = false;
      });
      widget.onChargeResolved?.call(pay);
    } catch (e) {
      if (!mounted || requestId != _requestId) return;
      setState(() {
        _loading = false;
        _error = '$e';
      });
      widget.onChargeResolved?.call(null);
    }
  }

  @override
  Widget build(BuildContext context) {
    if (widget.faceAmount <= 0) return const SizedBox.shrink();
    return Card(
      margin: const EdgeInsets.only(top: 12),
      color: EcColors.primary.withValues(alpha: 0.08),
      child: Padding(
        padding: const EdgeInsets.all(12),
        child: _loading
            ? const Row(
                children: [
                  SizedBox(
                    width: 16,
                    height: 16,
                    child: CircularProgressIndicator(strokeWidth: 2),
                  ),
                  SizedBox(width: 10),
                  Text('Calculating charge…', style: TextStyle(fontSize: 12)),
                ],
              )
            : _error != null
                ? Text(_error!, style: const TextStyle(fontSize: 12, color: EcColors.muted))
                : Column(
                    crossAxisAlignment: CrossAxisAlignment.start,
                    children: [
                      const Text(
                        'You will pay',
                        style: TextStyle(fontSize: 12, color: EcColors.muted),
                      ),
                      Text(
                        _money.format(_pay ?? widget.faceAmount),
                        style: const TextStyle(
                          fontSize: 20,
                          fontWeight: FontWeight.w900,
                          color: EcColors.ink,
                        ),
                      ),
                      if ((_discount ?? 0) > 0)
                        Text(
                          'Discount ${_money.format(_discount)} · Face ${_money.format(widget.faceAmount)}',
                          style: const TextStyle(fontSize: 12, color: EcColors.muted),
                        ),
                      if ((_commission ?? 0) > 0)
                        Text(
                          'Est. commission ${_money.format(_commission)}',
                          style: const TextStyle(fontSize: 12, color: EcColors.success),
                        ),
                    ],
                  ),
      ),
    );
  }
}
