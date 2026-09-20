<?php
/**
 * Legacy stub — secrets live in ../private/.env (SMOBILE_VTU_*).
 * Kept so older deploys that require this file do not fatally error.
 */
require_once __DIR__ . '/env_loader.php';

$smobileVtuBaseUrl = ec_env('SMOBILE_VTU_BASE_URL', 'https://smobileagent.com/api');
$smobileVtuApiKey = ec_env('SMOBILE_VTU_API_KEY', '') ?? '';
$smobileVtuWebhookSecret = ec_env('SMOBILE_VTU_WEBHOOK_SECRET', '') ?? '';
