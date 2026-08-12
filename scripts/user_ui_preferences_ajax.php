<?php
declare(strict_types=1);

session_start();
header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/../includes/conn.php';

function preferencesOut(int $status, array $payload): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    preferencesOut(403, ['ok' => false, 'error' => 'Not logged in.']);
}

$action = (string) ($_REQUEST['action'] ?? 'get');

if ($action === 'get') {
    $stmt = $conn->prepare('SELECT ui_preferences_json FROM employees WHERE id = ? LIMIT 1');
    $stmt->bind_param('i', $userId);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        preferencesOut(404, ['ok' => false, 'error' => 'User not found.']);
    }

    $preferences = json_decode((string) ($row['ui_preferences_json'] ?? ''), true);
    preferencesOut(200, [
        'ok' => true,
        'preferences' => is_array($preferences) ? $preferences : [],
    ]);
}

if ($action === 'save_scope') {
    $payload = json_decode((string) file_get_contents('php://input'), true);
    $scope = trim((string) ($payload['scope'] ?? ''));
    $value = $payload['value'] ?? null;

    if (!preg_match('/^[a-z][a-z0-9_]{0,63}$/', $scope) || !is_array($value)) {
        preferencesOut(400, ['ok' => false, 'error' => 'Invalid preference scope or value.']);
    }

    $conn->begin_transaction();
    try {
        $stmt = $conn->prepare('SELECT ui_preferences_json FROM employees WHERE id = ? FOR UPDATE');
        $stmt->bind_param('i', $userId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$row) {
            throw new RuntimeException('User not found.');
        }

        $preferences = json_decode((string) ($row['ui_preferences_json'] ?? ''), true);
        if (!is_array($preferences)) {
            $preferences = [];
        }
        $preferences[$scope] = $value;

        $encoded = json_encode($preferences, JSON_UNESCAPED_UNICODE);
        if ($encoded === false || strlen($encoded) > 65535) {
            throw new RuntimeException('Saved UI preferences are too large.');
        }

        $stmt = $conn->prepare('UPDATE employees SET ui_preferences_json = ? WHERE id = ?');
        $stmt->bind_param('si', $encoded, $userId);
        $stmt->execute();
        $stmt->close();
        $conn->commit();

        preferencesOut(200, ['ok' => true, 'scope' => $scope, 'value' => $value]);
    } catch (Throwable $e) {
        $conn->rollback();
        preferencesOut(500, ['ok' => false, 'error' => $e->getMessage()]);
    }
}

preferencesOut(400, ['ok' => false, 'error' => 'Unknown action.']);
