# Pilot data reset

The reset is deliberately CLI-only and runs as a dry run unless both the execution flag and the exact confirmation token are supplied.

## Normal orders

Preview:

```powershell
php scripts/pilot_reset.php --scope=orders
```

Execute after taking a database backup:

```powershell
php scripts/pilot_reset.php --scope=orders --execute --confirm=RESET-PILOT-ORDERS --delete-files
```

This removes normal order headers, items, addresses, customers, assignments, statuses, history, invoices, shipments, tracking records, production notes, and normal-order photo records. With `--delete-files`, numeric directories below `uploads/order_photos` are removed. Directories named `custom-<id>` are preserved.

Order sources, categories, status definitions, workflow settings, employees, catalog data, stock, and warehouse ledgers are preserved. The script reports warehouse ledger rows whose order number matches an order, but does not delete them or recalculate stock.

When normal orders are removed while Custom Orders are preserved, only their dangling export links (`production_order_id` and `production_photo_id`) are cleared. Custom Order contents remain intact.

## Custom Orders (do not run for the initial pilot cleanup)

Preview:

```powershell
php scripts/pilot_reset.php --scope=custom-orders
```

Execute:

```powershell
php scripts/pilot_reset.php --scope=custom-orders --execute --confirm=RESET-CUSTOM-ORDERS --delete-files
```

The official-number sequences are preserved by default. Resetting them can reuse business order numbers, so only do it intentionally:

```powershell
php scripts/pilot_reset.php --scope=custom-orders --execute --confirm=RESET-CUSTOM-ORDERS --delete-files --reset-custom-sequences
```

## Safety notes

- The script refuses to run through a web request.
- It refuses any database other than `scrubproduction`.
- Database deletion is transactional and rolls back on failure.
- File deletion is restricted to direct child directories matching `<number>` or `custom-<number>` under `uploads/order_photos`.
- Take and verify a fresh backup before every execution.

## Sanitizing repository SQL dumps

Historical SQL copies may still contain normal-order personal data even after the live database is reset. Preview and sanitize them with:

```powershell
php scripts/sanitize_order_sql_dumps.php
php scripts/sanitize_order_sql_dumps.php --execute --confirm=SANITIZE-ORDER-DUMPS
```

The sanitizer removes only normal-order/customer `INSERT` statements. It retains table schemas, configuration data, warehouse ledgers, and all `custom_order*` data.
