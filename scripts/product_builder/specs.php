<?php
declare(strict_types=1);
require_once __DIR__ . '/../../includes/get_order_detail_product_spec_selects.php';
require_once __DIR__ . '/../orders/department_config.php';

/** Reuse the same active fields, order and option values as the Custom Orders base product forms. */
function pb_component_spec_fields(mysqli $conn): array
{
    $result = [];
    foreach (['G','F','P','S','T'] as $type) {
        $result[$type] = [];
        foreach (productSpecFieldDefinitions($conn, $type) as $definition) {
            if (productSpecDefinitionGraphicsSubcategory($definition) !== '') continue;
            $source = productSpecNormalizeSourceKey((string) $definition['source_key']);
            $isNote = false;
            foreach (['spec_key','source_key','label'] as $part) {
                $key = productSpecNormalizeSourceKey((string) $definition[$part]);
                if ($key === 'note' || preg_match('/(?:^|-)(?:buyer-note|my-item-note|note)$/', $key)) $isNote = true;
            }
            $options = productSpecOptions($conn, (string) $definition['spec_key'], []);
            $result[$type][] = [
                'key'=>(string) $definition['spec_key'],
                'label'=>$type === 'G' && $source === 'note' ? 'Buyer Note' : (string) $definition['label'],
                'type'=>$isNote ? 'textarea' : ($options['field_type'] === 'text' ? 'text' : 'select'),
                // Preserve legacy component details in the familiar internal-note field.
                'storage'=>$source === 'my-item-note' ? 'notes' : 'options',
                'items'=>array_map(static fn(array $item): array => ['value'=>(string)$item['value'],'label'=>(string)$item['label']], $options['items']),
            ];
        }
    }
    return $result;
}
