# Task 14 — MEAL alert for a program without a logical framework

> **المهمة:** إشعار MEAL للبرنامج بلا إطار منطقي
> **المطوّر:** B1 (مراجعة Dev 1) · **الفريق:** B · **الأيام:** 12 (الثلاثاء 2026-10-27، نصف يوم؛ نُقلت من اليوم 7 في المراجعة 3) · **الجهد:** يوم واحد · **يعتمد على:** Task 02، Task 05
> **متى تستخدمه:** بعد CP2 (نهاية اليوم 6: `results.proposal_id` من Task 12 مدموج، وأصناف الأحداث و`OversightRecipients` متاحة) وبعد Task 02 (كتالوج الصلاحيات). Task 05 (الفريق A) قد لا يكون مدموجًا بالكامل في اليوم 7، وTask 07 (خانة «مدير MEAL») تُدمج في اليوم 9؛ إن غابت إحداهما يطبّق البرومبت البديل الموصوف فيه.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 14 of the HC Tracking proposal-lifecycle correction plan in this Laravel 13 / Livewire 4 repository. The outcome: whenever a program has no program-level logical framework and work is attached to it, the MEAL team receives one clickable, de-duplicated alert ("add a simple logical framework to achieve the strategy") linking to the results page with the create form open for that program. In the corrected story every proposal's framework contributes to its program's framework (Task 12), so a program without one breaks the chain from strategy to activities; this alert closes that gap early. It also feeds the data-gap markers in Task 16.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (Boost rules, Pest, Pint, no new dependencies or base folders).
2. The plan `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`: section `### Task 14` in full. It is Arabic and authoritative; English names in it are exact. Also read section 5 row "الإشعارات والبريد" and, for context, `### Task 07` (the **Default Proposal Reviewers** "MEAL Manager" slot) and `### Task 12` (`results.proposal_id`). Read section 0 «قواعد العمل بين الفريقين» and `### Task 21` (the `program.logframe_missing` row of the event catalog).
3. The decision log `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists; a recorded decision overrides the plan's default for D-14 (who the MEAL manager is) and D-24.
4. Rule files: `.ai/rules/alerts.md`, `.ai/rules/recipients.md`, `.ai/rules/views-components-notifications.md`, `.ai/rules/observers.md`, `.ai/rules/database-seeders.md`, `.ai/rules/concerns-factories.md`, `.ai/rules/models.md`, `.ai/rules/views-pages.md`, `.ai/rules/middleware-actions-fortify.md`, `.ai/rules/results.md`, `.ai/rules/pages.md`, `.ai/rules/views.md`, `.ai/rules/views-enums.md`, `.ai/rules/tests.md`. Then `grep -rin 'ledger\|withoutRecording\|afterCommit\|recipient' .ai/rules`.
5. Existing code to study first:
   - `app/Notifications/Alerts/AlertDispatcher.php`, `AlertLedger.php` (`claim()`, `release()`), `DataGapScanner.php` (already in the `daily` scanner group of `config/notifications.php`), `WorkingCalendar.php`, `NotificationSettings.php`.
   - `app/Notifications/NotificationPayload.php` (`link`, `groupKey`), `app/Notifications/NotificationGrouper.php`, `app/Notifications/RecipientResolver.php` (`usersWithRoles()`), `app/Notifications/RecipientFilter.php` (view-permission filter), `app/Notifications/SubjectLinks.php` (`'program'` subject).
   - `app/Notifications/Recipients/ResultRecipients.php` (MEAL watches results), `ManagedRecipients.php` (registered for `programs`), `config/notifications.php` (`modules`, `module_icons`, `scanners`).
   - `app/Models/Program.php` (`syncFromHr()` writes with `insertOrIgnore` inside `Activity::withoutRecording`, so no Eloquent events fire), `app/Http/Middleware/SyncProgramsFromHr.php`, `app/Observers/ProgramObserver.php`, `app/Models/Project.php` (`#[ObservedBy(MediaOwnerObserver::class)]`, `#[Scope] open()`), `app/Jobs/FanOutActivity.php` (dispatched `->afterCommit()`).
   - `resources/views/pages/results/⚡index.blade.php` (`#[Url] public string $program`, `openCreateModal()`), and `resources/views/pages/indicators/⚡index.blade.php` `mount()` for the `?create=` deep-link pattern.
   - Tests: `tests/Feature/Notifications/IndicatorAndGapScannerTest.php`, `AlertDispatcherTest.php`, `QuietWritesTest.php`, `tests/Feature/Models/ProgramSyncTest.php`, `tests/Feature/Notifications/DesktopAppTest.php`.
