<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

$projectRoot = realpath(dirname(__DIR__, 2));
if ($projectRoot === false) {
    fwrite(STDERR, "Cannot resolve project root.\n");
    exit(1);
}

$options = getopt('', ['days::', 'delete']);
$days = max(1, (int) ($options['days'] ?? 30));
$delete = array_key_exists('delete', $options);
$cutoff = time() - ($days * 86400);

$configured = trim((string) getenv('DARKSCRUB_IMPORT_CLEANUP_DIRS'));
$directories = $configured !== ''
    ? array_filter(array_map('trim', explode(PATH_SEPARATOR, $configured)))
    : [
        $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'imports',
    ];

$allowedExtensions = ['csv', 'xlsx', 'xls', 'tmp', 'upload'];
$matched = 0;
$removed = 0;
$bytes = 0;

foreach ($directories as $directory) {
    $resolved = realpath($directory);
    if ($resolved === false || !is_dir($resolved)) {
        continue;
    }

    $rootPrefix = rtrim(str_replace('\\', '/', $projectRoot), '/') . '/';
    $resolvedNormalized = rtrim(str_replace('\\', '/', $resolved), '/') . '/';
    if (strpos($resolvedNormalized, $rootPrefix) !== 0 || $resolvedNormalized === $rootPrefix) {
        fwrite(STDERR, "Skipped unsafe cleanup directory: {$resolved}\n");
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($resolved, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::LEAVES_ONLY
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || !$file->isFile() || $file->isLink()) {
            continue;
        }
        if ($file->getMTime() >= $cutoff) {
            continue;
        }
        if (!in_array(strtolower($file->getExtension()), $allowedExtensions, true)) {
            continue;
        }

        $matched++;
        $bytes += $file->getSize();
        echo ($delete ? 'DELETE ' : 'DRY-RUN ') . $file->getPathname() . PHP_EOL;
        if ($delete && @unlink($file->getPathname())) {
            $removed++;
        }
    }
}

echo sprintf(
    "%s: %d file(s), %d removed, %.2f MB, older than %d day(s).\n",
    $delete ? 'Cleanup' : 'Dry run',
    $matched,
    $removed,
    $bytes / 1048576,
    $days
);
