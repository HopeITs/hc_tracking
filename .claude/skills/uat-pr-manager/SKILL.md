---
name: uat-pr-manager
description: Run the user-story test scenarios for the Public Relations Manager role against HC Tracking and report pass/fail per scenario. Use when asked to test, UAT or verify the PR Manager role, or the ClickUp "PR manager review" subtask.
---

# UAT: Public Relations Manager

Role name in code: `'Public Relations Manager'` (`userWithRole('Public Relations Manager')`). Source: ClickUp subtask "PR manager review" and `docs/user-story.ar.md` §2. Permissions: `config/access-control.php` → `role_baselines`.

## How to run (same for every role)
1. Read `.ai/rules/index.md` and the rule files for the paths involved (proposals, funding-agreements, transfers, donors).
2. For each scenario below, `Grep` `tests/` for existing coverage. Run it: `php artisan test --compact <file> --filter=<name>`. Use `userWithRole()`, a fresh `Livewire::actingAs(...)->test(...)` per forbidden call.
3. A scenario with no test is **NOT COVERED**. List proposed gap tests and wait for approval before writing them (house style: `.ai/rules/tests.md`, `testing-best-practices` skill).
4. A scenario that fails because the app is wrong is a **DEFECT**: report it, do not bend the test. Do not change code unless asked.
5. **Never** click Livewire actions in the Browser pane on a dev port: it writes to the real dev DB. Browser use is read-only inspection.
6. Report a table: ID, scenario, result (PASS / FAIL / NOT COVERED), evidence. Do not post to ClickUp unless asked.

## Scenarios
- **PM-01** ✅ Review a concept note and return it with a reason; the PR user sees the reason and can resubmit.
- **PM-02** ✅ Add and remove reviewers; correct a wrong final status.
- **PM-03** ✅ Record a funding agreement with installments; one expected transfer (`source = auto`) per installment.
- **PM-04** ⚠ A second agreement on the same proposal is refused; so is one on a proposal the donor hasn't approved.
- **PM-05** ✅ Accept the final report: the proposal becomes `completed`.
- **PM-06** ✅ Record an extension: new end date, reason, file.
- **PM-07** 🚫 Cannot approve a donor, validate monthly reports, or edit transfers.
- **PM-08** ✅ Only the PR Manager records the donor's approval of a proposal (`proposals.approve`, D-70): Financial, General Manager and every other role are refused and see no Approve button. The General Manager (CEO) is notified when the PR Manager approves.
