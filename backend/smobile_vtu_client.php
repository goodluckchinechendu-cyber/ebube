<?php
/**
 * HTTP client for https://smobileagent.com/api (Bearer API key).
 */

function smobile_vtu_load_config(): array
{
    static $cfg = null;
    if ($cfg !== null) {
        return $cfg;
    }

    require_once __DIR__ . '/env_loader.php';

    $baseUrl = rtrim((string) (ec_env('SMOBILE_VTU_BASE_URL', 'https://smobileagent.com/api') ?? 'https://smobileagent.com/api'), '/');
    $apiKey = trim((string) (ec_env('SMOBILE_VTU_API_KEY', '') ?? ''));
    $webhookSecret = trim((string) (ec_env('SMOBILE_VTU_WEBHOOK_SECRET', '') ?? ''));

    // Legacy fallback if private/.env is missing on an old host.
    $path = __DIR__ . '/config.smobile_vtu.php';
    if (($apiKey === '' || strtoupper($apiKey) === 'CHANGE_ME') && is_file($path)) {
        require $path;
        if (!empty($smobileVtuBaseUrl)) {
            $baseUrl = rtrim((string) $smobileVtuBaseUrl, '/');
        }
        if (isset($smobileVtuApiKey) && trim((string) $smobileVtuApiKey) !== '') {
            $apiKey = trim((string) $smobileVtuApiKey);
        }
        if (isset($smobileVtuWebhookSecret) && trim((string) $smobileVtuWebhookSecret) !== '') {
            $webhookSecret = trim((string) $smobileVtuWebhookSecret);
        }
    }

    $cfg = [
        'base_url' => $baseUrl,
        'api_key' => $apiKey,
        'webhook_secret' => $webhookSecret,
        'configured' => $apiKey !== '' && strtoupper($apiKey) !== 'CHANGE_ME',
        'webhook_configured' => $webhookSecret !== '' && strtoupper($webhookSecret) !== 'CHANGE_ME',
    ];
    return $cfg;
}

/** Normalize network to API names: MTN, GLO, Airtel, 9mobile (or 1–4). */
function smobile_vtu_normalize_network(mixed $network): string|int|null
{
    if ($network === null || $network === '') {
        return null;
    }
    if (is_int($network) || (is_string($network) && ctype_digit(trim($network)))) {
        $id = (int) $network;
        if ($id >= 1 && $id <= 4) {
            return $id;
        }
    }

    $name = strtoupper(trim((string) $network));
    $map = [
        'MTN' => 'MTN',
        '1' => 'MTN',
        'AIRTEL' => 'Airtel',
        '2' => 'Airtel',
        'GLO' => 'GLO',
        'GLOMOBILE' => 'GLO',
        '3' => 'GLO',
        '9MOBILE' => '9mobile',
        '9 MOBILE' => '9mobile',
        'ETISALAT' => '9mobile',
        '4' => '9mobile',
    ];
    $compact = str_replace(' ', '', $name);
    if (isset($map[$name])) {
        return $map[$name];
    }
    if (isset($map[$compact])) {
        return $map[$compact];
    }
    return is_string($network) ? trim($network) : $network;
}

/**
 * @return array{http_code:int, body:array, raw:string}
 */
function smobile_vtu_request(string $method, string $path, ?array $jsonBody = null, array $query = []): array
{
    if (!function_exists('curl_init')) {
        return [
            'http_code' => 500,
            'body' => [
                'response_code' => 500,
                'success' => false,
                'message' => 'PHP cURL extension is required for VTU purchases',
            ],
            'raw' => '',
        ];
    }

    $cfg = smobile_vtu_load_config();
    if (!$cfg['configured']) {
        return [
            'http_code' => 503,
            'body' => [
                'response_code' => 503,
                'success' => false,
                'message' => 'SMobile VTU API key not configured. Set backend/config.smobile_vtu.php',
            ],
            'raw' => '',
        ];
    }

    $url = rtrim($cfg['base_url'], '/') . '/' . ltrim($path, '/');
    if ($query !== []) {
        $url .= (str_contains($url, '?') ? '&' : '?') . http_build_query($query);
    }

    $headers = [
        'Authorization: Bearer ' . $cfg['api_key'],
        'Accept: application/json',
        'Content-Type: application/json',
    ];

    $ch = curl_init($url);
    if ($ch === false) {
        return [
            'http_code' => 500,
            'body' => [
                'response_code' => 500,
                'success' => false,
                'message' => 'Could not start VTU request',
            ],
            'raw' => '',
        ];
    }

    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CUSTOMREQUEST => strtoupper($method),
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_TIMEOUT => 60,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
    ];

    if ($jsonBody !== null) {
        $opts[CURLOPT_POSTFIELDS] = json_encode($jsonBody, JSON_UNESCAPED_SLASHES);
    }

    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $error = curl_error($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0) {
        return [
            'http_code' => 502,
            'body' => [
                'response_code' => 502,
                'success' => false,
                'message' => 'VTU provider unreachable: ' . $error,
            ],
            'raw' => '',
        ];
    }

    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) {
        $decoded = [
            'response_code' => $httpCode ?: 502,
            'success' => false,
            'message' => 'Invalid JSON from VTU provider',
            'raw_body' => substr((string) $raw, 0, 500),
        ];
    }

    if (!isset($decoded['response_code'])) {
        $decoded['response_code'] = $httpCode;
    }
    if (!isset($decoded['message']) || trim((string) $decoded['message']) === '') {
        if (!empty($decoded['error'])) {
            $decoded['message'] = (string) $decoded['error'];
        } elseif (($decoded['success'] ?? null) === false) {
            $decoded['message'] = 'VTU request failed';
        }
    }

    return [
        'http_code' => $httpCode > 0 ? $httpCode : (int) ($decoded['response_code'] ?? 502),
        'body' => $decoded,
        'raw' => (string) $raw,
    ];
}

function smobile_vtu_respond(array $result): void
{
    $code = (int) ($result['http_code'] ?? 500);
    if ($code < 100 || $code > 599) {
        $code = 500;
    }
    if (!headers_sent()) {
        header('Content-Type: application/json; charset=UTF-8');
    }
    http_response_code($code);
    echo json_encode($result['body'], JSON_UNESCAPED_SLASHES);
}
