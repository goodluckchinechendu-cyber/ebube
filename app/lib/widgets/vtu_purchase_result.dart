import 'package:flutter/material.dart';
import 'package:intl/intl.dart';
import 'package:share_plus/share_plus.dart';

import '../config/theme.dart';
import '../models/vtu_models.dart';
import '../receipt/receipt_constants.dart';
import '../receipt/receipt_image_capture.dart';
import '../receipt/receipt_image_save.dart';
import '../screens/shell/main_shell.dart';
import '../screens/transactions/transactions_screen.dart';
import '../state/auth_controller.dart';
import 'transaction_receipt.dart';

final _money = NumberFormat.currency(symbol: '₦', decimalDigits: 2);

/// Opens a full-screen receipt with optional auto-share after paint.
Future<void> openPurchaseReceipt(
  BuildContext context, {
  required String receiptId,
  required String product,
  required double amount,
  required String status,
  required String phone,
  String network = 'MTN',
  String customerName = '',
  String servedBy = '',
  bool autoShare = false,
  DateTime? transactionAt,
}) async {
  await Navigator.of(context, rootNavigator: true).push(
    MaterialPageRoute(
      builder: (_) => _PurchaseReceiptPage(
        receiptId: receiptId,
        product: product,
        amount: amount,
        status: status,
        phone: phone,
        network: network,
        customerName: customerName,
        servedBy: servedBy,
        autoShare: autoShare,
        transactionAt: transactionAt ?? DateTime.now(),
      ),
    ),
  );
}

Future<void> showVtuPurchaseResult(
  BuildContext context, {
  required VtuResponse res,
  required String productLabel,
  required String phone,
  String network = 'MTN',
  required double faceAmount,
}) async {
  final ok = res.success;
  final uncertain = res.isUncertain;
  final processing = res.isProcessing;
  final rawReceipt = '${res.raw['receipt_id'] ?? ''}'.trim();
  final rawRef = (res.reference ?? '').trim();
  final receiptId = rawReceipt.isNotEmpty ? rawReceipt : rawRef;
  final amount = (res.amountCharged ?? res.faceValue ?? faceAmount).toDouble();
  final txnAtRaw = '${res.raw['transaction_at'] ?? ''}'.trim();
  final transactionAt = DateTime.tryParse(txnAtRaw) ?? DateTime.now();

  String normalizedReceiptId(String id) {
    if (id.isEmpty) return id;
    // Keep the raw receipt/reference id on the slip (no VTU- prefix).
    return id.startsWith('VTU-') ? id.substring(4) : id;
  }

  await showDialog<void>(
    context: context,
    barrierDismissible: false,
    builder: (ctx) => AlertDialog(
      icon: Icon(
        ok
            ? Icons.check_circle_outline
            : processing
                ? Icons.hourglass_top
                : Icons.error_outline,
        color: ok
            ? EcColors.success
            : processing
                ? EcColors.primaryDark
                : EcColors.danger,
        size: 48,
      ),
      title: Text(
        ok
            ? 'Success!'
            : uncertain
                ? 'Check Transactions'
                : processing
                    ? 'Processing'
                    : 'Failed',
      ),
      content: Column(
        mainAxisSize: MainAxisSize.min,
        children: [
          Text(
            res.message.isEmpty
                ? (ok
                    ? '$productLabel purchase successful'
                    : uncertain
                        ? 'No confirmed response. Do not buy again — open Transactions first.'
                        : processing
                            ? 'Still processing. Your wallet stays held and will auto-complete or auto-refund.'
                            : 'Purchase failed')
                : res.message,
            textAlign: TextAlign.center,
          ),
          if (processing) ...[
            const SizedBox(height: 10),
            const Text(
              'You can leave this screen. Final status updates automatically.',
              textAlign: TextAlign.center,
              style: TextStyle(fontSize: 12, color: EcColors.muted),
            ),
          ],
          if (res.reference != null && res.reference!.isNotEmpty) ...[
            const SizedBox(height: 8),
            Text(
              'Ref: ${res.reference}',
              style: const TextStyle(color: EcColors.muted, fontSize: 13),
              textAlign: TextAlign.center,
            ),
          ],
          if (res.amountCharged != null) ...[
            const SizedBox(height: 4),
            Text(
              'Wallet charged: ${_money.format(res.amountCharged)}'
              '${res.faceValue != null && res.faceValue != res.amountCharged ? ' (face ${_money.format(res.faceValue)})' : ''}',
              style: const TextStyle(color: EcColors.muted, fontSize: 13),
              textAlign: TextAlign.center,
            ),
          ],
        ],
      ),
      actions: [
        if (processing) ...[
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Close'),
          ),
          FilledButton(
            onPressed: () {
              Navigator.pop(ctx);
              final shell = MainShellScope.of(context);
              if (shell != null) {
                shell.selectRoute('transactions');
              } else {
                Navigator.of(context).push(
                  MaterialPageRoute(builder: (_) => const TransactionsScreen()),
                );
              }
            },
            child: const Text('View Transactions'),
          ),
        ] else if (ok && receiptId.isNotEmpty) ...[
          TextButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('Close'),
          ),
          TextButton(
            onPressed: () async {
              Navigator.pop(ctx);
              await openPurchaseReceipt(
                context,
                receiptId: normalizedReceiptId(receiptId),
                product: productLabel,
                amount: amount,
                status: 'Completed',
                phone: phone,
                network: network,
                autoShare: true,
                transactionAt: transactionAt,
              );
            },
            child: const Text('Share'),
          ),
          FilledButton(
            onPressed: () async {
              Navigator.pop(ctx);
              await openPurchaseReceipt(
                context,
                receiptId: normalizedReceiptId(receiptId),
                product: productLabel,
                amount: amount,
                status: 'Completed',
                phone: phone,
                network: network,
                transactionAt: transactionAt,
              );
            },
            child: const Text('View Receipt'),
          ),
        ] else
          FilledButton(
            onPressed: () => Navigator.pop(ctx),
            child: const Text('OK'),
          ),
      ],
    ),
  );
}

