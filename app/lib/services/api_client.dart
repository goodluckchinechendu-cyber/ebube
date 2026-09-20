import 'dart:async';
import 'dart:convert';

import 'package:http/http.dart' as http;

import '../config/app_config.dart';
import 'http_retry.dart';
import 'session_store.dart';

class ApiException implements Exception {
  ApiException(this.message, {this.statusCode, this.body});

  final String message;
  final int? statusCode;
  final Map<String, dynamic>? body;

  @override
  String toString() => message;
}

/// In-memory session token mirrored from [SessionStore].
class AuthTokenHolder {
  static String? token;
}

class ApiClient {
  ApiClient({http.Client? client, String? baseUrl, SessionStore? sessionStore})
      : _client = client ?? http.Client(),
        baseUrl = (baseUrl ?? AppConfig.apiBaseUrl).replaceAll(RegExp(r'/+$'), ''),
        _sessionStore = sessionStore ?? SessionStore();

  final http.Client _client;
  final String baseUrl;
  final SessionStore _sessionStore;

  Uri _uri(String path) {
    final cleaned = path.startsWith('/') ? path : '/$path';
    return Uri.parse('$baseUrl$cleaned');
  }

  Future<Map<String, String>> _headers() async {
    final headers = Map<String, String>.from(AppConfig.brandHeaders);
    var token = AuthTokenHolder.token;
    token ??= await _sessionStore.loadToken();
    if (token != null && token.isNotEmpty) {
      AuthTokenHolder.token = token;
      headers['Authorization'] = 'Bearer $token';
      headers['X-Ebube-Session'] = token;
    }
    return headers;
  }

  Future<Map<String, dynamic>> post(
    String path, {
    Map<String, dynamic>? body,
    bool throwOnFailure = true,
    bool? retry,
  }) async {
    try {
      final allowRetry = retry ?? !isNonIdempotentApiPath(path);
      final response = await postWithRetry(
        _client,
        _uri(path),
        headers: await _headers(),
        body: jsonEncode(body ?? {}),
        timeout: const Duration(seconds: 120),
        attempts: allowRetry ? 3 : 1,
      );
      return _decode(response, throwOnFailure: throwOnFailure);
    } on ApiException {
      rethrow;
    } catch (e) {
      throw ApiException(
        'No connection to the API at $baseUrl. Check your network and try again. ($e)',
      );
    }
  }

  Future<Map<String, dynamic>> get(
    String path, {
    Map<String, String>? query,
    bool throwOnFailure = true,
  }) async {
    try {
      var uri = _uri(path);
      if (query != null && query.isNotEmpty) {
        uri = uri.replace(queryParameters: {
          ...uri.queryParameters,
          ...query,
        });
      }
      final response = await getWithRetry(
        _client,
        uri,
        headers: await _headers(),
        timeout: const Duration(seconds: 120),
      );
      return _decode(response, throwOnFailure: throwOnFailure);
    } on ApiException {
      rethrow;
    } catch (e) {
      throw ApiException(
        'No connection to the API at $baseUrl. Check your network and try again. ($e)',
      );
    }
  }

  Map<String, dynamic> _decode(
    http.Response response, {
    bool throwOnFailure = true,
  }) {
    Map<String, dynamic> data;
    try {
      final decoded = jsonDecode(response.body);
      data = decoded is Map<String, dynamic>
          ? decoded
          : <String, dynamic>{'success': false, 'message': 'Unexpected response'};
    } catch (_) {
      throw ApiException(
        'Invalid server response (${response.statusCode})',
        statusCode: response.statusCode,
      );
    }

    if (throwOnFailure && response.statusCode == 401) {
      throw ApiException(
        '${data['message'] ?? 'Session expired. Please sign in again.'}',
        statusCode: 401,
        body: data,
      );
    }

    if (throwOnFailure &&
        (response.statusCode >= 400 || data['success'] == false)) {
      throw ApiException(
        '${data['message'] ?? 'Request failed'}',
        statusCode: response.statusCode,
        body: data,
      );
    }

    return data;
  }

  void close() => _client.close();
}
