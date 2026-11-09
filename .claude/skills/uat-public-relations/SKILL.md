---
name: uat-public-relations
description: Run the user-story test scenarios for the Public Relations role (fundraising officer) against HC Tracking and report pass/fail per scenario. Use when asked to test, UAT or verify the Public Relations role, or the ClickUp "Public relations review" subtask.
---

# UAT: Public Relations

Role name in code: `'Public Relations'` (`userWithRole('Public Relations')`). Source: ClickUp subtask "Public relations review" and `docs/user-story.ar.md` §1. Permissions: `config/access-control.php` → `role_baselines`.

## How to run (same for every role)
1. Read `.ai/rules/index.md` and the rule files for the paths involved.
2. For each scenario below, `Grep` `tests/` for existing coverage. Run it: `php artisan test --compact <file> --filter=<name>`. Use `userWithRole()`, a fresh `Livewire::actingAs(...)->test(...)` per forbidden call.
3. A scenario with no test is **NOT COVERED**. List proposed gap tests and wait for approval before writing them (house style: `.ai/rules/tests.md`, `testing-best-practices` skill).
4. A scenario that fails because the app is wrong is a **DEFECT**: report it, do not bend the test. Do not change code unless asked.
5. **Never** click Livewire actions in the Browser pane on a dev port: it writes to the real dev DB. Browser use is read-only inspection (DOM, labels, Arabic text).
6. Report a table: ID, scenario, result (PASS / FAIL / NOT COVERED), evidence (test name or file:line). Do not post to ClickUp unless asked.

## Scenarios
- **PR-01** ✅ Create a proposal with an existing donor, a location row (city only) and a concept note; move concept note → review → submitted.
- **PR-02** 🚫 Cannot record a donor: only the Public Relations Manager records one and the General Manager approves it. The donor picker lists existing approved donors only.
- **PR-03** ⚠ Location row with `is_new` when program/center is missing; submitting without a city is refused.
- **PR-04** ✅ Add negotiation notes (text and file) after submission.
- **PR-05** 🚫 Cannot enter a budget, record an agreement, correct a status, manage reviewers, or see Transfers/Accounts (each as a separate fresh component call).
- **PR-06** ✅ AI drafting works; the AI usage log is not visible.

## Access (decided 2026-11-09)
`Public Relations` holds `proposals.view/create/update` and `donors.view` (migration `grant_public_relations_proposal_permissions`), but not `donors.create`, `proposals.approve` or any money view. If PR-01 is refused, that is a defect.
