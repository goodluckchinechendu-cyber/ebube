<?php

if (!function_exists('random_bytes')) {
    function random_bytes(int $length): string
    {
        if ($length < 1) {
            throw new Exception('Length must be at least 1');
        }
        if (function_exists('openssl_random_pseudo_bytes')) {
            return openssl_random_pseudo_bytes($length);
        }
        $bytes = '';
        while (strlen($bytes) < $length) {
            $bytes .= chr(mt_rand(0, 255));
        }
        return substr($bytes, 0, $length);
    }
}

if (!function_exists('random_int')) {
    function random_int(int $min, int $max): int
    {
        return $min + mt_rand(0, $max - $min);
    }
}

function api_json_fatal_handler(): void
{
  $error = error_get_last();
  if ($error === null) {
    return;
  }
  $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
  if (!in_array($error['type'], $fatalTypes, true)) {
    return;
  }
  if (!headers_sent()) {
    header('Content-Type: application/json; charset=UTF-8');
    http_response_code(500);
  }
  echo json_encode([
    'success' => false,
    'message' => 'Server error: ' . $error['message'],
    'file' => basename((string) $error['file']),
    'line' => (int) $error['line'],
  ]);
}

register_shutdown_function('api_json_fatal_handler');
