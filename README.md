# HC Tracking

Laravel 13 application on an **isolated PHP 8.5.10** runtime.

---

## Why there is a `php.cmd` in this repo

This machine's XAMPP runs **PHP 8.4.12**, and seven other Laravel apps in
`C:\xampp\htdocs` depend on it. This project needs **PHP 8.5**, so 8.5.10 was
installed to `C:\php85` and deliberately **kept off the system PATH**.

Nothing in XAMPP, in `PATH`, or in `C:\composer` was modified. Typing `php`
anywhere on this machine still gives you 8.4.12, exactly as before.

Inside this project, use the wrappers instead.

**PowerShell** needs the `.\` prefix, because PowerShell never searches the
current directory for executables:

```
.\php.cmd artisan migrate
.\composer.cmd install
```

**cmd.exe** resolves them without the prefix:

```
php.cmd artisan migrate
composer.cmd install
```

The `.\` form works in both shells, so prefer it if in doubt. Both wrappers
call `C:\php85\php.exe` explicitly; override the interpreter with the `PHP85`
environment variable if PHP 8.5 lives somewhere else.

### The safety net

`composer.json` declares `"php": "^8.5"`, so Composer's `platform_check.php`
aborts immediately if the app is ever run on 8.4:

```
Your Composer dependencies require a PHP version ">= 8.5.0". You are running 8.4.12.
```

That is intentional. A loud failure beats subtly different behaviour between
PHP versions. `config.platform.php` is pinned to `8.5.10` so dependency
resolution always targets the runtime that actually executes the code.

---

## Requirements

| | |
|---|---|
| PHP | 8.5.10 at `C:\php85` (NTS, vs17 x64) |
| Composer | 2.9.5 (`C:\composer\composer.phar`) |
| Database | MariaDB 10.4.28 via XAMPP (Laravel `mysql` driver) |
| Node | 24.14.0 / npm 11.11.0 |

---

## Setup

```
.\composer.cmd install
npm install
.\php.cmd artisan migrate
npm run build
```

Then:

```
.\php.cmd artisan serve
```

The app is served at <http://localhost:8000>. Apache is **not** used for this
project; production hosting is a separate, later decision.

---

## Databases

| Schema | Purpose |
|---|---|
| `hc_tracking` | development |
| `hc_tracking_test` | automated tests (see `phpunit.xml`) |
| `hc_tracking_old` | archived schema of the previous attempt - reference only |
| `hc_tracking_test_old` | archived test schema - reference only |

The app connects as the `hc_tracking` MariaDB user, **not** `root`. That user is
granted only on the two live schemas; anything else is denied.

Tests run against `hc_tracking_test` on MariaDB rather than the sqlite
in-memory default, so migrations are verified on the same engine the app uses.
This is slower than sqlite and that trade is deliberate.

The connection uses Laravel's `mysql` driver. Laravel also ships a `mariadb`
driver; both work against XAMPP's MariaDB 10.4.28, and `mysql` was chosen for
portability. Switching is a one-line change in `.env` and `phpunit.xml`.

---

## Quality gates

```
.\composer.cmd run test          # config:clear + Pint + PHPStan + Pest
.\composer.cmd run lint          # apply Pint formatting
.\composer.cmd run lint:check    # verify formatting only
.\composer.cmd run types:check   # Larastan / PHPStan
```

Static analysis runs at **PHPStan level 7** (`phpstan.neon`).

---

## Laravel Boost

`laravel/boost` is installed as a dev dependency, providing AI coding
guidelines (`CLAUDE.md`, `AGENTS.md`), seven Laravel-specific agent skills and
an MCP server.

Every generated MCP config points at `C:\php85\php.exe` rather than a bare
`php`. Boost writes `"php"` by default, which resolves to XAMPP's 8.4.12 here
and is rejected by the platform guard, so the MCP server would never start.
If you re-run `.\php.cmd artisan boost:install`, re-check those paths in:

```
.mcp.json             .cursor/mcp.json
.codex/config.toml    .junie/mcp/mcp.json
```

The Codex config uses a TOML *literal* string (single quotes) for the path. A
basic double-quoted string would read the Windows backslashes as invalid
escape sequences.

---

## Frontend

Tailwind CSS **4.3.3** (latest) via `@tailwindcss/vite`.

Alpine.js **3.17.1** (latest) is provided by Livewire 4, together with the
official `anchor`, `collapse`, `focus`, `intersect`, `mask`, `morph`,
`persist`, `resize`, `sort` and `csp` plugins.

**Do not `npm install alpinejs`.** Livewire bundles its own Alpine; adding a
second copy loads two instances and breaks reactivity.

---

## Repository

```
origin  git@gitlab.com:youss8/hc-tracking-app.git
```

This is a new, separate repository. The month-old project at
`git@gitlab.com:youss8/HC-Tracking.git` is untouched.

GitLab lowercases project paths, so `HC-Tracking` occupies the path
`youss8/hc-tracking`. That is why this project is `hc-tracking-app` - the two
names would otherwise have resolved to the same repository.

---

## Stack

Laravel 13.30.1 · Livewire 4 · Flux 2 · Fortify · Tailwind 4 · Alpine 3 ·
Pest 5 · Larastan 3 · Pint.
