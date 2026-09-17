---
paths:
  - '{app/Providers/FortifyServiceProvider.php,app/Actions/Fortify/**,config/fortify.php}'
---

# Fortify

## laravel/passkeys stays installed — it's a hard Fortify dependency, not optional
User asked to "remove passkeys from the system." Investigated and found `laravel/passkeys` is required by `laravel/fortify` itself (composer.lock: fortify's own `require` block lists `laravel/passkeys: ^0.2.0`), not something this app added independently — it can't be `composer remove`d without removing Fortify, which runs the entire login/2FA/session-auth system. Presented this tradeoff to the user; they chose to leave the passkey feature (backend, routes, Security settings UI, `app/Models/User` relation, migration) fully untouched rather than do a partial teardown. Only the login page's `<x-passkey-verify />` widget was dropped, as part of the login-page redesign matching a provided screenshot with no passkey option shown — that's a visual/page-scope change, not a system removal. Do not attempt `composer remove laravel/passkeys` or drop the passkeys migration without re-confirming with the user; the constraint above still applies.
