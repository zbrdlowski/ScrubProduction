# Aktualizácia Kanbanu – dostupnosť celého rozhrania

Tento balík je pre produkciu, na ktorej už sú vytvorené tabuľky Kanbanu. Obsahuje 16 aktuálnych aplikačných súborov. SQL sa znova neimportuje a existujúce karty sa nemenia.

## Nahratie

1. Zálohujte prepisované súbory. Balík obsahuje aj celé index.php, includes/auth.php, includes/navbar.php a includes/sidebar.php; prípadné odlišné produkčné úpravy pred nahratím porovnajte.
2. Nahrajte obsah priečinka upload/ do koreňa produkčného Darkscrubu so zachovaním adresárov. Prepíšte existujúce súbory. Nevytvárajte na serveri nový priečinok upload.
3. Obnovte stránku cez Ctrl+F5 a otvorte index.php?page=kanban pod bežným prihláseným účtom. Vpravo hore majú byť Návod a SK/EN. Po vytvorení nástenky je tam aj tlačidlo Šablóny.
4. Ak stále chýbajú, overte, že sa súbory nahrali do koreňa používaného produkčnou doménou, najmä includes/kanban.php. Ak hosting drží starú verziu PHP v OPcache, obnovte ju bežným postupom hostingu.

Neprideľujte kvôli Kanbanu administrátorské oprávnenia. Celý modul vrátane Návodu, SK/EN a vlastných kariet je prístupný každému prihlásenému zamestnancovi. Každý používateľ stále vidí iba svoju nástenku. Ostatné moduly si ponechávajú vlastné oprávnenia.

## Čo bolo overené

V lokálnej aplikácii boli Návod a oba jazyky dostupné aj pred touto aktualizáciou. Celá stránka index.php bola overená pre úrovne 0, 100, 300, 500 a 900 v SK/EN. API prešlo 40 scenármi vrátane ochrany cudzích násteniek a CSRF. Testy nemenia produkčné účty ani dáta.

Aktualizácia výslovne zakazuje cache HTML Kanbanu a automaticky verzuje CSS/JS podľa obsahu. Balík obsahuje všetky potrebné súbory vrátane oboch návodov. Konkrétna príčina chýbajúcich tlačidiel na vzdialenej produkcii nebola overená priamym prístupom k serveru.

## Súbory na upload

- index.php
- includes/auth.php
- includes/navbar.php
- includes/sidebar.php
- includes/kanban.php
- assets/css/kanban.css
- scripts/kanban/api.php
- scripts/kanban/service.php
- scripts/kanban/i18n.php
- scripts/kanban/help.php
- scripts/kanban/kanban.js
- scripts/kanban/locales/sk.json
- scripts/kanban/vendor/Sortable.min.js
- scripts/kanban/vendor/LICENSE
- docs/kanban/navod.md
- docs/kanban/navod.en.md
