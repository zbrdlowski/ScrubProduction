# OMEGA TXT export – návod pre účtovníctvo

Modul pripravuje dva prepojené súbory na import do OMEGY:

- `OMEGA_partneri_*.txt` – evidencia T04, zákazníci,
- `OMEGA_objednavky_*.txt` – evidencia T01, došlé objednávky a ich položky.

Oba súbory sú tabulátorové TXT v kódovaní Windows-1250. Neupravujte ich v Exceli.

## Bežný denný postup

1. Vyberte interval, v ktorom boli objednávky importované do Darkscrubu.
2. Ako **Deň spracovania** ponechajte deň, keď súbory pripravujete.
3. Kliknite na **Skontrolovať**.
4. Preverte pripravené, čakajúce a blokované objednávky. Objednávky, ktoré v tomto balíku nemajú ísť do OMEGY, odznačte.
5. Kliknite na **Vytvoriť nemenný balík**.
6. Z vytvoreného balíka stiahnite najprv **Zákazníci TXT** a potom **Objednávky TXT**.
7. Oba súbory importujte do OMEGY v rovnakom poradí.

## Čo znamenajú karty

- **Pripravené objednávky** – majú všetky potrebné údaje a môžu ísť do balíka.
- **Čakajú na payout** – cudzo-menové eBay objednávky, ku ktorým ešte chýba importovaný payout s kurzom.
- **Custom z pracovného dňa** – zákazkové objednávky zaradené podľa predchádzajúceho pracovného dňa.
- **Blokované chybou dát** – objednávky s chýbajúcou alebo neplatnou fakturovanou sumou či iným povinným údajom.

## Ako sa objednávky vyberajú

- **Shoptet** – objednávky importované vo zvolenom intervale.
- **eBay v EUR** – objednávky importované vo zvolenom intervale.
- **eBay v inej mene** – iba objednávky, ku ktorým bol vo zvolenom intervale importovaný payout s kurzom. Môže ísť aj o staršiu objednávku.
- **Custom** – výrobné objednávky so zdrojom CUSTOM, pridané do výroby v predchádzajúci pracovný deň. Víkend sa preskočí.

Objednávka bez kladnej fakturovanej sumy sa zobrazí ako blokovaná a do balíka sa nezaradí.

## Nemenný balík

Balík je nemenná snímka vybraných objednávok. Opakované stiahnutie nemení zákaznícke kódy ani obsah. Jedna výrobná objednávka môže byť zaradená iba do jedného balíka.

História balíkov ukazuje, či už boli súbory zákazníkov a objednávok stiahnuté. Starší balík môžete kedykoľvek otvoriť a stiahnuť znova.

## Zákaznícky seed

Seed je posledný použitý číselný kód zákazníka. Bežne ho nemeňte. Ak ho treba upraviť, zadáva sa celý kód, napríklad `M2602995`. Nasledujúci nový zákazník dostane ďalšie číslo. Seed sa nedá znížiť pod najvyšší už pridelený kód.

## DPH a ceny

- Slovensko používa typ `03` a sadzbu DPH 23 %.
- EÚ mimo Slovenska používa typ `OSSzd` a sadzbu cieľovej krajiny.
- Krajiny mimo EÚ používajú typ `15t`, dopravu `15s` a nulovú DPH.
- V položke R02 je množstvo samostatne a jednotková cena je bez DPH.
- Pri cudzej mene sa použije suma v EUR a kurz zo spárovaného payoutu.

## Technická inštalácia

Pri prvom nasadení musí správca spustiť databázový súbor `db/accounting_omega_exports.sql`. Počiatočný posledný použitý zákaznícky kód je `M2602995`.
