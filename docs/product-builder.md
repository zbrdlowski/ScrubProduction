# Produkty a listingy — prvá etapa

## Dátum vytvorenia a denné skupiny

Saved products má prvý stĺpec Created vo formáte DD.MM.YY; po nabehnutí myšou sa zobrazí celý uložený dátum a čas. Každý deň začína tmavým pásom s dátumom DD.MM.YYYY a modrými čiarami, rovnako ako oddeľovače v Orders.

Server vyberie najviac 100 najnovších výsledkov podľa created_at a tie zobrazí chronologicky od najstarších po najnovšie, v rovnakom smere ako Orders. Pri rovnakom čase rozhoduje ID. Vyhľadávanie sa aplikuje pred limitom; pri úpravách produktu sa jeho deň vytvorenia nemení. Formátovanie v prehliadači nemení časovú zónu uloženého dátumu.

Nasadenie: nahrajte obsah **.cache/product-builder/product-builder-dates.zip** so zachovaním adresárov a obnovte stránku cez Ctrl+F5. Balík obsahuje includes/product_builder.php, assets/product_builder.css a scripts/product_builder/{app.js,service.php}. SQL import nie je potrebný. Kompletný product-builder-upload.zip je aktualizovaný.

Overenie: php tests/product_builder_dates_test.php a node tests/product_builder_dates_ui_test.js; výber posledných 100 záznamov, poradie, zhodné časy, filtrovanie, denné skupiny, rozbalenie komponentov a mobilné zobrazenie.

## Oddelenie editora a výpisu

Editor má samostatný tmavý rám, nadpis Product editor, zlatý horný okraj a označenie New product / Editing product. Rám zahŕňa celý formulár aj spodnú lištu uloženia. Saved products začína po samostatnej medzere a má vlastný tyrkysový rám a hlavičku s vyhľadávaním.

Výpis používa kompaktnú mriežku, striedanie sivých a tlmených tyrkysových riadkov a rovnaké farby bundle ako Orders. Farba riadku je vizuálne striedanie, nepredstavuje stav objednávky. Rozbalený riadok je zvýraznený modro a jeho komponenty zostávajú v tmavšom detaile. Na úzkych obrazovkách zostáva tabuľka vo vlastnom posuvnom kontajneri.

Nasadenie: nahrajte obsah **.cache/product-builder/product-builder-layout.zip** so zachovaním adresárov a použite Ctrl+F5. Obsahuje includes/product_builder.php, assets/product_builder.css a scripts/product_builder/app.js. SQL import nie je potrebný. Kompletný product-builder-upload.zip je aktualizovaný.

## Prefix automatického SKU

Nové produkty s nevyplneným SKU dostanú prefix podľa zvoleného Bundle type, napríklad P_, G_, S_, GP_ alebo GFPS_. Nasleduje pôvodný náhodný kód s 20 hexadecimálnymi znakmi. Ručne zadané aj existujúce SKU zostávajú zachované vrátane starého formátu DS-. Neskoršia zmena bundle automaticky neprepisuje SKU.

Nasadenie: nahrajte **scripts/product_builder/service.php** alebo obsah **.cache/product-builder/product-builder-sku-prefix.zip** so zachovaním adresárov. SQL import nie je potrebný. Kompletný product-builder-upload.zip obsahuje aktuálnu verziu.

## Aktuálne zásoby plastov v detaile komponentov

- Rozbalený riadok Saved products aj detail na čítanie obsahujú pri listingu s komponentmi P stĺpce Preferred supplier a Stock quantity. Údaje sa načítajú z items.main_supplier a items.quantity podľa uloženého Product code / reference = items.barcode.
- Quantity zostáva počet kusov komponentu v listingu. Stock quantity zobrazuje aktuálny skladový údaj; pri každom novom rozbalení sa načíta znova. Údaje skladu sa nekopírujú do listingu a nemenia jeho uložené názvy, farby, množstvá ani nastavenia.
- Nulová zásoba sa zobrazí ako 0, nevyplnená ako pomlčka. Neznámy alebo duplicitný barcode zobrazí pomlčku s vysvetlením pri nabehnutí myšou. Duplicitné položky sa nesčítavajú a nezvyšujú počet riadkov komponentov. Ostatné typy nemajú skladové údaje; listing bez plastov nezobrazuje tieto dva stĺpce.
- Nasadenie po update 008: nahrajte obsah **.cache/product-builder/product-builder-inventory-details.zip** so zachovaním adresárov a obnovte stránku cez Ctrl+F5. Balík obsahuje includes/product_builder.php a scripts/product_builder/{api.php,app.js,service.php}. **SQL import nie je potrebný.** Kompletný product-builder-upload.zip obsahuje aj túto zmenu.
- Overenie: php tests/product_builder_inventory_test.php a node tests/product_builder_inventory_ui_test.js; dočasné databázové tabuľky a simulované API, bez zápisu do reálnych zásob alebo listingov.

