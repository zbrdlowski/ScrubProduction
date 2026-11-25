<?php
declare(strict_types=1);

/**
 * Restore EXPORTED on custom orders whose status was changed after export.
 * Browser: open while signed in as an Orders administrator; review and submit.
 * CLI: php scripts/custom_orders/repair_exported_status.php [--apply]
 * GET / CLI without --apply only previews. Safe to run repeatedly.
 */
function customOrdersExportedStatusRepairWhere(): string
{
  // Match the export lock, including older records with only an export timestamp.
  return "(COALESCE(production_order_id, 0) > 0 OR exported_at IS NOT NULL)
    AND BINARY COALESCE(status, '') <> BINARY 'EXPORTED'";
}

function customOrdersExportedStatusRepairRows(mysqli $conn, bool $forUpdate = false): array
{
  $result = $conn->query('
    SELECT id, internal_code, official_order_number, status, production_order_id, exported_at
    FROM custom_orders
    WHERE ' . customOrdersExportedStatusRepairWhere() . '
    ORDER BY id' . ($forUpdate ? ' FOR UPDATE' : ''));
  $rows = $result->fetch_all(MYSQLI_ASSOC);
  $result->free();
  return $rows;
}

function customOrdersRepairExportedStatuses(mysqli $conn, ?int $actorId): array
{
  $conn->begin_transaction();
  try {
    // Re-read under row locks; the preview may be older than the submitted repair.
    $rows = customOrdersExportedStatusRepairRows($conn, true);
    if ($rows) {
      $update = $conn->prepare("UPDATE custom_orders
        SET status = 'EXPORTED', updated_by = COALESCE(?, updated_by), updated_at = NOW()
        WHERE id = ? AND " . customOrdersExportedStatusRepairWhere());
      $audit = $conn->prepare("INSERT INTO custom_order_activity
        (custom_order_id, actor_employee_id, action, payload, note)
        VALUES (?, ?, 'header_updated', ?, 'Restored Exported status after export (bulk repair)')");
      foreach ($rows as $row) {
        $id = (int) $row['id'];
        $update->bind_param('ii', $actorId, $id);
        $update->execute();
        if ($update->affected_rows !== 1) {
          throw new RuntimeException('Could not repair custom order #' . $id);
        }
        $payload = json_encode([
          'repair' => 'exported_status',
          'status' => 'EXPORTED',
          'previous_status' => $row['status'],
          'production_order_id' => $row['production_order_id'],
          'exported_at' => $row['exported_at'],
          'changes' => [[
            'field' => 'status', 'label' => 'Status',
            'from' => $row['status'] ?? 'empty', 'to' => 'EXPORTED',
          ]],
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $audit->bind_param('iis', $id, $actorId, $payload);
        $audit->execute();
      }
      $update->close();
      $audit->close();
    }
    $conn->commit();
    return $rows;
  } catch (Throwable $e) {
    $conn->rollback();
    throw $e;
  }
}

function customOrdersExportedStatusRepairH($value): string
{
  return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function customOrdersExportedStatusRepairTable(array $rows): void
{
  echo '<div class="table-wrap"><table><thead><tr><th>Custom ID</th><th>Objednávka</th><th>Pôvodný stav</th><th>Nový stav</th><th>Production ID</th><th>Exportované</th></tr></thead><tbody>';
  foreach ($rows as $row) {
    echo '<tr>';
    foreach ([$row['id'], $row['official_order_number'] ?: $row['internal_code'], $row['status'], 'Exported', $row['production_order_id'], $row['exported_at']] as $value) {
      echo '<td>', customOrdersExportedStatusRepairH($value), '</td>';
    }
    echo '</tr>';
  }
  echo '</tbody></table></div>';
}

function customOrdersExportedStatusRepairMain(): int
{
  global $conn, $pdo;
  $isCli = PHP_SAPI === 'cli';
  if (!$isCli) {
    if (session_status() !== PHP_SESSION_ACTIVE) session_start();
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    if (empty($_SESSION['user_id'])) {
      http_response_code(403);
      echo 'Najprv sa prihlás do administrácie.';
      return 1;
    }
  }
  require_once dirname(__DIR__, 2) . '/includes/conn.php';
  require_once dirname(__DIR__, 2) . '/includes/auth.php';
  mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

  $apply = false;
  $actorId = null;
  if ($isCli) {
    $args = array_slice($GLOBALS['argv'] ?? [], 1);
    if ($args !== [] && $args !== ['--apply']) {
      fwrite(STDERR, "Usage: php scripts/custom_orders/repair_exported_status.php [--apply]\n");
      return 1;
    }
    $apply = $args === ['--apply'];
  } else {
    auth_require('orders.admin', 'Na túto opravu je potrebné administrátorské oprávnenie Orders.');
    $actorId = (int) $_SESSION['user_id'];
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if (!in_array($method, ['GET', 'POST'], true)) {
      http_response_code(405);
      header('Allow: GET, POST');
      return 1;
    }
    if (empty($_SESSION['custom_orders_exported_status_repair_token'])) {
      $_SESSION['custom_orders_exported_status_repair_token'] = bin2hex(random_bytes(32));
    }
    if ($method === 'POST') {
      $token = $_POST['csrf_token'] ?? null;
      if (($_POST['action'] ?? '') !== 'repair' || !is_string($token)
          || !hash_equals($_SESSION['custom_orders_exported_status_repair_token'], $token)) {
        http_response_code(403);
        echo 'Neplatný formulár. Obnov stránku a skús to znova.';
        return 1;
      }
      $apply = true;
    }
  }

  try {
    $repaired = $apply ? customOrdersRepairExportedStatuses($conn, $actorId) : null;
    $pending = customOrdersExportedStatusRepairRows($conn);
  } catch (Throwable $e) {
    if ($isCli) {
      fwrite(STDERR, 'Repair failed: ' . $e->getMessage() . PHP_EOL);
    } else {
      http_response_code(500);
      echo 'Chyba pri spracovaní: ', customOrdersExportedStatusRepairH($e->getMessage());
    }
    return 1;
  }

  if ($isCli) {
    if ($repaired !== null) echo 'Repaired: ', count($repaired), PHP_EOL;
    echo 'Remaining to repair: ', count($pending), PHP_EOL;
    foreach ($repaired ?? $pending as $row) {
      echo '#', $row['id'], ' ', $row['official_order_number'] ?: $row['internal_code'],
        ': ', $row['status'], ' -> EXPORTED', PHP_EOL;
    }
    if (!$apply) echo "Preview only. Run again with --apply to save.\n";
    return 0;
  }
  ?>
<!doctype html>
<html lang="sk">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Oprava stavu Exported</title>
  <style>
    body{font:16px/1.5 system-ui,sans-serif;margin:32px auto;padding:0 20px;max-width:1100px;color:#172735;background:#f4f6f8}
    h1{font-size:26px}.table-wrap{overflow:auto}table{border-collapse:collapse;width:100%;background:white}
    th,td{padding:10px 12px;text-align:left;border-bottom:1px solid #d9e1e8;white-space:nowrap}
    th{background:#e7edf2}button{padding:12px 18px;background:#176448;color:white;border:0;border-radius:5px;font:inherit;cursor:pointer}
    .success{padding:14px;background:#e0f3e9;border-left:4px solid #176448}form{margin:24px 0}a{color:#175880}
  </style>
</head>
<body>
  <h1>Oprava stavu exportovaných custom objednávok</h1>
  <p>Objednávkam s prepojením na Production alebo uloženým časom exportu sa nastaví stav <strong>Exported</strong>. Pôvodný stav a oprava sa uložia do histórie objednávky.</p>
  <?php if ($repaired !== null): ?>
    <p class="success">Opravené objednávky: <strong><?= count($repaired) ?></strong>.</p>
    <?php if ($repaired) customOrdersExportedStatusRepairTable($repaired); ?>
  <?php endif; ?>
  <h2>Na opravu: <?= count($pending) ?></h2>
  <?php if ($pending): ?>
    <?php customOrdersExportedStatusRepairTable($pending); ?>
    <form method="post">
      <input type="hidden" name="action" value="repair">
      <input type="hidden" name="csrf_token" value="<?= customOrdersExportedStatusRepairH($_SESSION['custom_orders_exported_status_repair_token']) ?>">
      <button type="submit">Nastaviť Exported všetkým vyexportovaným objednávkam</button>
    </form>
  <?php else: ?>
    <p>Všetky vyexportované custom objednávky už majú stav Exported.</p>
  <?php endif; ?>
  <p>Existujúci zámok po exporte naďalej blokuje ručné zmeny stavu. Opravu môžeš spustiť opakovane.</p>
  <p><a href="../../index.php?page=custom_orders">Späť na Custom Orders</a></p>
</body>
</html>
  <?php
  return 0;
}

// Allow the transaction and preview to be tested with connection-local fixtures.
if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === realpath(__FILE__)) {
  exit(customOrdersExportedStatusRepairMain());
}
