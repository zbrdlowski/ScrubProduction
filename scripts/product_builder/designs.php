<?php
declare(strict_types=1);
require_once __DIR__ . '/helpers.php';
function pb_design_type($value): string
{
    $type = pb_text($value, 'Design group', 1);
    if (!in_array($type, ['G','S'], true)) throw new InvalidArgumentException('Choose Graphics or Seat Covers.');
    return $type;
}
function pb_design_list(PDO $pdo): array
{
    return $pdo->query('SELECT d.id,d.component_type,d.name,d.version,COUNT(DISTINCT c.product_id) AS usage_count
        FROM product_builder_designs d LEFT JOIN product_builder_components c ON c.design_id=d.id
        GROUP BY d.id,d.component_type,d.name,d.version ORDER BY d.component_type,d.name')->fetchAll(PDO::FETCH_ASSOC);
}
function pb_save_design(PDO $pdo, array $data): int
{
    $type = pb_design_type($data['component_type'] ?? '');
    $name = pb_text($data['name'] ?? '', 'Design name', 255);
    if (preg_match('/[\x00-\x1F\x7F]/u', $name)) throw new InvalidArgumentException('Design name cannot contain control characters.');
    $id = (int)($data['id'] ?? 0);
    try {
        if ($id > 0) {
            $stmt = $pdo->prepare('UPDATE product_builder_designs SET name=?,version=version+1 WHERE id=? AND component_type=? AND version=?');
            $stmt->execute([$name,$id,$type,(int)($data['version'] ?? 0)]);
            if ($stmt->rowCount() !== 1) throw new InvalidArgumentException('This design has changed or no longer exists. Refresh the list.');
        } else {
            $stmt = $pdo->prepare('INSERT INTO product_builder_designs (component_type,name) VALUES (?,?)');
            $stmt->execute([$type,$name]); $id = (int)$pdo->lastInsertId();
        }
        return $id;
    } catch (PDOException $e) {
        if (($e->errorInfo[1] ?? 0) === 1062) throw new InvalidArgumentException('A design with this name already exists in this group.');
        throw $e;
    }
}
function pb_delete_design(PDO $pdo, array $data): void
{
    $type = pb_design_type($data['component_type'] ?? '');
    $id = (int)($data['id'] ?? 0);
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT id FROM product_builder_designs WHERE id=? AND component_type=? AND version=? FOR UPDATE');
        $stmt->execute([$id,$type,(int)($data['version'] ?? 0)]);
        if (!$stmt->fetchColumn()) throw new InvalidArgumentException('This design has changed or no longer exists. Refresh the list.');
        $stmt = $pdo->prepare('SELECT id FROM product_builder_components WHERE design_id=? LIMIT 1'); $stmt->execute([$id]);
        if ($stmt->fetchColumn()) throw new InvalidArgumentException('This design is used by a saved listing. Change its design before deleting it.');
        $pdo->prepare('DELETE FROM product_builder_designs WHERE id=?')->execute([$id]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
}
