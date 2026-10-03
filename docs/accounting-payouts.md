# eBay payouty – návod pre účtovníctvo

Táto stránka slúži na nahratie výpisov platieb z eBay, kontrolu ich párovania s objednávkami a vytvorenie výcucu pre OMEGU.

## Bežný postup

1. Z eBay stiahnite pôvodný výpis transakcií vo formáte CSV pre UK alebo DE.
2. V časti **Import surového payout CSV** vyberte jeden alebo viac súborov a kliknite na **Importovať payout**.
3. Skontrolujte kartu **Nespárované**. Ak je na nej nula, objednávkové platby sú pripravené.
4. Vyberte správny mesiac a kliknite na **Export Vycuc**.
5. Vytvorený súbor CSV importujte do OMEGY.

Rovnaký alebo časovo sa prekrývajúci výpis môžete nahrať opakovane. Systém už uložené transakcie rozpozná a nevytvorí duplicity.

## Čo znamenajú karty

- **Objednávky** – prijaté platby za eBay objednávky v zvolenom mesiaci.
- **Nespárované** – platby, ku ktorým systém nenašiel objednávku v Darkscrube. Tieto riadky treba preveriť.
- **Refundácie** – vrátené platby zákazníkom. Vo výcucu pre OMEGU nie sú zmiešané s predajom.
- **Ostatné poplatky** – poplatky a pohyby, ktoré nie sú objednávkou ani refundáciou.

## Export Vycuc

Export vytvorí CSV oddelené bodkočiarkou pre vybraný mesiac. Obsahuje iba riadky objednávok. Ak má jedna eBay objednávka vo výpise viac riadkov, export ich spojí podľa čísla objednávky.

Refundácie a ostatné poplatky zostávajú viditeľné na stránke, ale do výcucu objednávok sa nezaradia.

## Dôležité upozornenia

- Súbor pred importom neotvárajte a neukladajte v Exceli; nahrajte pôvodné CSV z eBay.
- Importovaný súbor sa po spracovaní neuchováva na disku. V databáze zostanú normalizované údaje a pôvodný riadok na kontrolu.
- Modul rozpozná anglické aj nemecké rozloženie, čiarku aj bodkočiarku a bežné kódovania výpisov.

## Technická inštalácia

Pri prvom nasadení musí správca spustiť databázový súbor `db/accounting_payouts.sql`.
