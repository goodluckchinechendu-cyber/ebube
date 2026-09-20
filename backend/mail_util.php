<?php

/** Last SMTP server reply (for api_check / debugging). */
$appMailLastSmtpReply = '';

function app_mail_set_smtp_reply(string $reply): void
{
    global $appMailLastSmtpReply;
    $appMailLastSmtpReply = trim($reply);
}

function app_mail_last_smtp_reply(): string
{
    global $appMailLastSmtpReply;
    return $appMailLastSmtpReply;
}

function app_mail_settings(): array
{
    global $mailSmtpHost, $mailSmtpPort, $mailSmtpUser, $mailSmtpPass, $mailSmtpSecure;
    global $mailFromEmail, $mailFromName;

    return [
        'smtp_host' => trim((string) ($mailSmtpHost ?? '')),
        'smtp_port' => (int) ($mailSmtpPort ?? 587),
        'smtp_user' => trim((string) ($mailSmtpUser ?? '')),
        'smtp_pass' => (string) ($mailSmtpPass ?? ''),
        'smtp_secure' => strtolower(trim((string) ($mailSmtpSecure ?? 'tls'))),
        'from_email' => trim((string) ($mailFromEmail ?? '')),
        'from_name' => trim((string) ($mailFromName ?? 'SMobile')),
    ];
}

function app_mail_from_address(): string
{
    $settings = app_mail_settings();
    if ($settings['from_email'] !== '') {
        return strtolower($settings['from_email']);
    }

    $host = $_SERVER['HTTP_HOST'] ?? 'smobileinternetsolutions.com';
    $host = preg_replace('/:\d+$/', '', (string) $host);
    $host = preg_replace('/^www\./i', '', $host);
    if (!preg_match('/^[a-z0-9.-]+\.[a-z]{2,}$/i', $host)) {
        $host = 'smobileinternetsolutions.com';
    }
    return 'no-reply@' . strtolower($host);
}

function app_mail_from_name(): string
{
    $settings = app_mail_settings();
    return $settings['from_name'] !== '' ? $settings['from_name'] : 'SMobile';
}

/** Display name for OTP, PIN reset, and other app emails (from config.mail.php per domain). */
function app_brand_display_name(): string
{
    return app_mail_from_name();
}

function smtp_is_configured(array $settings): bool
{
    return $settings['smtp_host'] !== '' && $settings['smtp_user'] !== '';
}

/**
 * Resend API key detection (SMobile pattern).
 * Key may live in MAIL_SMTP_PASS or MAIL_SMTP_USER when it starts with re_.
 */
function app_mail_resend_api_key(array $settings): string
{
    $pass = trim((string) ($settings['smtp_pass'] ?? ''));
    $user = trim((string) ($settings['smtp_user'] ?? ''));
    if (str_starts_with($pass, 're_')) {
        return $pass;
    }
    if (str_starts_with($user, 're_')) {
        return $user;
    }
    $envKey = trim((string) (function_exists('ec_env') ? (ec_env('RESEND_API_KEY', '') ?? '') : (getenv('RESEND_API_KEY') ?: '')));
    if (str_starts_with($envKey, 're_')) {
        return $envKey;
    }
    return '';
}

function app_mail_uses_resend(array $settings): bool
{
    return app_mail_resend_api_key($settings) !== '';
}

/**
 * Send via Resend HTTP API (preferred on Railway — same approach as SMobile).
 *
 * @param list<array{filename:string,content:string,content_type?:string}> $attachments
 */
