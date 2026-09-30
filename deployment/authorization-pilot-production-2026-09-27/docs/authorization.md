# Central permissions

## Installation

Run `db/authorization.sql` against the `scrubproduction` database. The migration
is additive and keeps the legacy `employees.permission` value intact.

The initial migration creates these roles:

- **Administrator** – all centrally registered permissions;
- **Accounting – prezeranie** – `accounting.view`;
- **Accounting – operátor** – view, import and export.

Users with legacy level `900` receive Administrator. Users in departments 1
and 3 receive Accounting operator, preserving the access rules used before the
migration.

## Administration

Open **Admin → User Permissions**. Select an employee, assign one or more roles
and optionally set a per-permission override:

- **Zdediť** uses the result of assigned roles;
- **Povoliť** grants the permission directly;
- **Zakázať** denies the permission even if a role grants it.

Every saved change is written to `auth_permission_audit` with the actor, time,
IP address and before/after snapshot.

## Enforcement

Application code uses:

```php
auth_can('accounting.view');
auth_require('accounting.import');
```

Permission checks protect the menu, page and write/download endpoint. Hiding a
button is never the only protection.

Resolution order is:

1. legacy level 900 emergency administrator bypass;
2. direct employee `deny`;
3. direct employee `allow`;
4. permission granted by any assigned role;
5. default deny.

If the authorization tables have not yet been installed, Accounting and access
administration temporarily use their former legacy rules. This allows a staged
deployment without locking existing users out.

## Adding another module

1. Add stable permission keys to `auth_permissions`, for example
   `stock.view`, `stock.move`, `stock.settings`.
2. Assign them to one or more roles in `auth_role_permissions`.
3. Use `auth_can()` for menu and UI visibility.
4. Use `auth_require()` in every PHP/AJAX endpoint that reads sensitive data or
   changes state.
5. Test both a permitted and a denied user before removing the old numeric
   permission condition.
