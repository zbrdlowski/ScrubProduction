<?php
declare(strict_types=1);

function orderSearchOperatorLabels(): array
{
  return [
    'contains' => 'contains',
    'equals' => 'is',
    'not_equals' => 'is not',
    'starts_with' => 'starts with',
    'is_empty' => 'is empty',
    'is_not_empty' => 'is not empty',
    'on' => 'on',
    'before' => 'before',
    'after' => 'after',
    'between' => 'between',
    'gt' => 'greater than',
    'gte' => 'greater or equal',
    'lt' => 'less than',
    'lte' => 'less or equal',
  ];
}

function orderSearchFieldRegistry(): array
{
  static $fields = null;
  if ($fields !== null) {
    return $fields;
  }

  $text = ['contains', 'equals', 'not_equals', 'starts_with', 'is_empty', 'is_not_empty'];
  $choice = ['equals', 'contains', 'not_equals', 'is_empty', 'is_not_empty'];
  $json = ['contains', 'equals', 'is_not_empty'];
  $date = ['on', 'before', 'after', 'between', 'is_empty', 'is_not_empty'];
  $number = ['equals', 'not_equals', 'gt', 'gte', 'lt', 'lte', 'is_empty', 'is_not_empty'];

  $fields = [
    'global_text' => ['label' => 'Anywhere text', 'group' => 'Universal', 'type' => 'text', 'kind' => 'global_text', 'operators' => ['contains'], 'placeholder' => 'order, customer, note, item, invoice, tracking'],

    'order_number' => ['label' => 'Order number', 'group' => 'Order', 'type' => 'text', 'kind' => 'direct', 'expr' => 'o.order_number', 'operators' => $text, 'suggest' => true],
    'external_order_id' => ['label' => 'External order ID', 'group' => 'Order', 'type' => 'text', 'kind' => 'direct', 'expr' => 'o.external_order_id', 'operators' => $text, 'suggest' => true],
    'source' => ['label' => 'Source', 'group' => 'Order', 'type' => 'text', 'kind' => 'direct', 'expr' => 'os.code', 'operators' => $choice, 'suggest' => true],
    'status' => ['label' => 'Order status', 'group' => 'Order', 'type' => 'text', 'kind' => 'direct', 'expr' => 'o.status', 'operators' => $choice, 'suggest' => true],
    'priority' => ['label' => 'Priority', 'group' => 'Order', 'type' => 'number', 'kind' => 'direct', 'expr' => 'o.priority', 'operators' => $number],
    'order_date' => ['label' => 'Order date', 'group' => 'Order', 'type' => 'date', 'kind' => 'direct', 'expr' => 'o.order_date', 'operators' => $date],
    'imported_at' => ['label' => 'Imported date', 'group' => 'Order', 'type' => 'date', 'kind' => 'direct', 'expr' => 'o.imported_at', 'operators' => $date],
    'payment_method' => ['label' => 'Payment method', 'group' => 'Order', 'type' => 'text', 'kind' => 'direct', 'expr' => 'o.payment_method', 'operators' => $choice, 'suggest' => true],
    'shipping_method' => ['label' => 'Shipping method', 'group' => 'Order', 'type' => 'text', 'kind' => 'direct', 'expr' => 'o.shipping_method', 'operators' => $choice, 'suggest' => true],
    'customs_identifier' => ['label' => 'Customs identifier', 'group' => 'Order', 'type' => 'text', 'kind' => 'direct', 'expr' => 'o.customs_identifier', 'operators' => $text],
    'source_meta' => ['label' => 'Order JSON source meta', 'group' => 'Advanced JSON', 'type' => 'text', 'kind' => 'direct', 'expr' => 'o.source_meta', 'operators' => ['contains', 'is_not_empty'], 'placeholder' => 'raw source_meta text'],

    'customer_name' => ['label' => 'Customer name', 'group' => 'Customer', 'type' => 'text', 'kind' => 'direct', 'expr' => 'cu.name', 'operators' => $text, 'suggest' => true],
    'customer_email' => ['label' => 'Customer email', 'group' => 'Customer', 'type' => 'text', 'kind' => 'direct', 'expr' => 'cu.email', 'operators' => $text, 'suggest' => true],
    'customer_phone' => ['label' => 'Customer phone', 'group' => 'Customer', 'type' => 'text', 'kind' => 'direct', 'expr' => 'cu.phone', 'operators' => $text],

    'country' => ['label' => 'Country', 'group' => 'Address', 'type' => 'text', 'kind' => 'direct', 'expr' => 'COALESCE(oa_ship.country, oa_bill.country)', 'operators' => $choice, 'suggest' => true, 'placeholder' => 'US, DE, SK...'],
    'city' => ['label' => 'City', 'group' => 'Address', 'type' => 'text', 'kind' => 'exists', 'from' => 'order_addresses oa_city', 'where' => 'oa_city.order_id = o.id', 'expr' => 'oa_city.city', 'operators' => $text, 'suggest' => true],
    'address_text' => ['label' => 'Address text', 'group' => 'Address', 'type' => 'text', 'kind' => 'exists', 'from' => 'order_addresses oa_text', 'where' => 'oa_text.order_id = o.id', 'expr' => "CONCAT_WS(' ', oa_text.name, oa_text.company, oa_text.street, oa_text.city, oa_text.zip, oa_text.state, oa_text.country, oa_text.email, oa_text.phone)", 'operators' => ['contains', 'equals', 'starts_with', 'is_not_empty']],

    'all_notes' => ['label' => 'Any note', 'group' => 'Notes', 'type' => 'text', 'kind' => 'notes', 'operators' => ['contains', 'is_not_empty'], 'placeholder' => 'customer note, production note, item note'],
    'order_note' => ['label' => 'Order note', 'group' => 'Notes', 'type' => 'text', 'kind' => 'direct', 'expr' => 'o.note', 'operators' => ['contains', 'equals', 'is_empty', 'is_not_empty']],
    'production_note' => ['label' => 'Production note', 'group' => 'Notes', 'type' => 'text', 'kind' => 'direct', 'expr' => 'o.production_note', 'operators' => ['contains', 'equals', 'is_empty', 'is_not_empty']],
    'item_waiting_note' => ['label' => 'Item waiting note', 'group' => 'Notes', 'type' => 'text', 'kind' => 'exists', 'from' => 'order_items oi_wait', 'where' => 'oi_wait.order_id = o.id AND oi_wait.deleted_at IS NULL', 'expr' => 'oi_wait.waiting_note', 'operators' => ['contains', 'equals', 'is_not_empty']],

    'item_type' => ['label' => 'Item type', 'group' => 'Items', 'type' => 'text', 'kind' => 'exists', 'from' => 'order_items oi_type', 'where' => 'oi_type.order_id = o.id AND oi_type.deleted_at IS NULL', 'expr' => 'oi_type.item_type_code', 'operators' => $choice, 'suggest' => true, 'placeholder' => 'G, F, P, S...'],
    'item_status' => ['label' => 'Item status', 'group' => 'Items', 'type' => 'text', 'kind' => 'exists', 'from' => 'order_items oi_status', 'where' => 'oi_status.order_id = o.id AND oi_status.deleted_at IS NULL', 'expr' => 'oi_status.status', 'operators' => $choice, 'suggest' => true],
    'item_title' => ['label' => 'Item title', 'group' => 'Items', 'type' => 'text', 'kind' => 'exists', 'from' => 'order_items oi_title', 'where' => 'oi_title.order_id = o.id AND oi_title.deleted_at IS NULL', 'expr' => 'oi_title.title', 'operators' => $text, 'suggest' => true],
    'item_sku' => ['label' => 'Item SKU', 'group' => 'Items', 'type' => 'text', 'kind' => 'exists', 'from' => 'order_items oi_sku', 'where' => 'oi_sku.order_id = o.id AND oi_sku.deleted_at IS NULL', 'expr' => 'oi_sku.sku', 'operators' => $text, 'suggest' => true],
    'item_label' => ['label' => 'Item custom label', 'group' => 'Items', 'type' => 'text', 'kind' => 'exists', 'from' => 'order_items oi_label', 'where' => 'oi_label.order_id = o.id AND oi_label.deleted_at IS NULL', 'expr' => 'oi_label.custom_label', 'operators' => $text, 'suggest' => true],
    'category' => ['label' => 'Category', 'group' => 'Items', 'type' => 'text', 'kind' => 'exists', 'from' => 'order_categories oc_cat JOIN categories c_cat ON c_cat.id = oc_cat.category_id', 'where' => 'oc_cat.order_id = o.id', 'expr' => 'c_cat.code', 'operators' => $choice, 'suggest' => true],

    'option_category_info' => ['label' => 'JSON category info', 'group' => 'Item JSON', 'type' => 'text', 'kind' => 'json_multi', 'operators' => $json, 'from' => 'order_items oi_catinfo', 'where' => 'oi_catinfo.order_id = o.id AND oi_catinfo.deleted_at IS NULL', 'paths' => [['options_json', '$."Category Info"'], ['options_json', '$._item.category_info'], ['internal_options_json', '$._category_info']], 'suggest' => true],
    'material' => ['label' => 'Material', 'group' => 'Item JSON', 'type' => 'text', 'kind' => 'json_multi', 'operators' => $json, 'from' => 'order_items oi_material', 'where' => 'oi_material.order_id = o.id AND oi_material.deleted_at IS NULL', 'paths' => [['internal_options_json', '$._print_material'], ['options_json', '$."base-material"'], ['options_json', '$.base_material'], ['options_json', '$.material']], 'suggest' => true, 'placeholder' => 'holochrome, chrome, standard...'],
    'finish' => ['label' => 'Finish', 'group' => 'Item JSON', 'type' => 'text', 'kind' => 'json_multi', 'operators' => $json, 'from' => 'order_items oi_finish', 'where' => 'oi_finish.order_id = o.id AND oi_finish.deleted_at IS NULL', 'paths' => [['internal_options_json', '$._print_finish'], ['options_json', '$."graphics-finish"'], ['options_json', '$.graphics_finish'], ['options_json', '$.finish']], 'suggest' => true],
    'grip' => ['label' => 'Grip', 'group' => 'Item JSON', 'type' => 'text', 'kind' => 'json_multi', 'operators' => $json, 'from' => 'order_items oi_grip', 'where' => 'oi_grip.order_id = o.id AND oi_grip.deleted_at IS NULL', 'paths' => [['internal_options_json', '$._print_grip'], ['options_json', '$.grip']], 'suggest' => true],
    'midfork' => ['label' => 'Midforks', 'group' => 'Item JSON', 'type' => 'text', 'kind' => 'json_multi', 'operators' => $json, 'from' => 'order_items oi_midfork', 'where' => 'oi_midfork.order_id = o.id AND oi_midfork.deleted_at IS NULL', 'paths' => [['options_json', '$."mid-forks"'], ['options_json', '$."mid-forks-color"'], ['options_json', '$."mid-forks-size"'], ['options_json', '$."4pcs-midfork-size"'], ['options_json', '$."4pcs-midfork-brand-logo"'], ['internal_options_json', '$._graphics_mid_forks_mid_forks_color'], ['internal_options_json', '$._graphics_mid_forks_mid_forks_size'], ['internal_options_json', '$._graphics_4_pcs_fork_stickers_4pcs_midfork_size'], ['internal_options_json', '$._graphics_4_pcs_fork_stickers_4pcs_midfork_brand_logo']], 'suggest' => true],
    'item_options_text' => ['label' => 'Customer options JSON', 'group' => 'Advanced JSON', 'type' => 'text', 'kind' => 'exists', 'from' => 'order_items oi_options', 'where' => 'oi_options.order_id = o.id AND oi_options.deleted_at IS NULL', 'expr' => 'oi_options.options_json', 'operators' => ['contains', 'is_not_empty'], 'placeholder' => 'raw options_json text'],
    'internal_options_text' => ['label' => 'Internal options JSON', 'group' => 'Advanced JSON', 'type' => 'text', 'kind' => 'exists', 'from' => 'order_items oi_internal', 'where' => 'oi_internal.order_id = o.id AND oi_internal.deleted_at IS NULL', 'expr' => 'oi_internal.internal_options_json', 'operators' => ['contains', 'is_not_empty'], 'placeholder' => 'raw internal_options_json text'],

    'invoice_number' => ['label' => 'Invoice number', 'group' => 'Related', 'type' => 'text', 'kind' => 'exists', 'from' => 'order_invoices oi_inv', 'where' => 'oi_inv.order_id = o.id AND oi_inv.deleted_at IS NULL', 'expr' => 'oi_inv.invoice_number', 'operators' => $text, 'suggest' => true],
    'tracking_number' => ['label' => 'Tracking number', 'group' => 'Related', 'type' => 'text', 'kind' => 'exists', 'from' => 'order_tracking_numbers otn', 'where' => 'otn.order_id = o.id AND otn.deleted_at IS NULL', 'expr' => 'otn.tracking_number', 'operators' => $text],
    'activity' => ['label' => 'Activity log', 'group' => 'Related', 'type' => 'text', 'kind' => 'exists', 'from' => 'order_activity oact', 'where' => 'oact.order_id = o.id', 'expr' => "CONCAT_WS(' ', oact.action, oact.note, oact.payload, oact.entity_type)", 'operators' => ['contains', 'is_not_empty']],
    'assigned_worker' => ['label' => 'Assigned worker', 'group' => 'Related', 'type' => 'text', 'kind' => 'assigned_worker', 'operators' => ['contains', 'equals'], 'suggest' => true],
  ];

  return $fields;
}

