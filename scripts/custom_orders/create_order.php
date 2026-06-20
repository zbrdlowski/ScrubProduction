<?php
declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$userId = (int) ($_SESSION['user_id'] ?? 0);
$orderId = customOrdersCreateSkeleton($conn, $userId);
customOrdersFlash('success', 'Custom order created.');
customOrdersRedirect($orderId);