## Krabica a celková hmotnosť produktu — update 008

- Spodná lišta so SKU a cenami obsahuje Box a Weight (kg). Výber krabice je voliteľný; ponuka zobrazuje názov a rozmery v cm. Pri editácii sa obnovia obe uložené hodnoty.
- Hmotnosť produktu sa zadáva ručne ako celková hmotnosť zabaleného produktu vrátane krabice. Je nezávislá od hmotnosti prázdnej krabice; výber ani úprava krabice ju neprepočítavajú. Podporuje bodku aj čiarku, najviac tri desatinné miesta a rozsah 0 až 999999.999 kg.
- Nevyplnená hmotnosť je NULL a vo výpise sa zobrazí pomlčka; nula je platná hodnota 0.000 kg. Staršie produkty zostávajú bez priradenej krabice a bez hmotnosti. Zápis staršieho klienta, ktorý tieto polia neposiela, zachová už uložené hodnoty.
- Saved products aj detail na čítanie zobrazujú krabicu s rozmermi a celkovú hmotnosť. Neskoršie úpravy názvu alebo rozmerov krabice sa prejavia vo výpise; ručná hmotnosť produktu zostáva zachovaná.
- Krabicu používanú v produktoch nemožno vymazať. Editor ukazuje počet použití; ochranu zabezpečuje server aj cudzí kľúč. Pred zmazaním treba zmeniť alebo zrušiť jej priradenie.

Nasadenie na existujúcu inštaláciu s update 007:

1. Importujte **db/product_builder_update_008.sql** cez phpMyAdmin. Pridá nullable box_id, weight_kg a cudzí kľúč do product_builder_products. Opakovaný import zachová existujúce údaje.
2. Nahrajte obsah **.cache/product-builder/product-builder-update-008.zip** so zachovaním adresárov. Sedem súborov: includes/product_builder.php, assets/product_builder.css, scripts/product_builder/{api.php,app.js,helpers.php,service.php,boxes.php}.
3. Obnovte stránku cez Ctrl+F5.

Kompletný inštalačný SQL aj súhrnná aktualizácia zahŕňajú update 008. Diagnostika má 20 kontrol; úplný runtime ZIP obsahuje 19 súborov. Automatický výpočet z hmotností komponentov zostáva ďalšou etapou.

Overenie: php tests/product_builder_packaging_test.php, php tests/product_builder_update_all_test.php a node tests/product_builder_packaging_ui_test.js. Databázové testy overujú aj zachovanie údajov pri opakovanom importe; UI testy používajú simulované API.

## Krabice — update 007

- Horné tlačidlo Boxes otvorí samostatný číselník krabíc s vyhľadávaním, vytvorením, editáciou a zmazaním.
- Operátor zadáva názov (najviac 150 znakov), Length, Width a Height v cm v troch samostatných vstupoch a Weight v kg. Rozmery musia byť kladné, s najviac dvoma desatinnými miestami; hmotnosť môže byť nulová a má tri desatinné miesta (presnosť 1 g). Predvolená hodnota formulára je 0 kg.
- Tabuľka product_builder_boxes má stabilné id, názov, length_cm, width_cm, height_cm, weight_kg, verziu a časové údaje. Názvy sú jedinečné bez ohľadu na veľkosť písmen. Zmena aj zmazanie kontrolujú verziu záznamu.
- Hmotnosť patrí prázdnej krabici. Výber krabice pri produkte a samostatnú ručnú celkovú hmotnosť dopĺňa update 008; výpočet hmotnosti komponentov je ďalšia etapa.
- Zobrazenie používa products.view, zápis products.manage a existujúci CSRF token. Oprávnenia používateľov sa nemenia.

