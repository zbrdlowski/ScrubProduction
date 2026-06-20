# Custom Orders Manual

Tento modul sluzi na evidenciu custom objednavok este predtym, nez sa z nich stane normalna production objednavka v `orders` module.

Pouzivajte ho na:
- prve zachytenie leadu z Instagramu, WhatsAppu, Messengeru, Facebooku, TikToku, emailu
- priebezne doplnanie nekompletnych udajov
- evidenciu depositov a PayPal payment ID
- evidenciu upsell poloziek
- pripravu presnych itemov a custom options pre grafika
- export do production systemu az vtedy, ked uz objednavka dava zmysel

## Zakladna logika

Kazda custom objednavka ma 2 identifikatory:
- `internal code`
  Priklad: `CO000123`
  Tento vznika automaticky hned po vytvoreni leadu.
  Pouzivajte ho interne a pri PayPal payment requestoch.
- `official order number`
  Priklad: `SO20795`, `GO00012`, `SC00345`
  Toto sa neprideluje automaticky.
  Prideluje sa az ked chcete, pocas procesu.

Typy oficialnych cisel:
- `SO`
  bezna Scrub custom objednavka
- `GO`
  GrenzGaenger / affiliate custom objednavka
- `SC`
  seat cover custom objednavka

## Odporucany workflow

### 1. Vytvorenie leadu

Klikni `New Custom Lead`.

Hned po vytvoreni vznikne:
- `internal code`
- prazdny zaznam pripraveny na doplnanie

V tejto faze staci vyplnit len:
- `source channel`
- `social platform`
- `social handle`
- `customer name`, ak ho mas
- zakladne info o motorke

### 2. Vypytanie depositu

Ked customer support pyta deposit:
- poslite PayPal request s `internal code` v poznamke alebo reference
- po prijati platby ju zapis do sekcie `Payments And Deposits`

Odporucany zapis:
- `Kind`: `DEPOSIT`
- `PayPal tx ID`: presny identifikator platby
- `Amount`: realne prijata suma
- `Currency`: bezne `EUR`
- `Note`: napr. `first design deposit`

Ak zakaznik prekroci 3 upravy designu:
- zaloz dalsiu platbu ako `EXTRA_DEPOSIT`

Poznamka:
- system automaticky pocita sumu depositov
- pri finalnej kalkulacii sa deposity odpocitavaju od hrubej sumy objednavky

### 3. Zbieranie detailov pre grafika

Priebezne doplnaj:
- bike data
- rider name
- rider number
- graphics brief
- bike photo URLs
- reference URLs

Nemusi byt vsetko hned. Modul je navrhnuty presne na to, ze data prichadzaju postupne.

### 4. Pridavanie itemov

Kazdy realny produkt, ktory sa ma neskor vyrabat alebo dodat, pridaj ako samostatny item.

Priklady:
- graphics kit
- plastics kit
- seat cover
- fitting
- midfork stickers
- apparel
- bike mat
- rim tapes

Ak to bol dopredany produkt navyse oproti povodnemu zaujmu:
- zapni `Mark as upsell`
- vypln `Upsell source`

Priklady `Upsell source`:
- `converted from graphics-only`
- `upsold during design discussion`
- `added after deposit`

### 5. Pridelenie official number

Ked uz objednavka zacina byt dost realna:
- vyber prefix `SO`, `GO` alebo `SC`
- klikni `Assign Official Number`

Nerobte to zbytocne skoro, ak este nie je jasne, ci z toho realne objednavka bude.

### 6. Export do production

Pouzi `Export To Production` az ked:
- objednavka ma official number
- ma aspon 1 item
- je vyplnene meno pre shipping
- je vyplnena shipping adresa
- je vyplneny aspon email alebo telefon
- ma zmysluplnu cenu

Po exporte:
- vznikne zaznam v normalnom `orders` systeme
- custom objednavka dostane status `EXPORTED`
- dalsie produkcne zmeny uz ries v standardnom order module

## Vyznam poli

### Header And Customer Snapshot

#### `Status`

Odporucane pouzitie:
- `LEAD`
  novy kontakt, este sa zistuju zakladne info
- `DEPOSIT_PENDING`
  cakame na deposit
- `DEPOSIT_PAID`
  deposit je prijaty, ideme zbierat detail