function send_resend_api_email(
    string $toEmail,
    string $subject,
    string $htmlBody,
    string $textBody = '',
    array $attachments = []
): bool {
    $settings = app_mail_settings();
    $apiKey = app_mail_resend_api_key($settings);
    if ($apiKey === '') {
        error_log('[mail] Resend API key missing (set MAIL_SMTP_PASS or RESEND_API_KEY to re_…)');
        return false;
    }

    $fromEmail = app_mail_from_address();
    $fromName = app_mail_from_name();
    $formattedFrom = sprintf('%s <%s>', $fromName, $fromEmail);

    if ($textBody === '') {
        $textBody = trim(html_entity_decode(strip_tags(str_replace(
            ['<br>', '<br/>', '<br />', '</p>', '</tr>', '</div>'],
            ["\n", "\n", "\n", "\n\n", "\n", "\n"],
            $htmlBody
        )), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($textBody === '') {
            $textBody = $subject;
        }
    }

    $payload = [
        'from' => $formattedFrom,
        'to' => [$toEmail],
        'subject' => $subject,
        'html' => $htmlBody,
        'text' => $textBody,
    ];

    if ($attachments !== []) {
        $resendAtt = [];
        foreach ($attachments as $att) {
            $filename = (string) ($att['filename'] ?? 'attachment.bin');
            $content = (string) ($att['content'] ?? '');
            $resendAtt[] = [
                'filename' => $filename,
                'content' => base64_encode($content),
            ];
        }
        $payload['attachments'] = $resendAtt;
    }

    if (!function_exists('curl_init')) {
        error_log('[mail] curl extension required for Resend API');
        return false;
    }

    $ch = curl_init('https://api.resend.com/emails');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload),
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($httpCode >= 200 && $httpCode < 300) {
        app_mail_set_smtp_reply('Resend API HTTP ' . $httpCode);
        error_log('[mail] Resend API success (' . $httpCode . '): ' . (string) $response);
        return true;
    }

    app_mail_set_smtp_reply('Resend API HTTP ' . $httpCode . ' ' . (string) $response);
    error_log(sprintf(
        '[mail] Resend API failed (HTTP %d): %s | cURL: %s',
        $httpCode,
        (string) $response,
        $curlError
    ));
    return false;
}

function smtp_read_line($socket): string
{
    $data = '';
    while (!feof($socket)) {
        $line = fgets($socket, 515);
        if ($line === false) {
            break;
        }
        $data .= $line;
        if (isset($line[3]) && $line[3] === ' ') {
            break;
        }
    }
    return $data;
}

function smtp_expect_ok($socket, array $codes): bool
{
    $reply = smtp_read_line($socket);
    if ($reply !== '') {
        app_mail_set_smtp_reply($reply);
    }
    if ($reply === '') {
        return false;
    }
    $code = (int) substr($reply, 0, 3);
    return in_array($code, $codes, true);
}

function smtp_cmd($socket, string $command, array $okCodes): bool
{
    fwrite($socket, $command . "\r\n");
    return smtp_expect_ok($socket, $okCodes);
}

/**
 * Normalize body for SMTP DATA (CRLF + RFC5321 dot-stuffing).
 */
function smtp_prepare_data_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = str_replace("\n", "\r\n", $body);
    // Dot-stuff lines that begin with '.'
    $body = preg_replace('/^\./m', '..', $body) ?? $body;
    return $body;
}

/**
 * @param list<array{filename:string,content:string,content_type?:string}> $attachments
 */
