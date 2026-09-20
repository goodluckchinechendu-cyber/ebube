<?php
require_once 'config.php';
require_once 'schema.php';
require_once __DIR__ . '/session_auth.php';

$schemaError = ensure_app_tables($mysqli);
if ($schemaError !== null) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database setup failed: ' . $schemaError]);
    $mysqli->close();
    exit;
}

$sessionUser = ec_require_session($mysqli);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';

if ($method === 'GET') {
    $rows = [];
    $r = $mysqli->query(
        'SELECT id, title, message, created_by_name, created_at
         FROM announcements ORDER BY created_at DESC LIMIT 50'
    );
    if ($r) {
        while ($row = $r->fetch_assoc()) {
            $rows[] = [
                'id' => (int) $row['id'],
                'title' => $row['title'],
                'message' => $row['message'],
                'created_by_name' => $row['created_by_name'],
                'created_at' => $row['created_at'],
            ];
        }
    } else {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Query failed: ' . $mysqli->error]);
        $mysqli->close();
        exit;
    }
    echo json_encode(['success' => true, 'announcements' => $rows]);
    $mysqli->close();
    exit;
}

if ($method !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'message' => 'Method not allowed']);
    $mysqli->close();
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON body']);
    $mysqli->close();
    exit;
}

$action = $data['action'] ?? 'send';

if ((int) ($sessionUser['role'] ?? 0) < 2) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Admin access required']);
    $mysqli->close();
    exit;
}

if ($action === 'delete') {
    $id = isset($data['id']) ? (int) $data['id'] : 0;
    if ($id <= 0) {
        http_response_code(400);
        echo json_encode(['success' => false, 'message' => 'id required']);
        $mysqli->close();
        exit;
    }
    $stmt = $mysqli->prepare('DELETE FROM announcements WHERE id = ?');
    if (!$stmt) {
        http_response_code(500);
        echo json_encode(['success' => false, 'message' => 'Database error: ' . $mysqli->error]);
        $mysqli->close();
        exit;
    }
    $stmt->bind_param('i', $id);
    if ($stmt->execute() && $stmt->affected_rows > 0) {
        echo json_encode(['success' => true]);
    } else {
        http_response_code(404);
        echo json_encode(['success' => false, 'message' => 'Message not found']);
    }
    $stmt->close();
    $mysqli->close();
    exit;
}

$title = trim($data['title'] ?? '');
$message = trim($data['message'] ?? '');
$createdBy = trim((string) ($sessionUser['full_name'] ?? 'Admin'));
if ($createdBy === '') {
    $createdBy = 'Admin';
}

if ($title === '' || $message === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'title and message required']);
    $mysqli->close();
    exit;
}

$stmt = $mysqli->prepare(
    'INSERT INTO announcements (title, message, created_by_name) VALUES (?, ?, ?)'
);
if (!$stmt) {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database error: ' . $mysqli->error]);
    $mysqli->close();
    exit;
}

$stmt->bind_param('sss', $title, $message, $createdBy);

if ($stmt->execute()) {
    echo json_encode(['success' => true, 'id' => (int) $mysqli->insert_id]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Failed to send: ' . $stmt->error]);
}

$stmt->close();
$mysqli->close();