- `IN_PROGRESS`
  objednavka sa aktivne doplnuje a sklada
- `READY_TO_EXPORT`
  vsetko podstatne je pripravene
- `EXPORTED`
  uz preklopene do production
- `CANCELLED`
  zakaznik to stopol
- `DEAD`
  lead sa nehynbe, neodpoveda, alebo nema zmysel

#### `Complexity`

Rozsah:
- `1` az `10`

Odporucanie:
- `1-3`
  jednoduche customy
- `4-6`
  bezna custom objednavka s viacerymi detailmi
- `7-10`
  komplikovane buildy, viac produktov, vela doplnani, problemovy customer

Nie je to technicke validacne pole. Je to interny management signal.

#### `Source channel`

Volny text.

Odporucane hodnoty:
- `Instagram`
- `WhatsApp`
- `Messenger`
- `Facebook`
- `TikTok`
- `Email`
- `Web`

#### `Social platform`

Volny text, ale drzte sa jednej formy.

Odporucane hodnoty:
- `Instagram`
- `WhatsApp`
- `Messenger`
- `Facebook`
- `TikTok`

#### `Social handle`

Nick alebo identifikator zakaznika.

Priklady:
- `@john_125`
- `jason.moto`
- `+49123456789` ak komunikuje iba cez WhatsApp

#### `Customer name`

Realne meno zakaznika, ak je zname.

Nemusi byt povinne pri zalozeni leadu.
Pred exportom je silno odporucane.

#### `Customer email`

Email zakaznika.

Pred exportom musi byt vyplnene aspon:
- email
alebo
- telefon

#### `Customer phone`

Telefon zakaznika.

Pred exportom musi byt vyplnene aspon:
- email
alebo
- telefon

#### `Customer country`

Pouzivaj 2-znakovy kod krajiny.

Validne a odporucane priklady:
- `DE`
- `AT`
- `FR`
- `IT`
- `US`
- `CA`
- `GB`
- `CH`
- `SK`
- `CZ`
- `NL`

Poznamka:
- system mapuje aj niektore kratke formy ako `UK -> GB`, `NE -> NL`
- idealne aj tak zapisuj rovno oficialny 2-letter kod

#### `Bike brand`, `Bike model`, `Bike year`, `Bike details`

Sluzia na orientaciu customer supportu aj grafika.

Priklad:
- `Bike brand`: `KTM`
- `Bike model`: `SX 125`
- `Bike year`: `2023`
- `Bike details`: `restyle plastics, black frame, wants OEM lines but sharper`

#### `Rider name`

Meno, ktore ma byt na grafike, ak sa pouziva.

#### `Rider number`

Startovne cislo / race number.

Validacia je volny text.
Priklady:
- `7`
- `23`
- `450`

#### `Currency`

Odporucane:
- `EUR`
- `USD`
- `GBP`

Najlepsie drzat `EUR`, ak neexistuje dovod inak.

### Shipping polia

#### `Shipping name`

Povinne pre export.
Meno prijemcu.

#### `Shipping company`

Volitelne.
Firma, ak sa posiela na firmu.

#### `Shipping street`

Povinne pre export.

#### `Shipping city`

Povinne pre export.

#### `Shipping ZIP`

Povinne pre export.

#### `Shipping country`

Povinne pre export.
Pouzivaj 2-letter kod krajiny, napr. `DE`, `FR`, `US`.

#### `Shipping email`

Volitelne, ale odporucane.

#### `Shipping phone`

Volitelne, ale odporucane.

#### `Shipping method`

Volny text.

Priklady:
- `FedEx Economy`
- `FedEx Express`
- `DHL`
- `Personal pickup`

#### `Shipping price`

Ciselna hodnota.

Priklady:
- `0`
- `14.90`
- `29.90`

### Deposit / revisions

#### `Revisions included`

Kolko zmien designu je v deposite.

Default:
- `3`

#### `Revisions used`

Kolko zmien uz customer minul.

Priklady:
- `0`
- `1`
- `2`
- `3`

Ak ide nad limit:
- dopln dalsi `EXTRA_DEPOSIT`

### `Last contact`

Kedy ste si naposledy pisali / riesili objednavku.

### `Next follow-up`

Kedy sa k tomu ma support vratit.

