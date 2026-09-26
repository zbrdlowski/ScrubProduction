<?php
declare(strict_types=1);

function accounting_payout_user_can_access(): bool
{
    $permission = (int) ($_SESSION['permission'] ?? 0);
    $departmentId = (int) ($_SESSION['dpt'] ?? 0);

    return $permission === 900 || in_array($departmentId, [1, 3], true);
}
