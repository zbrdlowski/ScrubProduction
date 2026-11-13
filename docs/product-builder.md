## Európsky dátum v modale zalistovania

Dátum v modale Web/eBay/Amazon sa zobrazuje a zadáva pevne vo formáte dd.mm.yyyy v SK aj EN, nezávisle od nastavenia prehliadača. Kalendár zostáva dostupný. Pred odoslaním sa dátum overí vrátane počtu dní a priestupného roka a prevedie na pôvodný ISO formát pre API/databázu. Nový SQL import netreba.

Nasadenie: **.cache/product-builder/product-builder-european-date.zip**, súbory scripts/product_builder/app.js a page.php. Overené v prehliadači s locale en-US: zobrazenie, opätovné otvorenie, konverzia do ISO, zadanie cez kalendár, neplatné dátumy a mobil. Kompletný upload ZIP je aktualizovaný.

## Stĺpce platforiem, predaj a dátum zalistovania (13. 11. 2026)

Hlavná tabuľka Builder aj Listings má 19 stĺpcov. Za IMG je samostatná úzka bunka s editáciou a kopírovaním adresára; kopírovanie vrátane vibrácie a prístupu na čítanie je zachované. Každá platforma má tri bunky: stav s cenou, ikony otvorenia/editácie a počet predaných kusov s dátumom zalistovania. Odkaz otvára nový tab. Do not list je červená #813d43, List fialová #713d81 a Listed zelená #276342, zhodne s IMG. Rovnaký počet stĺpcov používajú denné oddeľovače, detaily, načítavanie, chyby aj prázdny výsledok. Na úzkej obrazovke sa posúva iba tabuľka.

Modal Listed obsahuje povinný editovateľný dátum. Pri novom zalistovaní predvyplní miestny dnešný dátum; pri existujúcom Listed s neznámym dátumom ponechá pole prázdne na doplnenie. Dátum sa ukladá ako DATE do product_builder_channel_listings.listed_date, je nezávislý pre každú platformu a pri zmene stavu zostáva zachovaný. SQL starším záznamom nepriraďuje odhadovaný dátum. Cena, mena, URL a dátum sa ukladajú spolu so zachovaním kontroly verzie.

Počty sú kumulatívne predané kusy zo všetkých evidovaných objednávok pre presné SKU, nie počet objednávok ani živá štatistika e-shopu. Web = order_sources.SHOPTET; eBay = EBAY; Amazon = AMAZON. Shoptet/Amazon sa párujú cez order_items.sku, eBay cez seller SKU v custom_label (pri prázdnom custom_label cez sku). Nejde o prefixové ani LIKE vyhľadávanie. Počítajú sa kladné qty, vynechajú sa zmazané a zrušené položky/objednávky, automaticky vytvorené komponenty označené options_json._auto_generated a samostatné opakovania/reklamácie/náhrady vo follow-up objednávkach. Rozdelené objednávky SPLIT sa započítajú cez zostávajúce a presunuté množstvá. Počet nezávisí od ručne zadaného dátumu zalistovania. Pri chýbajúcom zdroji platformy sa zobrazuje pomlčka s vysvetlením; nula znamená zavedený zdroj bez zodpovedajúceho predaja. Lokálna databáza zatiaľ nemá zdroj AMAZON.

Nasadenie na existujúcu inštaláciu s update 010: **najprv importovať db/product_builder_update_011.sql cez phpMyAdmin**, potom nahrať obsah **.cache/product-builder/product-builder-channel-columns.zip** so zachovaním adresárov. ZIP obsahuje sedem webových súborov a SQL 011; SQL treba importovať, samotný upload nestačí. Pri neznámom stave starších aktualizácií je pripravený product_builder_update_all.sql. Kompletný product-builder-upload.zip obsahuje 28 webových súborov vrátane nového sales.php. SQL inštalácie, CLI inštalátor a diagnostika sú aktualizované na 32 požadovaných objektov. Lokálna vývojová databáza už má update 011.

Overenie: dočasné tabuľky pre výpočet predaja (bundle, duplicitné skutočné nákupy, presné SKU, eBay label, zrušenie/zmazanie, SPLIT, náhrady, chýbajúci zdroj a Amazon), ukladanie/validácia dátumov a opakovaný SQL import. UI používa simulované API a kontroluje presunuté ikony, kopírovanie, ceny, dátumy, čítacie práva, SK/EN a mobil. Testy nemenia reálne objednávky ani produkty.

## Zalistovanie na Web, eBay a Amazon

Každá platforma má v hlavnej tabuľke samostatný dropdown: **Do not list / Nelistovať** (červená), **List / Zalistovať** (zelená) a **Listed / Zalistované** (modrá). Predvolený stav pre nové aj existujúce produkty je Do not list; existujúca cena sama o sebe neznamená, že je produkt zalistovaný. Stavy Do not list a List sa uložia hneď po zmene. Listed otvorí modal s cenou, menou a URL; stav sa uloží až po potvrdení platných údajov. Zrušenie modalu zachová pôvodný stav.

Cena z pôvodného formulára Web/eBay/Amazon slúži ako návrh a predvyplní modal. Skutočná predajná cena sa uchováva zvlášť, s vlastnou menou; automatický prepočet mien sa nerobí. Kým stav nie je Listed, bunka ukazuje pôvodnú navrhovanú cenu, pri Listed ukazuje skutočnú cenu. Odkaz Open listing / Otvoriť listing otvorí uložené HTTP(S) URL v novom tabe. Ceruzka umožní cenu, menu a URL upraviť. Pri zmene z Listed na iný stav zostanú posledná skutočná cena a URL uložené pre opätovné použitie. Nula je platná cena.