Pouzivaj to na pripomienky pri zakaznikoch, co nereaguju.

### `Dead order`

Zaskrtni, ak je lead prakticky mrtvy.

Priklady:
- neodpoveda opakovane
- nechce poslat deposit
- objednavka sa rozpadla

### `Graphics brief`

Sem patri ludsky zrozumitelny brief pre grafika.

Priklad:
- `Customer wants aggressive black/red kit, rider number 27, no sponsor overload, metallic details, match seat cover`

### `Bike photo URLs`

Sem zapisuj linky na fotky motorky.

Odporucanie:
- 1 link na riadok
- ak nie je URL, moze byt aj textova poznamka kde su subory ulozene

### `Reference URLs / files`

Sem patria:
- inspiracie
- linky na predosle designy
- screenshoty
- link na cloud folder

### `Customer notes`

Sem patri obsah, ktory dava zmysel aj neskor v production context.

Priklady:
- poziadavky zakaznika
- dolezite dohodnute body
- poziadavky na vizual

### `Internal notes`

Sem patria interne poznamky teamu.

Priklady:
- customer je pomaly v odpovediach
- treba znovu pytat VIN / adresu / mail
- support navrhuje upsell seat cover po schvaleni grafiky

## Items And Upsells

Kazdy item predstavuje konkretnu polozku objednavky.

### `Type`

Odporucane pouzitie:
- `G`
  graphics
- `P`
  plastics
- `S`
  seat cover
- `F`
  fitting
- `T`
  accessories / add-ons
- `M`
  misc / vseobecny manual item

### `SKU`

Ak poznas produktove oznacenie, vypln ho.
Ak ho este nevies, nechaj `MANUAL` alebo prazdne.

### `Qty`

Cislo vacsie alebo rovne `1`.

### `Unit price`

Ciselna hodnota bez meny.

Priklady:
- `49.90`
- `149.90`
- `299.00`

### `Title`

Najdolezitejsie item pole.
Malo by byt jasne, co sa realne predava.

Priklady:
- `Custom graphics kit KTM SX 125 2023`
- `Seat cover black/red ribbed`
- `Grey plastics set Husqvarna 2024`
- `Midfork stickers`

### `Custom label`

Volitelny interny alebo customer-facing doplnkovy nazov.

### `Category info`

Pomocne pole pre kompatibilitu / orientaciu.

Priklad:
- `KTM | SX 125 | 2023 | Restyle plastics`

### Item option polia

Pouzi ich podla typu itemu.

#### `Rider name`

Pouzi najma pri graphics itemoch.

#### `Rider number`

Pouzi najma pri graphics itemoch.

#### `Material`

Pouzi pri graphics itemoch.

Priklady:
- `Standard`
- `Chrome`
- `More Layers`
- `Fluo orange`

#### `Finish`

Pouzi pri graphics itemoch.

Priklady:
- `Gloss`
- `Matte`
- `Frozen`

#### `Grip`

Pouzi, ak to item dava zmysel.

Priklady:
- `Yes`
- `No`
- `Medium`
- `Strong`

#### `Tr. swingarms`

Volitelne.
Pouzi, ak treba riesit transfer / swingarm detail.

#### `Patch style`

Najma pre seat cover.

Priklady:
- `No Patch`
- `Black Patch`
- `Custom Patch`

#### `Waterproof seams`

Najma pre seat cover.

Priklady:
- `Yes`
- `No`

#### `Enduro pocket`

Najma pre seat cover.

Priklady:
- `Yes`
- `No`

#### `Side brand patches`

Najma pre seat cover.

Priklady:
- `Yes`
- `No`
- `Scrub`
- `OEM`

#### `Item note`

Volny text k itemu.

Priklad:
- `Use same red as plastics, rider wants cleaner side plates, no extra logos`

### `Mark as upsell`

Zapni, ked zakaznik povodne item nechcel a support ho dotiahol neskor.

### `Upsell source`

Vysvetli preco je to upsell.

Priklady:
- `added after deposit`
- `converted from graphics-only`
- `support recommended matching seat cover`

## Payments And Deposits

### `Kind`

Validne hodnoty:
- `DEPOSIT`
- `EXTRA_DEPOSIT`
- `BALANCE`
- `REFUND`

