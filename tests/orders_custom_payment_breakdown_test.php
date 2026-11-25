<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/conn.php';
require_once dirname(__DIR__) . '/scripts/orders/financial_helpers.php';
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);
$source = file_get_contents(dirname(__DIR__) . '/scripts/orders/get_order_detail.php');
// Execute the production helpers and selection/rendering logic without the HTTP bootstrap.
foreach (['MoneyValue','BooleanValue','TableColumns','CustomPaymentKindLabel','CustomPaymentKindAffectsBalance',
  'CustomPaymentDateLabel','NormalizeCustomPaymentLines','LoadCustomPaymentRows',
  'NormalizeCustomFinancialBreakdown','LoadCustomFinancialBreakdownFallback'] as $name) {
  if (!preg_match('/^function orderDetail' . $name . '\b.*?^\}/ms', $source, $match)) throw new RuntimeException('Missing helper: ' . $name);
  eval($match[0]);
}
$start = strpos($source, '$customFinancialBreakdown = [];');
$end = strpos($source, '$financialInfo = order_financial_effective_totals', $start);
$selection = substr($source, $start, $end - $start);
$renderStart = strpos($source, '<?php if (!empty($customFinancialBreakdown)): ?>');
$renderEnd = strpos($source, '<?php if ($showCustomPaymentsPanel): ?>', $renderStart);
$render = substr($source, $renderStart, $renderEnd - $renderStart);
function h($value): string { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }

// Connection-local tables shadow the application tables; no real orders/payments are written.
$conn->query('CREATE TEMPORARY TABLE custom_orders (id INT PRIMARY KEY, currency VARCHAR(3), shipping_price DECIMAL(10,2), customs_ddp_amount DECIMAL(10,2), customs_ddp_note VARCHAR(255))');
$conn->query('CREATE TEMPORARY TABLE custom_order_items (custom_order_id INT, item_type_code VARCHAR(8), qty INT, unit_price DECIMAL(10,2))');
$conn->query('CREATE TEMPORARY TABLE custom_order_payments (id INT PRIMARY KEY, custom_order_id INT, payment_kind VARCHAR(32), amount DECIMAL(10,2), currency VARCHAR(3), received_at DATETIME, invoice_number VARCHAR(128), paypal_transaction_id VARCHAR(128), note TEXT)');
$conn->query("INSERT INTO custom_orders VALUES (1,'EUR',10,20,'DDP')");
$conn->query("INSERT INTO custom_order_items VALUES (1,'G',2,100)");
$conn->query("INSERT INTO custom_order_payments VALUES (1,1,'DEPOSIT',100,'EUR','2026-11-24 10:00:00','','TX1','Original')");
$snapshot = ['custom_order_id' => 1, 'financial_breakdown' => [
  'total' => 330, 'graphics' => 300, 'shipping' => 10, 'customs_ddp' => 20,
  'deposits' => 100, 'paid_net' => 100, 'balance_due' => 230,
  'payment_lines' => [['id' => 1, 'kind' => 'DEPOSIT', 'amount' => 100, 'currency' => 'EUR']],
]];
$read = static function (array $sourceMeta, bool $isCustomOrder = true, bool $isFollowupOrder = false) use ($conn, $selection, $render): array {
  $linkedCustomOrderId = (int) ($sourceMeta['custom_order_id'] ?? 0);
  eval($selection);
  // The rendered balance must use the current Production total, not the original custom items.
  $orderValueBreakdown = ['total' => 350.0];
  $orderCurrencySuffix = ' EUR';
  ob_start(); eval('?>' . $render); $html = ob_get_clean();
  return [$customFinancialBreakdown, $customProductionPaymentLines, preg_replace('/\s+/', ' ', strip_tags($html))];
};
$checks = 0;
$assert = static function (bool $condition, string $message) use (&$checks): void {
  $checks++;
  if (!$condition) throw new RuntimeException($message);
};
$verify = static function (float $paid, float $deposits, int $lines, string $label) use ($read, $snapshot, $assert): array {
  [$breakdown, $panel, $html] = $read($snapshot);
  $assert((float) $breakdown['paid_net'] === $paid, $label . ': live paid total');
  $assert((float) $breakdown['deposits'] === $deposits, $label . ': live deposits');
  $assert(count($breakdown['payment_lines']) === $lines, $label . ': live payment lines');
  $assert((float) $breakdown['graphics'] === 300.0 && (float) $breakdown['total'] === 330.0, $label . ': payment refresh must not replace product prices');
  $assert(strpos($html, 'Balance due: ' . number_format(350 - $paid, 2, '.', '')) !== false, $label . ': rendered balance uses current Production total');
  return [$breakdown, $panel, $html];
};

$verify(100,100,1,'Initial export');
$conn->query("INSERT INTO custom_order_payments VALUES (2,1,'BALANCE',50,'EUR','2026-11-25 10:00:00','','TX2','Added after export')");
[, $panel, $html] = $verify(150,100,2,'Added payment');
$assert(count($panel) === 2 && strpos($html, 'Balance 25.11.2026: 50.00') !== false, 'Both Production views show the added payment');
$conn->query('UPDATE custom_order_payments SET amount=80 WHERE id=1');
$verify(130,80,2,'Edited deposit');
$conn->query("INSERT INTO custom_order_payments VALUES
  (3,1,'EXTRA_DEPOSIT',20,'EUR','2026-11-25 11:00:00','','',''),
  (4,1,'REFUND',10,'EUR','2026-11-25 12:00:00','','',''),
  (5,1,'ADVANCE_INVOICE',999,'EUR','2026-11-25 13:00:00','','',''),
  (6,1,'BALANCE',0,'EUR','2026-11-25 14:00:00','','','')");
[, $panel, $html] = $verify(140,100,4,'Extra deposit, refund and advance invoice');
$assert(count($panel) === 6 && strpos($html, '-10.00') !== false && strpos($html, '999.00') === false, 'Refund reduces the balance; advance invoice and zero rows stay outside financial lines');
$conn->query('DELETE FROM custom_order_payments WHERE id=2');
$verify(90,100,3,'Deleted payment');
$conn->query('DELETE FROM custom_order_payments WHERE id<>5');
[, $panel] = $verify(0,0,0,'Only advance invoice remains');
$assert(count($panel) === 1, 'Advance invoice remains visible in the payment records panel');
$conn->query('DELETE FROM custom_order_payments');
[, $panel] = $verify(0,0,0,'All payments deleted');
$assert($panel === [], 'Deleted payments must not reappear from the export snapshot');
[$legacy] = $read(['custom_order_id' => 1]);
$assert((float) $legacy['total'] === 230.0 && (float) $legacy['paid_net'] === 0.0, 'Legacy order without snapshot uses the existing fallback');
[$historical] = $read(['financial_breakdown' => $snapshot['financial_breakdown']]);
$assert((float) $historical['paid_net'] === 100.0, 'Snapshot remains usable when there is no linked custom order');
foreach ([[true,true],[false,false]] as [$custom,$followup]) {
  [$breakdown,$panel] = $read($snapshot,$custom,$followup);
  $assert($breakdown === [] && $panel === [], 'Other sources and follow-up orders do not inherit original payments');
}
echo 'orders_custom_payment_breakdown: ', $checks, " checks passed\n";
