# OMEGA faktúry – návod pre účtovníctvo

Táto stránka importuje faktúry vyexportované z OMEGY a páruje ich s výrobnými a zákazkovými objednávkami v Darkscrube.

## Bežný postup

1. V OMEGE vytvorte pôvodný export evidencie T01 vo formáte TXT alebo TSV.
2. V časti **Import OMEGA TXT/TSV** vyberte súbor a kliknite na **Importovať OMEGA faktúry**.
3. Skontrolujte karty **Nespárované** a **Objednávky s viacerými faktúrami**.
4. Pomocou mesiaca a filtrov si zobrazte potrebné faktúry.

Rovnaký súbor alebo prekrývajúci sa súhrnný export môžete nahrať opakovane. Rovnaké faktúry sa nezdvojnásobia; zmenené faktúry a ich položky sa aktualizujú.

## Ako sa faktúra páruje

Systém číta z riadku R01 najmä tieto údaje:

| Stĺpec v OMEGE | Uložený údaj |
| --- | --- |
| B | číslo faktúry |
| E | dátum vystavenia |
| AH | číslo objednávky |
| AL | spôsob platby |
| AQ | celková suma faktúry |

Číslo objednávky sa hľadá medzi výrobnými aj zákazkovými objednávkami. Jedna objednávka môže mať viac faktúr, napríklad zálohovú a konečnú. Preto sa všetky zachovajú a pri takej objednávke sa zobrazí počet faktúr.

## Čo znamenajú karty

- **Faktúry v mesiaci** – všetky faktúry vystavené v zvolenom mesiaci.
- **Výrobné zhody** – faktúry spárované s výrobnou objednávkou.
- **Zákazkové zhody** – faktúry spárované so zákazkovou objednávkou.
- **Nespárované** – faktúry bez nájdenej objednávky; skontrolujte číslo objednávky v OMEGE a Darkscrube.
- **Objednávky s viacerými faktúrami** – napríklad kombinácia zálohovej a konečnej faktúry.
- **Suma faktúr** – súčet zobrazených faktúr podľa pravidiel stránky.

## Položky R02

Každý riadok R02 za faktúrou R01 sa uloží ako jej položka. Ukladá sa popis, množstvo, jednotka a jednotková cena bez DPH. Počet položiek vidíte v stĺpci **R02**.

Položky zatiaľ nie sú potrebné na samotné párovanie, ale zostávajú uložené pre budúce prehľady a kontroly.

## Dôležité upozornenia

- Nahrajte pôvodný export z OMEGY s maximálnou veľkosťou 30 MB.
- Nahraný súbor sa po importe neuchováva na disku.
- Ak je faktúra nespárovaná, opravte zdrojové číslo objednávky a následne import zopakujte.

## Technická inštalácia

Pri prvom nasadení musí správca spustiť databázový súbor `db/accounting_omega.sql`.
