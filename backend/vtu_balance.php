<?php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/smobile_vtu_client.php';

$result = smobile_vtu_request('GET', '/v1/balance');
smobile_vtu_respond($result);