function orderSearchFieldHelpMap(): array
{
  return [
    'option_category_info' => [
      'help' => 'Bike/model fitment text imported from item options. Use this when looking for a brand, model, year range, or model code saved in the order item JSON.',
      'examples' => ['KTM | EXC', 'Yamaha | YZ125', '6DWJ'],
    ],
    'material' => [
      'help' => 'Graphics print material. This combines production material keys and older customer option aliases into one user-friendly filter.',
      'examples' => ['Standard', 'Chrome', 'Holochrome', 'Holo chrome'],
    ],
    'finish' => [
      'help' => 'Graphics finish/laminate selected by the customer or production team.',
      'examples' => ['Matte', 'Gloss', 'Bloom'],
    ],
    'grip' => [
      'help' => 'Grip option for graphics. Values can be simple yes/no style answers or production-specific grip labels.',
      'examples' => ['Yes', 'No', 'Black'],
    ],
    'midfork' => [
      'help' => 'Midfork and fork-sticker related options, including size, color, and brand-logo variants from older and newer JSON keys.',
      'examples' => ['mid-forks', 'mid-forks-color', '4pcs-midfork-size'],
    ],
    'source_meta' => [
      'help' => 'Raw order-level JSON imported from the source system. Use only when the friendly fields do not cover the value you need.',
      'sources' => ['orders.source_meta'],
      'examples' => ['customs_ddp_enabled', 'financial_breakdown', '_followup'],
    ],
    'item_options_text' => [
      'help' => 'Raw customer-facing item options JSON. This searches the whole JSON text, so it is useful for unknown option keys or one-off imported values.',
      'sources' => ['order_items.options_json'],
      'examples' => ['name-color', 'base-material', 'note'],
    ],
    'internal_options_text' => [
      'help' => 'Raw internal production options JSON. This searches the whole JSON text, including internal notes and normalized production keys.',
      'sources' => ['order_items.internal_options_json'],
      'examples' => ['_print_material', '_graphics_buyer_note', '_plastics_my_item_note'],
    ],
  ];
}

