# Kanban – počítadlo aktívnych kariet, 17. 11. 2026

Aktualizácia existujúceho Kanbanu. Malé číslo pri odkaze Kanban v hornom menu predstavuje počet vlastných nedokončených kariet prihláseného zamestnanca.

- Backlog sa započítava, pretože ide o nedokončenú prácu.
- Všetky stĺpce označené ako dokončené sú vylúčené, aj keď sa volajú inak než Done.
- Vyhľadávanie ani limity zobrazenia počet nemenia.
- Na nástenke sa počet aktualizuje po uložení zmeny. Na ostatných stránkach pri ich načítaní.
- Pri nule sa číslo skryje. Popis odznaku je v SK/EN.

## Nasadenie

SQL neimportujte: databázová schéma sa nemení.

Zálohujte prepisované súbory a nahrajte obsah upload/ do koreňa existujúceho Darkscrubu so zachovaním ciest.
Priečinok upload samotný sa na server nevytvára. Nový scripts/kanban/count.php nahrajte pred includes/navbar.php a scripts/kanban/service.php, aby počas postupného uploadu nechýbala ich závislosť.

Balík obsahuje celý aktuálny includes/navbar.php. Prípadné odlišné úpravy produkčného navbaru pred prepísaním porovnajte.
Po nahratí obnovte stránku cez Ctrl+F5. Overte presun karty do Done a späť: číslo má klesnúť a následne stúpnuť.

## Súbory na upload

- scripts/kanban/count.php
- scripts/kanban/service.php
- scripts/kanban/kanban.js
- scripts/kanban/locales/sk.json
- docs/kanban/navod.md
- docs/kanban/navod.en.md
- includes/navbar.php

## Overenie

Prešli PHP kontroly syntaxe, databázové testy nad dočasnými tabuľkami, API a jazykové kontroly. Chrome overil odznak v reálnom navbare pre úrovne 0/100/300/500/900, SK/EN, presun do Done a späť, chybu zápisu a vyhľadávanie. Produkčné dáta neboli menené.
