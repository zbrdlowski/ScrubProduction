# ScrubProduction - PERMISSIONS.md

## Session hodnoty
Po prihlásení sa používajú najmä:

```php
$_SESSION['user_id']
$_SESSION['permission']
$_SESSION['dpt']
$_SESSION['name']
$_SESSION['username']
$_SESSION['dpt_name']
$_SESSION['personal_orders']
$_SESSION['grid']
```

## Permission levels
- `1` - user
- `400` - moderator
- `500` - admin

## Základné pravidlá
- Ak nie je nastavené `$_SESSION['permission']`, používateľ ide na `login.php`.
- Admin menu sa zobrazuje pri `permission > 300`.
- Citlivé importy používajú kontrolu `permission >= 500`.

## Departments
- `1` Admin
- `2` Graphics
- `3` Management
- `4` Production
- `5` Customer Service
- `6` Plastics
- `7` WEB-IT
- `8` Seat Covers
- `9` Fitting

## Orders ACL
All-access departments:
- `1` Admin
- `3` Management
- `4` Production
- `5` Customer Service
- `7` WEB-IT

Department-limited access:
- `2` Graphics -> `GRAPHICS`
- `6` Plastics -> `PLASTICS`
- `8` Seat Covers -> `SEATCOVER`
- `9` Fitting -> fitting položky / typ `F`

## Stock Management ACL
Stock Management menu je viditeľné pre:
- `permission >= 500`, alebo
- `dpt == 6`.

## Odporúčanie
Vytvoriť centrálny helper napr. `includes/auth.php`:

```php
function requireLogin(): void {}
function requirePermission(int $min): void {}
function currentUserId(): int {}
function currentPermission(): int {}
function currentDepartment(): int {}
function canAccessOrderCategory(string $category): bool {}
```

Tým sa odstráni duplicitná permission logika z jednotlivých stránok.
