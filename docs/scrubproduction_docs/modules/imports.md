# Module: Imports

## Účel
Import objednávok a dát zo súborov CSV.

## Aktuálny jednotný import objednávok
UI:
- `includes/import_orders.php`

Endpoint:
- `scripts/upload_import_orders.php`

Knižnice:
- `scripts/order_import_lib.php`
- `scripts/import_darkscrub_unified.php`

## Import flow
1. Používateľ otvorí stránku Import Orders.
2. Vyberie alebo pretiahne `DARKSCRUB_IMPORT.csv`.
3. Frontend pošle `FormData` na `scripts/upload_import_orders.php`.
4. Endpoint overí `permission >= 500`.
5. Súbor uloží do `uploads/imports`.
6. Spustí transakciu.
7. Zavolá `import_darkscrub_unified_csv($conn, $dest)`.
8. Commit alebo rollback.
9. Vráti JSON so štatistikou:
   - `orders`
   - `created`
   - `updated`
   - `items`
   - `skipped_shipping_items`

## Import pravidlá
- Import funguje ako add/update podľa `source + external_order_id`.
- Ak je súbor nevalidný, endpoint vráti JSON error.
- Povolené sú iba `.csv` súbory.

## Staršie/ďalšie importy
Projekt podľa kontextu používal alebo používa importy z:
- eBay
- Shoptet
- MX Locker
- jednotný DARKSCRUB / Google Sheets CSV

## Odporúčania
- Zdokumentovať presný CSV formát v `docs/import_formats/DARKSCRUB_IMPORT.md`.
- Pridať ukážkový anonymizovaný CSV súbor.
- Validovať povinné stĺpce pred importom.
- Logovať každý import do tabuľky `import_runs`.
