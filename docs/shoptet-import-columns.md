# Shoptet → Darkscrub: Apps Script a katalóg stĺpcov

Upravený skript: [darkscrub_import_spec.js](../scripts/google_apps_script/darkscrub_import_spec.js).
Je určený na vloženie do existujúceho Google Apps Script projektu pri tabuľke.
Výstup zostáva v hárku `DARKSCRUB_IMPORT` s pôvodnými 64 stĺpcami.

## Čo ukázala kontrola

Priložený pôvodný skript už čítal stĺpce podľa názvov: `readObjectsFromSheet_()`
zostavil objekt z prvého riadka a mapovanie používalo napríklad `val_(r, 'itemCode')`.
Číselný index pri párovaní hlavičky s jej bunkou je v poriadku; nie je to pevná pozícia
poľa v exporte. Aj pôvodný skript v lokálnom teste správne spracoval dodané surové CSV
so všetkými 105 stĺpcami. Samotné pridanie dvoch stĺpcov sa ako príčina chyby nepotvrdilo.

Príčinou môže byť vloženie dát pod starý prvý riadok alebo iná verzia skriptu v Google Sheets.
Živá tabuľka a nasadená verzia neboli k dispozícii, preto presnú príčinu nemožno potvrdiť.
Ak hlavička zostane stará, skript nevie spoľahlivo určiť, čo znamenajú posunuté hodnoty.
Nové kontroly zachytia chýbajúce či duplicitné hlavičky a vyplnené bunky bez hlavičky.
Neodhalia každý prípad starej hlavičky, ak má rovnaký počet stĺpcov a platné názvy.

## Nasadenie a každodenné použitie

1. V existujúcom Apps Script projekte nahraď obsah pôvodného skriptu celým obsahom
   nového súboru a ulož ho. Nepridávaj druhú kópiu rovnakých funkcií vedľa pôvodnej.
2. Pri každom novom exporte nahraď celý obsah hárka `SHOPTET_RAW`
   surovým CSV od bunky A1, **vrátane jeho aktuálneho prvého riadka s názvami stĺpcov**.
   Odstráň staré dáta v tomto hárku aj za hranicami nového exportu, aby nezostali staré riadky
   alebo hlavičky. Nemaž `itemHsCode` ani `graphics-parts-versions`.
3. Zabezpeč, aby sa CSV rozdelilo do samostatných stĺpcov. Dodaná vzorka používa čiarku
   ako oddeľovač, úvodzovky na ohraničenie textu a desatinnú čiarku vo vnútri hodnôt.
   Čísla objednávok, telefóny, PSČ a kódy položiek importuj ako text, aby Sheets nezmenil
   ich obsah. Skript číta zobrazené hodnoty a nedokáže obnoviť nuly stratené pri importe.
4. Zachovaj existujúci hárok `COUNTRIES` s mapovaním krajín.
   Obnov tabuľku, ak sa menu nezobrazuje, a spusti
   `Scrub Scripts → Generate unified import`.
5. Exportuj hárok `DARKSCRUB_IMPORT` ako CSV obvyklým spôsobom.
   Skript stále spracúva aj údaje v `EBAY_RAW` a `MX_RAW`,
   ak sú tieto hárky prítomné a obsahujú dáta.

## Správanie novej verzie

- Poradie vstupných stĺpcov môže byť ľubovoľné. Pridané pomenované stĺpce sa zachovajú
  v `source_raw_json`, aj keď ich skript ešte nepozná.
- Hlavičky tolerujú BOM, medzery na okrajoch, úvodzovky a rozdiel vo veľkosti písmen.
  Pevné poradie má iba výstupný import, pretože je súčasťou jeho formátu.
- Povinné názvy hlavičiek pre Shoptet sú `code`, `date`, `itemName`, `itemAmount`, `itemCode`.
  To neznamená povinné vyplnenie každej bunky; prázdne SKU na doprave či platbe ostáva podporované.
  Ostatné vstupné stĺpce sú voliteľné.
