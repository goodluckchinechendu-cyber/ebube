<?php
/**
 * Load KEY=VALUE pairs from ../private/.env into putenv/$_ENV.
 * Web requests cannot read that folder (private/.htaccess Deny).
 */

function ec_private_dir(): string
{
    static $dir = null;
    if ($dir !== null) {
        return $dir;
    }
    $candidates = [
        dirname(__DIR__) . '/private',
        dirname(__DIR__, 2) . '/private',
    ];
    foreach ($candidates as $candidate) {
        if (is_dir($candidate)) {
            $dir = $candidate;
            return $dir;
        }
    }
    $dir = dirname(__DIR__) . '/private';
    return $dir;
}

function ec_env(string $key, ?string $default = null): ?string
{
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') {
        return $default;
    }
    return (string) $v;
}

function ec_load_env_file(?string $path = null): void
{
    static $loaded = false;
    if ($loaded) {
        return;
    }
    $loaded = true;

    $path = $path ?? (ec_private_dir() . '/.env');
    if (!is_file($path) || !is_readable($path)) {
        return;
    }

    $lines = file($path, FILE_IGNORE_NEW_LINES);
    if ($lines === false) {
        return;
    }

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (!str_contains($line, '=')) {
            continue;
        }
        [$key, $value] = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        if ($key === '') {
            continue;
        }
        // Strip optional surrounding quotes.
        if (
            (str_starts_with($value, '"') && str_ends_with($value, '"'))
            || (str_starts_with($value, "'") && str_ends_with($value, "'"))
        ) {
            $value = substr($value, 1, -1);
        }
        // Do not override real process env.
        $existing = getenv($key);
        if ($existing !== false && $existing !== '') {
            $_ENV[$key] = $existing;
            continue;
        }
        putenv($key . '=' . $value);
        $_ENV[$key] = $value;
        $_SERVER[$key] = $value;
    }
}

ec_load_env_file();
