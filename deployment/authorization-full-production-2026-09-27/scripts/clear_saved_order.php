<?php
session_start();
require_once __DIR__ . '/plastics_access.php';
unset($_SESSION['saved_order']);
echo "OK";
?>