6. Skills to activate: `laravel-best-practices`, `testing-best-practices`, `livewire-development` (results page deep link).
7. Use Boost `search-docs` for observers handling events after commit, queued jobs `afterCommit`, and scheduling. Use `database-schema` on `results`, `programs`, `notification_alert_ledger`.

## Preconditions to verify
- `config/access-control.php` and roles are synced (Task 02); role `MEAL` exists; `results.view` is a permission.
- `ProjectStatus` v2 from Task 05 is in place (open projects = `Project::open()` scope).
- `results.proposal_id` exists (Task 12, frozen at CP2). If it does not, "no framework" can only be `results.program_id = X`; stop and ask whether to proceed with that interim definition.
- Optional: the **Default Proposal Reviewers** setting `proposal_default_reviewers` in `notification_settings` with a MEAL Manager slot, resolved through Task 07's slot resolver (`holderEmployeeId(ReviewerSlot::MealManager)`). If missing, recipients are MEAL-role users only; report it as a follow-up.
If a required item fails, stop and report.

## Revision 2 changes (2026-10-08)
- Owner is now **B1 (Team B)**, reviewed by Dev 1. *(Schedule superseded: Revision 3 moved this task to day 12, ½ day; the Revision 4 schedule note in plan section 6 allows moving it after launch if Task 17's extra effort needs the time — confirm with Dev 1 at CP5 whether it ships in October.)*
- `program.logframe_missing` is already a row of the Task 21 event catalog (digest importance, MEAL + General Management as oversight recipients). Register it in `App\Notifications\EventCatalog` (or confirm Dev 3 did) so `EventCatalogCoverageTest` passes and General Management sees it in the daily digest; the MEAL manager still gets the immediate clickable alert.
- Work under the inter-team rules (plan section 0 «قواعد العمل بين الفريقين»): do not change Phase 1 tables (`proposals`, `projects`, `donor_proposal`, `proposal_status_notes`) or the proposal state machine — request any such change from Dev 1 so it is recorded in the plan; integrate with Phase 1 by listening to its events (`ProposalStatusChanged`, `ProjectActivated`, `ProjectPeriodChanged`, `FundingAgreementRecorded`, dispatched after commit) instead of calling Phase 1 Actions directly; every PR is reviewed by Dev 1 within one working day and merges only with this task's suite green; no production access — seed or masked data only.
- `Project` and `projects` belong to Phase 1 (Tasks 05 and 30, Dev 1/Dev 2). Adding a `ProjectObserver` to the `Project` model must be agreed with Dev 1 first: if Phase 1 already has a project observer or dispatches a project-created/program-changed event, listen to that instead of adding a second observer. Never change the `projects` schema.

## Scope
### In scope
- One alert rule, `program.logframe_missing`, delivered through `AlertDispatcher` with ledger key rule `logframe_missing`, subject `program:{id}`, stage `logframe` (the plan's `program:{id}:logframe`).
- Triggers:
  1. A program newly mirrored from HR without a framework. `Program::syncFromHr()` fires no model events and must stay quiet (`.ai/rules/database-seeders.md`, `QuietWritesTest`). Recommended: make `syncFromHr()` return the newly inserted ids and let `SyncProgramsFromHr` dispatch the check after the sync; seeders keep calling `syncFromHr()` and never notify. Alternative: rely on the daily rule only. Choose in plan mode.
  2. A project created under, or moved to, a program without a framework: create `app/Observers/ProjectObserver.php` (`created`, and `updated` when `program_id` is dirty), attach it with `#[ObservedBy]` next to `MediaOwnerObserver`, and dispatch a queued job `->afterCommit()`.
  3. A daily rule in `DataGapScanner`: "program with open projects and no program framework".
- Create one small notifier (e.g. `app/Notifications/Alerts/LogframeGapAlert.php`) used by all three triggers, so there is one delivery path; and a job (e.g. `app/Jobs/CheckProgramLogframe.php`) for the web-request triggers.
- Re-arm: when the scan finds the program now has a framework, `AlertLedger::release('logframe_missing', 'program', $id)` so a later deletion alerts again.
- Results page deep link: `/results?program={id}&create=1` opens the create modal pre-filled with the program for holders of `results.create` only (follow `indicators/⚡index` `mount()`).
- Event catalog entry `program.logframe_missing` in `App\Notifications\EventCatalog` (Task 21) with MEAL + General Management (digest).
- Lang keys under `notifications.alerts.logframe_missing.*` in `lang/en/notifications.php` and `lang/ar/notifications.php` (single and grouped sentence).
### Out of scope
Framework editing and proposal frameworks (Task 12); chain view gap markers (Task 16); **Default Proposal Reviewers** UI (Task 07); new permissions (Task 02); monthly digest (Task 21).

## Business rules
- "No framework" = no `results` row with `program_id = program` and `proposal_id IS NULL`.
- One alert per program each time the gap is detected; the ledger prevents repeats; it re-arms after a framework appears and is later deleted.
- Not sent while seeding, during the silent HR sync itself, or during imports (`Activity::withoutRecording` contexts).
- Priority `NotificationPriority::Important`.
- Recipients: users with role `MEAL`, plus the MEAL Manager slot of **Default Proposal Reviewers** when present (Task 07). A program without a manager changes nothing.
- Ten programs appearing in one sync produce one grouped notification per recipient stating the count, linking to `/results`; each program still claims its own ledger row.
- Day logic and thresholds follow `.ai/rules/alerts.md`: the daily rule skips non-working days (`WorkingCalendar`), no literal day counts.

## Security and authorization
- Only users holding `results.view` receive it: build the payload with module `results` so `RecipientFilter` applies `results.view` (or document how the check is applied if you keep module `programs`).
- Do not add MEAL-specific logic to `RecipientResolver`; keep recipient knowledge in the notifier, as `DataGapScanner` does, or in the module map.
- No sensitive data: program name and link only, no money, no project lists.
- The deep link opens the create modal only after `Gate::allows('results.create')`; the route stays behind `can:results.view`.

## Validation
No user input. The deep-link `program` and `create` query values are validated: `program` must be an existing program id, `create` must equal `1`; anything else is ignored silently.

## Edge cases and concurrency
- Framework created then deleted: the next daily scan releases/re-claims and alerts again.
- Overlapping scanner runs: `AlertLedger::claim()` is atomic (unique key); `notifications:scan` already runs `withoutOverlapping()`.
- Project created inside a transaction that rolls back: the job is dispatched `afterCommit`, so nothing is sent.
- Program removed from HR: its mirror is kept; it is still checked if it has open projects.
- MEAL user without an HR account or inactive: `RecipientFilter`/`EmployeeRecipients` behaviour applies; no crash.
- Program with only a proposal framework (`proposal_id` not null): still "no framework".

## Data integrity
- Never notify inside a transaction: jobs `->afterCommit()`, observers handle events after commit.
- Ledger rows in `notification_alert_ledger` are the only de-duplication state; no new table.
- `syncFromHr()` keeps `insertOrIgnore` and `Activity::withoutRecording`; changing its return type must keep `ProgramSyncTest` and `QuietWritesTest` green.

## Implementation guidance
- `declare(strict_types=1);`, typed signatures, PHPDoc array shapes, `final` like the other alert classes.
- Query for the gap in one statement (programs left-joined / `whereDoesntHave` on program-level results), not one query per program.
- Payload via `AlertDispatcher::payload('results', NotificationPriority::Important, 'notifications.alerts.logframe_missing.single', ['subject' => $program->name], 'program', $program->id)`, then `send('logframe_missing', 'logframe', ...)`. For the grouped case use a payload `link` to `results.index`.
- If you register a new module in `config/notifications.php`, `DesktopAppTest` requires a `module_icons` entry and rendered banners (`php artisan notifications:banners`); prefer reusing `results`.
- Skeleton:
```php
final class LogframeGapAlert
{
    /** @param list<int> $programIds  @return int notifications sent */
    public function notify(array $programIds): int {}
}
```

## Tests (Pest)
Create fixtures inside `Activity::withoutRecording` (`.ai/rules/recipients.md`); use `userWithRole('MEAL')`; assert on the records you created, not on "nothing else exists" (`.ai/rules/alerts.md`).
- `tests/Feature/Notifications/LogframeGapAlertTest.php`:
  - a project created under a program without a framework alerts MEAL once (after commit);
  - a project under a program with a framework does not alert;
  - moving a project to a program without a framework alerts;
  - the daily scan does not repeat an alert already in the ledger;
  - the scan re-arms after a framework is created and later deleted;
  - a user without `results.view` receives nothing;
  - ten new programs in one sync give one grouped notification per recipient with the count;
  - nothing is sent on a non-working day, during seeding, or from `Program::syncFromHr()` called directly;
  - the notification links to `/results?program={id}&create=1`;
  - the event is in `EventCatalog` and General Management receives it through the oversight recipients (digest); `EventCatalogCoverageTest` stays green.
- `tests/Feature/ResultsFrameworkTest.php` (extend): `?program=&create=1` opens the modal for `results.create` holders only.
- Re-run `tests/Feature/Notifications/QuietWritesTest.php`, `tests/Feature/Models/ProgramSyncTest.php`, `tests/Feature/Notifications/IndicatorAndGapScannerTest.php`, `tests/Feature/LocalizationTest.php`.
Commands: `php artisan test --compact tests/Feature/Notifications/LogframeGapAlertTest.php`, then `--filter=...` per case.

## Acceptance criteria
- [ ] The MEAL manager receives one clickable notification per program missing a framework.
- [ ] No duplicate alerts across scans; re-alert after a framework is deleted.
- [ ] No alert during seeding or the HR sync itself; `QuietWritesTest` passes.
- [ ] Recipients without `results.view` receive nothing.
- [ ] General Management receives the event through the Task 21 catalog (digest).
- [ ] Messages exist in English and Arabic.

## Do not
- Duplicate recipient logic inside `RecipientResolver` instead of the module map / notifier.
- Send the notification from inside a transaction.
- Record activity or notify from `Program::syncFromHr()` itself.
- Use `startOfWeek()` without the ISO/Monday rule, or hard-code day counts.
- Add a new module key without its icon and banners.

## Quality bar (this task)
- The notification has a short title, the program name and one action «إنشاء إطار البرنامج» that opens the pre-filled create modal; many programs at once produce one grouped message.
- Everything in `quality-bar.md` applies. Before reporting, run `vendor/bin/phpstan analyse` on the touched paths and list the result in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions; wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside this task's scope; note anything else as a follow-up.

## Report back
Your final message must contain: a summary; files changed; migrations added (expected: none); tests run with pass/fail output; decisions assumed (D-14, trigger-1 option chosen, module key used); follow-ups (e.g. MEAL Manager slot pending Task 07); whether a `ProjectObserver` was added or a Phase 1 event reused, and Dev 1's agreement. Handoff facts for Task 16 (data-gap markers): the exact definition of "program without framework" and the ledger rule/stage names; for Task 21: the rule name so the monthly digest does not duplicate it.
````
