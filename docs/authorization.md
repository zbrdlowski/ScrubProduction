# Central permissions

## Installation

Run `db/authorization.sql` against the `scrubproduction` database. The migration
is additive and keeps the legacy `employees.permission` value intact.

The migration creates these roles:

- **Administrator** – all centrally registered permissions;
- **Správca oprávnení** – iba administrácia používateľských rolí a oprávnení;
- **Accounting – prezeranie** – `accounting.view`;
- **Accounting – operátor** – view, import and export.
- **Production worker** – základná práca na production a custom objednávkach;
- **Orders manager** a **Orders finance** – správa workflow, financií a dopravy;
- **Custom Orders manager** – správa, platby, export a mazanie custom objednávok;
- **Plastics warehouse worker/manager** – skladové operácie a administrácia skladu.

Users with legacy level `900` receive Administrator. Users in departments 1
and 3 receive Accounting operator, preserving the access rules used before the
migration.

Employee ID `3` receives the narrowly scoped **Správca oprávnení** role. This
does not grant any of the other administrator, accounting, order or warehouse
permissions and no other company officer is selected automatically.

Existing numeric levels and the plastics department are translated into the
initial Orders, Custom Orders and warehouse roles. The numeric column remains
untouched and can be used as a fallback during rollout.

The **Employee Edit → Legacy User Level** field still writes
`employees.permission`. After the central authorization tables are installed,
that field is treated as a legacy compatibility setting for older screens and
for the emergency `900` super-administrator bypass. Day-to-day module access
must be changed in **Admin → User Permissions**. Employee Edit locks the legacy
level for users who do not have `access.manage`.

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

The read-only pages linked from the top **Warehouse** menu are a deliberate
exception: `inventory_report`, `general_items` and `historical_movements` are
available to every signed-in employee. They provide stock lookup and the
plastics order/movement archive only; warehouse write endpoints remain covered
by the permissions below.

The centralized pilot currently enforces Accounting, production Orders, Custom
Orders and Plastics Stock. `index.php` also rejects invalid page names and
directory traversal before including a page file.

### Current permission groups

- `orders.view`, `orders.work`, `orders.manage`, `orders.financial`,
  `orders.shipping`, `orders.admin`, `orders.export_reset`;
- `custom_orders.view`, `custom_orders.work`, `custom_orders.manage`,
  `custom_orders.financial`, `custom_orders.export`, `custom_orders.delete`,
  `custom_orders.audit`;
- `attendance.view_all`;
- `plastics.view`, `plastics.work`, `plastics.purchase`, `plastics.receive`,
  `plastics.manage`, `plastics.reports`;
- `accounting.view`, `accounting.import`, `accounting.export`.

`attendance.view_all` opens the read-only **Admin → Staff Attendance** view with
employee selection and daily details. The additive migration is
`db/authorization_attendance_view_all.sql`; it grants employee ID `21` a direct
allow so the page is available immediately and remains editable in
**Admin → User Permissions**.

`orders.export_reset` is grouped under **Administration** in the permissions UI
because it is a dangerous administrative recovery tool, even though the
underlying action affects Orders.

For Multishipping, `orders.work` permits changes inside the employee's shipping
workplace. `orders.shipping` additionally allows access across both the
plastics and non-plastics shipping scopes.

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
