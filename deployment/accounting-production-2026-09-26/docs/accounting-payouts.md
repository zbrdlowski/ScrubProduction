# eBay payout accounting module

## Installation

1. Run `db/accounting_payouts.sql` against the `scrubproduction` database.
2. Open **Accounting** in Darkscrub.
3. Upload the original UK and/or DE eBay transaction-report CSV files.

The importer reads the eBay preamble, detects English/German layout, comma or
semicolon delimiter and UTF-8/Windows-1252 encoding. Uploaded files are parsed
directly from PHP's temporary upload location and are not copied into the
project. The database stores normalized transaction columns and the original
row as JSON for audit.

Re-importing the same file or an overlapping report is safe. File hashes and
transaction source keys prevent duplicate accounting rows.

## Vycuc export

The **Export Vycuc** button creates a semicolon-separated UTF-8 CSV for the
selected month. It contains only `ORDER` / `Bestellung` rows and aggregates
multiple transaction rows with the same eBay order number.

Refunds and other fees remain available in the accounting view but are not
mixed into the invoice export.

## Temporary import cleanup

The payout importer itself leaves no uploaded file behind. The existing order
importer archives every uploaded `DARKSCRUB_IMPORT.csv` in `uploads/imports`.
Preview removal of archives older than 30 days with:

```sh
php scripts/maintenance/cleanup_import_files.php --days=30
```

After reviewing the dry-run output, a daily cron job can perform deletion:

```cron
20 3 * * * cd /path/to/darkscrub && php scripts/maintenance/cleanup_import_files.php --days=30 --delete >> logs/import-cleanup.log 2>&1
```

The cleanup script refuses directories outside the Darkscrub project and only
deletes known import-file extensions. Do not point it at the project root or a
general-purpose uploads directory.