class _PurchaseReceiptPage extends StatefulWidget {
  const _PurchaseReceiptPage({
    required this.receiptId,
    required this.product,
    required this.amount,
    required this.status,
    required this.phone,
    required this.network,
    required this.customerName,
    required this.servedBy,
    required this.autoShare,
    required this.transactionAt,
  });

  final String receiptId;
  final String product;
  final double amount;
  final String status;
  final String phone;
  final String network;
  final String customerName;
  final String servedBy;
  final bool autoShare;
  final DateTime transactionAt;

  @override
  State<_PurchaseReceiptPage> createState() => _PurchaseReceiptPageState();
}

class _PurchaseReceiptPageState extends State<_PurchaseReceiptPage> {
  final _key = GlobalKey();
  final _shareKey = GlobalKey();
  bool _busy = false;
  bool _didAutoShare = false;

  @override
  void initState() {
    super.initState();
    if (widget.autoShare) {
      WidgetsBinding.instance.addPostFrameCallback((_) => _share(fromAuto: true));
    }
  }

  Rect? _origin() {
    final box = _shareKey.currentContext?.findRenderObject() as RenderBox?;
    if (box == null || !box.hasSize) return null;
    return box.localToGlobal(Offset.zero) & box.size;
  }

  Future<void> _share({bool fromAuto = false}) async {
    if (_busy) return;
    if (fromAuto) {
      if (_didAutoShare) return;
      _didAutoShare = true;
    }
    // Capture before busy UI unmounts the Share button (needed on iPad).
    final origin = _origin();
    setState(() => _busy = true);
    try {
      final png = await captureReceiptPng(
        _key,
        pixelRatio: ReceiptConstants.capturePixelRatio,
      );
      final result = await shareReceiptImage(
        pngBytes: png,
        fileName: receiptImageFileName(widget.receiptId),
        shareText: 'EbubeConnect receipt ${widget.receiptId}',
        sharePositionOrigin: origin,
      );
      if (!mounted) return;
      if (result.status == ShareResultStatus.dismissed) return;
      if (result.status == ShareResultStatus.success) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Receipt shared.')),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Could not share: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _save() async {
    if (_busy) return;
    final origin = _origin();
    setState(() => _busy = true);
    try {
      final png = await captureReceiptPng(
        _key,
        pixelRatio: ReceiptConstants.capturePixelRatio,
      );
      final result = await saveReceiptImage(
        pngBytes: png,
        fileName: receiptImageFileName(widget.receiptId),
        sharePositionOrigin: origin,
      );
      if (!mounted) return;
      if (result.status == ShareResultStatus.dismissed) return;
      ScaffoldMessenger.of(context).showSnackBar(
        const SnackBar(content: Text('Receipt image ready to save.')),
      );
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Could not save: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    final servedBy = widget.servedBy.trim().isNotEmpty
        ? widget.servedBy.trim()
        : (AuthScope.of(context).user?.fullName ?? 'Agent');
    return Scaffold(
      backgroundColor: const Color(0xFFE5E7EB),
      appBar: AppBar(title: const Text('Receipt')),
      body: Column(
        children: [
          Expanded(
            child: ListView(
              padding: const EdgeInsets.all(16),
              children: [
                Center(
                  child: RepaintBoundary(
                    key: _key,
                    child: TransactionReceiptSlip(
                      receiptId: widget.receiptId,
                      product: widget.product,
                      amount: widget.amount,
                      status: widget.status,
                      servedBy: servedBy,
                      transactionAt: widget.transactionAt,
                      phone: widget.phone,
                      network: widget.network,
                      customerName: widget.customerName,
                    ),
                  ),
                ),
              ],
            ),
          ),
          SafeArea(
            top: false,
            child: Padding(
              padding: const EdgeInsets.fromLTRB(16, 0, 16, 16),
              child: _busy
                  ? const Center(child: CircularProgressIndicator())
                  : Row(
                      children: [
                        Expanded(
                          child: OutlinedButton.icon(
                            onPressed: _save,
                            icon: const Icon(Icons.download_outlined),
                            label: const Text('Save'),
                          ),
                        ),
                        const SizedBox(width: 12),
                        Expanded(
                          child: FilledButton.icon(
                            key: _shareKey,
                            onPressed: _share,
                            icon: const Icon(Icons.share_outlined),
                            label: const Text('Share'),
                          ),
                        ),
                      ],
                    ),
            ),
          ),
        ],
      ),
    );
  }
}