Nová tabuľka product_builder_channel_listings obsahuje najviac jeden záznam na produkt a platformu. Uchováva stav, URL, skutočnú cenu, menu, vlastnú verziu, dátumy a posledného editora. Zmeny nepíšu do rozpracovaného formulára, pôvodných cien, SKU ani komponentov. Verzia každého kanála bráni prepísaniu novšej práce kolegu; po chybe možno načítať aktuálne údaje. Všetci prihlásení môžu údaje a odkazy vidieť, editácia používa existujúce products.view + products.manage a CSRF ochranu. UI funguje v SK/EN v Builderi aj Listings, vrátane detailového modalu.

Nasadenie na existujúci server: **najprv importujte db/product_builder_update_010.sql cez phpMyAdmin do aplikačnej databázy**, potom nahrajte webové súbory z **.cache/product-builder/product-builder-channel-listings.zip** so zachovaním adresárov. Balík obsahuje osem webových súborov a SQL 010; samotné nahratie SQL na server nie je import. SQL neprerába staršie ceny ani listingy a možno ho importovať opakovane. Pri neznámom stave starších aktualizácií použite product_builder_update_all.sql. Kompletný product-builder-upload.zip má 27 webových súborov. Inštalačné SQL, CLI inštalátor aj diagnostika sú aktualizované; diagnostika má 31 objektov. Lokálna vývojová databáza má update 010 aplikovaný.

Overenie: backendový test používa dočasné tabuľky a kontroluje validáciu, samostatné meny a kanály, verzie a zachovanie produktu. SQL test používa izolované tabuľky, kontroluje novú aj starú inštaláciu a opakovaný import. UI test so simulovaným API kontroluje stavy a farby, zrušenie aj uloženie modalu, nulovú cenu, chyby, konflikt kolegov, zachovanie rozpracovaného produktu, odkazy v novom tabe, čítacie práva, SK/EN a mobil. Reálne produkty sa pri testoch nemenia.

## Kompaktný detail Seat Covers

Seat cover používa rovnakú kompaktnú tabuľku ako grafika a fitting. Horný riadok obsahuje Type, Component name, Design, Reference / code, Quantity a Preferred supplier (Scrub). Pod ním sú Patch, Side Brand Patches, Waterproof Seams, Enduro Pocket, Printed Ribs a Complexity v samostatných tabuľkových bunkách. Vyplnené poznámky zostávajú pod parametrami a žlté označenie typu S je zachované. Platí v rozbalenom výpise aj detailovom modale, pre staršie aj nové listingy. Údaje ani formulár sa nemenia.

Nasadenie: **.cache/product-builder/product-builder-seat-cover-layout.zip** obsahuje scripts/product_builder/{app.js,page.php}. **SQL import netreba.** Kompletný product-builder-upload.zip je aktualizovaný.

Overenie: existujúce testy komponentových detailov a dodávateľov prešli vrátane všetkých parametrov S, samostatného aj zmiešaného bundla, modalu, prekladov a mobilu. API je simulované a reálne listingy sa nemenia. Výsledný seat cover blok bol vizuálne skontrolovaný.

## Grafika: listingové parametre a Number Yes/No

V Product Builderi sú Rider name, Name Font, Number Font a Printer sivé a disabled. Ich už uložené hodnoty sa pri editácii aj opätovnom uložení zachovajú, ale v rozbalenom detaile ani detailovom modale sa tieto polia nezobrazujú. Platí aj pre grafiku v Trim Kitoch a staršie uložené polia bez aktívnej definície. Ostatné typy komponentov a Custom Orders sa nemenia.

Number je select s voľbami Yes/No (SK: Áno/Nie) a prázdnou voľbou pre nevyplnené pole. Ukladá sa do existujúceho options_json pod graphics_number. Pôvodné číslo jazdca vrátane 0 sa pri zobrazení interpretuje ako Yes; pri ďalšom uložení sa uloží zvolená hodnota Yes/No. Prázdna hodnota zostáva nevybraná. Nevykonáva sa hromadný prepis dát.

Nasadenie: **.cache/product-builder/product-builder-graphics-listing-options.zip** obsahuje assets/product_builder.css a scripts/product_builder/{app.js,page.php}. Nahrajte so zachovaním adresárov a obnovte stránku po uložení rozpracovaného formulára. **SQL import netreba.** Kompletný product-builder-upload.zip je aktualizovaný.

Overenie: product_builder_graphics_listing_options_ui_test.js (disabled polia, Yes/No uloženie, zachovanie starých hodnôt, odstránené definície, oba detaily, preklady SK/EN, Trim Kit a mobil), existujúce testy detailov a kompletného editora. Testy simulujú API a nemenia reálne listingy. Sivé polia boli vizuálne skontrolované.

## Stlmenie stránky pri rozbalených komponentoch

Po otvorení komponentov v Builderi alebo Listings sa zvyšok stránky vrátane navigácie stlmí. Vybraný riadok produktu a jeho komponenty zostávajú na pôvodnom mieste, jasné a ohraničené. Otvorený je vždy jeden listing. Kliknutie na rozbaľovacie tlačidlo, mimo zvýrazneného bloku alebo Esc zatvorí komponenty a odstráni stlmenie. Klávesnica môže pokračovať mimo bloku; pri presune fokusu sa zvýraznenie ukončí. Editácia, otvorenie modalu a obnovenie výpisu ho tiež vyčistia. Pomalá odpoveď po zatvorení ho znova neotvorí. Kopírovanie SKU/modelcode vrátane náhradného kopírovania zachová zvýraznenie.

Nasadenie: **.cache/product-builder/product-builder-component-focus.zip** obsahuje assets/product_builder.css a scripts/product_builder/{app.js,page.php}. Nahrajte so zachovaním adresárov a obnovte stránku po uložení rozpracovaného formulára. **SQL import netreba.** Kompletný product-builder-upload.zip je aktualizovaný.

Overenie: product_builder_component_focus_ui_test.js (vrstvy stránky a navigácie, všetky spôsoby zatvárania, kopírovanie, modal, obnovenie výpisu, pomalá/chybná odpoveď a mobil), existujúce testy detailov a celého editora. Testy používajú simulované API; reálne listingy nemenia. Vzhľad bol vizuálne skontrolovaný.

