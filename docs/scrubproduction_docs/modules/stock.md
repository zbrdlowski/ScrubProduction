# Module: Stock / Warehouse

## Účel
Skladové hospodárstvo, najmä pre Plastics: skladové položky, police, scan in/out, presuny, inventúra a reporty.

## Menu stránky
Podľa sidebaru existujú tieto časti:

### Dashboards & Reports
- `plastics_dashboard`
- `stock_movements`
- `historical_movements`
- `inventory_report`
- `display_stock`

### Inventory & Items
- `items`
- `add_item`
- `upload_items`
- `shelves`

### Stock Operations
- `search_item`
- `scan_form_out`
- `bulk_scan_in`
- `reset_location`
- `relocate_item`
- `bulk_scan_in_2`
- `upload_csv`

### Orders Section
- `order_prepare`
- `plastics_orders_active`
- `receive_supply`
- `plastics_orders_sent`
- `plastics_orders_all`
- `kit_diss`
- `intake_print`

## ACL
Stock Management je viditeľný pre:
- adminov `permission >= 500`, alebo
- plastics department `dpt == 6`.

## Odporúčané pravidlá pre úpravy
- Pri scan operáciách vždy používať transakciu.
- Každý pohyb zapisovať do movement/history tabuľky.
- Nerozmazávať rozdiel medzi aktuálnym stavom a históriou pohybov.
- Pri CSV uploade vždy validovať hlavičky a robiť import v transakcii.
