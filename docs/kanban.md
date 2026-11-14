# Osobný kanban

Stránka: index.php?page=kanban. Položka **Môj kanban / My kanban** je v bočnom menu pri Projects; odkaz **Kanban** je aj v hornom menu naľavo od Attendance.
Každý prihlásený zamestnanec má vlastnú súkromnú nástenku. Ani administrátor cez toto API nečíta cudzie nástenky.
Celá stránka vrátane Návodu, SK/EN, šablón a úprav vlastných kariet je dostupná bez oprávnení k Product Builderu,
účtovníctvu či administrácii. Číselná úroveň používateľa ani oddelenie žiadnu časť Kanbanu neskrýva.
Samostatný databázový inštalátor je naďalej určený administrátorovi.

## Inštalácia

Nahrajte upravené súbory a nové adresáre:

- index.php
- includes/sidebar.php
- includes/navbar.php
- includes/auth.php
- includes/kanban.php
- assets/css/kanban.css
- scripts/kanban/ (vrátane vendor/Sortable.min.js a vendor/LICENSE)
- db/kanban.sql
- docs/kanban/navod.md a docs/kanban/navod.en.md

V databáze používanej aplikáciou vytvorte tabuľky jedným z týchto spôsobov:

- CLI: php scripts/kanban/install.php --execute
- V prehliadači ako administrátor s permission 900: scripts/kanban/install.php a tlačidlo Pripraviť kanban.
- phpMyAdmin: spustite db/kanban.sql vo vybranej databáze Darkscrubu.

Inštalátor vytvára iba chýbajúce tabuľky a dá sa zopakovať. Nemení existujúce employees, projekty ani objednávky.
Vyžaduje InnoDB a oprávnenie vytvárať tabuľky/cudzie kľúče. employee_id odkazuje na číselné employees.id.
Po inštalácii obnovte stránku. Bez databázovej migrácie sa zobrazí upozornenie, ostatné moduly zostávajú dostupné.
Lokálne spustenie inštalátora neaktualizuje vzdialený server.
Pri aktualizácii už nainštalovaného Kanbanu netreba opätovný SQL import. Nasadenie musí zahŕňať aktuálny
includes/kanban.php, CSS, celý scripts/kanban/ aj oba návody. Návod a SK/EN sa vykresľujú už v PHP,
nie podľa rolí ani podľa výsledku API. Ak na produkcii chýbajú, overte nasadený includes/kanban.php a správny
koreň webu. Po nahratí obnovte stránku cez Ctrl+F5; ak server ďalej vykonáva staré PHP, obnovte PHP OPcache
bežným postupom hostingu. Aplikácia zakazuje ukladanie HTML Kanbanu do cache a CSS/JS verzuje hashom obsahu.

## Jazyk rozhrania

Prepínač SK/EN je vpravo nad hlavičkou, na rovnakom mieste a v rovnakom štýle ako v Product Builderi.
Predvolený jazyk je slovenčina. Voľba sa pamätá v používateľskej cookie kanban_lang_<employees.id> a v session,
nezávisle od ostatných používateľov a modulov. Parameter kb_lang=sk/en má prednosť.

Preložené sú ovládacie prvky, formuláre, tooltipy, priority, dátumy, potvrdenia a chyby API aj validácie.
Nová nástenka dostane názvy stĺpcov šablóny v práve zvolenom jazyku.
Uložené názvy stĺpcov, kariet, popisy, štítky a referencie sú používateľské údaje; prepínanie jazyka ich nemení.
Aktualizácia jazykov nevyžaduje zmenu databázy. Nahrajte aj scripts/kanban/i18n.php a scripts/kanban/locales/sk.json.

## Používanie

Pri prvom otvorení vyberte šablónu Univerzálny, Grafika alebo Customer service.
Každý používateľ môže pridať, premenovať a prefarbiť stĺpce, zmeniť ich poradie a označiť ľubovoľný stĺpec ako dokončený.
Šablóna je počiatočné nastavenie; následné úpravy sú individuálne.
Tlačidlo Šablóny vpravo hore znova otvorí úvodný výber. Otvorenie alebo návrat nič nezmení.
Použitie šablóny má samostatné potvrdenie: nahradí pôvodné stĺpce, presunie všetky aktívne karty do prvého stĺpca
novej šablóny a všetky dokončené do posledného. Zachová aj nenačítanú históriu, obsah, ID, dátumy kariet a done_limit.
Zápis prebehne v jednej transakcii s kontrolou verzie nástenky. Nevyžaduje zmenu schémy.
Návod je bezprostredne naľavo od SK/EN a zobrazuje docs/kanban/navod.md alebo navod.en.md podľa jazyka.
Skupina stĺpcov je vycentrovaná, pokiaľ sa zmestí; pri pretečení zostáva vodorovne posúvateľná od prvého stĺpca.

