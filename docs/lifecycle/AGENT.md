# Session rules for every lifecycle task

Every prompt in `docs/lifecycle/prompts/` inherits these rules. Read this file once per session, before the task prompt. A prompt may tighten a rule, never loosen it.

## 1. Sources of truth (in this order)
1. `docs/lifecycle/contracts.md`: names, values, signatures, payloads, schema, permissions. **Frozen.**
2. `docs/lifecycle/decisions.md`: final business answers D-01…D-54. **Do not re-decide.**
3. The task prompt `docs/lifecycle/prompts/TNN.md`.
4. Upstream handoffs `docs/lifecycle/handoffs/TNN.md` and the code on `main`.
5. `CLAUDE.md` and the `.ai/rules` files whose globs match the paths you touch (`.ai/rules/index.md`).

`docs/lifecycle/quality-bar.md` is the code and UI/UX bar for every task; each prompt's "Quality bar (this task)" section adds to it.

If two sources disagree, the higher one wins. If the higher one is *missing* something you need, **stop and ask** (section 6). Never fill a contract gap with your own choice. There is no other plan file: do not look for the Arabic `.ar.md` plan or any `.docx`.

## 2. Start state (run before planning)
1. `git status` must be clean. Then `git switch main && git pull`, then `git switch -c lifecycle/TNN-<slug>`. For a later slice, re-use the task's branch.
2. Run the prompt's **Start state** checks: upstream classes, columns, permissions and test files exist. Run the upstream test files the prompt names; they must be green.
3. Boost `database-schema` on every table you will read or change.
4. If any check fails: report exactly what failed and STOP. Never stub, fake or rebuild an upstream task. Exception: the prompt says "build against the contract".

## 3. Plan mode
- Restate the task in 5 bullets, list every file you will create, change or delete, and cite the `contracts.md` section for every contract you touch.
- Ask only about things the pack does not answer. List them as `Q1…Qn`, each with your recommended answer. Any question that touches `contracts.md` goes to **Dev 1**, not to the developer at the keyboard.
- Wait for approval. Do not write files before approval.

## 4. Build rules
- **Test first.** Write or extend the test file named in "Build order", run it, see it fail for the right reason, then implement. One step, then the narrowest test run.
- **PHP command:** use the PHP binary named in your `CLAUDE.local.md` (e.g. `.\php.cmd` on isolated installs). Default `php`. Run `<php> artisan test --compact <files>` or `--filter=`. **Never run the full suite** (~10 min); T28 is the only exception.
- After PHP changes: `vendor/bin/pint --dirty --format agent`.
- `php artisan make:* --no-interaction` for new classes, models, migrations, tests (`--pest`).
- Use Boost `search-docs` before relying on a framework API you have not used in this repo.
- Real skills only: `laravel-best-practices`, `livewire-development`, `fluxui-development`, `tailwindcss-development`, `testing-best-practices`, `fortify-development`, `infer-conventions`.

## 5. Non-negotiables (every task, checked in review)
- **Authorization:** `Gate::authorize(...)` inside every Action (not only in the component); one policy per new model (`PolicyCoverageTest`); a 403 test per action; a BOLA test for every action that takes a child id (load it through the parent relation: `$parent->children()->whereKey($id)->firstOrFail()`).
- **Concurrency:** state changes run in one `DB::transaction`, reload the row with `lockForUpdate()`, compare an `expected*` status, refuse with the stale message and write nothing. Lock several rows in ascending id order.
- **After commit:** domain events (`ShouldDispatchAfterCommit`) and notifications (`ActivityRecorder` fan-out) run after commit. A failing listener never rolls back the action. Listeners are queued and idempotent.
- **Money:** "Hide money, keep names". Amounts are queried only for holders of `funding-agreements.view`, `transfers.view` or `accounts.view`; skip the query server-side (`@can` alone is not enough). No amounts in activities, toasts, notifications, exports or the matrix.
- **HR is read-only:** never `save()`/`delete()` an HR model; never copy HR names or titles into tracking tables; never select `contracts.salary`/`currency`/`*`; never `whereHas` across connections.
- **Status writes** only through the writers listed in `contracts.md §4`; guard tests enforce this.
- **Code style:** `declare(strict_types=1);`, `#[Fillable]`, `casts()`, `CarbonImmutable`, explicit types, PHPDoc array shapes, curly braces, exhaustive `match` without `default`, strict mode (eager-load; set relations before saving when `HasSlug` reads them).
- **UI:** every visible string in `lang/en` **and** `lang/ar` (`LocalizationTest`); Flux modals bound with `wire:model` booleans, never `:open`; no `@php(...)` shorthand; logical Tailwind classes (RTL); `x-ui.*` components already on the page; status never shown by colour alone.
- **Migrations:** `database-schema` first; pre-checks before DDL (MySQL does not roll back DDL); explicit index/FK names; working `down()`; utf8mb4_unicode_ci (`DatabaseEncodingTest`).

## 6. Stop and escalate
Stop, write the question in chat, and wait if the task needs any of these:
- a change to `contracts.md` (a name, value, signature, payload, column or permission);
- a change to a Phase 1 table (`proposals`, `projects`, `project_proposal`, `donor_proposal`, `proposal_status_notes`) or the state machine, when you are Team B;
- a new dependency, a new base folder, or a schema change the prompt does not list;
- anything on production.

## 7. Never
Commit, push, or merge (the developer reviews and commits). Run `migrate:fresh`, `RolesAndPermissionsSeeder` or role-resetting seeders on a shared DB. Touch production or ask for credentials. Write to the HR database. Use native memory instead of Boost `record-rule`. Call `record-rule` for something a human has not confirmed. Work outside the prompt's scope (note it as a follow-up instead).

## 8. Context hygiene
One prompt slice = one session. If the session auto-compacts before you finish, stop: write the progress so far into the handoff file under "In progress", and tell the developer to `/clear` and resume with `/lifecycle TNN <slice>`.

## 9. Finish
1. The prompt's **Definition of done** is complete, with real test output pasted.
2. Write or update `docs/lifecycle/handoffs/TNN.md` from `handoffs/_TEMPLATE.md`. This file is the integration contract; the chat is not.
3. Final chat message: summary, files changed, migrations, test output, deviations from the prompt (with reasons), follow-ups.
