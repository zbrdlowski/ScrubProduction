<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
function pb_graphics_version_list(PDO $pdo): array
{
    return $pdo->query('SELECT v.id,v.name,v.sort_order,v.version,COUNT(DISTINCT c.product_id) AS usage_count
        FROM product_builder_graphics_versions v LEFT JOIN product_builder_components c ON c.graphics_version_id=v.id
        GROUP BY v.id,v.name,v.sort_order,v.version ORDER BY v.sort_order,v.name,v.id')->fetchAll(PDO::FETCH_ASSOC);
}
function pb_save_graphics_version(PDO $pdo, array $data): int
{
    $name = pb_text($data['name'] ?? '', 'Version name', 30);
    if (preg_match('/[\x00-\x1F\x7F]/u', $name)) throw new InvalidArgumentException('Version name cannot contain control characters.');
    $sortOrder = filter_var($data['sort_order'] ?? 0, FILTER_VALIDATE_INT, ['options'=>['min_range'=>0,'max_range'=>9999]]);
    if ($sortOrder === false) throw new InvalidArgumentException('Display order must be a whole number from 0 to 9999.');
    $id = (int)($data['id'] ?? 0);
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE product_builder_graphics_versions SET name=?,sort_order=?,version=version+1 WHERE id=? AND version=?');
            $stmt->execute([$name,$sortOrder,$id,(int)($data['version'] ?? 0)]);
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('This version has changed or no longer exists. Refresh the list.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO product_builder_graphics_versions (name,sort_order) VALUES (?,?)');
            $stmt->execute([$name,$sortOrder]); $id = (int)$pdo->lastInsertId();
        }
        return $id;
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) throw new InvalidArgumentException('A Graphics version with this name already exists.');
        throw $e;
    }
}
function pb_delete_graphics_version(PDO $pdo, array $data): void
{
    $id = (int)($data['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM product_builder_graphics_versions WHERE id=? AND version=? FOR UPDATE');
        $stmt->execute([$id,(int)($data['version'] ?? 0)]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('This version has changed or no longer exists. Refresh the list.');
        $stmt = $pdo->prepare('SELECT id FROM product_builder_components WHERE graphics_version_id=? LIMIT 1'); $stmt->execute([$id]);
        if ($stmt->fetchColumn()) throw new InvalidArgumentException('This version is used by a saved listing. Change its version before deleting it.');
        $pdo->prepare('DELETE FROM product_builder_graphics_versions WHERE id=?')->execute([$id]); $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
