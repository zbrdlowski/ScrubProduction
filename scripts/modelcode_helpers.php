<?php
declare(strict_types=1);
/** Shared by Models Database and the independent product builder. */
function scrub_modelcode_candidate(): string
{
    $alphabet = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ';
    $code = '';
    for ($i = 0; $i < 4; $i++) $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    return $code;
}
function scrub_modelcode_uni_exists(mysqli $conn, string $code): bool
{
    $ready = $conn->query("SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'product_builder_uni_categories'")->num_rows > 0;
    if (!$ready) return false;
    $stmt = $conn->prepare('SELECT id FROM product_builder_uni_categories WHERE modelcode = ? LIMIT 1');
    $stmt->bind_param('s', $code); $stmt->execute();
    $exists = (bool) $stmt->get_result()->fetch_assoc(); $stmt->close();
    return $exists;
}
function scrub_generate_modelcode(mysqli $conn): string
{
    $stmt = $conn->prepare('SELECT 1 FROM scrubdata WHERE modelcode = ? LIMIT 1');
    try {
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $code = scrub_modelcode_candidate();
            $stmt->bind_param('s', $code); $stmt->execute();
            if (!$stmt->get_result()->fetch_row() && !scrub_modelcode_uni_exists($conn, $code)) return $code;
        }
    } finally { $stmt->close(); }
    throw new RuntimeException('Unable to generate an available modelcode. Please try again.');
}