## Rovnaká veľkosť riadkov parametrov grafiky

Riadok Material, Finish a ostatných parametrov grafiky má rovnakú veľkosť hlavičiek, textu a odsadenie ako identifikačná tabuľka nad ním. Výška bežnej jednoradovej hodnoty je dorovnaná; dlhé hodnoty sa naďalej zalamujú. Platí v rozbalenom výpise aj modale. Overené existujúcim testom detailov a vizuálnou kontrolou.

Nasadenie: **.cache/product-builder/product-builder-detail-row-size.zip** obsahuje assets/product_builder.css a scripts/product_builder/page.php. **SQL import netreba.** Kompletný product-builder-upload.zip je aktualizovaný.

## Kompaktné vstupy plastov a fittingu

V editačnej bunke Component name sú pri plastoch vstupy v jednom riadku v poradí Product code / barcode → Component name → Color. Pri fittingu sú vedľa seba Component name a Reference / code. Na úzkej obrazovke sa zalomia pod seba. Poradie klávesu Tab zodpovedá zobrazeniu; automatické dopĺňanie názvu a farby podľa barcode aj ukladanie zostávajú zachované.

Nasadenie: **.cache/product-builder/product-builder-component-inputs.zip** obsahuje assets/product_builder.css a scripts/product_builder/{app.js,page.php}. Nahrajte so zachovaním adresárov a obnovte stránku po uložení rozpracovaného formulára. **SQL import netreba.** Kompletný product-builder-upload.zip je aktualizovaný.

Overenie: existujúci product_builder_ui_test.js prešiel vrátane poradia a zarovnania vstupov, automatického dopĺňania plastov, viacerých komponentov, ukladania a mobilného rozloženia. API je simulované; reálne produkty sa nemenia. Desktopový formulár bol vizuálne skontrolovaný.

## Rozbalený bundle: samostatné sekcie podľa typu komponentu

Builder, Listings aj modal Detail používajú spoločný blok s farebnými sekciami G, F, P a S podľa vzhľadu editačného formulára. Typy sú v poradí ich prvého výskytu; viac položiek rovnakého typu je spolu a zachováva si uložené poradie. Staršie T komponenty sa naďalej zobrazujú ako G.

G a F používajú kompaktné tabuľkové riadky s rovnakými farbami, hranicami a veľkosťou textu ako plasty. Grafika má názov, dizajn, verziu, referenciu, množstvo a Preferred supplier v jednom riadku; pod ním je nízky pás parametrov bez veľkých medzier. Fitting má svoje nastavenia priamo v tom istom riadku. S zostáva samostatnou kartou s dizajnom a parametrami. Každý parameter formulára má vlastnú označenú bunku: pri G napríklad Material, Finish, meno/fonty, číslo/farby, Swingarms, Grip a Printer; pri S Patch, Side Brand Patches, Waterproof Seams, Enduro Pocket, Printed Ribs a Complexity; pri F nastavenie Fitting. Polia pochádzajú z rovnakých definícií ako formulár, vrátane dodatočných uložených polí bez aktívnej definície. Nevyplnený parameter zobrazuje pomlčku; nula zostáva nulou. Poznámky sú oddelene pod parametrami, zobrazujú sa iba vyplnené a zachovávajú zalomenie riadkov.

P zostáva tabuľkou s názvom, barcode, farbou, počtom kusov, živým preferovaným dodávateľom a skladovým množstvom. Interná a zákaznícka poznámka majú vlastné označenie. Ostatné typy nemajú nepotrebné skladové stĺpce. Údaje sa escapujú; názvy a voľný text sa neprekladajú, štandardné názvy polí a voľby používajú SK/EN. Detailový modal je širší a mriežka sa prispôsobí dostupnej šírke; úzka obrazovka posúva horizontálne iba tabuľku plastov.

Nasadenie: **.cache/product-builder/product-builder-compact-components.zip** obsahuje assets/product_builder.css a scripts/product_builder/{app.js,page.php}. Nahrajte obsah so zachovaním adresárov a po uložení rozpracovaného formulára obnovte stránku. **Žiadny ďalší SQL import netreba.** Kompletný product-builder-upload.zip je aktualizovaný. Uložené produkty, parametre, SKU ani IMG stavy sa touto úpravou nemenia.

Overenie: product_builder_component_details_ui_test.js kontroluje všetky definované polia, viac položiek, staršie/odstránené hodnoty, poznámky, nulové množstvo, escapovanie, dodávateľov, inline detail aj modal a mobil. Prešli aj existujúce testy inventory, Trim Kit, i18n a generovanie stránok/práv. Testy používajú simulované API; reálne listingy nemenia. Náhľady boli vizuálne skontrolované. Kompaktná úprava G/F (11. 11. 2026) prešla opakovaným overením všetkých parametrov, poznámok, skladových údajov, Trim Kit, modalu a mobilu; údaje ani databázu nemení.

## Category s modelcode a stĺpec IMG

V Builderi aj Listings je modelcode s kopírovaním v Category vedľa malého kruhového U (univerzálny listing) alebo S (špecifický listing). Názov kategórie zostáva pod nimi. Pôvodný stĺpec Modelcode je IMG.

IMG obsahuje ✗ (červená, predvolená hodnota), R (purpurová), A (žltá) a Path s ikonami adresára/kopírovania (zelená). Zmeny ✗/R/A sa uložia ihneď; Path otvorí modal a uloží sa až po potvrdení neprázdnej cesty. Zrušenie modalu ponechá pôvodný stav. Ikona adresára umožní cestu opäť zobraziť/upraviť a ikona kopírovania skopíruje celú cestu vrátane medzier a spätných lomiek. Kopírovanie má vizuálnu odozvu a 25 ms vibráciu tam, kde ju prehliadač podporuje.

