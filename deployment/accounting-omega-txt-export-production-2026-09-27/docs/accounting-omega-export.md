# OMEGA TXT export

Modul vytvára dva navzájom previazané importné súbory:

- `OMEGA_partneri_*.txt` — evidencia T04, partneri,
- `OMEGA_objednavky_*.txt` — evidencia T01, došlé objednávky (`R01`, typ dokladu 11) a položky `R02`.

Oba súbory sú tabulátorové, používajú CRLF a kódovanie Windows-1250. Prípona je `.txt`.

## Inštalácia

1. Nahrať nové a zmenené PHP súbory so zachovaním adresárovej štruktúry.
2. V phpMyAdmin spustiť celý súbor `db/accounting_omega_exports.sql`.
3. Otvoriť `Accounting > OMEGA TXT Export`.

Počiatočné posledné použité číslo partnera je `M2602995`. Prvý nový partner preto dostane `M2602996`.

## Denný postup

1. Vybrať interval, v ktorom boli objednávky importované do Darkscrubu.
2. Ako deň spracovania ponechať deň, keď sa TXT súbory pripravujú.
3. Skontrolovať zoznam pripravených, čakajúcich a blokovaných objednávok. Objednávky, ktoré sa nemajú fakturovať v balíku (napríklad dealeri), odznačiť checkboxom.
4. Kliknúť na `Vytvoriť nemenný balík`. Do balíka sa uložia iba zaškrtnuté riadky.
5. Z balíka stiahnuť najprv zákazníkov a potom objednávky.

Balík je snapshot. Opakované stiahnutie nemení zákaznícke kódy ani obsah. Jedna production objednávka môže byť zaradená iba do jedného balíka.

Posledný použitý zákaznícky seed sa dá upraviť nad tabuľkou. Zadáva sa celý kód, napríklad `M2602995`. Seed sa nedá znížiť pod najvyšší kód, ktorý už bol pridelený existujúcemu balíku.

## Pravidlá zaradenia

- Shoptet: objednávky importované v zvolenom intervale.
- eBay v EUR: objednávky importované v zvolenom intervale.
- eBay v inej mene: iba objednávky, ku ktorým bol v zvolenom intervale importovaný payout s kurzom. Môže ísť aj o staršiu objednávku.
- Custom: production objednávky so zdrojom `CUSTOM`, pridané do production v predchádzajúci pracovný deň voči dňu spracovania. Víkend sa preskočí.
- Objednávka bez kladnej fakturovanej sumy sa zobrazí ako blokovaná a do balíka sa nezaradí.

## DPH a ceny

- EÚ mimo Slovenska používa typ `OSSzd` a sadzbu cieľovej krajiny.
- Slovensko používa typ `03` a sadzbu 23 %.
- Krajiny mimo EÚ používajú typ `15t`, doprava `15s`, a nulovú DPH.
- V `R02` je množstvo samostatne a stĺpec E obsahuje jednotkovú cenu bez DPH podľa manuálu OMEGA.
- Pri cudzej mene sa použije EUR suma a kurz zo spárovaného payoutu.

## Oprávnenia

Stránka a endpointy sú dostupné iba superadminovi s permission `900` a oddeleniam `1` a `3`, rovnako ako ostatná účtovná sekcia.
