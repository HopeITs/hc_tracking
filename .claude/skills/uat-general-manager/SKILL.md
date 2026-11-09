---
name: uat-general-manager
description: Run the user-story test scenarios for the General Manager role against HC Tracking and report pass/fail per scenario. Use when asked to test, UAT or verify the General Manager role, or the ClickUp "General manager review" subtask.
---

# UAT: General Manager

Role name in code: `'General Manager'` (`userWithRole('General Manager')`). Source: ClickUp subtask "General manager review" and `docs/user-story.ar.md` §3. The baseline is every permission except: manage-roles, manage-permissions, reports.mark-seen, notification-settings.manage, reports.reopen, MEAL-only (`meal-coverage`, `report-templates`, `evidence-types`, `narrative-questions`), targets create/update, `proposals.approve` (D-70: the PR Manager approves), and `ai.draft`.

## How to run (same for every role)
1. Read `.ai/rules/index.md` and the rule files for the paths involved (donors, proposals, activity).
2. For each scenario below, `Grep` `tests/` for existing coverage. Run it: `php artisan test --compact <file> --filter=<name>`. Use `userWithRole()`, a fresh `Livewire::actingAs(...)->test(...)` per forbidden call.
3. A scenario with no test is **NOT COVERED**. List proposed gap tests and wait for approval before writing them (house style: `.ai/rules/tests.md`, `testing-best-practices` skill).
4. A scenario that fails because the app is wrong is a **DEFECT**: report it, do not bend the test. Do not change code unless asked.
5. Click-through on the dev site only when the user asks for it: it writes to the real dev DB. Build any test data you need (name it `UAT ...`), and delete it when the test is done (agreed with the team lead, 2026-11-09), together with its activity entries and notifications. If a role must be lent to the user's account, note the original role and restore it.
6. Before a site test after a pull, run `php artisan migrate` and `php artisan permissions:sync`: a permission missing from the dev DB hides whole sections (e.g. `donors.approve` hides the donor requests).
7. Report a table: ID, scenario, result (PASS / FAIL / NOT COVERED), evidence. Do not post to ClickUp unless asked.

## Scenarios
- **GM-01** ✅ Approve a pending donor; the PR user's proposal can then be saved.
- **GM-02** ✅ Is notified when the PR Manager approves a proposal; sees no Approve button and the server refuses the approval (D-70, agreed with the team lead 2026-11-09). The approval itself is PM-08 to PM-10.
- **GM-04** ✅ Open the activity log (only this role and Super Admin can).
- **GM-05** 🚫 Cannot confirm report templates, set targets, reopen reports or mark them seen.

Postponed until the AI features are built (team lead, 2026-11-09): AI drafting is refused to this role; GM-06 see the AI usage log (the permission exists, no page yet). GM-03 moved to the PR Manager as PM-10.
