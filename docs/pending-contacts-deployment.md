# Pending objednávky – evidencia kontaktovania (v5)

Balík dopĺňa stránku index.php?page=orders&status=PENDING.
Platbu naďalej potvrdzuje pôvodné tlačidlo v detaile so zadaním prijatej sumy.

## Zmena vo v5: tlačidlá formulára

Tlačidlo Save contact sa teraz volá **Save** a je vpravo v spodnom riadku formulára. **Cancel** je v tom istom riadku vľavo. Save zostáva prepojené s validáciou formulára a počas ukladania je vypnuté; pri histórii iba na čítanie sa nezobrazuje.

Z v4 sa menia iba includes/orders_pending_contact_modal.php a scripts/orders/pending_contacts.js. Ak už máte databázu aktualizovanú na v4, ďalšiu migráciu nespúšťajte; stačí nahrať súbory a Ctrl+F5.

## Zmena vo v4: Cancellation requested bez ďalšieho termínu

- Pri výsledku **Cancellation requested** sa pole **Next contact** skryje a nevyžaduje, bez ohľadu na spôsob kontaktu.
- Žiadosť sa uloží bez ďalšieho termínu a zobrazí vo filtri **To cancel**. V stĺpci Next contact bude **To cancel / No follow-up**.
- Takáto objednávka sa už nevracia do **To contact**. Platí to aj pre staršie žiadosti o zrušenie, pri ktorých predchádzajúca verzia vynútila dátum.
- Záznam žiadosti nemení stav objednávky: samotné zrušenie vykonajte existujúcou akciou v detaile.
- Pri ostatných výsledkoch zostáva dátum povinný. Ak zákazník zmení názor, nový kontakt s iným výsledkom a termínom obnoví bežný proces.
- Kompaktné stĺpce Last contact a Next contact z v3 zostávajú zachované.

**Pri aktualizácii na v4 treba na serveri znovu spustiť inštalátor.** Stĺpec next_contact_at sa upraví tak, aby povolil NULL. Existujúce kontakty a objednávky sa nemenia.

## Nasadenie alebo aktualizácia

1. Zálohujte serverové súbory z nižšie uvedeného zoznamu, ktoré budete prepisovať.
2. Obsah priečinka upload/ nahrajte do koreňa aplikácie darkscrub/ so zachovaním podpriečinkov. Konfigurácia ani prihlasovacie údaje nie sú súčasťou balíka.
3. Prihláste sa ako administrátor a otvorte darkscrub/scripts/orders/install_pending_contacts_2026_11.php. Kliknite na **Install / update**. Tento krok je potrebný pri novej inštalácii alebo prechode z v1, v2 či v3. Ak už bola databáza aktualizovaná na v4, krok preskočte.
   - Inštalátor vytvorí chýbajúcu tabuľku a podľa potreby povolí prázdny termín v existujúcej tabuľke. Opakované spustenie je bezpečné.
   - Alternatíva cez SSH z koreňa aplikácie: php scripts/orders/install_pending_contacts_2026_11.php --execute.
   - Alternatíva cez phpMyAdmin: najprv spustite db/orders/pending_contacts_2026_11.sql a potom db/orders/pending_contacts_optional_next_2026_11.sql v databáze aplikácie. Vyberte jeden spôsob inštalácie.
4. Otvorte Pending objednávky a obnovte stránku pomocou Ctrl+F5.
5. Po úspešnom dokončení môžete zo servera odstrániť scripts/orders/install_pending_contacts_2026_11.php. Ostatné súbory ponechajte.

Kým nie je databáza aktualizovaná, Pending zobrazí upozornenie na spustenie inštalátora. Existujúce objednávky zostávajú dostupné.

## Súbory na server

- includes/orders.php
- includes/orders_pending_contact_helpers.php
- includes/orders_pending_contact_modal.php
- scripts/orders/get_order_detail.php
- scripts/orders/pending_contacts.php
- scripts/orders/pending_contacts.js
- scripts/orders/install_pending_contacts_2026_11.php
- db/orders/pending_contacts_2026_11.sql
- db/orders/pending_contacts_optional_next_2026_11.sql

