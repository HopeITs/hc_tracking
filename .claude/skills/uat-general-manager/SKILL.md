---
name: uat-general-manager
description: Run the user-story test scenarios for the General Manager role against HC Tracking and report pass/fail per scenario. Use when asked to test, UAT or verify the General Manager role, or the ClickUp "General manager review" subtask.
---

# UAT: General Manager

Role name in code: `'General Manager'` (`userWithRole('General Manager')`). Source: ClickUp subtask "General manager review" and `docs/user-story.ar.md` §3. The baseline is every permission except: manage-roles, manage-permissions, reports.mark-seen, notification-settings.manage, reports.reopen, MEAL-only (`meal-coverage`, `report-templates`, `evidence-types`, `narrative-questions`), targets create/update, and `ai.draft`.

## How to run (same for every role)
1. Read `.ai/rules/index.md` and the rule files for the paths involved (donors, proposals, activity).
2. For each scenario below, `Grep` `tests/` for existing coverage. Run it: `php artisan test --compact <file> --filter=<name>`. Use `userWithRole()`, a fresh `Livewire::actingAs(...)->test(...)` per forbidden call.
3. A scenario with no test is **NOT COVERED**. List proposed gap tests and wait for approval before writing them (house style: `.ai/rules/tests.md`, `testing-best-practices` skill).
4. A scenario that fails because the app is wrong is a **DEFECT**: report it, do not bend the test. Do not change code unless asked.
5. **Never** click Livewire actions in the Browser pane on a dev port: it writes to the real dev DB. Browser use is read-only inspection.
6. Report a table: ID, scenario, result (PASS / FAIL / NOT COVERED), evidence. Do not post to ClickUp unless asked.

## Scenarios
- **GM-01** ✅ Approve a pending donor; the PR user's proposal can then be saved.
- **GM-02** 🚫 Cannot approve a proposal (`approved`): only the Public Relations Manager does (D-70). The approval itself (locations completed, missing projects created) is tested as the PR Manager.
- **GM-03** ⚠ Approval of a proposal with a location row that has no program or center is refused and writes nothing (tested as the PR Manager; the GM cannot approve).
- **GM-04** ✅ Open the activity log (only this role and Super Admin can).
- **GM-05** 🚫 Cannot confirm report templates, set targets, reopen reports or mark them seen, or use AI drafting.
- **GM-06** ✅ See the AI usage log.
