# Module: HR / Employees / Attendance

## Účel
Správa zamestnancov, používateľských účtov, oddelení, dochádzky a profilu.

## Hlavné stránky
- `employee`
- `calendar`
- `profile`

## Databázové entity
- `employees`
- `position`
- `attdn_YYYY`

## Login napojenie
`login.php` používa tabuľku `employees` a podľa `position_id` načíta názov oddelenia z tabuľky `position`.

## Session hodnoty vytvárané pri login-e
- `admin`
- `user_id`
- `permission`
- `dpt`
- `user_photo`
- `name`
- `username`
- `personal_orders`
- `grid`
- `dpt_name`

## Odporúčania
- Presunúť login SQL na prepared statement.
- Prejsť z MySQL `PASSWORD()` na `password_hash()` / `password_verify()`.
- Vytvoriť migration script na prehashovanie hesiel.
- Centralizovať permission a department helper funkcie.
