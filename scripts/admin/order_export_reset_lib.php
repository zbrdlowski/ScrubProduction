<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';

function orderExportResetCurrentUserAllowed(): bool
{
  return auth_can('orders.export_reset');
}

function orderExportResetH(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