Balík obsahuje všetkých deväť serverových súborov, návod a kontrolné súčty SHA256. Neobsahuje konfiguráciu, databázový export ani testovacie dáta.

## Používanie

- **Pending: to contact** otvorí objednávky, ktorým uplynula lehota na prvý alebo ďalší kontakt.
- Prvý kontakt sa ponúkne po 72 hodinách od order_date. Výpočet používa čas databázy pri načítaní stránky; nepridáva sa cron.
- Filtre sú **All**, **To contact**, **To cancel**, **Contacted**, **Under 3 days** a podľa potreby **Missing date**.
- V All sú najprv žiadosti o zrušenie, potom kontakty po termíne od najstaršieho dátumu. Pending sa nezoskupuje podľa dňa importu.
- Počítadlá rešpektujú ostatné filtre a zahŕňajú všetky výsledky, aj nad limit 1 000 zobrazených objednávok. Horná záložka má celkový počet rovnako ako ostatné stavové záložky.
- **Last contact** nahrádza pri Pending stĺpec Assigned. Zobrazuje spôsob, čas, výsledok a autora. Celá poznámka je dostupná po podržaní kurzora a v histórii.
- **Next contact** zobrazuje stav, termín a tlačidlo vedľa seba. Ostatné zoznamy si zachovávajú Assigned a svoje pôvodné rozloženie.
- **Record contact** otvorí telefón/e-mail zákazníka, formulár a históriu. Bežný ďalší termín je predvolene o dva dni, od začiatku daného dňa.
- Uloží sa prihlásený používateľ a čas databázy. Neúspešný telefonát má označenie **Contact attempt**.
- **Contacted** čaká na ďalší termín; potom prejde do **To contact** ako **Follow-up due**. Žiadosti o zrušenie sa riadia samostatným procesom opísaným vyššie.
- Chýbajúci pôvodný dátum sa neodhaduje podľa importu; objednávka má **Missing order date**.
- Po potvrdení platby alebo zrušení objednávky zostáva **Contact history** dostupná v detaile. História sa načítava po 50 záznamoch.
- Aplikácia eviduje vykonaný kontakt; sama neposiela správy ani netelefonuje.

## Oprávnenia a údaje

Zobrazenie histórie vyžaduje orders.view, záznam orders.work, platba orders.financial a inštalátor orders.admin.
Kontaktná história je oddelená od importovaných údajov a čisteného order_activity.
CSRF token chráni formulár; opakovanie požiadavky nevytvorí duplicitu. Zastaraný formulár sa odmietne, ak už vznikol novší kontakt.
Zápis kontroluje stav a uzamkne objednávku rovnako ako potvrdenie platby.

## Overenie

- Databázové testy: lehota 72 hodín, filtre, počty, radenie, validácia, bezpečné zobrazenie a zachovanie histórie.
- Testy v4: všetky spôsoby kontaktu bez dátumu pri zrušení, NULL v databáze, staršie vynútené termíny, To cancel, obnovenie kontaktovania a zachovanie stavu objednávky.
- Opakovaný upgrade pôvodnej tabuľky zachová všetky hodnoty kontaktov.
- API: oprávnenia, CSRF, konflikty, chýbajúci dátum pri bežnom kontakte, žiadosť bez dátumu a história.
- Edge: otvorenie/zatvorenie, prepínanie výsledkov, skrytie dátumu, obnovenie povinného dátumu, uloženie, obnova zoznamu a filter To cancel.
- Kontrola pôvodného potvrdenia platby, PHP a JavaScript syntaxe.

Testovacie zápisy prebiehajú v dočasných tabuľkách. Na produkčný server sa zmeny automaticky nenahrávajú.

## Návrat na predchádzajúcu verziu

Obnovte zálohované súbory aplikácie. Tabuľku ani jej históriu nemažte. Ponechajte next_contact_at ako nullable, aby zostali zachované žiadosti uložené bez termínu.
