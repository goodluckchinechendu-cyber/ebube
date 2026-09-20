<?php

/** @var array<string, array<string, string>>|null */
$mailBrandHosts = null;

function mail_brand_hosts(): array
{
    global $mailBrandHosts;
    if ($mailBrandHosts === null) {
        $path = __DIR__ . '/config.mail.brands.php';
        $mailBrandHosts = is_file($path) ? require $path : [];
    }
    return $mailBrandHosts;
}

function normalize_mail_request_host(?string $host): string
{
    $host = strtolower(trim((string) $host));
    if ($host === '') {
        return '';
    }
    $host = preg_replace('/:\d+$/', '', $host) ?? $host;
    if (str_starts_with($host, 'www.')) {
        $host = substr($host, 4);
    }
    return $host;
}

/** Host header may be wrong behind proxies; try several sources. */
function resolve_mail_request_host(): string
{
    $candidates = [
        $_SERVER['HTTP_X_FORWARDED_HOST'] ?? '',
        $_SERVER['HTTP_HOST'] ?? '',
        $_SERVER['SERVER_NAME'] ?? '',
    ];
    foreach ($candidates as $raw) {
        foreach (explode(',', (string) $raw) as $part) {
            $host = normalize_mail_request_host($part);
            if ($host === '' || $host === 'localhost' || $host === '127.0.0.1') {
                continue;
            }
            if (isset(mail_brand_hosts()[$host])) {
                return $host;
            }
        }
    }
    foreach ($candidates as $raw) {
        foreach (explode(',', (string) $raw) as $part) {
            $host = normalize_mail_request_host($part);
            if ($host !== '' && $host !== 'localhost' && $host !== '127.0.0.1') {
                return $host;
            }
        }
    }
    return '';
}

function mail_brand_for_slug(string $slug): ?array
{
    $slug = strtolower(trim($slug));
    if ($slug === '') {
        return null;
    }
    foreach (mail_brand_hosts() as $brand) {
        if (($brand['slug'] ?? '') === $slug) {
            return $brand;
        }
    }
    return null;
}

function apply_mail_brand_settings(array $brand): void
{
    global $mailFromName, $mailFromEmail;

    // Display name / From address only — SMTP host/user/pass come from config.mail.php
    // or api/credentials/config.mail.<domain>.php (must match the mailbox password).
    if (!empty($brand['from_name'])) {
        $mailFromName = $brand['from_name'];
    }
    if (!empty($brand['from_email'])) {
        $mailFromEmail = $brand['from_email'];
    }
}

/**
 * Optional per-domain SMTP credentials (not in git).
 * File: api/credentials/config.mail.smobileagent.com.php
 */
function apply_mail_credentials_for_request(): bool
{
    global $mailSmtpHost, $mailSmtpPort, $mailSmtpUser, $mailSmtpPass, $mailSmtpSecure;
    global $mailFromEmail, $mailFromName;

    $slug = trim((string) ($_SERVER['HTTP_X_SMOBILE_BRAND_SLUG'] ?? ''));
    $paths = [];
    if ($slug !== '') {
        $paths[] = __DIR__ . '/credentials/config.mail.' . $slug . '.php';
    }
    $host = resolve_mail_request_host();
    if ($host !== '') {
        $paths[] = __DIR__ . '/credentials/config.mail.' . $host . '.php';
    }

    foreach ($paths as $path) {
        if (is_file($path)) {
            require $path;
            return true;
        }
    }

    return false;
}

/**
 * cPanel often rejects MAIL FROM if it does not match the authenticated mailbox.
 */
function mail_align_from_address_with_smtp(): void
{
    global $mailFromEmail, $mailSmtpUser;

    if (!filter_var((string) $mailSmtpUser, FILTER_VALIDATE_EMAIL)) {
        return;
    }

    $smtpUser = strtolower((string) $mailSmtpUser);
    $from = strtolower(trim((string) $mailFromEmail));

    $smtpDomain = substr($smtpUser, (int) strpos($smtpUser, '@') + 1);
    $fromDomain = str_contains($from, '@')
        ? substr($from, (int) strpos($from, '@') + 1)
        : '';

    if ($from === '' || $fromDomain !== $smtpDomain) {
        $mailFromEmail = $smtpUser;
    }
}

function apply_mail_brand_for_host(string $host): bool
{
    $host = normalize_mail_request_host($host);
    $brands = mail_brand_hosts();
    if ($host === '' || !isset($brands[$host])) {
        return false;
    }
    apply_mail_brand_settings($brands[$host]);
    return true;
}

function apply_mail_brand_for_slug(string $slug): bool
{
    $brand = mail_brand_for_slug($slug);
    if ($brand === null) {
        return false;
    }
    apply_mail_brand_settings($brand);
    return true;
}

/**
 * Prefer brand slug sent by the app (reliable when all sites share one API folder).
 * Falls back to HTTP host.
 */
function apply_mail_brand_from_request(): ?string
{
    $slug = trim((string) (
        $_SERVER['HTTP_X_SMOBILE_BRAND_SLUG']
        ?? $_SERVER['REDIRECT_HTTP_X_SMOBILE_BRAND_SLUG']
        ?? ''
    ));
    if ($slug !== '' && apply_mail_brand_for_slug($slug)) {
        return 'slug:' . $slug;
    }

    $host = resolve_mail_request_host();
    if ($host !== '' && apply_mail_brand_for_host($host)) {
        return 'host:' . $host;
    }

    return null;
}

/** @deprecated use apply_mail_brand_from_request */
function apply_mail_brand_for_request_host(): ?string
{
    return apply_mail_brand_from_request();
}

function mail_brand_for_request_host(): ?array
{
    $slug = trim((string) ($_SERVER['HTTP_X_SMOBILE_BRAND_SLUG'] ?? ''));
    if ($slug !== '') {
        $brand = mail_brand_for_slug($slug);
        if ($brand !== null) {
            return $brand;
        }
    }
    $host = resolve_mail_request_host();
    if ($host === '') {
        return null;
    }
    $brands = mail_brand_hosts();
    return $brands[$host] ?? null;
}
