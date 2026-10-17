# Dovolenky – používateľský manuál

Stránka **Holidays** slúži na plánovanie dovoleniek, náhradného voľna a evidovanie ďalších neprítomností. Zobrazuje šesť mesiacov naraz a pri každom pracovníkovi ukazuje stav jednotlivých dní.

## Význam označení

| Kód | Význam | Potrebuje rozhodnutie? |
| --- | --- | --- |
| `Rh` | Požiadaná dovolenka | Áno, admin ju schváli alebo zamietne. |
| `Ah` | Schválená dovolenka | Nie, požiadavka už bola schválená. |
| `Rt` | Požiadané náhradné voľno (TOIL) | Áno, admin ho schváli alebo zamietne. |
| `At` | Schválené náhradné voľno (TOIL) | Nie, požiadavka už bola schválená. |
| `D` | Lekár (Doctor) | Nie je to žiadosť o povolenie; ide o nahlásenie neprítomnosti. |
| `S` | PN / choroba (Sick) | Nie je to žiadosť o povolenie; ide o nahlásenie neprítomnosti. |

Víkendy a štátne sviatky sú farebne odlíšené a do žiadosti sa nezapočítavajú ako pracovné dni.

## Dôležité pravidlo pre Doctor a Sick

`D` a `S` predstavujú **nahlásenie skutočnosti**, nie žiadosť, pri ktorej admin rozhoduje, či zamestnanec môže byť neprítomný.

Ak sa záznam zobrazí adminovi medzi notifikáciami alebo čakajúcimi položkami, admin ho štandardne potvrdí tlačidlom **Approve**. V tomto prípade potvrdenie znamená:

- admin bol o neprítomnosti informovaný,
- skontroloval dátum a typ záznamu,
- vzal informáciu na vedomie a zaevidoval ju.

Notifikácia je zámerná. Po potvrdení je preukázateľné, že admin o neprítomnosti vedel. `D` alebo `S` sa zamieta iba v prípade zjavne chybného alebo duplicitného záznamu, ktorý treba opraviť.

## Ako vytvoriť záznam

1. Kliknite na **New request**.
2. Vyberte dátum **From** a **To**.
3. Vyberte typ neprítomnosti.
4. Do poľa **Note** doplňte užitočnú informáciu, napríklad čas návštevy lekára.
5. Kliknite na **Submit request**.

Bežný používateľ môže lekára nahlásiť priamo. PN / Sick sa eviduje podľa interného postupu, spravidla ju po nahlásení zamestnancom zapíše admin.

### Rýchla žiadosť kliknutím na deň

O dovolenku alebo inú neprítomnosť na konkrétny dnešný alebo budúci pracovný deň možno požiadať aj priamo z kalendára:

1. Vo vlastnom riadku kliknite na prázdnu bunku požadovaného dňa.
2. Otvorí sa formulár **New time off request**.
3. Zvolený deň sa automaticky nastaví do polí **From** aj **To**.
4. Ak má neprítomnosť trvať viac dní, upravte dátum **To**.
5. Vyberte typ, prípadne doplňte poznámku a odošlite záznam.

Minulé dni, víkendy a štátne sviatky nie sú pre bežného používateľa klikateľné. Kliknutie funguje ako predvyplnenie formulára; pred odoslaním vždy skontrolujte dátum, typ neprítomnosti a celý rozsah.

## Schvaľovanie dovolenky a TOIL

Dovolenka (`Rh`) a náhradné voľno (`Rt`) sú skutočné žiadosti. Kým ich admin nespracuje, zostávajú v stave **Pending**.

Admin môže:

- požiadavku schváliť cez **Approve**,
- požiadavku zamietnuť cez **Reject**,
- pridať vysvetlenie do poľa **Admin note**.

Po schválení sa `Rh` zmení na `Ah` a `Rt` na `At`. Používateľ dostane notifikáciu o výsledku.

## Úpravy a zrušenie

- V časti **My requests** môže používateľ upraviť poznámku pri dostupných budúcich záznamoch.
- Tlačidlo **Cancel** je používateľovi dostupné pri nahlásenom alebo schválenom zázname typu Holiday, TOIL, Doctor alebo Other, pokiaľ záznam ešte obsahuje dnešný alebo budúci pracovný deň.
- Ak celý záznam začína dnes alebo v budúcnosti, používateľ ho môže zrušiť celý.
- Ak záznam začal v minulosti a pokračuje do dneška alebo budúcnosti, zruší sa iba jeho dnešná a budúca časť. Už uplynulé pracovné dni zostanú v evidencii.
- Záznam, ktorého všetky dni sú už v minulosti, používateľ spätne zrušiť ani odstrániť nevie.
- PN / Sick používateľ cez **My requests** sám nezruší; rieši ju s adminom.
- Admin môže krížikom `×` odstrániť konkrétny pracovný deň zo záznamu bez odstránenia celého rozsahu, vrátane dňa v minulosti.

Ak je dnešný deň víkend alebo štátny sviatok, za hranicu budúcej časti sa považuje najbližší nasledujúci pracovný deň.

## Práca s kalendárom

- Tlačidlá **Previous 6 months**, **Current** a **Next 6 months** menia zobrazené obdobie.
- Pri posúvaní stránky zostáva názov mesiaca aj riadok s dátumami viditeľný.
- Pri horizontálnom posúvaní zostáva stĺpec s menom zamestnanca na ľavej strane.
- Kliknutie na vlastnú prázdnu bunku dnešného alebo budúceho pracovného dňa predvyplní daný dátum do nového záznamu.

## Odporúčania

- Neprítomnosť nahláste čo najskôr.
- Pred odoslaním skontrolujte dátumy a správny typ.
- Do poznámky nevkladajte citlivé zdravotné údaje; stačí informácia potrebná na evidenciu.
- Ak bol vytvorený nesprávny alebo duplicitný záznam, kontaktujte admina.
