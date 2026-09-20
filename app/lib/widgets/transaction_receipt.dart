import 'package:flutter/material.dart';
import 'package:intl/intl.dart';

import '../receipt/receipt_constants.dart';
import 'network_badge.dart';

/// Bank-style transaction receipt slip (mirrors reference APP layout).
class TransactionReceiptSlip extends StatelessWidget {
  const TransactionReceiptSlip({
    super.key,
    required this.receiptId,
    required this.product,
    required this.amount,
    required this.status,
    required this.servedBy,
    required this.transactionAt,
    this.phone = '',
    this.network = 'MTN',
    this.customerName = '',
  });

  final String receiptId;
  final String product;
  final double amount;
  final String status;
  final String servedBy;
  final DateTime transactionAt;
  final String phone;
  final String network;
  final String customerName;

  static const _mono = TextStyle(
    fontFamily: 'monospace',
    fontSize: 11,
    color: Colors.black,
    height: 1.35,
  );

  static const _muted = TextStyle(
    fontFamily: 'monospace',
    fontSize: 10,
    color: Color(0xFF374151),
    height: 1.35,
  );

  String get _recipient => phone.isNotEmpty ? phone : customerName;

  String get _recipientLabel => phone.isNotEmpty ? 'Phone' : 'Customer';

  /// Hide legacy `VTU-` prefix on the slip only.
  String get _displayReceiptId {
    final id = receiptId.trim();
    if (id.length > 4 && id.toUpperCase().startsWith('VTU-')) {
      return id.substring(4);
    }
    return id;
  }

  @override
  Widget build(BuildContext context) {
    final money = NumberFormat.currency(symbol: '₦', decimalDigits: 2);
    final date = DateFormat('yyyy-MM-dd').format(transactionAt);
    final time = DateFormat('HH:mm:ss').format(transactionAt);
    final slipWidth = ReceiptConstants.slipWidthFor(context);

    return Container(
      width: slipWidth,
      color: Colors.white,
      padding: const EdgeInsets.fromLTRB(14, 18, 14, 20),
      child: DefaultTextStyle(
        style: _mono,
        child: Column(
          crossAxisAlignment: CrossAxisAlignment.stretch,
          children: [
            Column(
              children: [
                Image.asset(
                  'assets/images/ebube.png',
                  height: 64,
                  fit: BoxFit.contain,
                  errorBuilder: (_, error, stackTrace) => const SizedBox.shrink(),
                ),
              ],
            ),
            const SizedBox(height: 12),
            const Text(
              'TRANSACTION RECEIPT',
              textAlign: TextAlign.center,
              style: TextStyle(
                fontFamily: 'monospace',
                fontWeight: FontWeight.bold,
                fontSize: 12,
              ),
            ),
            const SizedBox(height: 4),
            Text('Receipt #$_displayReceiptId', textAlign: TextAlign.center, style: _muted),
            Text('$date  $time', textAlign: TextAlign.center, style: _muted),
            _rule(),
            Text('Network: ${NetworkBadge.labelFor(network)}', style: _mono),
            Text('Product: $product', style: _mono),
            if (_recipient.isNotEmpty) ...[
              const SizedBox(height: 8),
              _pair(_recipientLabel, _recipient),
            ],
            _pair('Amount paid', money.format(amount), bold: true),
            _rule(),
            _pair('STATUS', status.toUpperCase()),
            _rule(),
            const Text('REFERENCE', textAlign: TextAlign.center, style: TextStyle(fontWeight: FontWeight.bold)),
            const SizedBox(height: 4),
            Text(_displayReceiptId, textAlign: TextAlign.center, style: _muted),
            const SizedBox(height: 6),
            _pair('SERVED BY', servedBy),
            _rule(),
            const Text(
              'THANK YOU FOR YOUR PATRONAGE',
              textAlign: TextAlign.center,
              style: TextStyle(fontWeight: FontWeight.bold),
            ),
            const SizedBox(height: 4),
            const Text(
              'Your trusted Telecom Partner.',
              textAlign: TextAlign.center,
              style: _muted,
            ),
            _rule(),
            const Text(
              'This is a computer generated receipt',
              textAlign: TextAlign.center,
              style: _muted,
            ),
            const Text(
              '*** END OF RECEIPT ***',
              textAlign: TextAlign.center,
              style: _muted,
            ),
          ],
        ),
      ),
    );
  }

  Widget _rule() {
    return const Padding(
      padding: EdgeInsets.symmetric(vertical: 10),
      child: Text(
        '--------------------------------',
        textAlign: TextAlign.center,
        style: TextStyle(fontFamily: 'monospace', fontSize: 11, letterSpacing: 0.5),
      ),
    );
  }

  Widget _pair(String label, String value, {bool bold = false}) {
    return Padding(
      padding: const EdgeInsets.symmetric(vertical: 2),
      child: Row(
        crossAxisAlignment: CrossAxisAlignment.start,
        children: [
          SizedBox(
            width: 92,
            child: Text(label, style: _muted),
          ),
          Expanded(
            child: Text(
              value,
              style: _mono.copyWith(fontWeight: bold ? FontWeight.bold : FontWeight.w600),
            ),
          ),
        ],
      ),
    );
  }
}