- Duplicitné názvy po očistení a zjednotení veľkosti písmen vyvolajú chybu.
  Úplne prázdny nepomenovaný stĺpec je prípustný. Nepomenovaný stĺpec s údajom vyvolá chybu
  s číslom riadka a stĺpca.
- Kontrola a mapovanie všetkých vstupov prebehnú pred vymazaním výstupu.
  Chyba pri čítaní alebo mapovaní preto ponechá predchádzajúci import.
  Toto nie je transakčná záruka pri zlyhaní samotného zápisu do Sheets.
- Neprázdne `graphics-parts-versions` sa ukladajú aj do `options_json`.
  `itemHsCode` a ostatné nemapované polia zostávajú v `source_raw_json`.
- Mapovanie eBay, MX Locker, krajín, číslovanie riadkov a funkcie poznámok MX Locker ostávajú zachované.

## Katalóg prebytočných polí

Podklad: `Y:\ORDERS_DOWNLOAD\2026\NOV\13\shoptet_raw.csv`
a referenčný `Darkscrub.csv` v rovnakom priečinku.
Vzorka obsahuje 13 dátových riadkov a 105 vstupných stĺpcov.
Dva nižšie uvedené stĺpce chýbali v `source_raw_json` referenčného importu.
Čísla stĺpcov sú iba informácia o tejto vzorke, skript ich nepoužíva.

### Dva doteraz ručne odstraňované stĺpce

| Názov | Stĺpec vo vzorke | Vyplnené riadky | Uloženie v novej verzii | Význam a budúce použitie |
| --- | ---: | ---: | --- | --- |
| `itemHsCode` | 67 | 0 / 13 | `source_raw_json.itemHsCode` | Podľa názvu HS kód položky. Možné budúce použitie pri colných údajoch. Typ a formát reálnych hodnôt treba potvrdiť na neprázdnom exporte; uchovávať ako text. |
| `graphics-parts-versions` | 70 | 0 / 13 | `source_raw_json["graphics-parts-versions"]` a pri neprázdnej hodnote aj `options_json["graphics-parts-versions"]` | Pole verzie alebo výberu dielov grafiky. Konkrétne prípustné hodnoty vzorka neobsahuje. Hodnota sa zachová bez rozdeľovania a bez odvodzovania výrobných pravidiel. |

Prázdna hodnota vo vzorke neznamená, že stĺpec bude vždy prázdny.
V teste sa použila výslovne syntetická hodnota `Full kit`
na overenie prenosu `graphics-parts-versions`;
nejde o potvrdenú hodnotu zo Shoptetu.

### Ďalšie polia bez samostatného mapovania v generátore

Tieto polia už boli v pôvodnom `source_raw_json`.
Nová verzia ich naďalej uchováva pod rovnakými názvami.
Význam nižšie je odvodený z názvov polí a dostupných hodnôt,
nie z dodanej dokumentácie schémy Shoptetu.

| Názov | Stĺpec vo vzorke | Vyplnené riadky | Pozorované hodnoty / možné použitie |
| --- | ---: | ---: | --- |
| `customerIdentificationNumber` | 17 | 0 / 13 | Dodatočný identifikátor zákazníka; presný význam treba potvrdiť. |
| `deliveryVatId` | 20 | 0 / 13 | Daňový identifikátor na doručovacej adrese. |
| `varchar1` | 30 | 0 / 13 | Vlastné krátke textové pole; význam závisí od konfigurácie exportu. |
| `varchar2` | 31 | 0 / 13 | Vlastné krátke textové pole. |
| `varchar3` | 32 | 0 / 13 | Vlastné krátke textové pole. |
| `text1` | 33 | 0 / 13 | Vlastné textové pole. |
| `text2` | 34 | 0 / 13 | Vlastné textové pole. |
| `text3` | 35 | 0 / 13 | Vlastné textové pole. |
| `itemUnitPriceVat` | 54 | 13 / 13 | Všetky hodnoty `0,00`; DPH jednotkovej ceny. |
| `itemTotalPriceVat` | 58 | 13 / 13 | Všetky hodnoty `0,00`; DPH celkovej ceny položky. |
| `itemUnitDiscountPriceWithVat` | 62 | 13 / 13 | Pozorované hodnoty od `0,00` do `227,40`; podľa názvu jednotková zľavnená cena s DPH. |
| `itemUnitDiscountPriceWithoutVat` | 63 | 13 / 13 | Pozorované hodnoty od `0,00` do `227,40`; podľa názvu jednotková zľavnená cena bez DPH. |
| `billVatIdValidationStatus` | 66 | 0 / 13 | Stav validácie fakturačného daňového identifikátora; možné hodnoty zatiaľ neznáme. |