function smtp_build_mime_message(
    string $toEmail,
    string $subject,
    string $htmlBody,
    string $textBody = '',
    array $attachments = []
): string {
    $fromEmail = app_mail_from_address();
    $fromName = app_mail_from_name();
    $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';
    $encodedFromName = '=?UTF-8?B?' . base64_encode($fromName) . '?=';
    $messageId = sprintf(
        '<%s.%s@%s>',
        bin2hex(random_bytes(8)),
        time(),
        preg_replace('/^.*@/', '', $fromEmail) ?: 'ebubeconnect.com'
    );
    $date = date('r');

    if ($textBody === '') {
        $textBody = trim(html_entity_decode(strip_tags(str_replace(
            ['<br>', '<br/>', '<br />', '</p>', '</tr>', '</div>'],
            ["\n", "\n", "\n", "\n\n", "\n", "\n"],
            $htmlBody
        )), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($textBody === '') {
            $textBody = $subject;
        }
    }

    $headers = [
        'MIME-Version: 1.0',
        'Date: ' . $date,
        'Message-ID: ' . $messageId,
        'From: ' . $encodedFromName . ' <' . $fromEmail . '>',
        'Reply-To: ' . $fromEmail,
        'To: <' . $toEmail . '>',
        'Subject: ' . $encodedSubject,
        'X-Mailer: EbubeConnect',
        'X-Priority: 3',
    ];

    // multipart/alternative only
    if ($attachments === []) {
        $altBoundary = 'b_alt_' . bin2hex(random_bytes(8));
        $headers[] = 'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"';
        $parts = [
            '--' . $altBoundary,
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($textBody)),
            '--' . $altBoundary,
            'Content-Type: text/html; charset=UTF-8',
            'Content-Transfer-Encoding: base64',
            '',
            chunk_split(base64_encode($htmlBody)),
            '--' . $altBoundary . '--',
            '',
        ];
        return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $parts);
    }

    // multipart/mixed with alternative + attachments (APP statement pattern)
    $mixedBoundary = 'b_mix_' . bin2hex(random_bytes(8));
    $altBoundary = 'b_alt_' . bin2hex(random_bytes(8));
    $headers[] = 'Content-Type: multipart/mixed; boundary="' . $mixedBoundary . '"';

    $body = [
        '--' . $mixedBoundary,
        'Content-Type: multipart/alternative; boundary="' . $altBoundary . '"',
        '',
        '--' . $altBoundary,
        'Content-Type: text/plain; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($textBody)),
        '--' . $altBoundary,
        'Content-Type: text/html; charset=UTF-8',
        'Content-Transfer-Encoding: base64',
        '',
        chunk_split(base64_encode($htmlBody)),
        '--' . $altBoundary . '--',
        '',
    ];

    foreach ($attachments as $att) {
        $filename = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) ($att['filename'] ?? 'attachment.html')) ?: 'attachment.html';
        $contentType = (string) ($att['content_type'] ?? 'application/octet-stream');
        $content = (string) ($att['content'] ?? '');
        $body[] = '--' . $mixedBoundary;
        $body[] = 'Content-Type: ' . $contentType . '; name="' . $filename . '"';
        $body[] = 'Content-Transfer-Encoding: base64';
        $body[] = 'Content-Disposition: attachment; filename="' . $filename . '"';
        $body[] = '';
        $body[] = chunk_split(base64_encode($content));
    }
    $body[] = '--' . $mixedBoundary . '--';
    $body[] = '';

    return implode("\r\n", $headers) . "\r\n\r\n" . implode("\r\n", $body);
}

/**
 * @param list<array{filename:string,content:string,content_type?:string}> $attachments
 */
function smtp_send_html_email(
    string $toEmail,
    string $subject,
    string $htmlBody,
    array $settings,
    string $textBody = '',
    array $attachments = []
): bool {
    $host = $settings['smtp_host'];
    $port = (int) $settings['smtp_port'];
    if ($port <= 0) {
        $port = 587;
    }
    $secure = $settings['smtp_secure'];
    $user = $settings['smtp_user'];
    $pass = $settings['smtp_pass'];
    $fromEmail = app_mail_from_address();

    $remoteHost = $host;
    if ($secure === 'ssl') {
        $remoteHost = 'ssl://' . $host;
    }

    $socket = @stream_socket_client(
        $remoteHost . ':' . $port,
        $errno,
        $errstr,
        30,
        STREAM_CLIENT_CONNECT
    );
    if (!$socket) {
        error_log("SMTP connect failed: $errstr ($errno)");
        return false;
    }

    stream_set_timeout($socket, 30);

    if (!smtp_expect_ok($socket, [220])) {
        fclose($socket);
        return false;
    }

    $serverName = $_SERVER['SERVER_NAME'] ?? $host;
    if (!smtp_cmd($socket, 'EHLO ' . $serverName, [250])) {
        fclose($socket);
        return false;
    }

    if ($secure === 'tls') {
        if (!smtp_cmd($socket, 'STARTTLS', [220])) {
            fclose($socket);
            return false;
        }
        if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
            fclose($socket);
            return false;
        }
        if (!smtp_cmd($socket, 'EHLO ' . $serverName, [250])) {
            fclose($socket);
            return false;
        }
    }

    if ($user !== '') {
        if (!smtp_cmd($socket, 'AUTH LOGIN', [334])) {
            fclose($socket);
            return false;
        }
        if (!smtp_cmd($socket, base64_encode($user), [334])) {
            fclose($socket);
            return false;
        }
        if (!smtp_cmd($socket, base64_encode($pass), [235])) {
            fclose($socket);
            return false;
        }
    }

    if (!smtp_cmd($socket, 'MAIL FROM:<' . $fromEmail . '>', [250])) {
        fclose($socket);
        return false;
    }
    if (!smtp_cmd($socket, 'RCPT TO:<' . $toEmail . '>', [250, 251])) {
        fclose($socket);
        return false;
    }
    if (!smtp_cmd($socket, 'DATA', [354])) {
        fclose($socket);
        return false;
    }

    $raw = smtp_build_mime_message($toEmail, $subject, $htmlBody, $textBody, $attachments);
    $payload = smtp_prepare_data_body($raw) . "\r\n.";
    fwrite($socket, $payload . "\r\n");
    if (!smtp_expect_ok($socket, [250])) {
        fclose($socket);
        return false;
    }

    smtp_cmd($socket, 'QUIT', [221]);
    fclose($socket);
    return true;
}