Nové polia product_builder_products.img_status, img_path a img_version sú oddelené od editácie produktu. ✗ sa interne ukladá ako none. Zmena IMG zachová rozpracovaný formulár, SKU, komponenty a verziu produktu. Vlastná verzia IMG bráni prepísaniu zmeny iného používateľa; pri chybe zostane potvrdená hodnota a tlačidlo na načítanie aktuálneho stavu. Pri prepnutí z Path na iný stav sa posledná cesta uchová pre ďalšie použitie. Cesta je len text, server ju neotvára ani nekontroluje existenciu adresára. Maximálna dĺžka je 2048 znakov.

Samostatná úprava farieb po nasadení IMG: **.cache/product-builder/product-builder-img-colors.zip** obsahuje assets/product_builder.css a scripts/product_builder/page.php. Nahrajte so zachovaním adresárov a obnovte stránku. Ak už je update 009 importovaný, ďalší SQL import netreba. Farebné zmeny sú aj v IMG a kompletnom upload balíku.

Práva zostávajú rovnaké: všetci prihlásení môžu stav vidieť a cestu kopírovať; zápis vyžaduje products.view + products.manage a platný CSRF token. Údaje a nové popisy fungujú v SK/EN.

Nasadenie: najprv importujte **db/product_builder_update_009.sql** do existujúcej aplikačnej databázy cez phpMyAdmin. Potom nahrajte webové súbory z **.cache/product-builder/product-builder-img.zip** so zachovaním adresárov; SQL 009 je priložený aj v priečinku db v ZIP-e, ale musí sa importovať. Balík obsahuje sedem webových súborov a jeden SQL skript. Po uložení rozpracovaného formulára obnovte stránku. Lokálna vývojová databáza už má update 009 aplikovaný. Kompletný product-builder-upload.zip, inštalačné SQL, kumulatívny update_all a diagnostika sú aktualizované; diagnostika má 23 požadovaných objektov.

Overenie: product_builder_img_test.php používa dočasné tabuľky na uloženie stavov/ciest, konflikty a zachovanie ostatných dát; product_builder_img_ui_test.js overuje farby, okamžité uloženie, modal vrátane rýchleho opätovného otvorenia, chyby/opakovanie, kopírovanie, prístup čitateľa, SK/EN a mobil. Prešli aj existujúce testy migrácií (izolované tabuľky), práv, prekladov, kopírovania stĺpcov a dodávateľov. Reálne listingy sa pri testoch nemenia.

## Preferred supplier pre vlastnú výrobu

Grafika (G, vrátane Trim Kit), fitting (F) a seat covery (S) zobrazujú Preferred supplier = Scrub v rozbalenom výpise komponentov aj v modale Detail. Platí to v Builderi aj Listings pre existujúce aj nové listingy. Stĺpec je dostupný aj pri samostatnom G, F alebo S; skladové množstvo sa zobrazuje iba v listingoch obsahujúcich plasty. Dodávateľ a zásoby plastov sa naďalej načítavajú z items.

Nasadenie: **.cache/product-builder/product-builder-scrub-supplier.zip** obsahuje scripts/product_builder/app.js a scripts/product_builder/page.php. Nahrajte ich so zachovaním adresárov a obnovte stránku po uložení rozpracovaného formulára. **SQL import netreba.** Kompletný product-builder-upload.zip je tiež aktualizovaný.

Overenie: existujúci tests/product_builder_inventory_ui_test.js prešiel pre zmiešaný bundle, samostatné G/F/S, rozbalené riadky, detailový modal používateľa iba na čítanie a mobil. API je simulované; test nemení listingy ani zásoby.

## Vyhľadávanie podľa stĺpca Category

Vyhľadávanie v Builderi aj Listings zahŕňa okrem názvu, SKU a modelcode aj značku, model alebo UNI kategóriu a roky zobrazené v stĺpci Category. Nájde napríklad KTM, SX 50, KTM 2016-2023, celý text KTM · SX 50 · 2016-2023, Kawasaki · Default alebo Husaberg · TE · 2013-2014. Bodky · aj viacnásobné medzery slúžia ako oddeľovače; pomlčky v rokoch sa normalizujú. Všetky zadané časti kategórie musia sedieť na rovnaký listing.

UNI kategórie používajú aktuálny názov a značku z číselníka, rovnako ako výpis; po premenovaní sa hľadá podľa nového zobrazeného textu. Rovnaká SQL podmienka sa aplikuje na výber riadkov aj počet výsledkov, takže vyhľadávanie funguje cez celú databázu a správne stránkuje. Pôvodné vyhľadávanie názvu, SKU a modelcode zostáva dostupné. Placeholder je preložený do SK/EN.

Nasadenie na aktuálnu inštaláciu: nahrajte obsah **.cache/product-builder/product-builder-category-search.zip** so zachovaním adresárov. Obsahuje päť súborov: assets/product_builder.css, scripts/product_builder/{service.php,page.php,app.js} a scripts/product_builder/locales/sk.json; zahŕňa aj posledné úpravy stĺpcov a obnovovania číselníkov. **SQL import nie je potrebný.** Po uložení rozpracovaného formulára obnovte stránku. Kompletný product-builder-upload.zip je aktualizovaný.

Overenie: php tests/product_builder_category_search_test.php (dočasné tabuľky, všetky uvedené príklady, čiastkové aj skopírované kategórie, rôzne pomlčky, premenovanie UNI, presné počty a dve stránky výsledkov). Prešli aj existujúce databázové testy stránkovania 2 507 listingov, dátumového poradia a renderovania SK/EN.

## Kompaktné stĺpce, kopírovanie kódov a predvyplnená hmotnosť

Saved products v Builderi aj Listings má úzky stĺpec Created podľa šírky dátumu. Recommended price, Web, eBay a Amazon sú vycentrované vrátane hlavičiek. Samostatný stĺpec Weight (kg) je odstránený; uložená celková hmotnosť produktu sa zobrazuje pod názvom a rozmermi v stĺpci Box. Tabuľka, denné oddeľovače a rozbalené detaily používajú 12 stĺpcov.

