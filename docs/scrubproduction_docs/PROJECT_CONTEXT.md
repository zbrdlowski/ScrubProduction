# ScrubProduction - PROJECT_CONTEXT.md

## Účel projektu
ScrubProduction je interný informačný systém pre Scrubdesignz. Slúži ako evidencia a riadenie objednávok, výrobných úloh, skladového hospodárstva, zamestnancov, dochádzky a pomocných firemných procesov.

Tento dokument je určený ako krátky kontext pre Claude, Codex alebo ChatGPT pri ďalšej práci na projekte.

## Technológie
- Backend: PHP 8.x štýl, primárne klasické PHP súbory bez frameworku.
- Databáza: MySQL / MariaDB.
- Pripojenie k DB: `includes/conn.php` vytvára súčasne `$pdo` aj `$conn`.
- Frontend: AdminLTE 3.x, Bootstrap, jQuery, Font Awesome.
- Layout: hlavný entrypoint je `index.php`.
- Stránky sa načítavajú cez `index.php?page=...` a include mechanizmus `includes/{page}.php`.
- AJAX/API skripty sú najmä v adresári `scripts/`.
- Uploady/importy idú cez `uploads/` a špecializované import skripty.

## Lokálne a produkčné prostredie
- Vývoj doma prebieha lokálne, typicky cez XAMPP.
- Nasadenie v práci beží na Synology NAS.
- Pri úpravách treba rátať s rozdielmi medzi XAMPP a Synology NAS: PHP verzia, práva k súborom, session path, case-sensitive cesty, upload limity, timeouts.

## Session a autentifikácia
- `index.php` nastavuje session lifetime na 7 dní.
- Ak `$_SESSION['permission']` nie je nastavené, používateľ je presmerovaný na `login.php`.
- `login.php` prihlasuje cez tabuľku `employees` a ukladá do session minimálne:
  - `admin`
  - `user_id`
  - `permission`
  - `dpt`
  - `user_photo`
  - `name`
  - `username`
  - `personal_orders`
  - `grid`
  - `dpt_name`

## Permission model
Aktuálne používané role:
- `1` - bežný používateľ
- `400` - moderátor
- `500` - admin

Praktické pravidlá:
- Admin menu sa zobrazuje pri `permission > 300`.
- Niektoré importy a systémové operácie vyžadujú `permission >= 500`.
- Stock Management je viditeľný pre adminov alebo plastics department.

## Departments / dpt
Používané oddelenia:
- `1` Admin
- `2` Graphics
- `3` Management
- `4` Production
- `5` Customer Service
- `6` Plastics
- `7` WEB-IT
- `8` Seat Covers
- `9` Fitting

Logika objednávok rozlišuje prístup podľa oddelenia. Niektoré oddelenia vidia len relevantné kategórie alebo item typy.

## Hlavné moduly
- Orders: centrálna evidencia objednávok, workflow, filtre, assignmenty, importy.
- Stock Management: sklad, police, scan in/out, presuny, inventúra.
- Plastics Orders: príprava, odoslanie, prijatie a história plastových objednávok.
- Employees / HR: zamestnanci, dochádzka, profil používateľa.
- Imports: CSV importy objednávok a skladových dát.
- Maintenance: zálohy, logy, cleanup.
- Projects: interné projekty.

## Navigácia
Hlavný sidebar je v `includes/sidebar.php`. Nové stránky sa typicky pridávajú ako:
1. nový súbor `includes/nazov_stranky.php`,
2. položka do `$pageLabels` v `index.php`,
3. položka do menu v `includes/sidebar.php`,
4. voliteľne AJAX endpoint v `scripts/...`.

## Coding conventions
- Projekt používa kombináciu procedurálneho PHP, HTML a inline JS.
- Existuje mix `mysqli` a `PDO`; nové časti je dobré robiť konzistentne, ale treba rešpektovať existujúci kód.
- Pri novom kóde preferuj prepared statements.
- Pri AJAX endpointoch vždy vracaj JSON s `Content-Type: application/json; charset=utf-8`.
- Pri interných stránkach kontroluj session/permission.
- Pri výstupoch do HTML používaj `htmlspecialchars()`.

## Dôležité pravidlo pre AI
Pri úpravách nikdy neprepísať celý modul naslepo. Najprv identifikovať konkrétne súbory, zachovať existujúci štýl a pripraviť patch po malých krokoch.
