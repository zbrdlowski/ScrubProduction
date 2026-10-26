<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/orders_multishipping_helpers.php';

$checks = 0;
$check = static function (string $groupStatus, array $memberStatuses, bool $allowed) use (&$checks): void {
  $members = array_map(static fn($status) => ['status' => $status], $memberStatuses);
  $error = ordersMultishippingUnlockError(['status' => $groupStatus], $members);
  if (($error === '') !== $allowed) {
    throw new RuntimeException('Unexpected unlock eligibility for ' . $groupStatus . ' / ' . implode(', ', $memberStatuses));
  }
  if (!$allowed && $groupStatus === 'SHIPPED' && $members && strpos($error, 'every member order') === false) {
    throw new RuntimeException('Blocked shipped groups must explain the requirement for every member.');
  }
  $checks++;
};

$check('SHIPPED', ['READY_TO_SHIP', 'READY_TO_SHIP'], true);
$check('SHIPPED', ['READY_TO_SHIP', 'SHIPPED'], false);
$check('SHIPPED', ['SHIPPED', 'READY_TO_SHIP'], false);
$check('SHIPPED', ['SHIPPED', 'SHIPPED'], false);
$check('SHIPPED', ['READY_TO_SHIP', 'CANCELLED'], false);
$check('SHIPPED', ['READY_TO_SHIP', 'IN_PROGRESS'], false);
$check('SHIPPED', ['READY_TO_SHIP', ''], false);
$check('SHIPPED', [], false);
$check('EXPORTED', ['READY_TO_SHIP', 'READY_TO_SHIP'], true);
$check('DRAFT', ['READY_TO_SHIP', 'READY_TO_SHIP'], false);
$check('CANCELLED', ['READY_TO_SHIP', 'READY_TO_SHIP'], false);
$check('UNKNOWN', ['READY_TO_SHIP', 'READY_TO_SHIP'], false);
$check(' shipped ', [' ready_to_ship ', 'READY_TO_SHIP'], true);

echo 'orders_multishipping_unlock: ', $checks, " checks passed\n";