Pouzitie:
- `DEPOSIT`
  prvy deposit
- `EXTRA_DEPOSIT`
  dalsi deposit po precerpani zmien alebo pri dalsom kole
- `BALANCE`
  doplatok
- `REFUND`
  vratenie penazi

### `PayPal tx ID`

Presne ID prijatej platby.
Velmi dolezite pre neskorsie hladanie.

### `Amount`

Kladna ciselna hodnota.

### `Currency`

Najcastejsie `EUR`.

### `Received at`

Datum a cas prijatia platby.

### `Note`

Priklad:
- `deposit for first draft`
- `extra deposit after 3 revisions`

## Contact Attempts

Sekcia `Contact Attempts` sluzi na historiu komunikacie.

Pouzivaj ju vzdy, ked:
- pytate doplnujuce osobne udaje
- pytate adresu
- pytate bike photos
- pytate potvrdenie detailov

### `Channel`

Priklady:
- `Instagram`
- `WhatsApp`
- `Messenger`
- `Email`

### `Note`

Priklady:
- `asked for shipping address`
- `asked for rider number confirmation`
- `asked for bike side photos`

## Kedy je bezpecne objednavku exportovat

Pred exportom si prejdi tento checklist:

- ma `official order number`
- ma aspon 1 realny item
- kazdy dolezity item ma zrozumitelny nazov
- je vyplnene `shipping name`
- je vyplnene `shipping street`
- je vyplnene `shipping city`
- je vyplnene `shipping ZIP`
- je vyplnene `shipping country`
- je vyplneny aspon email alebo telefon
- shipping cena dava zmysel
- item ceny davaju zmysel
- support brief je dostatocny pre dalsie oddelenia

Ak nieco z toho chyba, radsej este neexportuj.

## Odporucane standardy vypisovania

Pre konzistenciu odporucam:

- krajiny pisat ako 2-letter kody
  priklad `DE`, `FR`, `US`
- meny pisat ako 3-letter kody
  priklad `EUR`, `USD`
- platformy pisat stale rovnako
  nie raz `Wapp`, raz `Whatsapp`, raz `WA`
- material / finish pisat konzistentne
  napr. stale `Gloss`, nie raz `gloss`, raz `Glossy`
- pri itemoch pisat plny zmysluplny nazov
  nie len `graphics`

## Co je povinne hned a co neskor

### Pri zalozeni leadu staci

- `source channel`
- `social platform`
- `social handle` alebo `customer name`
- zakladna predstava co chce

### Pred pracou grafika by uz idealne malo byt

- bike data
- graphics brief
- item typu `G`
- rider name / rider number, ak sa pouziva
- poznamky k materialu a finishu
- fotky motorky alebo aspon referencia

### Pred exportom musi byt

- official number
- shipping udaje
- kontakt
- itemy
- ceny

## Realne priklady

### Jednoducha graphics-only objednavka

Header:
- `Status`: `DEPOSIT_PAID`
- `Source channel`: `Instagram`
- `Social platform`: `Instagram`
- `Social handle`: `@mx_jason`
- `Bike brand`: `KTM`
- `Bike model`: `SX 250`
- `Bike year`: `2024`
- `Rider number`: `27`
- `Currency`: `EUR`

Item:
- `Type`: `G`
- `Title`: `Custom graphics kit KTM SX 250 2024`
- `Unit price`: `219.90`
- `Material`: `Chrome`
- `Finish`: `Gloss`
- `Rider number`: `27`

Payment:
- `Kind`: `DEPOSIT`
- `Amount`: `100`

### Graphics + upsell seat cover + plastics

Povodny zaujem:
- graphics

Neskor doplnene:
- seat cover
- plastics

Postup:
- graphics item nechaj normalne
- seat cover item oznac `Mark as upsell`
- plastics item oznac `Mark as upsell`
- do `Upsell source` napis dovod

## Limity aktualnej verzie

Aktualna verzia zatial:
- neuploaduje fotky priamo do modulu
- pracuje s URL / textovymi referenciami
- nema este tvrde dropdown validacie na kazdy material a finish
- nema este specialny reporting dashboard pre upsell statistiky

To je v poriadku. Modul je pripraveny tak, aby sa dal dalej rozsirovat bez rozbitia workflow.
