# PayPal platby – návod pre účtovníctvo

Táto stránka slúži na denný import PayPal výpisov, dohľadanie správneho čísla objednávky a vytvorenie výcucu pre OMEGU.

## Bežný postup

1. Z PayPalu stiahnite pôvodný výpis aktivít vo formáte CSV. Pred nahratím ho neotvárajte ani neukladajte v Exceli.
2. Súbor pretiahnite alebo vyberte v časti **Import pôvodného PayPal CSV**. Výpis pokojne môže každý deň obsahovať posledné dva týždne.
3. Skontrolujte karty **Na potvrdenie** a **Nespárované**.
4. Pri žltom návrhu overte pôvodné CO/SO z platobnej požiadavky a uložte ho. Pri nespárovanej platbe dohľadajte objednávku podľa PayPal ID, mena, e-mailu a textových stôp.
5. Ak už v Custom Orders vidíte správne CO/SO alebo potrebujete obnoviť staršie párovanie, kliknite na **Vykonať kontrolu**.
6. Priamo nad tabuľkou vyberte správny mesiac a hore pri Pomocníkovi stiahnite **Vycuc PayPal** pre OMEGU.

Opakované a prekrývajúce sa výpisy sú bezpečné. Systém už uložené transakcie rozpozná a preskočí.

## Ako systém hľadá objednávku

Párovanie skúša stopy v tomto poradí:

1. presný PayPal **Transaction ID** uložený pri platbe v Custom Orders,
2. pri refundácii odkaz na pôvodnú PayPal transakciu,
3. presné SO, e-shopové, eBay, SK alebo SC číslo nájdené vo výpise,
4. CO nájdené v texte, ktoré sa zachová aj po pridelení SO,
5. jediná jednoznačná zhoda zákazníka podľa mena alebo e-mailu – tú musí človek potvrdiť.

Exportná referencia zodpovedá pôvodnej platobnej požiadavke: platba k CO zostane pod CO a platba k SO pod SO. Platí to aj pri zhode podľa PayPal Transaction ID. Neskoršie pridelenie SO nezmení CO pri staršom deposite. Samotný typ Deposit nerozhoduje — rozhoduje číslo uložené v pôvodnom PayPal výpise. Ak výpis žiadne CO/SO neobsahuje, systém použije doterajšie dohľadanie objednávky; nejasnú referenciu treba overiť ručne.

## Tlačidlo Vykonať kontrolu

Tlačidlo **Vykonať kontrolu** znovu preverí PayPal platby vo vybranom mesiaci podľa aktuálnych údajov v Custom Orders a obnoví exportné referencie podľa pôvodného výpisu. Ručne uložené referencie zachová.

Pri jednoznačnej zhode systém automaticky:

- dohľadá objednávku a zachová pôvodné CO/SO platby, aj pri SO číslach s príponou, napríklad `SO21120-3`,
- skontroluje sekciu **Payments, Deposits And Advance Invoices** v príslušnej Custom Order,
- ak rovnaká platba už existuje, nevytvorí ju druhýkrát,
- ak bola platba zapísaná ručne bez PayPal Transaction ID a zhoduje sa objednávka, deň, suma aj mena, doplní Transaction ID k existujúcemu záznamu,
- ak platba chýba, vytvorí ju ako **Deposit** s poznámkou `Automatic payment assignment`,
- ak je objednávka ešte v stave **Lead**, zmení ju na **Deposit Paid**.

Iný stav objednávky kontrola nikdy nemení. Ak je zhoda nejednoznačná, systém platbu automaticky nepriradí a nechá ju na ručnú kontrolu.

Objednávka jednoznačne nájdená podľa CO môže dostať platbu a stav **Deposit Paid** aj vtedy, keď ešte nemá SO. Platba je pripravená na výcuc pod pôvodným CO; na pridelenie SO netreba čakať.

## Čo robiť s nespárovanou platbou

- Skontrolujte údaje v stĺpcoch **Zákazník**, **PayPal ID** a **Stopy v PayPal**.
- Vyhľadajte Transaction ID, e-mail, meno alebo CO v Custom Orders.
- Overte CO/SO, ku ktorému bola konkrétna platba pôvodne vyžiadaná.
- Toto číslo zapíšte do poľa **Exportná referencia** a kliknite na ikonu uloženia.
- Po uložení zostanete v zozname **Nespárované**, aby ste mohli pokračovať v kontrole ďalších platieb.
- Ak bola platba vyžiadaná k CO, použite CO aj vtedy, keď už existuje SO. Ak pôvodné číslo neviete overiť, platbu nechajte nespárovanú.

## Vycuc PayPal

Toto je súbor určený na import do OMEGY. Má rovnaké rozloženie ako eBay výcuc:

`Date;Suma;Poplatok;Order Number;PayPal;IBAN partnera`

Obsahuje iba potvrdené prijaté platby vo vybranom mesiaci. Nespárované alebo nepotvrdené platby sa automaticky vynechajú, takže neblokujú vytvorenie výcucu. Neobsahuje detailné riadky košíka, refundácie ani výbery. Dve skutočné platby k jednej objednávke zostanú ako dva samostatné pohyby, napríklad deposit pod `CO000114` a doplatok pod `SO21662`.

Výcuc použije pôvodné CO/SO aj pri už importovaných automaticky spárovaných platbách, bez opätovného importu CSV. Staršie hodnoty v tabuľke obnovíte cez **Vykonať kontrolu**. Ručne potvrdená exportná referencia má prednosť; ak ste ju v minulosti zmenili na SO, opravte ju ručne podľa pôvodnej platobnej požiadavky.

## Export PayPal CSV

Tento export nie je určený na bežný import do OMEGY. Je to kontrolná kópia pôvodného PayPal výpisu so zachovanými stĺpcami; pri spárovaných riadkoch dopĺňa iba **Invoice Number**.

Keďže má slúžiť ako úplná kontrolná kópia, sprístupní sa až po vyriešení všetkých relevantných platieb a návrhov v danom mesiaci.

## Technická inštalácia

Pri prvom nasadení musí správca spustiť databázový súbor `db/accounting_paypal.sql`.
