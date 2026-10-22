# Tatra B. Payouts

Spoločná stránka spracúva **Shoptet Pay CSV** aj **CSV výpis priamo z Tatra banky**. Formát sa rozpozná podľa hlavičky. Párovanie slúži na kontrolu úhrad a prípravu výcucu pre OMEGU.

## Import a kontrola

1. Nahrajte jeden alebo viac pôvodných CSV cez rovnakú plochu na stránke.
2. Po importe sa otvorí najnovší mesiac z nahraných pohybov a príslušný zdroj.
3. Vyberte **Oba zdroje**, **Shoptet Pay** alebo **Tatra banka**.
4. Skontrolujte **Nespárované** a **Objednávky PENDING**.
5. Po dodatočnom importe objednávok použite **Vykonať kontrolu**, prípadne nahrajte CSV znova.

Mesiac a zdroj určujú tabuľku aj výcuc. Typ transakcie, párovanie a textové vyhľadávanie filtrujú iba tabuľku. Bankové pohyby patria do mesiaca podľa **dátumu zúčtovania**; ak sa dátum spracovania líši, zobrazí sa tiež.

CSV súbory sa po spracovaní neuchovávajú. Databáza uchováva normalizované pohyby, odkazy na objednávky a históriu importov. Číslo maskovanej karty zo Shoptetu sa použije iba na rozpoznanie duplicít a neukladá sa.

## Párovanie Shoptet Pay

Páruje sa presné číslo objednávky alebo externé číslo zdroja SHOPTET. Viac zodpovedajúcich objednávok znamená nejednoznačnú zhodu.

Pri opakovanom importe sa aktualizuje stav a dostupné vyúčtovanie. Starší výpis nezníži už známy stav vyrovnania. Identita platby nezávisí od neskoršie doplneného vyúčtovania.

## Párovanie bankového výpisu

Priame kladné platby sa porovnávajú s objednávkami podľa variabilného symbolu, referencie platiteľa a čísiel v správe pre prijímateľa. Správa rozdelená do viacerých CSV stĺpcov sa spojí aj vtedy, keď banka rozdelila samotné číslo objednávky.

Podporované sú aj zákazkové objednávky podľa **SO** alebo **CO**. Do výcucu sa použije oficiálne číslo; zákazka bez neho čaká na pridelenie SO. Zákazka a jej prepojená výrobná objednávka predstavujú jednu zhodu. Samotné meno zákazníka alebo suma na automatické priradenie nestačia.

**Import nemení stav objednávky, potvrdenie úhrady, fakturovanú sumu ani výrobu.** Objednávka PENDING ostáva PENDING aj po prijatí celej sumy. Odkaz **PENDING – potvrdiť ručne** otvorí objednávku, kde použite existujúce potvrdenie platby.

### Doplatky a čiastočné úhrady

Každá banková úhrada zostáva samostatným pohybom. Napríklad tri platby jednej objednávky v septembri a októbri vytvoria tri riadky, každý vo svojom mesiaci.

Pri objednávke sa zobrazuje **Prijaté v nahraných výpisoch** – súčet spárovaných kladných platieb zo Shoptet Pay a Tatra banky za všetky dátumy, osobitne pre každú menu. Refundácie sa od tohto súčtu neodpočítavajú. Úhrady z ostatných modulov ani z nenahraných výpisov v súčte nie sú. Suma sa nezapisuje do finančných údajov objednávky.

### Ručné priradenie

Pri priamej bankovej platbe rozbaľte **Upraviť párovanie**:

- **Priradiť** – zadajte presné číslo existujúcej objednávky alebo SO. Použite aj pri doplatku bez čísla v bankovej správe.
- **Nie je objednávka** – vynechá napríklad inú firemnú prijatú platbu zo súčtu úhrad a z výcucu.
- **Obnoviť automatické párovanie** – zruší ručný zásah a znovu vyhodnotí pôvodné údaje platby.

Ručné rozhodnutie sa zachová pri ďalšom importe aj pri kontrole. Zmeny párovania vyžadujú oprávnenie na účtovný import. Aktuálne ručné rozhodnutie má uloženého používateľa a čas.

### Súhrnné prevody a ostatné pohyby

Prijaté payouty zo Shoptetu, PayPalu a eBay sa rozpoznajú podľa platiteľa alebo referencie payoutu. Sú v kategórii **Prijaté prevody payoutov**, nepredstavujú novú úhradu objednávky a neexportujú sa do výcucu.

Pri Shoptet prevode sa porovná referencia SWPE, suma a mena s nahraným payoutom Shoptet Pay. Tabuľka ukáže zhodu, chýbajúci payout alebo rozdiel. Tým sa predíde druhému započítaniu už importovaných kartových platieb.

Záporné bankové pohyby sa nepovažujú automaticky za refundácie zákazníkom. Známe bankové poplatky, transakčná daň a debetný úrok sú v **Poplatkoch**; ostatné odchádzajúce platby sú v **Ostatných pohyboch**.