Pri SKU aj Modelcode je rovnaká ikonka kopírovania. Skopíruje iba presný kód, zobrazí zelenú fajku, krátku animáciu a prístupné hlásenie. Na zariadeniach/prehliadačoch podporujúcich navigator.vibrate po úspešnom kopírovaní požiada o 25 ms vibráciu. Preferencia obmedzených animácií vypína animáciu. Clipboard API má fallback pre HTTP alebo odmietnuté oprávnenie; chyba nezobrazí falošné potvrdenie úspechu. Kopírovanie funguje aj používateľom s prístupom iba na čítanie.

Pri NOVOM produkte výber krabice predvyplní Weight (kg) z product_builder_boxes.weight_kg, vrátane nuly. Kým používateľ hmotnosť ručne neupraví, zmena krabice upraví aj tento návrh; odobratie krabice ho vyprázdni. Ručná úprava (aj zámerné vymazanie poľa) má prednosť. Editácia existujúceho produktu a obnova číselníkov jeho hmotnosť neprepisujú. Nový prázdny formulár obnoví predvyplňovanie. Uložená hmotnosť zostáva samostatnou hodnotou product_builder_products.weight_kg.

Nasadenie: nahrajte obsah **.cache/product-builder/product-builder-listing-columns.zip** so zachovaním adresárov. Obsahuje štyri súbory: assets/product_builder.css, scripts/product_builder/{app.js,page.php} a scripts/product_builder/locales/sk.json. **SQL import nie je potrebný.** Po uložení rozpracovaného formulára jednorazovo obnovte stránku cez Ctrl+F5. Kompletný product-builder-upload.zip je tiež aktualizovaný.

Overenie: node tests/product_builder_listing_columns_ui_test.js (šírka a zarovnanie, kopírovanie/fallback/chyba, vizuálna odozva a volanie vibrácie, nula, ručné prepísanie, uloženie/otvorenie, SK/EN, čitateľ a mobil). Schránka aj vibrácie sú v teste simulované. Prešli aj existujúce prehliadačové testy balenia, dátumov a cien. Reálne listingy ani používateľskú schránku testy nemenia.

## Obnovenie číselníkov bez straty rozpracovaného listingu

Product Builder po návrate na kartu alebo do okna obnoví UNI kategórie, dizajny grafiky a poťahov sedadiel, verzie grafiky a krabice. Hore pri tlačidlách číselníkov je aj **Obnoviť číselníky / Refresh libraries** na ručné načítanie. Stačí uložiť nový dizajn v Product Controls v druhej karte a vrátiť sa do Buildera; nový záznam sa objaví v dropdowne.

Obnovenie mení iba ponuky a súvisiace údaje číselníkov. Produkt ani tabuľkový výpis sa znovu nenačítavajú. Názvy komponentov, vlastné texty, vybrané ID, SKU, ceny, mena, krabica, hmotnosť, poznámky, nastavenia a príznak neuložených zmien zostávajú zachované. Select2 ponechá aj rozpracované vyhľadávanie. Zmeny výberu počas pomalej odpovede majú prednosť pred starým stavom; oneskorená odpoveď kategórií neprepíše novšiu.

Vymazaný vybraný záznam sa nenahradí iným. Chýbajúca UNI kategória ostane zobrazená ako nedostupná a vyžaduje nový platný výber pred uložením. Pri chybe načítania zostanú existujúce ponuky dostupné a používateľ môže obnovenie zopakovať. Automatické obnovenie nezačína počas aktívneho ukladania a neposiela zápisové požiadavky. Listings ani čitatelia bez editačného formulára nezačnú načítavať chránené číselníky.

Nasadenie na aktuálnu inštaláciu: nahrajte obsah **.cache/product-builder/product-builder-library-refresh.zip** so zachovaním adresárov. Obsahuje tri súbory: scripts/product_builder/{app.js,page.php} a scripts/product_builder/locales/sk.json. **SQL import nie je potrebný.** Pred prvým načítaním novej verzie dokončite aktuálne rozpracovaný listing; potom stránku obnovte. Ďalšie aktualizácie číselníkov už nevyžadujú reload. Kompletný product-builder-upload.zip je aktualizovaný.

Overenie: node tests/product_builder_library_refresh_ui_test.js pridá dizajn cez Controls v druhej karte a overí návratové udalosti, zachovanie celého formulára, rozpracované vyhľadávanie, pomalé odpovede, zmazané výbery, chybu/opakovanie, SK/EN, prístup čitateľa a mobil. Headless Playwright štandardne simuluje všetky karty ako aktívne, preto test po prepnutí explicitne odošle focus/visibilitychange. API zápisy sú simulované a reálne produkty ani číselníky sa nemenia. Prešli aj testy verzií grafiky, samostatných stránok a prekladov.

## Stránkovanie všetkých listingov

Listings aj výpis v Product Builderi sprístupňujú všetky záznamy cez serverové stránkovanie. Hore aj dole sú tlačidlá prvá/predchádzajúca/nasledujúca/posledná stránka, pole na priamy prechod na číslo stránky a voľba 25, 50 alebo 100 riadkov (predvolene 100). Počet výsledkov a zobrazený rozsah sú vždy uvedené pri ovládaní. Ovládanie podporuje SK/EN a mobil.

API products prijíma page a per_page; SQL používa LIMIT a OFFSET iba pre aktuálnu stránku. Odpoveď obsahuje pagination s page, per_page, total, pages, from a to. Vyhľadávanie podľa názvu, SKU, modelcode a kategórie filtruje celú databázu pred výpočtom počtu strán a limitom. Zmena vyhľadávania alebo veľkosti stránky začína prvou stránkou. Príliš vysoké číslo stránky sa upraví na poslednú; neplatné parametre server odmietne. Globálne štatistiky zostávajú nezávislé od filtra.

