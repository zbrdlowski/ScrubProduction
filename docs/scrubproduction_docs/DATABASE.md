# ScrubProduction - DATABASE.md

> Tento dokument je štartovací návrh. Najpresnejšiu verziu treba vygenerovať priamo z MySQL schémy cez `SHOW CREATE TABLE` alebo export z phpMyAdmin.

## Databáza
Názov DB podľa `includes/conn.php`:

```text
scrubproduction
```

## Známe hlavné tabuľky

### Users / HR
- `employees` - používatelia/zamestnanci, login údaje, permission, department/position, profilové nastavenia.
- `position` - oddelenia / pracovné pozície; používa sa na `dpt_name`.
- `attdn_YYYY` - ročné dochádzkové tabuľky, napr. `attdn_2026`.

### Orders
- `orders` - hlavička objednávky.
- `order_items` - položky objednávky, obsahuje `item_type_code`, SKU, options JSON, internal options JSON.
- `customers` - zákazníci.
- `order_addresses` - fakturačné/dodacie adresy.
- `order_assignments` - priradenie objednávok/položiek zamestnancom.
- `order_activity` - história a audit udalostí objednávky.
- `shipments` - zásielky.
- `invoices` - faktúry.
- `order_sources` - zdroje objednávok.
- `categories` - kategórie.
- `order_categories` - väzba objednávka-kategória.
- `order_item_categories` - väzba položka-kategória.

### Stock / inventory
Presné názvy treba doplniť zo schémy. Podľa UI projekt obsahuje logiku pre:
- skladové položky,
- police/lokácie,
- stock movements,
- archived movements,
- CSV upload inventára,
- scan in/out operácie.

## Orders: dôležité polia podľa kódu

### `orders`
Používané alebo očakávané polia:
- `id`
- `status`
- `source`
- `external_order_id`
- dátumové polia pre filtrovanie,
- shipping/payment informácie,
- priority.

### `order_items`
Používané polia:
- `id`
- `order_id`
- `item_type_code`
- `sku`
- `custom_label`
- `options_json`
- `internal_options_json`
- `deleted_at`

Použitie JSON polí:
- `internal_options_json._printer`
- `internal_options_json._print_material`
- `internal_options_json._print_finish`
- `options_json.base-material`
- `options_json.graphics-finish`

### `order_assignments`
Používané polia:
- `order_id`
- `employee_id`
- `removed_at`

## Item type codes
- `G` - Graphics
- `T` - Trim Kit
- `M` - Bike Mats
- `P` - Plastics
- `S` - Seatcover
- `F` - Fitting

## Order statuses
- `NEW`
- `IN_PROGRESS`
- `NEED_INFO`
- `DRAFT_READY`
- `READY_TO_INVOICE`
- `READY_TO_SHIP`
- `SHIPPED`
- `DONE`
- `HOLD`
- `CANCELLED`
- `PENDING`

## Order sources
- `EBAY`
- `SHOPTET`
- `MX_LOCKER`
- `SO`
- `DARKSCRUB` / `DARKSCRUB_UNIFIED` pri novšom importe

## Odporúčaný export pre AI
Pri ďalšej dokumentácii skopírovať z MySQL:

```sql
SHOW CREATE TABLE orders;
SHOW CREATE TABLE order_items;
SHOW CREATE TABLE customers;
SHOW CREATE TABLE order_addresses;
SHOW CREATE TABLE order_assignments;
SHOW CREATE TABLE order_activity;
SHOW CREATE TABLE shipments;
SHOW CREATE TABLE invoices;
SHOW CREATE TABLE employees;
SHOW CREATE TABLE position;
```

AI potom vie presne doplniť vzťahy, indexy, foreign keys a chýbajúce polia.
