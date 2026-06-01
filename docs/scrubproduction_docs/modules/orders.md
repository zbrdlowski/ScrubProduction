# Module: Orders

## Účel
Centrálna evidencia objednávok a výrobných položiek pre Scrubdesignz.

## Hlavný súbor
- `includes/orders.php`

## Súvisiace súbory
- `includes/orders_dashboard.php`
- `includes/import_orders.php`
- `scripts/upload_import_orders.php`
- `scripts/order_import_lib.php`
- `scripts/import_darkscrub_unified.php`
- pravdepodobne ďalšie `scripts/orders/...` endpointy pre detail, assignmenty a workflow.

## Filtre v Orders UI
Používané GET filtre:
- `dept`
- `cat`
- `type`
- `q`
- `status`
- `source`
- `exclude_status`
- `country`
- `payment`
- `shipping`
- `priority`
- `date_from`
- `date_to`
- `worker`
- `print_printer`
- `print_material`
- `print_finish`

## Default správanie
Ak nie sú nastavené filtre, modul používa default `exclude_status=PENDING,SHIPPED`, teda Open Orders.

## Stavy
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

## Zdroje
- `EBAY`
- `SHOPTET`
- `MX_LOCKER`
- `SO`
- `DARKSCRUB` / unified CSV import

## Item typy
- `G` Graphics
- `T` Trim Kit
- `M` Bike Mats
- `P` Plastics
- `S` Seatcover
- `F` Fitting

## Kategórie
- `GRAPHICS`
- `PLASTICS`
- `SEATCOVER`
- `FITTING`

## Department visibility
- Graphics: `GRAPHICS`
- Plastics: `PLASTICS`
- Seat Covers: `SEATCOVER`
- Fitting: item type `F` alebo fitting indikátory v SKU/custom label.
- Admin/Management/Production/Customer Service/WEB-IT: all access.

## Print settings
Orders modul číta print nastavenia z JSON polí:
- `order_items.internal_options_json`
- `order_items.options_json`

Používané JSON keys:
- `_printer`
- `_print_material`
- `_print_finish`
- `base-material`
- `graphics-finish`

## Odporúčané ďalšie kroky
- Vytvoriť `scripts/orders/` ako jasný namespace pre endpointy.
- Zdokumentovať workflow status transitions.
- Doplniť ERD pre orders tabuľky.
- Zaviesť jednotný helper pre filters parsing.
