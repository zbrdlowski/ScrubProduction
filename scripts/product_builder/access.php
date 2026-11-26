<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/auth.php';
function pb_can_manage_products(): bool
{
    return isset($_SESSION['permission']) && auth_can('products.view') && auth_can('products.manage');
}
// Preparing images does not grant permission to create or edit products.
function pb_can_prepare_transfers(): bool
{
    return isset($_SESSION['permission']);
}
function pb_can_access_api(string $method, string $action): bool
{
    if (!isset($_SESSION['permission'])) return false;
    // All signed-in employees may read listings and the transfer database.
    if ($method === 'GET' && in_array($action, ['products', 'product', 'transfers', 'transfer', 'transfer_form_options'], true)) return true;
    if ($method === 'POST' && in_array($action, ['save_transfer_image', 'save_transfer_folder', 'save_manual_transfer'], true)) return pb_can_prepare_transfers();
    if (!auth_can('products.view')) return false;
    return $method !== 'POST' || pb_can_manage_products();
}
