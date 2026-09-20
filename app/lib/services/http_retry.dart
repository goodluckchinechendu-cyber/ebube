import 'dart:async';

import 'package:http/http.dart' as http;

bool _isTransientNetworkError(Object e) {
  final msg = e.toString().toLowerCase();
  return e is TimeoutException ||
      e is http.ClientException ||
      msg.contains('connection abort') ||
      msg.contains('connection reset') ||
      msg.contains('connection closed') ||
      msg.contains('failed host lookup') ||
      msg.contains('network is unreachable') ||
      msg.contains('software caused connection abort');
}

Future<void> _retryDelay(int attempt) =>
    Future<void>.delayed(Duration(milliseconds: 500 * (attempt + 1)));

bool _shouldRetryStatus(int statusCode) =>
    statusCode == 502 ||
    statusCode == 503 ||
    statusCode == 504 ||
    statusCode == 508;

const _unavailableBody =
    '{"success":false,"message":"Server is temporarily unavailable. Please try again."}';

const _uncertainPurchaseBody =
    '{"success":false,"status":"uncertain","message":"No confirmed response from the server. Do not buy again — open Transactions to see if this purchase already went through."}';

const _uncertainWalletBody =
    '{"success":false,"status":"uncertain","message":"No confirmed response from the server. Do not fund again — refresh balances and check funding history first."}';

/// Paths that must never be auto-retried (money-moving / non-idempotent).
bool isNonIdempotentApiPath(String path) {
  final p = path.toLowerCase();
  return p.contains('vtu_purchase') ||
      p.contains('users_wallet') ||
      p.contains('wallet_funding') ||
      p.contains('wallet_transfer') ||
      p.contains('withdrawals') ||
      p.contains('users_delete') ||
      p.contains('set_transaction_pin') ||
      p.contains('verify_transaction_pin');
}

bool _isWalletMoneyPath(String path) {
  final p = path.toLowerCase();
  return p.contains('users_wallet') ||
      p.contains('wallet_funding') ||
      p.contains('wallet_transfer');
}

/// GET with short retries for transient hosting / network failures.
Future<http.Response> getWithRetry(
  http.Client client,
  Uri uri, {
  required Map<String, String> headers,
  Duration timeout = const Duration(seconds: 30),
  int attempts = 3,
}) async {
  Object? lastError;
  for (var i = 0; i < attempts; i++) {
    try {
      final response = await client.get(uri, headers: headers).timeout(timeout);
      if (_shouldRetryStatus(response.statusCode) && i < attempts - 1) {
        await _retryDelay(i);
        continue;
      }
      return response;
    } catch (e) {
      lastError = e;
      if (_isTransientNetworkError(e) && i < attempts - 1) {
        await _retryDelay(i);
        continue;
      }
      if (i < attempts - 1) {
        await _retryDelay(i);
        continue;
      }
    }
  }

  return http.Response(
    _unavailableBody,
    lastError is TimeoutException ? 504 : 503,
    headers: {'content-type': 'application/json'},
  );
}

/// POST with optional retries.
///
/// Use [attempts] = 1 for money-moving endpoints to avoid double-debits on timeout.
Future<http.Response> postWithRetry(
  http.Client client,
  Uri uri, {
  required Map<String, String> headers,
  required String body,
  Duration timeout = const Duration(seconds: 30),
  int attempts = 3,
}) async {
  final maxAttempts = attempts < 1 ? 1 : attempts;
  final moneyPath = isNonIdempotentApiPath(uri.path);
  Object? lastError;
  for (var i = 0; i < maxAttempts; i++) {
    try {
      final response = await client
          .post(uri, headers: headers, body: body)
          .timeout(timeout);
      if (_shouldRetryStatus(response.statusCode) && i < maxAttempts - 1) {
        await _retryDelay(i);
        continue;
      }
      return response;
    } catch (e) {
      lastError = e;
      // Never retry timeouts on non-idempotent calls (attempts already 1 for those).
      if (_isTransientNetworkError(e) && i < maxAttempts - 1) {
        await _retryDelay(i);
        continue;
      }
      if (i < maxAttempts - 1) {
        await _retryDelay(i);
        continue;
      }
    }
  }

  // Money paths: treat silence as uncertain — never nudge a blind retry.
  final fallbackBody = moneyPath
      ? (_isWalletMoneyPath(uri.path) ? _uncertainWalletBody : _uncertainPurchaseBody)
      : _unavailableBody;
  return http.Response(
    fallbackBody,
    lastError is TimeoutException ? 504 : 503,
    headers: {'content-type': 'application/json'},
  );
}
