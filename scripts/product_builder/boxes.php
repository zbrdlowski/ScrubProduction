<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
function pb_validate_box(array $data): array
{
    $name = pb_text($data['name'] ?? '', 'Box name', 150);
    if (preg_match('/[\x00-\x1F\x7F]/u', $name)) throw new InvalidArgumentException('Box name cannot contain control characters.');
    return ['name'=>$name,
        'length_cm'=>pb_decimal($data['length_cm'] ?? '', 'Length (cm)', 5, 2, false),
        'width_cm'=>pb_decimal($data['width_cm'] ?? '', 'Width (cm)', 5, 2, false),
        'height_cm'=>pb_decimal($data['height_cm'] ?? '', 'Height (cm)', 5, 2, false),
        'weight_kg'=>pb_decimal($data['weight_kg'] ?? '', 'Weight (kg)', 6, 3, true)];
}
function pb_box_list(PDO $pdo): array
{
    return $pdo->query('SELECT b.id,b.name,b.length_cm,b.width_cm,b.height_cm,b.weight_kg,b.version,(SELECT COUNT(*) FROM product_builder_products p WHERE p.box_id=b.id) AS usage_count FROM product_builder_boxes b ORDER BY b.name,b.id')->fetchAll(PDO::FETCH_ASSOC);
}
function pb_save_box(PDO $pdo, array $data): int
{
    $clean = pb_validate_box($data); $id = (int)($data['id'] ?? 0);
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE product_builder_boxes SET name=?,length_cm=?,width_cm=?,height_cm=?,weight_kg=?,version=version+1 WHERE id=? AND version=?');
            $stmt->execute(array_merge(array_values($clean), [$id,(int)($data['version'] ?? 0)]));
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('This box has changed or no longer exists. Refresh the list and open it again.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO product_builder_boxes (name,length_cm,width_cm,height_cm,weight_kg) VALUES (?,?,?,?,?)');
            $stmt->execute(array_values($clean)); $id = (int)$pdo->lastInsertId();
        }
        return $id;
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) throw new InvalidArgumentException('A box with this name already exists.');
        throw $e;
    }
}
function pb_delete_box(PDO $pdo, array $data): void
{
    $id = (int)($data['id'] ?? 0); $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM product_builder_boxes WHERE id=? AND version=? FOR UPDATE');
        $stmt->execute([$id,(int)($data['version'] ?? 0)]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('This box has changed or no longer exists. Refresh the list and open it again.');
        $used = $pdo->prepare('SELECT id FROM product_builder_products WHERE box_id=? LIMIT 1'); $used->execute([$id]);
        if ($used->fetchColumn()) throw new InvalidArgumentException('This box is used by a saved product. Change its box before deleting it.');
        $pdo->prepare('DELETE FROM product_builder_boxes WHERE id=?')->execute([$id]); $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