Nasadenie na existujúcu inštaláciu s update 006:

1. Importujte **db/product_builder_update_007.sql** do databázy aplikácie. Vytvorí prázdnu knižnicu; nemení listingy, ceny ani komponenty. Opakovaný import zachová existujúce krabice.
2. Nahrajte **.cache/product-builder/product-builder-update-007.zip** so zachovaním adresárov. Šesť súborov: includes/product_builder.php, assets/product_builder.css, scripts/product_builder/{api.php,app.js,helpers.php,boxes.php}.
3. Obnovte stránku cez Ctrl+F5.

Aktuálny kompletný SQL a súhrnná aktualizácia zahŕňajú aj update 008; diagnostika má 20 kontrol. Kompletný runtime ZIP obsahuje 19 súborov.

## Ceny produktu a platforiem — update 006

- Cenové polia sú v spodnej lište spolu so SKU a uložením. Na širokej obrazovke tvoria jeden riadok, na menších obrazovkách sa zalomia. Vysvetlivka SKU je pod informačnou ikonou.
- Každý produkt má Recommended price a samostatné ceny Web, eBay a Amazon. Každá suma má vlastnú menu; zmena meny sumu neprepočítava.
- Odporúčaná cena je povinná pri vytvorení a uložení úprav. Platformové ceny sú voliteľné. Prázdna cena je NULL a v zozname sa zobrazí pomlčka; nula je platná cena 0.00. Existujúcim produktom sa cena nedopočítava.
- Ceny sú pri celom produkte, nie pri jednotlivých komponentoch. Štyri stĺpce výpisu a detail pre čítanie zobrazujú sumu aj menu.
- Mena je samostatne uložená aj pri nevyplnenej platformovej cene. Výber: EUR, USD, GBP, CZK, PLN, HUF, CHF, SEK, NOK, DKK, CAD, AUD, NZD, JPY. Suma podporuje maximálne dve desatinné miesta, bodku aj čiarku a rozsah 0 až 99999999.99.
- Nemenia sa údaje na externých platformách; ceny sa ukladajú do tohto interného modulu.

Nasadenie na existujúcu inštaláciu s update 005:

1. Importujte **db/product_builder_update_006.sql** do databázy aplikácie. Pridá osem stĺpcov do product_builder_products. Pôvodné listingy, SKU, komponenty aj UNI ceny ostávajú zachované. Opakovaný import nemení už zadané ceny.
2. Nahrajte **.cache/product-builder/product-builder-update-006.zip** so zachovaním adresárov. Päť súborov: includes/product_builder.php, assets/product_builder.css, scripts/product_builder/{app.js,helpers.php,service.php}.
3. Obnovte stránku cez Ctrl+F5.

Pri neistom stave starších aktualizácií použite aktuálny db/product_builder_update_all.sql, ktorý zahŕňa 002 až 008. Záverečná kontrola má 20 riadkov OK. Kompletný product_builder_phpmyadmin.sql aj product-builder-upload.zip sú aktualizované.

Overenie: php tests/product_builder_prices_test.php, php tests/product_builder_update_all_test.php a node tests/product_builder_prices_ui_test.js; prehliadačové testy používajú CLI fixture a simulované API.

Stránka: `index.php?page=product_builder`, samostatná položka **Products & listings** v menu.
Modul nepoužíva pôvodný Product Listing Catalog ani tabuľky `scrub_catalog_*`.

Frontend tohto modulu vrátane validácií a hlásení je v angličtine.

## Funkcie