function orderSearchPublicFieldSources(array $field): array
{
  if (!empty($field['paths']) && is_array($field['paths'])) {
    $sources = [];
    foreach ($field['paths'] as $pathDef) {
      if (is_array($pathDef) && count($pathDef) === 2) {
        $sources[] = (string) $pathDef[0] . ' -> ' . (string) $pathDef[1];
      }
    }
    return $sources;
  }

  if (!empty($field['from']) && !empty($field['expr'])) {
    return [(string) $field['from'] . ' -> ' . (string) $field['expr']];
  }

  if (!empty($field['expr'])) {
    return [(string) $field['expr']];
  }

  return [];
}
function orderSearchPublicFields(): array
{
  $out = [];
  $helpMap = orderSearchFieldHelpMap();
  foreach (orderSearchFieldRegistry() as $key => $field) {
    $help = $helpMap[$key] ?? [];
    $out[] = [
      'key' => $key,
      'label' => (string) $field['label'],
      'group' => (string) $field['group'],
      'type' => (string) $field['type'],
      'operators' => array_values($field['operators']),
      'placeholder' => (string) ($field['placeholder'] ?? ''),
      'suggest' => !empty($field['suggest']),
      'help' => (string) ($help['help'] ?? ''),
      'sources' => array_values($help['sources'] ?? orderSearchPublicFieldSources($field)),
      'examples' => array_values($help['examples'] ?? []),
      'advanced' => ((string) ($field['group'] ?? '') === 'Advanced JSON'),
    ];
  }
  return $out;
}