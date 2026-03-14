<?php

$ACL = require __DIR__ . '/access_control.php';

function canAccessPage(string $page): bool
{
    global $ACL;

    if (!isset($ACL['pages'][$page])) {
        return false; // unknown page = blocked
    }

    $rule = $ACL['pages'][$page];

    if ($_SESSION['permission'] < ($rule['min_permission'] ?? 0)) {
        return false;
    }

    if (!empty($rule['departments']) && !in_array($_SESSION['dpt'], $rule['departments'])) {
        return false;
    }

    return true;
}

function canShowMenu(string $menuKey): bool
{
    global $ACL;

    if (!isset($ACL['menus'][$menuKey])) return false;

    return $_SESSION['permission'] >= $ACL['menus'][$menuKey]['min_permission'];
}
?>