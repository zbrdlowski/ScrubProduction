# Dead Code Audit - 2026-06-14

Audit scope:
- `includes/**/*.php`
- `scripts/**/*.php`

Method:
- static scan of references across `.php`, `.js`, `.html`, `.htm`
- routed page detection via `index.php?page=...`
- direct path/name reference detection
- conservative classification: if a file had any plausible reference, it was treated as used

Important limitation:
- this is a static audit, not runtime tracing
- PHP projects can load files dynamically, so `suspicious_no_reference` means "no static reference found", not "safe to delete immediately"

## Summary

- Total audited PHP files: `262`
- `includes`: `129`
- `scripts`: `133`
- Includes reachable as page modules: `57`
- `likely_used`: `204`
- `suspicious_no_reference`: `40`
- `archive_or_legacy_copy`: `18`

## High-confidence archive / legacy copies

These look like backups, old revisions, or alternate copies and should be reviewed first for cleanup:

- `includes/backup/bulk_scan_in.php`
- `includes/backup/bulk_scan_in_redesign.php`
- `includes/backup/index.php`
- `includes/backup/items.php`
- `includes/backup/kit_diss.php`
- `includes/backup/order_prepare.php`
- `includes/backup/order_prepare_form.php`
- `includes/backup/scan_in.php`
- `includes/backup/scan_in_before_items_update.php`
- `includes/backup/scan_in_old.php`
- `includes/items_bckp.php`
- `includes/items-1.php`
- `includes/items-ai.php`
- `includes/kit_diss_1.php`
- `includes/kit_diss_old.php`
- `includes/kit_diss-1.php`
- `includes/orders_old.php`
- `includes/plastics_dashboard_1.php`

## Suspicious Includes

No static reference was found for these include files:

- `includes/calendar_default.php`
- `includes/calendar_no_user.php`
- `includes/calendar_user.php`
- `includes/controlls_product_specs.php`
- `includes/edit_item.php`
- `includes/employee_delete.php`
- `includes/fetch_data.php`
- `includes/items_bckp_1.php`
- `includes/items_table_renderer.php`
- `includes/modeldata_edit.php`
- `includes/permissions.php`
- `includes/plastics_orders.php`
- `includes/plastics_stock.php`
- `includes/position_modal.php`
- `includes/pprofile.php`
- `includes/profile_detail_modal.php`
- `includes/qr.php`
- `includes/qr_scanner.php`
- `includes/quick_search.php`
- `includes/real_data.php`
- `includes/receiving_summary.php`
- `includes/settings.php`
- `includes/shelf_utilization.php`
- `includes/schedule_modal.php`
- `includes/sidebar_1.php`
- `includes/upload_csv_module.php`

## Suspicious Scripts

No static reference was found for these script endpoints/helpers:

- `scripts/archive_data.php`
- `scripts/get_barcodes.php`
- `scripts/get_last_movements.php`
- `scripts/chat/get_unread_count.php`
- `scripts/chat/get_unread_thread.php`
- `scripts/import_ebay.php`
- `scripts/import_mxlocker.php`
- `scripts/import_shoptet.php`
- `scripts/inline_edit_template.php`
- `scripts/multi_table_csv_upload.php`
- `scripts/orders/get_order_traffic.php`
- `scripts/receive_supply_upload.php`
- `scripts/save_shelf_layout.php`
- `scripts/update_stock_levels.php`

## Notes

- Some `includes` are definitely page modules because they are linked from the sidebar/navbar through `index.php?page=...`.
- Some backup files share the same basename as live page files. Those were intentionally left in `archive_or_legacy_copy`.
- The three old importers:
  - `scripts/import_ebay.php`
  - `scripts/import_mxlocker.php`
  - `scripts/import_shoptet.php`
  look unused in the current UI flow, because the active import now goes through `scripts/upload_import_orders.php` and `scripts/import_darkscrub_unified.php`.
- `scripts/orders/get_order_traffic.php` is suspicious and worth checking manually, because it sounds like a helper that may have been used by dashboard/status widgets in the past.

## Recommended next cleanup order

1. Review and archive/delete `archive_or_legacy_copy` files.
2. Review the `Suspicious Scripts` list.
3. Review the `Suspicious Includes` list, especially modal/helper fragments.
4. Only after manual confirmation, delete files in small batches.

Raw machine-readable scan:
- `tmp/dead_audit_refs2.json`
