# Central authorization deployment

This package contains the central role and permission system plus its
integration with Accounting, Orders, Custom Orders and Plastics Stock.

## Installation

1. Back up the application files and database.
2. Copy the package contents over the DarkScrub application root while
   preserving the directory structure.
3. Run `db/authorization.sql` against the application database.
4. Sign in as the emergency administrator (`employees.permission = 900`) and
   open **Admin → User Permissions**.
5. Verify one administrator and one ordinary employee before assigning or
   removing further roles.

The SQL migration is additive and idempotent. It does not remove or overwrite
the legacy `employees.permission` values. Existing assignments are inserted
with duplicate-safe statements, so rerunning the migration does not reset
manual permissions.

See `docs/authorization.md` for the permission model and extension guide.
