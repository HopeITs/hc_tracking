# Laravel 13 baseline — design

**Date:** 2026-09-04
**Status:** implemented

## Goal

Stand up a Laravel 13 framework baseline for HC Tracking on PHP 8.5, in
`C:\xampp\htdocs\hc-tracking`, without disturbing anything else on the machine.

## Context discovered before building

The folder was empty, but the machine was not a blank slate:

- XAMPP shipped **PHP 8.4.12**; PHP 8.5 was not installed anywhere.
- **Seven other Laravel apps** shared that PHP install — three of them Laravel 11.
- A **`hc-tracking` database already existed** (28 tables, 26 FKs, all business
  tables empty) alongside `hc_tracking_test`.
- The previous codebase survived at `C:\xampp\htdocs\hc-tracking-old`
  (Laravel 13.8 + Livewire 4.3), but its **20 domain migrations were gone** —
  absent from disk and from git history. The live database schema was the only
  surviving record of the domain model.

## Decisions

| # | Decision | Rationale |
|---|---|---|
| 1 | PHP 8.5.10 NTS installed to `C:\php85`, kept off PATH | Gives this project 8.5 while leaving the other seven apps on 8.4.12. Reversible by deleting one folder. |
| 2 | `"php": "^8.5"` + `config.platform.php = 8.5.10` | Running on the wrong PHP fails loudly via `platform_check.php` rather than behaving subtly differently. |
| 3 | Committed `php.cmd` / `composer.cmd` wrappers | Project-local commands reach 8.5 without a PATH change. `PHP85` env var overrides the location. |
| 4 | Livewire starter kit (TALL) | Server-rendered, matches the app's internal-tool shape. Installed from the repo's `main` branch — the tagged Packagist release v1.0.1 is still Laravel 12. |
| 5 | Pest 5 + Larastan at level 7 | Kit ships both. Level 7 is stricter than the level 6 originally agreed and passes clean, so it was kept rather than weakened. |
| 6 | Existing databases archived, not dropped | Dump → restore as `*_old` → verify tables/FKs/rows → only then drop. The old schema is irreplaceable. |
| 7 | New schemas `hc_tracking` / `hc_tracking_test`, underscore-named | Hyphens force backtick-quoting in every raw query. |
| 8 | Dedicated `hc_tracking` MariaDB user, not `root` | App never runs with superuser rights; grants scoped to its two schemas. |
| 9 | Tests target MariaDB `hc_tracking_test`, not sqlite `:memory:` | Migrations get verified on the engine the app actually uses. Slower, deliberately. |
| 10 | `artisan serve` only; no Apache VirtualHost | Production hosting deferred by decision. |

## Deviations from the original plan

- **Larastan level 7 instead of 6** — the starter kit's own default, and it
  passes with zero errors. Weakening it would have been a regression.
- **Scaffolded in a temp directory then moved.** The installer's `--force` tries
  to delete and recreate the target, which Windows refuses while the directory
  is a live process's working directory.
- **`config/app.php` patched.** Laravel 13 hardcodes `'timezone' => 'UTC'` and
  no longer reads `env('APP_TIMEZONE')`. Restored to
  `env('APP_TIMEZONE', 'UTC')`, otherwise the `.env` setting is inert and every
  timestamp silently falls back to UTC.
- **Laravel Boost not installed.** Offered by the installer, outside the agreed
  scope, so deliberately skipped with `--no-boost`.

## Not in scope

Domain modelling, authentication policy, roles/permissions, seeders, CI,
production hosting, and any port of code from `hc-tracking-old`.
