<?php

function normalize_phone_digits(string $phone): string
{
    return preg_replace('/\D/', '', $phone);
}

function is_valid_pin(string $password): bool
{
    return (bool) preg_match('/^\d{4}$/', $password);
}

function pin_validation_message(): string
{
    return 'PIN must be exactly 4 digits';
}

function normalize_phone_for_storage(string $phone): string
{
    return normalize_phone_digits($phone);
}

function normalize_email(string $email): string
{
    return strtolower(trim($email));
}

function is_email_login(string $login): bool
{
    return str_contains($login, '@');
}

function allowed_payout_banks(): array
{
    return [
        'Access Bank',
        'Zenith Bank',
        'GTBank',
        'UBA',
        'Fidelity',
        'First Bank',
    ];
}

function validate_payout_details(
    string $accountName,
    string $bankName,
    string $accountNumber
): ?string {
    if (trim($accountName) === '') {
        return 'Account name is required';
    }
    if (!in_array($bankName, allowed_payout_banks(), true)) {
        return 'Please select a valid bank';
    }
    $digits = preg_replace('/\D/', '', $accountNumber);
    if (strlen($digits) !== 10) {
        return 'Account number must be 10 digits';
    }
    return null;
}

function user_payout_from_row(array $row): array
{
    return [
        'account_name' => (string) ($row['account_name'] ?? ''),
        'bank_name' => (string) ($row['bank_name'] ?? ''),
        'account_number' => (string) ($row['account_number'] ?? ''),
    ];
}

/**
 * Public user payload for login / session responses (no password hashes).
 */
function login_user_payload(array $user): array
{
    $hasTxnPin = trim((string) ($user['transaction_pin_hash'] ?? '')) !== '';
    $verified = !empty($user['email_verified_at'])
        && trim((string) $user['email_verified_at']) !== ''
        && trim((string) $user['email_verified_at']) !== '0000-00-00 00:00:00';

    $payload = [
        'id' => (int) $user['id'],
        'full_name' => $user['full_name'],
        'phone' => $user['phone'] ?? '',
        'location' => $user['location'] ?? '',
        'gender' => $user['gender'] ?? '',
        'email' => $user['email'],
        'role' => (int) $user['role'],
        'email_verified' => $verified,
        'has_transaction_pin' => $hasTxnPin,
        'momo_balance' => (float) ($user['momo_balance'] ?? 0),
        'vtu_balance' => (float) ($user['vtu_balance'] ?? 0),
        'logical_balance' => (float) ($user['logical_balance'] ?? 0),
        'data_bundle_balance' => (float) ($user['data_bundle_balance'] ?? 0),
        'commission_balance' => (float) ($user['commission_balance'] ?? 0),
        'last_seen_announcement_id' => (int) ($user['last_seen_announcement_id'] ?? 0),
    ];

    $role = (int) ($user['role'] ?? 0);
    $isExternal = (int) ($user['is_external'] ?? 0) === 1;
    $walletId = trim((string) ($user['wallet_id'] ?? ''));

    // Super Admin may see visibility flags. Other roles never receive is_external /
    // internal labels — only a Wallet ID when one is assigned to their own account.
    if ($role >= 3) {
        $payload['is_external'] = $isExternal;
        $payload['wallet_id'] = $walletId;
    } elseif ($walletId !== '') {
        $payload['wallet_id'] = $walletId;
    }

    // Admin UI: whether this Admin may promote others to Admin.
    if ($role >= 3) {
        $payload['can_assign_admin'] = true;
    } elseif ($role === 2) {
        if (array_key_exists('can_assign_admin', $user)) {
            $payload['can_assign_admin'] = (bool) $user['can_assign_admin'];
        } elseif (array_key_exists('can_create_admins', $user) && $user['can_create_admins'] !== null) {
            $payload['can_assign_admin'] = (int) $user['can_create_admins'] === 1;
        } else {
            // Legacy row without flag: allow (server still re-checks on assign).
            $payload['can_assign_admin'] = true;
        }
    }

    return $payload + user_payout_from_row($user);
}
