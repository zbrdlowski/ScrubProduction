<?php
/**
 * Download FIFO log file
 */

if (!isset($_GET['file'])) {
    http_response_code(400);
    die('No file specified');
}

$filename = basename($_GET['file']); // Security: remove path traversal
$filepath = __DIR__ . '/../logs/receiving/fifo/' . $filename;

// Validate file exists and is in the correct directory
if (!file_exists($filepath) || !is_file($filepath)) {
    http_response_code(404);
    die('File not found');
}

// Validate the file is actually in the logs directory
$realpath = realpath($filepath);
$allowedDir = realpath(__DIR__ . '/../logs/receiving/fifo');
if (strpos($realpath, $allowedDir) !== 0) {
    http_response_code(403);
    die('Access denied');
}

// Send file as download
header('Content-Type: text/plain; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Content-Length: ' . filesize($filepath));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

readfile($filepath);
exit;
?>
