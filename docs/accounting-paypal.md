# PayPal platby – návod pre účtovníctvo

Táto stránka slúži na denný import PayPal výpisov, dohľadanie správneho čísla objednávky a vytvorenie výcucu pre OMEGU.

## Bežný postup

1. Z PayPalu stiahnite pôvodný výpis aktivít vo formáte CSV. Pred nahratím ho neotvárajte ani neukladajte v Exceli.
2. Súbor pretiahnite alebo vyberte v časti **Import pôvodného PayPal CSV**. Výpis pokojne môže každý deň obsahovať posledné dva týždne.
3. Skontrolujte karty **Na potvrdenie** a **Nespárované**.
4. Pri žltom návrhu overte správne SO a uložte ho. Pri nespárovanej platbe dohľadajte objednávku podľa PayPal ID, mena, e-mailu a textových stôp.
5. Ak zákazková objednávka medzitým dostala SO alebo už v Custom Orders vidíte správne CO/SO, kliknite na **Vykonať kontrolu**.
6. Priamo nad tabuľkou vyberte správny mesiac a hore pri Pomocníkovi stiahnite **Vycuc PayPal** pre OMEGU.

Opakované a prekrývajúce sa výpisy sú bezpečné. Systém už uložené transakcie rozpozná a preskočí.

## Ako systém hľadá objednávku

Párovanie skúša stopy v tomto poradí:

1. presný PayPal **Transaction ID** uložený pri platbe v Custom Orders,
2. pri refundácii odkaz na pôvodnú PayPal transakciu,
3. presné SO, e-shopové, eBay, SK alebo SC číslo nájdené vo výpise,
4. CO nájdené v texte, ktoré sa prevedie na aktuálne SO,
5. jediná jednoznačná zhoda zákazníka podľa mena alebo e-mailu – tú musí človek potvrdiť.

CO slúži iba ako stopa pri dohľadaní leadu. Do exportu sa vždy použije aktuálne oficiálne SO. Ak zákazková objednávka ešte SO nemá, platba zostane nespárovaná.

## Tlačidlo Vykonať kontrolu

Tlačidlo **Vykonať kontrolu** použite najmä vtedy, keď bola platba pôvodne nespárovaná, ale objednávka už medzitým dostala SO. Kontrola znovu preverí PayPal platby vo vybranom mesiaci podľa aktuálnych údajov v Custom Orders.

Pri jednoznačnej zhode systém automaticky:

- priradí PayPal platbe aktuálne SO, a to aj pri číslach s príponou, napríklad `SO21120-3`,
- skontroluje sekciu **Payments, Deposits And Advance Invoices** v príslušnej Custom Order,
- ak rovnaká platba už existuje, nevytvorí ju druhýkrát,
- ak bola platba zapísaná ručne bez PayPal Transaction ID a zhoduje sa objednávka, deň, suma aj mena, doplní Transaction ID k existujúcemu záznamu,
- ak platba chýba, vytvorí ju ako **Deposit** s poznámkou `Automatic payment assignment`,
- ak je objednávka ešte v stave **Lead**, zmení ju na **Deposit Paid**.

Iný stav objednávky kontrola nikdy nemení. Ak je zhoda nejednoznačná, systém platbu automaticky nepriradí a nechá ju na ručnú kontrolu.

Objednávka nájdená podľa CO môže dostať platbu a stav **Deposit Paid** aj vtedy, keď ešte nemá SO. V takom prípade však naďalej zostane medzi **Nespárovanými** pre OMEGU, kým jej nebude pridelené oficiálne SO. Potom znova kliknite na **Vykonať kontrolu**.

## Čo robiť s nespárovanou platbou

- Skontrolujte údaje v stĺpcoch **Zákazník**, **PayPal ID** a **Stopy v PayPal**.
- Vyhľadajte Transaction ID, e-mail, meno alebo CO v Custom Orders.
- Overte aktuálne SO objednávky.
- SO zapíšte do poľa **Exportná referencia** a kliknite na ikonu uloženia.
- Po uložení zostanete v zozname **Nespárované**, aby ste mohli pokračovať v kontrole ďalších platieb.
- Ak SO ešte neexistuje, nič nevymýšľajte. Počkajte na jeho pridelenie a potom použite **Vykonať kontrolu**.

## Vycuc PayPal

Toto je súbor určený na import do OMEGY. Má rovnaké rozloženie ako eBay výcuc:

`Date;Suma;Poplatok;Order Number;PayPal;IBAN partnera`

Obsahuje iba potvrdené prijaté platby vo vybranom mesiaci. Nespárované alebo nepotvrdené platby sa automaticky vynechajú, takže neblokujú vytvorenie výcucu. Neobsahuje detailné riadky košíka, refundácie ani výbery. Dve skutočné platby k jednému SO zostanú ako dva samostatné pohyby.

## Export PayPal CSV

Tento export nie je určený na bežný import do OMEGY. Je to kontrolná kópia pôvodného PayPal výpisu so zachovanými stĺpcami; pri spárovaných riadkoch dopĺňa iba **Invoice Number**.

Keďže má slúžiť ako úplná kontrolná kópia, sprístupní sa až po vyriešení všetkých relevantných platieb a návrhov v danom mesiaci.

## Technická inštalácia

Pri prvom nasadení musí správca spustiť databázový súbor `db/accounting_paypal.sql`.
