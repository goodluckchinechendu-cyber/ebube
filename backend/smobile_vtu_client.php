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

/**
 * Pull status from common SMobile / nested response shapes.
 */
function smobile_vtu_extract_status(array $body): string
{
    $candidates = [
        $body['status'] ?? null,
        $body['transaction_status'] ?? null,
        $body['txn_status'] ?? null,
        is_array($body['data'] ?? null) ? ($body['data']['status'] ?? null) : null,
        is_array($body['data'] ?? null) ? ($body['data']['transaction_status'] ?? null) : null,
        is_array($body['transaction'] ?? null) ? ($body['transaction']['status'] ?? null) : null,
        is_array($body['result'] ?? null) ? ($body['result']['status'] ?? null) : null,
    ];
    foreach ($candidates as $c) {
        if ($c === null || $c === '') {
            continue;
        }
        return strtolower(trim((string) $c));
    }
    return '';
}

/**
 * Classify SMobile status into success | processing | failed.
 * Unknown / empty with a live provider reference must NOT become failed —
 * SMobile is the source of truth and may still be processing.
 *
 * @return 'success'|'processing'|'failed'
 */
function smobile_vtu_classify_status(
    string $status,
    ?bool $successFlag = null,
    ?int $httpCode = null,
    bool $hasReference = false
): string {
    $s = strtolower(trim($status));
    $s = str_replace([' ', '-'], '_', $s);

    $success = [
        'success', 'successful', 'completed', 'complete', 'ok', 'done', 'delivered', 'fulfilled',
    ];
    $processing = [
        'processing', 'pending', 'queued', 'queue', 'in_progress', 'inprogress', 'progress',
        'awaiting', 'initiated', 'submitted', 'accepted', 'received', 'ongoing', 'waiting',
        'started', 'running', 'open', 'active', 'process',
    ];
    $failed = [
        'failed', 'failure', 'fail', 'reversed', 'cancelled', 'canceled', 'error', 'errored',
        'declined', 'rejected', 'timeout', 'timed_out', 'expired', 'abort', 'aborted',
    ];

    if (in_array($s, $success, true)) {
        return 'success';
    }
    if (in_array($s, $failed, true)) {
        return 'failed';
    }
    if (in_array($s, $processing, true)) {
        return 'processing';
    }

    if ($s === '') {
        if ($successFlag === true) {
            return 'success';
        }
        if ($httpCode === 202 || $hasReference) {
            return 'processing';
        }
        if ($successFlag === false) {
            return 'failed';
        }
        return $hasReference ? 'processing' : 'failed';
    }

    // Non-empty but unrecognized: keep as processing so we do not contradict SMobile.
    return 'processing';
}

/**
 * @return array{raw:string, class:string, success:bool, processing:bool, failed:bool}
 */
function smobile_vtu_status_info(array $body, ?int $httpCode = null, bool $hasReference = false): array
{
    $raw = smobile_vtu_extract_status($body);
    $flag = array_key_exists('success', $body) ? (bool) $body['success'] : null;
    if (!$hasReference) {
        $ref = trim((string) ($body['reference'] ?? ''));
        if ($ref === '' && is_array($body['data'] ?? null)) {
            $ref = trim((string) ($body['data']['reference'] ?? ''));
        }
        $hasReference = $ref !== '';
    }
    $class = smobile_vtu_classify_status($raw, $flag, $httpCode, $hasReference);
    return [
        'raw' => $raw,
        'class' => $class,
        'success' => $class === 'success',
        'processing' => $class === 'processing',
        'failed' => $class === 'failed',
    ];
}

/**
 * Flatten SMobile plans payload into a list of plan maps.
 * Handles flat `plan_list`, list `plans`, and network-keyed object `plans`.
 *
 * @return list<array<string,mixed>>
 */
function smobile_vtu_flatten_plans(array $body, ?string $network = null, ?string $networkId = null): array
{
    $out = [];

    $appendList = static function ($raw) use (&$out): void {
        if (!is_array($raw)) {
            return;
        }
        // List of plans
        $isList = array_is_list($raw) || (isset($raw[0]) && is_array($raw[0]));
        if ($isList) {
            foreach ($raw as $plan) {
                if (is_array($plan)) {
                    $out[] = $plan;
                }
            }
            return;
        }
        // Network-keyed: {"1":[...], "2":[...]}
        foreach ($raw as $plans) {
            if (!is_array($plans)) {
                continue;
            }
            foreach ($plans as $plan) {
                if (is_array($plan)) {
                    $out[] = $plan;
                }
            }
        }
    };

    if (isset($body['plan_list']) && is_array($body['plan_list'])) {
        $appendList($body['plan_list']);
    } elseif (isset($body['plans']) && is_array($body['plans'])) {
        $appendList($body['plans']);
    } elseif (isset($body['data']) && is_array($body['data'])) {
        $data = $body['data'];
        if (isset($data['plan_list']) && is_array($data['plan_list'])) {
            $appendList($data['plan_list']);
        } elseif (isset($data['plans']) && is_array($data['plans'])) {
            $appendList($data['plans']);
        } else {
            $appendList($data);
        }
    }

    // Normalize id field so plan_id is always present.
    foreach ($out as $i => $plan) {
        if (!isset($plan['plan_id']) || trim((string) $plan['plan_id']) === '') {
            $id = $plan['id'] ?? $plan['planId'] ?? null;
            if ($id !== null && trim((string) $id) !== '') {
                $out[$i]['plan_id'] = (string) $id;
            }
        }
    }

    $wantId = null;
    if ($networkId !== null && $networkId !== '') {
        $wantId = (string) $networkId;
    } elseif ($network !== null && $network !== '') {
        $normalized = smobile_vtu_normalize_network($network);
        if (is_int($normalized)) {
            $wantId = (string) $normalized;
        } elseif (is_string($normalized)) {
            $map = ['MTN' => '1', 'Airtel' => '2', 'GLO' => '3', '9mobile' => '4'];
            $wantId = $map[$normalized] ?? null;
        }
    }

    if ($wantId === null || $out === []) {
        return array_values($out);
    }

    $filtered = [];
    foreach ($out as $plan) {
        $pid = trim((string) ($plan['network_id'] ?? $plan['networkId'] ?? $plan['network'] ?? ''));
        if ($pid === '') {
            // Plans from network-keyed maps often omit network_id; keep them when
            // the upstream request already scoped by network.
            $filtered[] = $plan;
            continue;
        }
        if (ctype_digit($pid)) {
            if ($pid === $wantId) {
                $filtered[] = $plan;
            }
            continue;
        }
        $norm = smobile_vtu_normalize_network($pid);
        $map = ['MTN' => '1', 'Airtel' => '2', 'GLO' => '3', '9mobile' => '4'];
        $asId = '';
        if (is_int($norm)) {
            $asId = (string) $norm;
        } elseif (is_string($norm) && isset($map[$norm])) {
            $asId = $map[$norm];
        }
        if ($asId === $wantId) {
            $filtered[] = $plan;
        }
    }

    return $filtered !== [] ? array_values($filtered) : array_values($out);
}