Kartu potiahnite medzi ľubovoľnými stĺpcami alebo zmeňte poradie v aktívnom stĺpci.
Úchyt v hlavičke presúva celý stĺpec. Na dotykovej obrazovke kartu alebo úchyt krátko podržte.
Zmeny sa ukladajú po pustení. Pri chybe sa obnoví posledný načítaný stav a zobrazí hlásenie.
Pri prerušení spojenia použite obnovenie nástenky na overenie, či server zmenu prijal.

Kliknutím na názov karty otvoríte detail: názov, popis, priorita, termín, štítok, referencia a úplný HTTP(S) odkaz.
Stĺpec možno zmeniť aj vo formulári. Šípky na karte a v nastavení stĺpca umožňujú zmeniť poradie bez ťahania.
Karty sú osobné poznámky/úlohy. Odkaz na projekt či objednávku nemení stav zdrojového záznamu a nekopíruje jeho údaje.

Dokončené stĺpce zobrazujú predvolene 20 najnovšie dokončených kariet, s nastaviteľným limitom 1–100 na stĺpec.
Načítanie je obmedzené už v SQL; aktívne stĺpce sa načítavajú po 100 kartách.
Načítať staršie/ďalšie sprístupní zvyšok. Vyhľadávanie prehľadáva aj staršie skryté karty a ich popis, štítok a referenciu.
Po obnovení nástenky či uložení zmeny sa opäť použije nastavený limit.
Počas vyhľadávania je drag & drop vypnutý, aby nebolo nejasné poradie medzi skrytými kartami; presun cez detail zostáva dostupný.

Presun do dokončeného stĺpca nastaví completed_at. Návrat do aktívneho stĺpca ho vymaže.
Presun medzi dokončenými stĺpcami ho zachová. Opätovné dokončenie nastaví nový čas.
Dokončené stĺpce majú pevné radenie podľa času dokončenia a ID, nie ručné poradie.
Zmena príznaku dokončenia stĺpca sa týka všetkých jeho kariet, aj nenačítaných.
Stĺpec možno odstrániť iba prázdny; posledný stĺpec nástenky sa odstrániť nedá.

## Implementácia a ochrana dát

- Tri tabuľky: kanban_boards, kanban_columns, kanban_cards; bez JSON kanbanu v employees.
- Jedna nástenka na employees.id; vlastníka určuje výhradne prihlásená session.
- POST vyžaduje CSRF token; validácia a parametrizované SQL sú na serveri.
- Zápisy sú transakčné a zamykajú nástenku. Verzia odmietne zastarané úpravy z iného okna.
- Čítanie drží zdieľaný zámok nástenky pre konzistentný obsah a verziu; stránkovanie kontroluje verziu.
- Po konflikte zostane formulár otvorený. Pred opakovaním úpravy ho zavrite, obnovte nástenku a znovu otvorte aktuálnu kartu.
- Prípadná sieťová chyba nevyvoláva automatické opakovanie zápisu.
- Text sa bezpečne escapuje; odkazy povoľujú len HTTP(S).
- Celý modul rešpektuje AdminLTE dark-mode a obsahuje aj svetlé farby.
- SortableJS 1.15.6 je lokálna knižnica s MIT licenciou; stránka nesťahuje kód z CDN.
  Zdroj: https://github.com/SortableJS/Sortable/tree/1.15.6

## Overenie

    php tests/kanban_service_test.php
    php tests/kanban_api_test.php
    php tests/kanban_i18n_test.php
    php tests/kanban_access_fixture.php
    node tests/kanban_access_ui_test.js
    php tests/kanban_ui_fixture.php
    node tests/kanban_ui_test.js
    node tests/kanban_i18n_ui_test.js
    node tests/kanban_help_ui_test.js

PHP testy používajú výhradne dočasné tabuľky. API test používa session v pamäti a nevytvára prihlasovací súbor.
UI fixture je statická stránka v .cache/kanban/; Playwright test interceptuje API a používa syntetické dáta.
Vyžaduje lokálny web na http://localhost/darkscrub/, balík playwright a Chrome.
Alternatívnu URL možno zadať cez KANBAN_TEST_URL; fixture base href treba prispôsobiť inému koreňu aplikácie.

Overené: vlastníctvo, CSRF, konflikty, validácia, poradie kariet/stĺpcov, obojstranné presuny, dokončenie a znovuotvorenie,
limit a história, dosah vyhľadávania, escapovanie textu, návrat po chybe zápisu, obnovenie stránky a mobilné rozloženie.
Screenshoty sa ukladajú do .cache/kanban/. Testy ani .cache nie sú potrebné na serveri.

Testy celej obálky index.php overujú dostupnosť Návodu a SK/EN pri úrovniach 0, 100, 300, 500 a 900.
Pri generovaní používajú iba session v pamäti a čítanie databázy; nevytvárajú prihlásenie do webu.