## Opakovaný import

Denné a prekrývajúce sa výpisy možno nahrať opakovane.

- Shoptet nemá jedinečné ID platby. Rozpoznanie používa typ, čas na sekundu, objednávku, sumu, menu, službu a maskovanú kartu. Úplne totožné riadky sa považujú za ten istý pohyb.
- Bankový export tiež neposkytuje spoľahlivé jedinečné ID každého pohybu. Identita vychádza z účtu, dátumu zúčtovania, sumy, meny, protiúčtu, bankovej identifikácie, referencií, správy a popisu. Zostatok, číslo výpisu a dátum spracovania ju nemenia.
- Ak bankový výpis obsahuje viac úplne rovnakých pohybov v ten istý deň, zachová sa ich počet. Pri čiastočnom výpise, ktorý obsahuje iba niektoré z takýchto nerozlíšiteľných pohybov, nemožno určiť, ktorý z nich ide. Používajte celé dni výpisu.

## Vycuc Tatra B.

Výstup zachováva šesťstĺpcový formát:

`Date;Suma;Poplatok;Order Number;Tatra B.;IBAN partnera`

CSV má UTF-8 BOM, bodkočiarky a desatinné čiarky. Výcuc obsahuje iba kladné spárované úhrady v EUR:

- Shoptet Pay – stav APPROVED, BILLING alebo BILLED a vyrovnaná suma v EUR.
- Tatra banka – priama prijatá platba, automaticky alebo ručne priradená existujúcej objednávke s oficiálnym číslom.

PENDING stav objednávky výcucu nebráni: banková úhrada už prišla, výrobu však treba potvrdiť ručne. Nespárované, ručne vynechané, zamietnuté alebo neznáme Shoptet stavy, refundácie, poplatky, payouty a iné meny sa vynechajú.

**Poplatok je 0,00**, pretože poplatky sú súhrnné a nerozpočítavajú sa na objednávky. Neznamená to platbu bez poplatkov. Bankový IBAN partnera sa doplní, ak ho export obsahuje vo forme IBAN; domáce číslo účtu sa na IBAN neprepočítava. Shoptet IBAN neposkytuje.

Samostatné platby tej istej objednávky zostávajú samostatnými riadkami. Opakované stiahnutie vytvorí výcuc celého vybraného mesiaca a zdroja. Pri importe do OMEGY zohľadnite už spracované pohyby; kombinovaný výcuc a samostatné výcucy zdrojov sa prekrývajú.

## Aktualizácia existujúcej inštalácie

Pre rozšírenie o bankové výpisy nahrajte do rovnakých priečinkov tieto súbory:

- `includes/accounting_tatra.php`
- `scripts/accounting/tatra_helpers.php`
- `scripts/accounting/tatra_bank_helpers.php`
- `scripts/accounting/import_tatra.php`
- `scripts/accounting/export_tatra_vycuc.php`
- `scripts/accounting/update_tatra_bank_match.php`
- `dist/css/accounting-tatra.css`
- `dist/js/accounting-tatra.js`
- `docs/accounting-tatra.md`
- `scripts/accounting/install_tatra.php`
- `db/accounting_tatra_bank.sql`

Potom z koreňa Darkscrubu na production serveri spustite:

```sh
php scripts/accounting/install_tatra.php --apply
php scripts/accounting/install_tatra.php --check
```

Pri už existujúcej Tatra inštalácii pribudne iba tabuľka `accounting_tatra_bank_transactions`. Doterajšie tabuľky a importy sa zachovajú. Inštalačný skript vytvára databázové tabuľky; aplikačné súbory nerozbaľuje ani nevytvára.

## Prvá inštalácia

Nahrajte aj pôvodné integračné súbory Tatra modulu a `db/accounting_tatra.sql`. Aktualizovaný inštalátor vie vytvoriť všetky tri tabuľky a používa pripojenie z `includes/conn.php` daného servera.

Na Windows možno použiť:

```powershell
C:\xampp\php\php.exe scripts/accounting/install_tatra.php --apply
```

Inštalátor funguje iba cez CLI. `--check` alebo spustenie bez parametra iba kontroluje schému. Návratový kód 0 znamená úspech, 2 chýbajúcu tabuľku pri kontrole a 1 chybu. `--help` zobrazí návod.

`--apply` možno opakovať. Vytvorí chýbajúce tabuľky a overí stĺpce, jedinečné indexy a väzby; existujúcu nekompatibilnú schému neprepisuje. Migrácia neimportuje CSV.

Prístup, import a export používajú existujúce účtovné oprávnenia. Jeden súbor môže mať najviac 20 MB, naraz najviac 20 súborov. Všetky súbory z jedného nahratia sa uložia spolu alebo sa pri chybe neuloží žiadny.
