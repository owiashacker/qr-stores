<?php
/**
 * AJAX endpoint: Toggle item favorite for the current visitor session.
 * POST params: slug (restaurant slug), item_id
 * Returns JSON: { ok: true, favorited: bool, count: int } or { ok: false, error: string }
 */
require_once __DIR__ . '/../../includes/functions.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'method_not_allowed']);
    exit;
}

$slug = trim($_POST['slug'] ?? '');
$itemId = (int) ($_POST['item_id'] ?? 0);

if (!$slug || $itemId <= 0) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'bad_request']);
    exit;
}

// Resolve restaurant
$stmt = $pdo->prepare('SELECT id FROM stores WHERE slug = ? AND is_active = 1 LIMIT 1');
$stmt->execute([$slug]);
$r = $stmt->fetch();
if (!$r) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => 'restaurant_not_found']);
    exit;
}

$result = toggleItemFavorite($pdo, $itemId, $r['id']);
if ($result === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'invalid_item']);
    exit;
}

echo json_encode(['ok' => true] + $result);