Poradie created_at DESC, id DESC a oddeľovače dní sú zachované. Komponenty aj editácia fungujú na každej stránke. Oprávnenia sa nemenia. Oneskorená odpoveď neprepíše novšie vyhľadávanie; chyba sa zobrazí vo výpise s tlačidlom Skúsiť znova. Tisíce listingov sa neposielajú do prehliadača naraz.

Nasadenie: nahrajte obsah **.cache/product-builder/product-builder-pagination.zip** so zachovaním adresárov a obnovte stránku cez Ctrl+F5. Balík obsahuje sedem súborov: assets/product_builder.css, scripts/product_builder/{helpers.php,service.php,api.php,page.php,app.js} a scripts/product_builder/locales/sk.json. Zahrnuté sú aj predchádzajúce úpravy SKU a Trim Kit. **SQL import nie je potrebný.** Kompletný product-builder-upload.zip je aktualizovaný.

Overenie: php tests/product_builder_pagination_test.php prechádza všetkých 2 507 testovacích listingov v dočasných tabuľkách, kontroluje poradie, počty, filtrovanie, prázdne výsledky a parametre. node tests/product_builder_pagination_ui_test.js overuje stránky, najstarší listing, detail/editáciu, veľkosti strán, priame skoky, filtrovanie, oneskorené odpovede, opakovanie načítania, oba jazyky a mobil. Prešli aj existujúce testy dátumových skupín a stránok/práv.

## Vygenerovanie nového SKU pri existujúcom listingu

V editácii je vedľa SKU tlačidlo s prútikom **Vygenerovať nové SKU / Generate new SKU**. Postup: otvoriť listing cez Upraviť, kliknúť na prútik a následne na Uložiť zmeny produktu. Kliknutie vytvorí iba návrh vo formulári; pôvodné SKU v databáze sa zmení až pri úspešnom uložení. Zrušenie editácie ponechá pôvodné SKU. Návrh možno ďalej upraviť ručne.

Prefix zodpovedá aktuálnemu bundle, napríklad P_, G_ alebo TFP_, nasledovaný 20 náhodnými hexadecimálnymi znakmi. Server overí dostupnosť návrhu; pri uložení jedinečnosť znovu chráni databáza. Pre nový produkt naďalej funguje automatické generovanie pri uložení prázdneho SKU. Pri existujúcom produkte treba použiť prútik alebo zadať SKU ručne.

Počas generovania je uloženie pozastavené. Oneskorená odpoveď neprepíše novšiu ručnú úpravu, zmenený bundle ani iný otvorený produkt. Chyba generovania zachová obsah poľa. Akcia používa existujúce oprávnenia editora a CSRF ochranu; na stránkach iba na čítanie sa nezobrazuje.

Nasadenie na aktuálnu inštaláciu: obsah **.cache/product-builder/product-builder-sku-generation.zip** nahrajte so zachovaním adresárov a použite Ctrl+F5. Obsahuje sedem súborov: assets/product_builder.css, scripts/product_builder/{helpers.php,service.php,api.php,page.php,app.js} a scripts/product_builder/locales/sk.json. Zahrnuté je aj predchádzajúce mapovanie Trim Kit komponentov. **SQL import nie je potrebný.** Kompletný product-builder-upload.zip je tiež aktualizovaný.

Overenie: php tests/product_builder_sku_generation_test.php (dočasné tabuľky, návrh bez zápisu, prefixy, kolízia pri uložení, zachovanie dát) a node tests/product_builder_sku_generation_ui_test.js (návrh/uloženie/zrušenie, chyba, oneskorené odpovede, ručné úpravy, SK/EN a mobil). Reálne listingy sa pri testoch nemenia.

# Produkty a listingy — prvá etapa

## Trim Kit bundle: TFP používa grafiku, fitting a plasty

Písmeno T označuje Trim Kit na úrovni bundle a SKU. Skutočný komponent je grafika G: TFP má G + F + P, TFPS má G + F + P + S, samostatné T má G a TP má G + P. Polia grafiky, knižnica Graphics designs, verzie a nastavenia sú rovnaké ako pri GFP. Seat cover sa pridáva iba pri bundle obsahujúcom S. Dropdown zobrazuje napríklad TFP · Trim Kit (Graphics + Fitting + Plastics), v slovenskej verzii s preloženými názvami.

Mapovanie pochádza zo serverovej funkcie pb_bundle_component_types() a rovnaké pravidlá používa formulár aj validácia. Kódy bundle, automatický prefix TFP_/TFPS_ a existujúce SKU sa nemenia. Prepínanie medzi TFP, GFP a TFPS zachová rozpracované komponenty. Historické kombinácie obsahujúce G aj T zobrazujú jednu skupinu grafiky; všetky pôvodné riadky zostávajú zachované.

Staršie komponenty uložené ako T sa pri čítaní/otvorení mapujú na G bez zápisu do databázy. Zachovajú sa názov, referencia, poznámky, množstvo a nastavenia; pri ďalšom explicitnom uložení sa zapíše typ G. Server prijíma aj T z pôvodného editora, pokiaľ zvolený bundle obsahuje T, a pred kontrolou dizajnu a verzie ho normalizuje na G. Dizajn Seat Covers sa pre trim grafiku odmietne.

Nasadenie na aktuálnu inštaláciu: nahrajte obsah **.cache/product-builder/product-builder-trim-kit.zip** so zachovaním adresárov a použite Ctrl+F5. Obsahuje štyri súbory: scripts/product_builder/{helpers.php,service.php,page.php,app.js}. **SQL import nie je potrebný.** Súhrnný product-builder-upload.zip je tiež aktualizovaný.

Overenie: php tests/product_builder_trim_kit_test.php (dočasné tabuľky, ukladanie TFP/TFPS, povinné komponenty, skupina dizajnu, verzie, SKU a staršie T záznamy) a node tests/product_builder_trim_kit_ui_test.js (celý formulár, barcode, prepínanie bundle, dizajn/verzia, uloženie/otvorenie, výpis, SK/EN a mobil). Reálne produkty ani zásoby testy nemenia.


