import 'package:flutter/material.dart';
import 'package:flutter/services.dart';

/// Asks for the 4-digit transaction PIN. Returns null if cancelled.
Future<String?> promptTransactionPin(
  BuildContext context, {
  String title = 'Transaction PIN',
  String message = 'Enter your 4-digit transaction PIN to confirm.',
}) async {
  final ctrl = TextEditingController();
  final formKey = GlobalKey<FormState>();
  var obscure = true;

  final pin = await showDialog<String>(
    context: context,
    barrierDismissible: false,
    builder: (ctx) {
      return StatefulBuilder(
        builder: (ctx, setLocal) {
          return AlertDialog(
            title: Text(title),
            content: Form(
              key: formKey,
              child: Column(
                mainAxisSize: MainAxisSize.min,
                crossAxisAlignment: CrossAxisAlignment.stretch,
                children: [
                  Text(message, style: const TextStyle(height: 1.35)),
                  const SizedBox(height: 16),
                  TextFormField(
                    controller: ctrl,
                    obscureText: obscure,
                    autofocus: true,
                    keyboardType: TextInputType.number,
                    maxLength: 4,
                    inputFormatters: [FilteringTextInputFormatter.digitsOnly],
                    decoration: InputDecoration(
                      labelText: 'PIN',
                      counterText: '',
                      suffixIcon: IconButton(
                        onPressed: () => setLocal(() => obscure = !obscure),
                        icon: Icon(obscure ? Icons.visibility : Icons.visibility_off),
                      ),
                    ),
                    validator: (v) {
                      if (v == null || !RegExp(r'^\d{4}$').hasMatch(v)) {
                        return 'Enter exactly 4 digits';
                      }
                      return null;
                    },
                    onFieldSubmitted: (_) {
                      if (formKey.currentState?.validate() == true) {
                        Navigator.pop(ctx, ctrl.text);
                      }
                    },
                  ),
                ],
              ),
            ),
            actions: [
              TextButton(
                onPressed: () => Navigator.pop(ctx),
                child: const Text('Cancel'),
              ),
              FilledButton(
                onPressed: () {
                  if (formKey.currentState?.validate() == true) {
                    Navigator.pop(ctx, ctrl.text);
                  }
                },
                child: const Text('Confirm'),
              ),
            ],
          );
        },
      );
    },
  );

  ctrl.dispose();
  return pin;
}