- 31 kombinácií G (Graphics), F (Fitting), P (Plastics), S (Seat cover), T (Trim Kit), vrátane GP, GFP, GPS, GFPS, PF, PS, TFP a TFPS.
- Formulár zobrazuje iba typy položiek daného bundle. Každý typ môže obsahovať viac položiek. Prepínanie typov v rozpracovanom formulári zachová ich hodnoty do obnovenia stránky.
- Špecifická kategória: modal značka → model → roky/modelcode zo `scrubdata`. Nový model sa vytvára pôvodným nástrojom Models Database v novej karte; potom kliknite na **Refresh models**. Platí existujúce oprávnenie editora Models Database.
- UNI kategória: značka → názov kategórie, s tooltipom zahrnutých modelov. Správa umožňuje pridať, upraviť alebo vymazať nepoužívanú kategóriu a upraviť obe ceny.
- Produkty sa ukladajú transakčne s položkami. SKU je možné zadať pri vytvorení aj zmeniť pri editácii, napríklad na existujúce SKU zo Shoptetu. Limit je 80 znakov; úvodné nuly a veľkosť písmen sa zachovajú, okrajové medzery sa odstránia. UNIQUE index odmietne duplicity aj pri rozdielnej veľkosti písmen. Prázdne SKU nového produktu vytvorí server vo formáte `Bundle type_` + 20 náhodných hexadecimálnych znakov, napríklad `P_`, `G_`, `S_` alebo `GP_` ako prefix. Existujúce SKU sa pri bežnej úprave zachová a nemožno ho vymazať.
- Komponent P má pole **Product code**: presná zhoda s `items.barcode` načíta **Component name** z `items.description` a **Color** z `items.color`. Obe polia možno upraviť. Neznámy alebo nejednoznačný kód zobrazí hlásenie a umožní ručné zadanie. Uložený názov a farba sú súčasťou komponentu; neskoršie zmeny skladu ich samy neprepíšu.
- V zozname **Saved products** tlačidlo v stĺpci **Components** rozbalí typy, názvy, kódy, farby, množstvá a poznámky komponentov. Funguje aj pre používateľov s prístupom iba na čítanie.
- Identifikátor odoslania zabraňuje vytvoreniu druhého produktu pri opakovanom odoslaní rovnakého formulára. Verzia záznamu zabraňuje prepísaniu novších úprav iného používateľa.

## Verzie grafiky — update 005

- Komponent G má hneď za Graphics design dropdown Version: ST, LE, R1 až R10. Seat Covers zostávajú bez tohto poľa.
- Výber dizajnu a verzie vytvorí Component name, napríklad Anomally R1. Názov možno ručne upraviť a doplniť. Text dopísaný za automatický názov sa pri zmene výberu zachová. Samotné otvorenie listingu alebo obnova knižnice uložený názov neprepisuje.
- Každá grafická položka má vlastný graphics_version_id. Pri editácii, prepnutí bundle a uložení sa výber obnoví; rozbalený listing zobrazuje aj verziu. Pri starších listingoch môže verzia zostať prázdna.
- Horné tlačidlo Graphics versions sprístupní pridanie, premenovanie, poradie a zmazanie nepoužívanej verzie. Používané verzie sú chránené pred zmazaním. Duplicity a súbežné úpravy sa kontrolujú na serveri.

Nasadenie na existujúcu inštaláciu s update 004:

1. Importujte **db/product_builder_update_005.sql** cez phpMyAdmin. Vytvorí tabuľku verzií, dvanásť predvolených možností a nullable väzbu pri komponentoch. Opakovaný import zachová premenované verzie a zmenené poradie.
2. Nahrajte **.cache/product-builder/product-builder-update-005.zip** so zachovaním adresárov. Sedem súborov: includes/product_builder.php, assets/product_builder.css, scripts/product_builder/{api.php,app.js,helpers.php,service.php,graphics_versions.php}.
3. Obnovte stránku cez Ctrl+F5.

Aktuálny product_builder_phpmyadmin.sql obsahuje aj update 005 pre novú inštaláciu. Pôvodné listingy, názvy komponentov, výbery dizajnov a SKU zostávajú zachované.

## Dizajny Graphics a Seat Covers — update 004

- Spoločná tabuľka product_builder_designs obsahuje samostatné skupiny G a S. Z dodaného Designz.csv sa importuje 453 Graphics a 1064 Seat Covers dizajnov; päť duplicitných názvov Graphics sa zlúči.
- Vedľa UNI categories sú samostatné tlačidlá Graphics a Seat Covers. Každá skupina má vyhľadávanie, pridanie, premenovanie a zmazanie nepoužívaného dizajnu. Duplicity názvov sa kontrolujú v rámci skupiny. Súbežné úpravy chráni verzia záznamu.
- Komponenty G/S majú vyhľadávací dropdown Design. Design, Component name a Reference / code sú na desktope vedľa seba, pri G navyše Version hneď za dizajnom; na mobile sa skladajú pod seba. Výber vyplní Component name, ktorý možno ďalej upraviť. Listing uloží design_id aj vlastný názov komponentu a všetky doplnkové polia. Pri editácii sa výber obnoví. Staršie listingy môžu zostať bez väzby na dizajn.
- Premenovanie v knižnici nemení uložené názvy komponentov. Používaný dizajn nemožno zmazať; jeho väzby chránia aj cudzie kľúče. Rozbalený listing ukazuje priradený dizajn.
- Opakovaný SQL import zachová premenované dizajny vďaka stabilnému seed_key. Dopĺňa chýbajúce názvy; zmazané pôvodné CSV dizajny môže opätovný import znovu pridať. CSV obsah nie je pri bežnom načítaní stránky importovaný.

