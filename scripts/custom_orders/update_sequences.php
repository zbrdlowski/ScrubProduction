<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$prefixes = ['SO', 'GO', 'SC'];
foreach ($prefixes as $prefix) {
  $value = max(0, (int) ($_POST['seq_' . strtolower($prefix)] ?? 0));
  $stmt = $conn->prepare('UPDATE custom_order_number_sequences SET current_value = ? WHERE prefix_code = ?');
  $stmt->bind_param('is', $value, $prefix);
  $stmt->execute();
  $stmt->close();
}

customOrdersFlash('success', 'Sequence seeds updated.');
customOrdersRedirect((int) ($_POST['custom_order_id'] ?? 0));
