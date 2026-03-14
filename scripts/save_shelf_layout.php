<?php
// scripts/save_shelf_layout.php
session_start();
require_once('../includes/conn.php'); // $pdo

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    http_response_code(400); echo json_encode(['error'=>'Invalid JSON']); exit;
}

if (!empty($input['clear_all'])) {
    // Clear positions for all shelves
    try {
        $pdo->beginTransaction();
        $pdo->exec("UPDATE shelves SET pos_x = NULL, pos_y = NULL, tile_w = NULL, tile_h = NULL");
        $pdo->commit();
        echo json_encode(['ok' => true]);
    } catch (Exception $e) {
        $pdo->rollBack();
        http_response_code(500); echo json_encode(['error'=>$e->getMessage()]);
    }
    exit;
}

$shelves = $input['shelves'] ?? null;
if (!is_array($shelves)) {
    http_response_code(400); echo json_encode(['error'=>'Missing shelves array']); exit;
}

try {
    $pdo->beginTransaction();
    $stmt = $pdo->prepare("UPDATE shelves SET pos_x = :pos_x, pos_y = :pos_y, tile_w = :tile_w, tile_h = :tile_h, rack_group = :rack_group WHERE location = :location");
    foreach ($shelves as $s) {
        if (empty($s['location'])) continue;
        $stmt->execute([
            ':pos_x' => isset($s['pos_x']) ? $s['pos_x'] : null,
            ':pos_y' => isset($s['pos_y']) ? $s['pos_y'] : null,
            ':tile_w' => isset($s['tile_w']) ? $s['tile_w'] : null,
            ':tile_h' => isset($s['tile_h']) ? $s['tile_h'] : null,
            ':rack_group' => isset($s['rack_group']) ? $s['rack_group'] : null,
            ':location' => $s['location']
        ]);
    }
    $pdo->commit();
    echo json_encode(['ok' => true]);
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500); echo json_encode(['error'=>$e->getMessage()]);
}
?>