## Prepínanie slovenčiny a angličtiny

Product Builder, Listings a Product Controls majú vpravo hore spoločný prepínač SK / EN. Predvolený jazyk je slovenčina. Voľba sa pamätá v relácii a v cookie samostatnej pre konkrétneho prihláseného používateľa v danom prehliadači (jeden rok); neovplyvňuje Custom Orders ani ostatné moduly. Parameter pb_lang povoľuje iba sk alebo en. API dostáva jazyk explicitne, takže aj súbežne otvorené karty vracajú hlásenia vo svojom jazyku.

Preložené sú formuláre, tabuľky, číselníky, modaly, popisy, štandardné možnosti komponentov a validačné/chybové hlásenia. Názvy produktov, dizajnov, kategórií a krabíc, SKU, barcode, modelcode, poznámky a uložené hodnoty možností sa nemenia. Napríklad voľba Matte sa po slovensky zobrazí ako Mat, ale do databázy sa naďalej uloží Matte. Výrobné označenia a vlastné novopridané popisy bez prekladu zostávajú pôvodné.

Prepnutie načíta stránku v druhom jazyku. Pri rozpracovanom produkte alebo číselníku sa vyžiada potvrdenie zahodenia zmien; zrušenie zachová celý formulár. Uložený editovaný produkt sa otvorí aj po prepnutí. Nový produkt ostane novým formulárom. Prepnutie nemení prístupové práva.

Spoločný slovník je scripts/product_builder/locales/sk.json; kľúčom je pôvodný anglický text. PHP helper i18n.php a JavaScript používajú rovnaké texty a pomenované parametre. Údaje sa nikdy neprekladajú plošným nahrádzaním HTML. Nové texty treba obaliť pb_t()/t() a pridať slovenský preklad. Pôvodné translate-backend.cjs a translate-frontend.cjs boli jednorazové migračné pomôcky; znova ich nespúšťajte.

Nasadenie na aktuálnu inštaláciu: obsah **.cache/product-builder/product-builder-language.zip** nahrajte so zachovaním adresárov a obnovte stránku cez Ctrl+F5. Balík obsahuje šesť súborov: assets/product_builder.css, scripts/product_builder/{page.php,app.js,api.php,i18n.php} a scripts/product_builder/locales/sk.json. **SQL import netreba.** Kompletný product-builder-upload.zip obsahuje 26 súborov.

Overenie: php tests/product_builder_i18n_test.php a node tests/product_builder_i18n_ui_test.js (SK/EN, všetky tri stránky a päť číselníkov, kanonické hodnoty pri uložení, cookie, jazyk API, ochrana neuložených zmien, reset validácie a mobil). API na zápis je v prehliadačových testoch simulované; reálne listingy a číselníky sa nemenia.


## Product database: Builder, Listings a Controls

Rozbaľovacie menu Product database v navbare aj bočnom menu obsahuje:

- **Product Builder** (index.php?page=product_builder): existujúci editor aj výpis. Vyžaduje products.view; zápis naďalej vyžaduje aj products.manage. Malé tlačidlá číselníkov zostávajú dostupné.
- **Listings** (index.php?page=product_listings): iba výpis, vyhľadávanie, denné skupiny, rozbalené komponenty a detail. Prístup majú všetci prihlásení používatelia. Editorom sa zobrazuje Edit ako odkaz na index.php?page=product_builder&edit=ID; Builder po načítaní otvorí vybraný produkt. Ostatní majú iba čítanie. Nový produkt alebo zrušenie editácie odstráni edit parameter z URL.
- **Product Controls** (index.php?page=product_controls): väčšie klikateľné bloky UNI categories, Graphics designs, Graphics versions, Seat cover designs a Boxes otvárajú spoločné modaly. Prístup vyžaduje products.view; zmeny vyžadujú aj products.manage. Formulár produktu ani výpis sa na tejto stránke nenačítavajú.

Všetky tri stránky používajú spoločné vykresľovanie v scripts/product_builder/page.php, rovnaké API a existujúce tabuľky. Povolenie pre všetkých sa týka iba prihlásených používateľov a iba GET akcií products/product. Číselníky, vyhľadanie barcode a modelov zostávajú chránené; zápis kontroluje oprávnenia aj CSRF. Používateľské roly sa nemenia.

Pôvodná adresa product_listing_catalog sa cez index.php presmeruje na Listings a starý odkaz je odstránený z navigácie. Staré tabuľky scrub_catalog_products a scrub_catalog_product_listings zostávajú zachované. Nový modul ich nepoužíva; lokálna kontrola našla medzi nimi iba ich vzájomný cudzí kľúč a čítanie/zápis v starom katalógu a jeho CSV importe. Pred odstránením na serveri treba vytvoriť zálohu a overiť prípadných externých používateľov týchto tabuliek; táto aktualizácia ich nemaže.

Nasadenie na aktuálnu inštaláciu: nahrajte celý obsah **.cache/product-builder/product-builder-pages.zip** so zachovaním adresárov a použite Ctrl+F5. Obsahuje 13 súborov: index.php, includes/{auth.php,navbar.php,sidebar.php,product_builder.php,product_listings.php,product_controls.php}, assets/product_builder.css a scripts/product_builder/{page.php,access.php,api.php,app.js,service.php}. Nové spoločné súbory page.php a access.php sú povinné. **SQL import nie je potrebný.** Kompletný product-builder-upload.zip má 24 súborov.

Overenie: php tests/product_builder_pages_test.php (dočasné tabuľky oprávnení, serverové vykreslenie stránok a navigácie) a node tests/product_builder_pages_ui_test.js (simulované API, bežný čitateľ, priamy prechod do editácie, uloženie produktu, všetkých päť editorov číselníkov, modaly, menu a mobil). Testy nemenia reálne používateľské roly, zásoby ani listingy.

