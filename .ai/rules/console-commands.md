---
paths:
  - '{database/seeders/**,app/Console/Commands/**}'
---

# Console Commands

## Never run RolesAndPermissionsSeeder on a live database
RolesAndPermissionsSeeder calls syncPermissions() on every role and wipes admin edits made on the Roles tab. Deploy permission changes with `php artisan permissions:sync` (additive only; supports --dry-run). Run `php artisan optimize:clear` first so it reads the current config.
