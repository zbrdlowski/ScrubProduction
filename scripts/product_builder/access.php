<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
function pb_can_manage_products(): bool
{
    return isset($_SESSION['permission']) && auth_can('products.view') && auth_can('products.manage');
}
function pb_can_access_api(string $method, string $action): bool
{
    if (!isset($_SESSION['permission'])) return false;
    // All signed-in employees may read the listing overview and its component details.
    if ($method === 'GET' && in_array($action, ['products', 'product'], true)) return true;
    if (!auth_can('products.view')) return false;
    return $method !== 'POST' || pb_can_manage_products();
}