## Dátum vytvorenia a denné skupiny

Saved products má prvý stĺpec Created vo formáte DD.MM.YY; po nabehnutí myšou sa zobrazí celý uložený dátum a čas. Každý deň začína tmavým pásom s dátumom DD.MM.YYYY a modrými čiarami, rovnako ako oddeľovače v Orders.

Výpis používa stránkovanie po 25, 50 alebo 100 výsledkoch podľa created_at, zoradených od najnovších po najstaršie. Najnovší deň aj najnovší listing v každom dni sú navrchu. Pri rovnakom čase rozhoduje ID zostupne. Vyhľadávanie sa aplikuje pred stránkovaním; pri úpravách produktu sa jeho deň vytvorenia nemení. Formátovanie v prehliadači nemení časovú zónu uloženého dátumu.

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
- Hmotnosť produktu sa ukladá ako samostatná celková hmotnosť zabaleného produktu vrátane krabice. Pri novom produkte ju výber krabice predvyplní jej hmotnosťou; ručná úprava aj už uložená hmotnosť majú prednosť. Podporuje bodku aj čiarku, najviac tri desatinné miesta a rozsah 0 až 999999.999 kg.
- Nevyplnená hmotnosť je NULL a vo výpise sa zobrazí pomlčka; nula je platná hodnota 0.000 kg. Staršie produkty zostávajú bez priradenej krabice a bez hmotnosti. Zápis staršieho klienta, ktorý tieto polia neposiela, zachová už uložené hodnoty.
- Saved products aj detail na čítanie zobrazujú krabicu s rozmermi a celkovú hmotnosť. Neskoršie úpravy názvu alebo rozmerov krabice sa prejavia vo výpise; ručná hmotnosť produktu zostáva zachovaná.
- Krabicu používanú v produktoch nemožno vymazať. Editor ukazuje počet použití; ochranu zabezpečuje server aj cudzí kľúč. Pred zmazaním treba zmeniť alebo zrušiť jej priradenie.

Nasadenie na existujúcu inštaláciu s update 007:

1. Importujte **db/product_builder_update_008.sql** cez phpMyAdmin. Pridá nullable box_id, weight_kg a cudzí kľúč do product_builder_products. Opakovaný import zachová existujúce údaje.
2. Nahrajte obsah **.cache/product-builder/product-builder-update-008.zip** so zachovaním adresárov. Sedem súborov: includes/product_builder.php, assets/product_builder.css, scripts/product_builder/{api.php,app.js,helpers.php,service.php,boxes.php}.
3. Obnovte stránku cez Ctrl+F5.

Kompletný inštalačný SQL aj súhrnná aktualizácia zahŕňajú update 008. Diagnostika má 20 kontrol; úplný runtime ZIP obsahuje 24 súborov. Automatický výpočet z hmotností komponentov zostáva ďalšou etapou.

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

Aktuálny kompletný SQL a súhrnná aktualizácia zahŕňajú aj update 008; diagnostika má 20 kontrol. Kompletný runtime ZIP obsahuje 24 súborov.

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

Stránky sú dostupné cez rozbaľovacie menu **Product database**: Product Builder, Listings a Product Controls.
Modul nepoužíva pôvodný Product Listing Catalog ani tabuľky `scrub_catalog_*`.

Frontend tohto modulu vrátane validácií a hlásení podporuje slovenčinu a angličtinu.

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

Na server nahrajte nasledujúcich 24 súborov do rovnakých relatívnych ciest v koreňovom adresári aplikácie:

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
scripts/product_builder/csv.php
scripts/product_builder/specs.php
scripts/product_builder/designs.php
plugins/select2/css/select2.min.css
plugins/select2/js/select2.full.min.js
scripts/product_builder/graphics_versions.php
scripts/product_builder/boxes.php
includes/navbar.php
includes/product_listings.php
includes/product_controls.php
scripts/product_builder/page.php
scripts/product_builder/access.php
```

Súbory `includes/conn.php`, testy, CLI inštalátor a zdrojové CSV nie sú súčasťou tohto uploadu. SQL importuje kategórie priamo. Po nasadení otvorte `index.php?page=product_builder`. Administrátor s permission 900 má prístup; ostatným podľa potreby prideľte rolu **Products manager** cez **User Permissions**.

## Inštalácia cez CLI (alternatíva)

1. `php scripts/product_builder/install.php`
   - Vytvorí sedem nových tabuliek podľa `db/product_builder.sql`.
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
- `product_builder_channel_listings`: stav zalistovania, URL, skutočná cena a mena pre každú platformu, verzia, dátumy a editor.

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

Banner "The product database needs to be installed or updated" kontroluje sedem tabuliek modulu, štyri doplnené stĺpce komponentov, trinásť stĺpcov cien/balenia/IMG v product_builder_products a sedem stĺpcov stavov platforiem v product_builder_channel_listings. Update 005 nenahrádza staršie aktualizácie.

Pri existujúcej inštalácii s neznámym stavom aktualizácií importujte do databázy aplikácie **db/product_builder_update_all.sql**. Obsahuje základné tabuľky a aktualizácie 002 až 010 v správnom poradí. Zachová listingy, SKU, komponenty, kategórie, vlastné názvy a výbery. Dopĺňa aj chýbajúce predvolené dizajny a verzie; predtým zmazané predvolené záznamy sa môžu vrátiť. Nemení používateľské oprávnenia a neimportuje UNI kategórie.

Záverečná kontrola vypíše názov zvolenej databázy a 31 riadkov so stavom OK/MISSING. Rovnakú kontrolu bez zmien dát možno spustiť cez **db/product_builder_check.sql**. Ak sú všetky riadky OK, ale aplikácia stále hlási chýbajúcu schému, overte, či produkčné PDO pripojenie používa tú istú databázu a server. Pri chybe importu riešte prvú SQL chybu.
