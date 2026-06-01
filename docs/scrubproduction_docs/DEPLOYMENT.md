# ScrubProduction - DEPLOYMENT.md

## Prostredia

### Local development
- XAMPP
- MySQL/MariaDB lokálne
- Windows filesystem typicky case-insensitive

### Work / production-like environment
- Synology NAS
- PHP cez web server na NAS
- MySQL/MariaDB podľa konfigurácie
- filesystem a práva môžu byť prísnejšie než na XAMPP

## Dôležité rozdiely XAMPP vs Synology
Pri prenose z domu do práce kontrolovať:

1. PHP verzia
2. MySQL/MariaDB verzia
3. `short_open_tag` / použitie `<?` namiesto `<?php`
4. práva k adresárom:
   - `uploads/`
   - `uploads/imports/`
   - session adresár
5. upload limity:
   - `upload_max_filesize`
   - `post_max_size`
6. timeouts:
   - `max_execution_time`
   - reverse proxy timeout, ak existuje
7. case sensitivity ciest
8. timezone
9. charset databázy `utf8mb4`

## Session
`index.php` nastavuje lifetime session cookie na 7 dní. Na Synology môže byť potrebné nastaviť vlastný session save path s právami zápisu.

## Odporúčaná konfigurácia
Vytvoriť súbor mimo Gitu alebo ignorovaný Gitom:

```php
// config/local.php
return [
  'db_host' => 'localhost',
  'db_name' => 'scrubproduction',
  'db_user' => '...',
  'db_pass' => '...',
  'env' => 'local',
];
```

`includes/conn.php` by potom čítal config podľa prostredia.

## Pred deploy checklist
- Export/import DB schémy hotový.
- Skontrolované DB credentials.
- Skontrolované práva pre `uploads/imports`.
- Skontrolované, že všetky nové súbory majú správny case v názve.
- Otestovaný login.
- Otestovaný Orders list.
- Otestovaný jeden import CSV.
- Otestované scan in/out.
- Otestovaná dochádzka/profil.
