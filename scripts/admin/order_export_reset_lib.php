<?php
declare(strict_types=1);

function orderExportResetAllowedUserIds(): array
{
  // Keep this intentionally tiny while the tool is owner-only.
  return [1];
}

function orderExportResetCurrentUserAllowed(): bool
{
  $userId = (int) ($_SESSION['user_id'] ?? 0);
  return in_array($userId, orderExportResetAllowedUserIds(), true);
}

function orderExportResetH(string $value): string
{
  return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}
