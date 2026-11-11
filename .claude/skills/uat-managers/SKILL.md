---
name: uat-managers
description: Run the user-story test scenarios for the Managers role (center/program manager) against HC Tracking and report pass/fail per scenario. Use when asked to test, UAT or verify the Managers role, or the ClickUp "Managers review" subtask.
---

# UAT: Managers (center / program manager)

Role name in code: `'Managers'` (`userWithRole('Managers')`). Source: ClickUp subtask "Managers review" and `docs/user-story.ar.md` §6. Task visibility bypass role; department managers are an HR job level (`department_manager_job_level` in config), not a role.

## How to run (same for every role)
1. Read `.ai/rules/index.md` and the rule files for the paths involved (projects, reports, tasks, programs).
2. For each scenario below, `Grep` `tests/` for existing coverage. Run it: `php artisan test --compact <file> --filter=<name>`. Use `userWithRole()`, a fresh `Livewire::actingAs(...)->test(...)` per forbidden call. Use `travelTo()` for working-day rules, and `travelBack()` before Livewire uploads.
3. A scenario with no test is **NOT COVERED**. List proposed gap tests and wait for approval before writing them (house style: `.ai/rules/tests.md`, `testing-best-practices` skill).
4. A scenario that fails because the app is wrong is a **DEFECT**: report it, do not bend the test. Do not change code unless asked.
5. **Never** click Livewire actions in the Browser pane on a dev port: it writes to the real dev DB. Browser use is read-only inspection.
6. Report a table: ID, scenario, result (PASS / FAIL / NOT COVERED), evidence. Do not post to ClickUp unless asked.

## Scenarios
- **MG-01** 🚫 Cannot add a center: no "Add center" button, and `projects.create` is refused. Only Super Admin, General Manager (CEO) and Public Relations Manager add centers (decision 2026-11-10).
- **MG-02** ✅ Acknowledge project ownership after donor approval: the project becomes `approved`.
- **MG-03** ✅ Fill and submit a monthly report: counts (0 allowed), notes, typed evidence.
- **MG-04** ⚠ Submit before the last working day, or while the previous month is unsubmitted: refused. After submit: locked. A not-yet-started month shows "upcoming", never overdue.
- **MG-05** ✅ Request a reopen; the decision shows on the Monthly Reports page and in the notifications. (The My Reporting Duties page was removed 2026-11-10; its old address redirects to Donor Reports.)
- **MG-06** ✅ A Grade 5 department manager sees the whole department's tasks; a non-manager does not.
- **MG-07** 🚫 Cannot validate their own report.
