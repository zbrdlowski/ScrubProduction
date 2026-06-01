# ScrubProduction - ARCHITECTURE.md

## Vysoká architektúra
ScrubProduction je klasická PHP/MySQL aplikácia založená na AdminLTE šablóne. Nemá framework typu Laravel/Symfony. Hlavná architektúra je:

```text
index.php
  -> includes/conn.php
  -> includes/navbar.php
  -> includes/sidebar.php
  -> includes/{page}.php

scripts/
  -> AJAX endpointy
  -> importné knižnice
  -> servisné PHP skripty
```

## Request lifecycle
1. Používateľ otvorí `index.php?page=orders` alebo inú stránku.
2. `index.php` spustí session a overí `$_SESSION['permission']`.
3. Načíta sa `includes/conn.php`.
4. Vykreslí sa hlavný layout AdminLTE.
5. Podľa `$_GET['page']` sa includne `includes/{page}.php`.
6. Stránka môže volať AJAX endpointy v `scripts/`.

## Hlavný layout
- `index.php` definuje titulok stránky cez `$pageLabels`.
- `includes/navbar.php` vykresľuje horný panel.
- `includes/sidebar.php` vykresľuje menu a rieši aktívne položky.
- Obsah stránky je v `div.content-wrapper`.

## Databázová vrstva
`includes/conn.php` vytvára:
- `$pdo` cez PDO,
- `$conn` cez mysqli.

Obe premenné používajú databázu `scrubproduction`.

Pozor: projekt mieša PDO a mysqli. Väčšina existujúcich modulov používa `$conn`. Pri novom kóde je vhodné:
- neporušiť existujúci štýl daného súboru,
- preferovať prepared statements,
- pri väčších refaktoroch zjednotiť prístup v rámci konkrétneho modulu.

## Autentifikácia a session
Login je cez `login.php`, ktorý používa tabuľku `employees`. Po úspešnom prihlásení sa ukladajú používateľské údaje do session.

Dôležité session hodnoty:
- `user_id`
- `permission`
- `dpt`
- `name`
- `username`
- `dpt_name`
- `personal_orders`
- `grid`

## Permission / ACL
Aplikácia kombinuje dva typy oprávnení:
1. globálny permission level (`permission`),
2. department (`dpt`).

Globálne pravidlá:
- `permission > 300` vidí Admin menu.
- `permission >= 500` je admin pre citlivé operácie ako importy.
- Stock management je dostupný adminom alebo `dpt = 6`.

Orders ACL:
- all-access departments: Admin, Management, Production, Customer Service, WEB-IT.
- Graphics vidí GRAPHICS.
- Plastics vidí PLASTICS.
- Seat Covers vidí SEATCOVER.
- Fitting pracuje najmä s typom `F` / fitting položkami.

## Orders modul
Orders je centrálny modul pre výrobné objednávky.

Hlavné entity:
- `orders`
- `order_items`
- `customers`
- `order_addresses`
- `order_assignments`
- `order_activity`
- `shipments`
- `invoices`
- `order_sources`
- `categories`
- `order_categories`
- `order_item_categories`

Stavy objednávok používané v UI:
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

Zdroje objednávok:
- `EBAY`
- `SHOPTET`
- `MX_LOCKER`
- `SO`
- novší jednotný import používa aj `DARKSCRUB` / `DARKSCRUB_IMPORT.csv`.

Kategórie:
- `GRAPHICS`
- `PLASTICS`
- `SEATCOVER`
- `FITTING`

Item type kódy:
- `G` Graphics
- `T` Trim Kit
- `M` Bike Mats
- `P` Plastics
- `S` Seatcover
- `F` Fitting

Špeciálne pravidlo:
- `T` a `M` súvisia s plastics aj graphics workflow, preto pri filtrovaní a viditeľnosti treba dávať pozor, aby ich videli správne oddelenia.

## Stock / warehouse modul
Stock Management obsahuje:
- dashboardy a reporty,
- inventár a položky,
- scan in/out,
- reset lokácie,
- relokáciu položiek,
- CSV upload,
- quick search,
- movements a archived movements.

Menu položky indikujú stránky:
- `plastics_dashboard`
- `stock_movements`
- `historical_movements`
- `inventory_report`
- `display_stock`
- `items`
- `add_item`
- `upload_items`
- `shelves`
- `search_item`
- `scan_form_out`
- `bulk_scan_in`
- `reset_location`
- `relocate_item`
- `bulk_scan_in_2`
- `upload_csv`

## HR / personalistika
HR časť obsahuje:
- Employees,
- Attendance / calendar,
- Profile,
- permission a department informácie cez tabuľky `employees` a `position`.

## Importy
Import objednávok je cez `includes/import_orders.php`, ktorý uploaduje `DARKSCRUB_IMPORT.csv` na endpoint `scripts/upload_import_orders.php`.

Import flow:
1. používateľ vyberie CSV,
2. JS pošle `FormData` na `scripts/upload_import_orders.php`,
3. endpoint overí `permission >= 500`,
4. uloží súbor do `uploads/imports`,
5. spustí import v transakcii,
6. vráti JSON štatistiku.

## Odporúčaná štruktúra dokumentácie
```text
docs/
  PROJECT_CONTEXT.md
  ARCHITECTURE.md
  DATABASE.md
  PERMISSIONS.md
  DEPLOYMENT.md
  modules/
    orders.md
    stock.md
    hr.md
    imports.md
```

## Riziká / technický dlh
- Dynamický include podľa `$_GET['page']` treba chrániť whitelistom.
- Login aktuálne skladá SQL reťazec z inputu; treba prejsť na prepared statements.
- Heslá cez MySQL `PASSWORD()` nie sú vhodné pre moderné aplikácie; odporúčané je `password_hash()` / `password_verify()`.
- DB credentials sú priamo v `includes/conn.php`; vhodné je presunúť ich do lokálneho configu mimo repozitára.
- Mix PDO/mysqli zvyšuje komplexitu.
- Inline JS/PHP/HTML je praktické, ale pri raste projektu bude vhodné oddeľovať logiku.
