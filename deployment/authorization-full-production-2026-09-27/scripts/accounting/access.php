<?php
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/auth.php';

function accounting_payout_user_can_access(string $permissionKey = 'accounting.view'): bool
{
    return auth_can($permissionKey);
}
