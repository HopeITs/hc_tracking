---
paths:
  - '{config/fortify.php,app/Console/Commands/**,app/Enums/**}'
---

# Enums

## Registration is off; accounts come from user:create; roles are a backed enum
`Features::registration()` is deliberately removed from config/fortify.php. This is an internal system holding donor budgets — re-enabling it exposes /register to anyone on the internet. tests/Feature/Auth/RegistrationDisabledTest.php fails if it comes back; tests/Feature/Auth/RegistrationTest.php skips itself automatically, so it is NOT proof either way.

TRAP: disabling it broke the login page. resources/views/pages/auth/login.blade.php called route('register') unguarded and threw RouteNotFoundException, 500ing /login. It is now wrapped in @if (Route::has('register')), matching welcome.blade.php which was already guarded. Guard any new link to an optional Fortify route the same way.

Accounts are created with `php artisan user:create` (interactive, or fully scripted with --name --email --role --password --no-interaction). It marks the address verified unless --unverified is passed, because /dashboard sits behind the `verified` middleware and there may be no mail transport configured.

`users.role` casts to App\Enums\UserRole (Officer, Manager, MEAL, Admin). Casing is part of the value — 'meal' is NOT 'MEAL' and now throws a ValueError on read rather than silently being a fourth role. `role` stays out of the User model's #[Fillable] so it can never be set by mass assignment; user:create uses forceFill.

Policies do not exist yet. Authorization rules were deliberately deferred until the UI exists and the real per-role access rules are known.
