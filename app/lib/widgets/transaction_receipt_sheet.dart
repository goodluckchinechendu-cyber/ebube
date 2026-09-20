import 'package:flutter/foundation.dart';
import 'package:flutter/material.dart';
import 'package:share_plus/share_plus.dart';

import '../receipt/receipt_constants.dart';
import '../receipt/receipt_image_capture.dart';
import '../receipt/receipt_image_save.dart';
import '../screens/transactions/transactions_screen.dart';
import '../state/auth_controller.dart';
import 'transaction_receipt.dart';

/// Opens the bank-style receipt sheet (save / share).
Future<void> showTransactionReceiptSheet(
  BuildContext context,
  TransactionItem item,
) {
  return showModalBottomSheet<void>(
    context: context,
    isScrollControlled: true,
    backgroundColor: Colors.transparent,
    builder: (_) => TransactionReceiptSheet(item: item),
  );
}

class TransactionReceiptSheet extends StatefulWidget {
  const TransactionReceiptSheet({super.key, required this.item});

  final TransactionItem item;

  @override
  State<TransactionReceiptSheet> createState() => _TransactionReceiptSheetState();
}

class _TransactionReceiptSheetState extends State<TransactionReceiptSheet> {
  final _receiptKey = GlobalKey();
  final _saveButtonKey = GlobalKey();
  final _shareButtonKey = GlobalKey();
  bool _busy = false;

  TransactionItem get item => widget.item;

  String get _servedBy {
    final currentUser = AuthScope.of(context).user;
    return item.servedBy.isNotEmpty ? item.servedBy : (currentUser?.fullName ?? 'Agent');
  }

  Rect? _shareOrigin(GlobalKey key) {
    final box = key.currentContext?.findRenderObject() as RenderBox?;
    if (box == null || !box.hasSize) return null;
    return box.localToGlobal(Offset.zero) & box.size;
  }

  Future<void> _captureAndSave() async {
    if (_busy) return;
    final origin = _shareOrigin(_saveButtonKey);
    setState(() => _busy = true);
    try {
      final png = await captureReceiptPng(
        _receiptKey,
        pixelRatio: ReceiptConstants.capturePixelRatio,
      );
      final result = await saveReceiptImage(
        pngBytes: png,
        fileName: receiptImageFileName(item.receiptId),
        shareText: 'Receipt ${item.receiptId}',
        sharePositionOrigin: origin,
      );
      if (!mounted) return;
      if (kIsWeb) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(content: Text('Receipt image downloaded.')),
        );
        return;
      }
      if (result.status == ShareResultStatus.dismissed) return;
      if (result.status == ShareResultStatus.success ||
          result.status == ShareResultStatus.unavailable) {
        ScaffoldMessenger.of(context).showSnackBar(
          const SnackBar(
            content: Text('Choose Save or Gallery to store the receipt image.'),
          ),
        );
      }
    } catch (e) {
      if (mounted) {
        ScaffoldMessenger.of(context).showSnackBar(
          SnackBar(content: Text('Could not save receipt: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  Future<void> _captureAndShare() async {
    if (_busy) return;
    final origin = _shareOrigin(_shareButtonKey);
    setState(() => _busy = true);
    try {
      final png = await captureReceiptPng(
        _receiptKey,
        pixelRatio: ReceiptConstants.capturePixelRatio,
      );
      final result = await shareReceiptImage(
        pngBytes: png,
        fileName: receiptImageFileName(item.receiptId),
        shareText: 'EbubeConnect receipt ${item.receiptId}',
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
          SnackBar(content: Text('Could not share receipt: $e')),
        );
      }
    } finally {
      if (mounted) setState(() => _busy = false);
    }
  }

  @override
  Widget build(BuildContext context) {
    return DraggableScrollableSheet(
      initialChildSize: 0.85,
      minChildSize: 0.5,
      maxChildSize: 0.95,
      builder: (context, scrollController) {
        return Container(
          decoration: const BoxDecoration(
            color: Color(0xFFE5E7EB),
            borderRadius: BorderRadius.vertical(top: Radius.circular(24)),
          ),
          child: Column(
            children: [
              const SizedBox(height: 10),
              Container(
                width: 40,
                height: 4,
                decoration: BoxDecoration(
                  color: Colors.grey.shade400,
                  borderRadius: BorderRadius.circular(2),
                ),
              ),
              Expanded(
                child: ListView(
                  controller: scrollController,
                  padding: const EdgeInsets.all(16),
                  children: [
                    Center(
                      child: RepaintBoundary(
                        key: _receiptKey,
                        child: TransactionReceiptSlip(
                          receiptId: item.receiptId,
                          product: item.product,
                          amount: item.total,
                          status: item.status,
                          servedBy: _servedBy,
                          transactionAt: item.transactionAt,
                          phone: item.phone,
                          network: item.network,
                          customerName: item.customerName,
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
                      ? const Padding(
                          padding: EdgeInsets.all(12),
                          child: Center(child: CircularProgressIndicator()),
                        )
                      : Column(
                          children: [
                            Row(
                              children: [
                                Expanded(
                                  child: OutlinedButton.icon(
                                    onPressed: () => Navigator.pop(context),
                                    icon: const Icon(Icons.close),
                                    label: const Text('Close'),
                                  ),
                                ),
                                const SizedBox(width: 12),
                                Expanded(
                                  child: FilledButton.icon(
                                    key: _saveButtonKey,
                                    onPressed: _captureAndSave,
                                    icon: const Icon(Icons.download_outlined),
                                    label: const Text('Save Receipt'),
                                  ),
                                ),
                              ],
                            ),
                            const SizedBox(height: 10),
                            SizedBox(
                              width: double.infinity,
                              child: FilledButton.icon(
                                key: _shareButtonKey,
                                onPressed: _captureAndShare,
                                style: FilledButton.styleFrom(
                                  backgroundColor: const Color(0xFF6366F1),
                                ),
                                icon: const Icon(Icons.share_outlined),
                                label: const Text('Share Receipt'),
                              ),
                            ),
                          ],
                        ),
                ),
              ),
            ],
          ),
        );
      },
    );
  }
}