Pôvodný generátor nemal osobitné mapovanie pre 15 z 105 polí.
Nová verzia pridáva `graphics-parts-versions` medzi produktové voľby.
Zvyšných 14 polí sa uchováva v surovom JSON; žiadne z nich netreba z CSV odstrániť.

### Ako tieto údaje použiť neskôr

V Apps Scripte je dostupné:

```javascript
const graphicsVersion = val_(r, 'graphics-parts-versions');
const hsCode = val_(r, 'itemHsCode');
```

Zo vzniknutého importného riadka:

```javascript
const raw = JSON.parse(importRow.source_raw_json || '{}');
const graphicsVersion = raw['graphics-parts-versions'] || '';
const hsCode = raw.itemHsCode || '';
```

Ak má ďalší nový stĺpec patriť medzi produktové voľby, stačí jeho názov doplniť do
`SHOPTET_OPTION_FIELDS`. Bez tejto úpravy bude stále zachovaný v `source_raw_json`.

Existujúci PHP importér `scripts/import_darkscrub_unified.php` uchováva
celý raw objekt pod `options_json._source_raw` a niektoré jeho polia
kopíruje aj na najvyššiu úroveň volieb. Samotná prítomnosť hodnoty
neznamená, že už existuje výrobná logika alebo ovládací prvok pre dané pole.

## Overenie

Lokálne prešlo 16 regresných testov v
[tests/darkscrub_import_spec_test.js](../tests/darkscrub_import_spec_test.js), vrátane:

- 25 rôznych poradí stĺpcov a vloženia nového stĺpca na začiatok, do stredu i na koniec;
- starších exportov bez dvoch nových polí;
- neprázdnych syntetických hodnôt oboch nových polí a zachovania neznámych polí;
- chýbajúcich, duplicitných, nesprávne rozdelených a prázdnych hlavičiek;
- zachovania posledného výstupu pri chybe vstupu;
- porovnania eBay a MX Locker s pôvodným skriptom;
- všetkých 13 riadkov referenčného CSV a celého obráteného poradia reálnych stĺpcov.

Hodnoty všetkých 64 výstupných polí sa porovnali.
Pri telefóne test zohľadňuje apostrof používaný Sheets na označenie textu.
Pri čase zohľadňuje zobrazenie hodiny bez úvodnej nuly (`04:55:13` / `4:55:13`).
JSON sa porovnáva podľa kľúčov a hodnôt. Jediným zámerným rozdielom reálnej vzorky
sú dva nové prázdne kľúče v `source_raw_json`.

Testy používajú lokálnu náhradu rozhrania SpreadsheetApp.
Nový skript zatiaľ nebol nasadený ani spustený v živej Google tabuľke.

Spustenie bez súkromných CSV:

```powershell
node tests/darkscrub_import_spec_test.js
```

Spustenie aj s dodanými referenciami:

```powershell
node tests/darkscrub_import_spec_test.js --raw 'Y:\ORDERS_DOWNLOAD\2026\NOV\13\shoptet_raw.csv' --expected 'Y:\ORDERS_DOWNLOAD\2026\NOV\13\Darkscrub.csv' --original 'C:\Users\PC\OneDrive\Desktop\darkscrub_import_spec.js'
```