Nasadenie na existujúcu inštaláciu s update 003:

1. Importujte **db/product_builder_update_004.sql** cez phpMyAdmin. Vytvorí tabuľku, importuje dizajny a pridá nullable design_id ku komponentom. Existujúce produkty, SKU a názvy zostávajú zachované.
2. Nahrajte obsah **.cache/product-builder/product-builder-update-004.zip** do koreňa aplikácie so zachovaním adresárov. Obsahuje deväť súborov: includes/product_builder.php, assets/product_builder.css, scripts/product_builder/{api.php,app.js,helpers.php,service.php,designs.php}, plugins/select2/css/select2.min.css a plugins/select2/js/select2.full.min.js.
3. Obnovte stránku cez Ctrl+F5. Správa používa existujúce products.manage; zobrazenie products.view.

Pri novej inštalácii stačí aktuálny product_builder_phpmyadmin.sql, ktorý obsahuje aj dizajny a update 004. Kompletný runtime ZIP je product-builder-upload.zip.

## Vzhľad Orders a doplnkové polia — update 003

- Product type a Category & compatibility sú na desktope vedľa seba. Na úzkom displeji sa skladajú pod seba.
- Product summary je zbaliteľný. SKU a uloženie sú v spodnej lište; komponenty využívajú celú šírku.
- Farebné riadky G/P/F/S/T zodpovedajú Custom Orders. Product code / barcode a Color sú pod Component name. Upsell ani Waiting sa nezobrazujú.
- Aktívne doplnkové polia, poradie a možnosti sa načítajú z existujúceho product_spec_options cez spoločné helpery Custom Orders. Nastavenia sú uložené osobitne pri každom komponente v options_json. Staršie poznámky sa zobrazujú v Scrub Note alebo My Item Note; ich obsah sa zachová.
- Rozbalený listing zobrazuje aj uložené nastavenia. Aj neaktívne alebo odstránené definície sa pri ďalšej editácii zachovajú ako uložené polia.

Nasadenie na existujúcu inštaláciu s update 002:

1. Importujte db/product_builder_update_003.sql do databázy aplikácie cez phpMyAdmin. Pridá iba nullable stĺpec options_json; nemení existujúce produkty ani ich SKU, farby a poznámky.
2. Nahrajte obsah .cache/product-builder/product-builder-update-003.zip so zachovaním adresárov. Obsahuje includes/product_builder.php, assets/product_builder.css a scripts/product_builder/{api.php,app.js,helpers.php,service.php,specs.php}.
3. Obnovte stránku. Existujúce spoločné súbory includes/get_order_detail_product_spec_selects.php a scripts/orders/department_config.php aj tabuľka product_spec_options musia byť dostupné rovnako ako pri Custom Orders.

Pri novej inštalácii stačí aktuálny product_builder_phpmyadmin.sql; obsahuje aj aktualizácie 002, 003 a 004.

## Aktualizácia existujúcej inštalácie — update 002

1. V existujúcej databáze aplikácie importujte cez phpMyAdmin **db/product_builder_update_002.sql**. Pridá stĺpec farby bez zmeny existujúcich produktov, SKU a položiek. Opakovaný import je bezpečný.
2. Nahrajte aktualizované súbory: `includes/product_builder.php`, `assets/product_builder.css`, `scripts/product_builder/api.php`, `scripts/product_builder/app.js`, `scripts/product_builder/helpers.php` a `scripts/product_builder/service.php`. Hotový balík je `.cache/product-builder/product-builder-update-002.zip`; obsahuje iba týchto šesť súborov v správnych adresároch.
3. Obnovte stránku `index.php?page=product_builder`. Pôvodné produkty ostanú uložené. Pri otvorení editácie sa chýbajúca farba komponentu P automaticky dohľadá podľa kódu v sklade. Uložené farby, názvy a poznámky zostanú zachované; doplnenú farbu zapíše až Save product changes.