function php_mail_send_html(string $toEmail, string $subject, string $htmlBody): bool
{
    if (!function_exists('mail')) {
        return false;
    }

    $from = app_mail_from_address();
    $fromName = app_mail_from_name();
    $headers = implode("\r\n", [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=UTF-8',
        'From: ' . $fromName . ' <' . $from . '>',
        'Reply-To: ' . $from,
    ]);

    return @mail($toEmail, $subject, $htmlBody, $headers);
}

/**
 * @param list<array{filename:string,content:string,content_type?:string}> $attachments
 */
function send_app_html_email(
    string $toEmail,
    string $subject,
    string $htmlBody,
    string $textBody = '',
    array $attachments = []
): bool {
    if (function_exists('apply_mail_brand_from_request')) {
        apply_mail_brand_from_request();
    }
    $toEmail = trim($toEmail);
    if ($toEmail === '' || !filter_var($toEmail, FILTER_VALIDATE_EMAIL)) {
        return false;
    }

    $settings = app_mail_settings();

    // Prefer Resend HTTP API on Railway (SMobile pattern) when key is re_…
    if (app_mail_uses_resend($settings)) {
        if (send_resend_api_email($toEmail, $subject, $htmlBody, $textBody, $attachments)) {
            return true;
        }
        error_log(
            'Resend send failed for ' . $toEmail
            . ' — reply: ' . app_mail_last_smtp_reply()
        );
        // Do not fall through to SMTP when Resend is configured.
        return false;
    }

    if (smtp_is_configured($settings)) {
        if (smtp_send_html_email($toEmail, $subject, $htmlBody, $settings, $textBody, $attachments)) {
            return true;
        }
        error_log(
            'SMTP send failed for ' . $toEmail
            . ' via ' . $settings['smtp_host']
            . ' as ' . $settings['smtp_user']
            . ' — reply: ' . app_mail_last_smtp_reply()
        );
    } elseif ($settings['smtp_host'] === '' || $settings['smtp_user'] === '') {
        error_log('Email not sent: SMTP host or user missing (or set RESEND_API_KEY / MAIL_SMTP_PASS=re_…)');
    }

    // Attachments require SMTP/Resend path; fall back to HTML-only via mail().
    if (function_exists('mail')) {
        return php_mail_send_html($toEmail, $subject, $htmlBody);
    }

    error_log(
        'Email not sent: configure Resend (MAIL_SMTP_PASS=re_…) or SMTP settings.'
    );
    return false;
}

function app_mail_delivery_status(): array
{
    $settings = app_mail_settings();
    $status = [
        'php_mail_available' => function_exists('mail'),
        'smtp_configured' => smtp_is_configured($settings),
        'resend_configured' => app_mail_uses_resend($settings),
        'mail_driver' => app_mail_uses_resend($settings) ? 'resend' : (smtp_is_configured($settings) ? 'smtp' : 'none'),
        'from_email' => app_mail_from_address(),
        'from_name' => app_mail_from_name(),
    ];
    if (function_exists('resolve_mail_request_host')) {
        $status['request_host'] = resolve_mail_request_host();
        $status['brand_slug_header'] = trim((string) ($_SERVER['HTTP_X_SMOBILE_BRAND_SLUG'] ?? ''));
        $status['mail_brand_source'] = apply_mail_brand_from_request();
        $brand = mail_brand_for_request_host();
        if ($brand !== null) {
            $status['mail_brand_from_name'] = $brand['from_name'] ?? '';
        }
        $status['from_name'] = app_mail_from_name();
        $status['smtp_host'] = $settings['smtp_host'];
        $status['smtp_user'] = $settings['smtp_user'];
        $status['smtp_pass_set'] = $settings['smtp_pass'] !== '';
        $status['credentials_file_loaded'] = is_file(
            __DIR__ . '/credentials/config.mail.' . ($status['request_host'] ?: 'none') . '.php'
        );
        $status['last_smtp_reply'] = app_mail_last_smtp_reply();
    }
    return $status;
}
