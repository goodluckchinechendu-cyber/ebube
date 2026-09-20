<?php
require_once 'config.php';
require_once 'schema.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    $mysqli->close();
    exit;
}

$data = json_decode(file_get_contents('php://input'), true) ?? [];
$action = $data['action'] ?? 'get';
$userId = isset($data['user_id']) ? (int) $data['user_id'] : 0;

if ($userId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'user_id required']);
    exit;
}

if ($action === 'get') {
    $stmt = $mysqli->prepare(
        'SELECT COALESCE(last_seen_announcement_id, 0) AS last_seen_announcement_id FROM users WHERE id = ?'
    );
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'User not found']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'last_seen_announcement_id' => (int) $row['last_seen_announcement_id'],
    ]);
    $mysqli->close();
    exit;
}

if ($action === 'set') {
    $lastSeen = isset($data['last_seen_announcement_id'])
        ? (int) $data['last_seen_announcement_id']
        : 0;

    $stmt = $mysqli->prepare(
        'UPDATE users SET last_seen_announcement_id = ? WHERE id = ?'
    );
    $stmt->bind_param('ii', $lastSeen, $userId);
    $ok = $stmt->execute();
    $stmt->close();

    if (!$ok) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Could not save preference']);
        exit;
    }

    echo json_encode([
        'success' => true,
        'last_seen_announcement_id' => $lastSeen,
    ]);
    $mysqli->close();
    exit;
}

http_response_code(400);
echo json_encode(['success' => false, 'message' => 'Unknown action']);
$mysqli->close();
