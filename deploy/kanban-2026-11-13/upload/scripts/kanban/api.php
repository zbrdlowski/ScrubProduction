<?php
declare(strict_types=1);
session_start();
require_once __DIR__ . '/i18n.php';
$GLOBALS['kbLanguage'] = kb_resolve_language();
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

function kb_reply(array $payload, int $status = 200): void
{
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}

$employeeId = (int) ($_SESSION['user_id'] ?? 0);
if ($employeeId <= 0 || !isset($_SESSION['permission'])) kb_reply(['ok' => false, 'error' => kb_t('Please sign in to the application.')], 401);
$method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
if (!in_array($method, ['GET', 'POST'], true)) {
    header('Allow: GET, POST');
    kb_reply(['ok' => false, 'error' => kb_t('Method not allowed.')], 405);
}
if ($method === 'POST' && (empty($_SESSION['kanban_csrf'])
    || !is_string($_SERVER['HTTP_X_CSRF_TOKEN'] ?? null)
    || !hash_equals($_SESSION['kanban_csrf'], $_SERVER['HTTP_X_CSRF_TOKEN']))) {
    kb_reply(['ok' => false, 'error' => kb_t('Your form has expired. Refresh the page.')], 403);
}
session_write_close();
require_once __DIR__ . '/../../includes/conn.php';
require_once __DIR__ . '/service.php';
try {
    if (!kb_query($pdo, 'SELECT id FROM employees WHERE id = ?', [$employeeId])->fetchColumn()) {
        kb_reply(['ok' => false, 'error' => kb_t('User not found.')], 401);
    }
    if (!kb_ready($pdo)) kb_reply(['ok' => false, 'error' => kb_t('Kanban is not ready yet. Contact an administrator.')], 503);
    if ($method === 'GET') kb_reply(['ok' => true] + kb_read($pdo, $employeeId, $_GET));
    $raw = file_get_contents('php://input', false, null, 0, 65537);
    if (strlen($raw) > 65536) throw new KanbanError(kb_t('The form is too large.'), 413);
    $data = json_decode($raw, true);
    if (!is_array($data)) throw new KanbanError(kb_t('Invalid form.'), 400);
    $action = kb_text($_GET['action'] ?? '', 'Action', 30, true);
    if ($action === 'initialize') {
        kb_initialize($pdo, $employeeId, kb_text($data['template'] ?? 'general', 'Template', 20));
        kb_reply(['ok' => true]);
    }
    kb_reply(['ok' => true] + kb_mutate($pdo, $employeeId, $action, $data));
} catch (KanbanError $e) {
    kb_reply(['ok' => false, 'error' => $e->getMessage()], $e->getCode() ?: 422);
} catch (Throwable $e) {
    error_log('Kanban: ' . $e->getMessage());
    kb_reply(['ok' => false, 'error' => kb_t('The operation could not be completed. Refresh the board and try again.')], 500);
}