Aktualizovaný kompletný inštalačný SQL už obsahuje aj update 002. Pri existujúcej inštalácii stačí menší update uvedený vyššie.

## Nasadenie cez phpMyAdmin

V phpMyAdmin vyberte existujúcu databázu DarkScrub a cez **Import** načítajte `db/product_builder_phpmyadmin.sql` ako UTF-8. Tento súbor už obsahuje schému, 53 UNI kategórií s modelcode z lokálnej inštalácie a registráciu oprávnení. Pri tomto postupe netreba samostatne importovať `product_builder.sql`, `product_builder_permissions.sql` ani spúšťať CLI inštalátor.

Závisí od existujúcich tabuliek aplikácie `scrubdata`, `auth_permissions`, `auth_roles` a `auth_role_permissions`. Vytvorí šesť nových trvalých tabuliek uvedené nižšie; pomocné tabuľky importu sú dočasné. Opakovaný import zachová existujúce kategórie a ich úpravy. Kolízie modelcode s údajmi servera preskočí a vypíše na konci importu; také kategórie doplňte cez **Manage UNI categories**, kde sa vygeneruje voľný kód. Kontrolný výsledok má byť `expected_seed_categories = 53`, `installed_seed_categories = 53`, `missing_seed_categories = 0`.

Na server nahrajte nasledujúcich 19 súborov do rovnakých relatívnych ciest v koreňovom adresári aplikácie:

```text
index.php
includes/auth.php
includes/sidebar.php
includes/product_builder.php
assets/product_builder.css
scripts/modelcode_helpers.php
scripts/scrub_model_manage.js
scripts/scrub_model_manage_ajax.php
scripts/product_builder/api.php
scripts/product_builder/app.js
scripts/product_builder/helpers.php
scripts/product_builder/service.php
scripts/product_builder/specs.php
scripts/product_builder/csv.php
scripts/product_builder/designs.php
scripts/product_builder/graphics_versions.php
scripts/product_builder/boxes.php
plugins/select2/css/select2.min.css
plugins/select2/js/select2.full.min.js
```

Súbory `includes/conn.php`, testy, CLI inštalátor a zdrojové CSV nie sú súčasťou tohto uploadu. SQL importuje kategórie priamo. Po nasadení otvorte `index.php?page=product_builder`. Administrátor s permission 900 má prístup; ostatným podľa potreby prideľte rolu **Products manager** cez **User Permissions**.

## Inštalácia cez CLI (alternatíva)

1. `php scripts/product_builder/install.php`
   - Vytvorí šesť nových tabuliek podľa `db/product_builder.sql`.
   - Importuje 1517 dizajnov z update 004 a 12 verzií grafiky z update 005.
   - Načíta 53 kategórií z dodaného `scripts/product_builder/UniCategories.csv`.
   - Generuje UNI modelcode rovnakým algoritmom ako Models Database, s kontrolou proti konkrétnym modelom aj UNI kategóriám.
2. Spustite `db/product_builder_permissions.sql` v databáze aplikácie. Zaregistruje oprávnenia `products.view`, `products.manage` a nepridelenú rolu **Products manager**. Nepriraďuje rolu zamestnancom ani nemení ich doterajšie prístupy.
3. Administrátor s permission 900 má prístup. Ďalším používateľom prideľte rolu Products manager cez **User Permissions**, prípadne samostatné oprávnenie `products.view` iba na čítanie. Na správu je potrebné aj `products.manage`.

Inštalátor nemení existujúce produkty, modely ani starý katalóg. Pri opakovanom importe sa preskočí existujúca dvojica značka + názov kategórie; jej modelcode, ceny a zahrnuté modely sa zachovajú. Premenovaná kategória predstavuje z pohľadu ďalšieho CSV importu inú dvojicu.

Tabuľky:

- `product_builder_uni_categories`: kategórie, modelcode, zahrnuté modely, ceny a verzia.
- `product_builder_products`: produkt, unikátne editovateľné SKU, bundle, kategória, jej identifikačné údaje, ceny s menami, priradená krabica, ručná celková hmotnosť, poznámka a verzia.
- `product_builder_components`: jednotlivé typované položky, referencia, farba, množstvo, detail, doplnkové nastavenia, väzba na dizajn a poradie.
- `product_builder_designs`: skupina G/S, názov, stabilný kľúč importu a verzia.
- `product_builder_graphics_versions`: názov verzie grafiky, poradie, stabilný kľúč importu a verzia záznamu.
- `product_builder_boxes`: názov krabice, tri rozmery v cm, hmotnosť v kg a verzia záznamu.

## CSV

Import rozpozná pôvodné hlavičky aj názvy `Značka`, `Názov kategórie`, `Zahrnuté modely`, `No license`, `Licensed`.
Pôvodný nepomenovaný tretí stĺpec sa mapuje na zahrnuté modely. Podporuje UTF-8/BOM, Windows-1250, čiarku, bodkočiarku a tabulátor. Limity: 2 MB a 2000 kategórií. Neplatný alebo duplicitný riadok zastaví import pred uložením; celé uloženie je transakčné. Ceny sa uchovávajú bez predpokladu meny; CSV menu neuvádza.

## Overenie

```sh
php tests/product_builder_test.php
php tests/product_builder_test.php --db
php tests/product_builder_designs_test.php
php tests/product_builder_graphics_versions_test.php
php tests/product_builder_boxes_test.php
php tests/product_builder_packaging_test.php
php tests/authorization_test.php
node --check scripts/product_builder/app.js
```

Databázový test používa dočasné tabuľky v jednej relácii; v reálnych tabuľkách nevytvára skúšobné produkty ani nemení kategórie.

Test UI potrebuje lokálny PHP webserver, Chrome a balík Playwright:

```sh
php tests/product_builder_ui_fixture.php
node tests/product_builder_ui_test.js
node tests/product_builder_legacy_color_ui_test.js
node tests/product_builder_designs_ui_test.js
node tests/product_builder_graphics_versions_ui_test.js
node tests/product_builder_modal_ui_test.js
node tests/product_builder_boxes_ui_test.js
node tests/product_builder_packaging_ui_test.js
```

Prípadne nastavte `NODE_PATH` na adresár s Playwright. Predvolená adresa je `http://localhost/darkscrub/`. Pre inú adresu použite `PRODUCT_BUILDER_BASE_PATH` pri vytvorení fixture a `PRODUCT_BUILDER_TEST_URL` pre URL výsledného `.cache/product-builder/preview.html`. UI fixture načítava Bootstrap 5 aj 4 v rovnakom poradí ako index.php. Modul obsluhuje otvorenie aj zatvorenie cez jednu inštanciu rozhrania; test kontroluje X, Cancel, focus a odstránenie backdropu. UI test používa mockované API; snímky ukladá do `.cache/product-builder/`.

## Ďalšie etapy

Databáza dizajnov Graphics a Seat Covers je implementovaná. Väzby na ďalšie uložené produkty zatiaľ nie sú implementované. Modul zatiaľ neposiela listingy na e-shopy a nespáruje importované objednávky; pripravuje trvalú identitu produktu pre tieto budúce integrácie.

## Neúplná databázová aktualizácia na serveri

Banner "The product database needs to be installed or updated" kontroluje šesť tabuliek modulu a stĺpce color, options_json, design_id a graphics_version_id v product_builder_components, plus osem cenových stĺpcov, box_id a weight_kg v product_builder_products. Update 005 nenahrádza staršie aktualizácie.

Pri existujúcej inštalácii s neznámym stavom aktualizácií importujte do databázy aplikácie **db/product_builder_update_all.sql**. Obsahuje základné tabuľky a aktualizácie 002 až 008 v správnom poradí. Zachová listingy, SKU, komponenty, kategórie, vlastné názvy a výbery. Dopĺňa aj chýbajúce predvolené dizajny a verzie; predtým zmazané predvolené záznamy sa môžu vrátiť. Nemení používateľské oprávnenia a neimportuje UNI kategórie.

Záverečná kontrola vypíše názov zvolenej databázy a dvadsať riadkov so stavom OK/MISSING. Rovnakú kontrolu bez zmien dát možno spustiť cez **db/product_builder_check.sql**. Ak sú všetky riadky OK, ale aplikácia stále hlási chýbajúcu schému, overte, či produkčné PDO pripojenie používa tú istú databázu a server. Pri chybe importu riešte prvú SQL chybu.
