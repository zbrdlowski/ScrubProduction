# OMEGA invoice import

## Installation

1. Run `db/accounting_omega.sql` against the `scrubproduction` database.
2. Open **Accounting → OMEGA Invoices** in Darkscrub.
3. Upload the original OMEGA T01 export (`.txt` or `.tsv`).

The importer accepts UTF-8 and Windows-1250 files up to 30 MB. Uploaded files
are parsed directly from PHP's temporary upload directory and are not retained.
If the web server has a lower `upload_max_filesize` or `post_max_size`, raise
those PHP limits above the size of the OMEGA export.

Large files can also be imported from the project directory without an HTTP
upload:

```sh
php scripts/accounting/import_omega.php db/fakturacia2026.txt
```

## R01 invoice mapping

The importer uses the same fixed columns as the former Google Sheet:

| OMEGA column | Stored value |
| --- | --- |
| B | Invoice number |
| E | Invoice issue date |
| AH | Order number |
| AL | Payment type |
| AQ | Invoice total |

It additionally stores the customer, document type and currency. An invoice is
matched by order number against both production orders and custom orders.

OMEGA exports can contain the same order number on more than one invoice, for
example a deposit and a final invoice. Darkscrub therefore stores invoices as a
one-to-many relation instead of reproducing the spreadsheet's first-match
`XLOOKUP` behaviour.

## R02 invoice items

Every R02 row following an R01 row is stored as an item of that invoice. The
description, quantity, unit and unit price without VAT are normalized; the full
source row is also kept as JSON for audit and future classification.

R02 rows are not required for the current invoice/order matching, but keeping
them makes later reporting by product, shipping, fitting, design or deposit
possible without re-importing old exports.

## Re-import behaviour

Repeated and overlapping cumulative exports are safe:

- an identical file hash is skipped;
- invoice number is the stable unique key;
- new invoices are inserted;
- changed invoices and their R02 items are updated;
- unchanged invoices are counted but not duplicated.
