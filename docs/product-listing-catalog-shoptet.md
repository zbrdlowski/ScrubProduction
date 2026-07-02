# Product Listing Catalog: Shoptet XLSX Conversion

Tento prevodnik vezme surovy Shoptet `.xlsx` export a pripravi CSV pre importer modulu `Product Listings`.

## Script

`scripts/product_listing_catalog/convert_shoptet_xlsx.ps1`

## Vstup

Skript ocakava Shoptet export, kde su dostupne aspon tieto stlpce:

- `code`
- `name`
- `itemType`
- `externalCode` (volitelne)
- `categoryText`, `categoryText2`, `categoryText3`, ...

Model code sa vybera zo zatvoriek v `categoryText*`, napr.:

`Shop by bike > Honda > CRF150F > 2008-2014 (7P4W) > Graphics Kit`

Z toho sa vyberie `7P4W`.

## Prefix map

Aktualne je v skripte nastavene:

- `G_` => `design`
- `GFP_` => `design`
- `S_` => `seatcover`

Ostatne prefixy sa standardne preskocia.

## Pouzitie

```powershell
powershell -ExecutionPolicy Bypass -File .\scripts\product_listing_catalog\convert_shoptet_xlsx.ps1 `
  -InputPath "C:\Users\zbrdl\OneDrive\Desktop\products.xlsx"
```

Vystup sa standardne ulozi vedla inputu ako:

- `products_product_listing_catalog.csv`
- `products_product_listing_catalog.skipped.csv`

## CSV vystup

Skript generuje tieto stlpce:

- `product_type`
- `product_code`
- `product_name`
- `model_code`
- `marketplace`
- `external_code`
- `external_url`
- `listing_title`
- `is_active`

Marketplace je momentalne vzdy `shoptet`.

## Poznamky

- Jeden Shoptet produkt moze vyprodukovat viac CSV riadkov, ak ma viac `categoryText*` s roznymi modelmi.
- Duplicity `product_code + model_code + marketplace` sa automaticky odfiltruju.
- Ak Shoptet export neobsahuje priamy produktovy URL stlpec, `external_url` ostane prazdny.
