# Task 21 — Comprehensive notification matrix: EventCatalog, MEAL + General Management oversight, monthly digest and project date notices

> **المهمة:** مصفوفة الإشعارات الشاملة: كل حركة على المشروع والمقترح والتقرير تصل لفريق MEAL والإدارة العامة، والملخص الشهري، وإشعارات بداية المشروع ونهايته، وتذكير المعلّق
> **المطوّر:** Dev 3 (الكتالوج والمستلمون والماسح اليومي والإعدادات والتغطية، مراجعة Dev 1) + B4 (الملخص الشهري) · **الفريق:** A (+B4 من الفريق B) · **الأيام:** 3–12 · **الجهد:** 4 أيام (Dev 3: 3، B4: 1) · **يعتمد على:** Task 02؛ وتتغذى من أحداث Task 04 و07 و08 و09 و17 و19 و31، ومستلمي Task 15 و23 و30
> **متى تستخدمه:** على أجزاء بحسب الجدول. **الجزء A** (مفاتيح `EventCatalog`، تُجمَّد في CP0 نهاية اليوم 2، والصنف في اليوم 5) و**الجزء B** (`OversightRecipients` وتوسيع `RecipientResolver`، اليوم 7، متاح قبل CP2 نهاية اليوم 6 أو مباشرة بعدها) بعد دمج Task 02. **الجزء C** (الماسح اليومي وقسم **Event Notifications**، اليوم 9) بعد دمج Task 04. **الجزء D** (الملخص الشهري، B4، اليوم 8) بأقسامه المتاحة، ويكتمل بعد CP3 (Task 17). **الجزء E** (`EventCatalogCoverageTest` وربط أحداث المهام الأخرى، اليومان 11–12) بعد CP4. أعد تشغيل البرومبت لكل جزء مع ذكر الجزء في أول رسالة.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو، ثم اكتب الجزء المطلوب (A…E). راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 21 of the HC Tracking proposal-lifecycle correction plan (Laravel 13, PHP 8.5, Livewire 4 + Flux UI 2, Pest). Owners: Dev 3 (Team A, partial availability) for the catalog, recipients, daily scanner, settings section and coverage test, reviewed by Dev 1; B4 (Team B) for the monthly digest. Days 3–12, 4 days of effort. The developer will tell you which part (A–E) to implement in this session; do only that part, but read everything so the parts fit together.

Binding domain fact (plan section 1.0, item 8): **notifications are essential** — "every movement on a project, a proposal or a report must reach the MEAL team and General Management", in addition to the people directly concerned. The outcome:
- **Part A — `App\Notifications\EventCatalog`**: one catalog of events with a stable key, module, importance (`immediate` / `digest`), direct recipients, and whether oversight applies (all, management only, none). Keys are frozen at CP0; the class lands on day 5 so other tasks can reference it.
- **Part B — `App\Notifications\Recipients\OversightRecipients`**: the MEAL team (holders of role `MEAL` + the MEAL officer of the project's city from Task 15, `meal_coverages`) and General Management (role `General Manager` + roles configured in settings), added automatically to every catalog event unless the event says otherwise; department recipients (head + members from HR via `EmployeeProfile::employeeIdsInDepartment`, Task 30) for `department_task.*` (Task 31). `RecipientResolver` is extended to apply the catalog.
- **Part C — daily scanner(s)**: `project.started` on `start_date`, `project.ended` on `end_date` (active projects), `proposal.on_hold_reminder` on `status_remind_on`, and `monthly_report.due` / `.overdue` (if Task 17 did not already register them in `DeadlineCatalog` — check first). Plus an **Event Notifications** section in the existing **Notification Rules** admin page: per event enable/disable, move between immediate and daily digest, add/remove oversight roles — no code change needed.
- **Part D — `reports:monthly-digest`** (B4): first working day of the month, 08:00, for each program manager and General Management, copied to the MEAL Manager and the Public Relations Manager: monthly reports (generated, submitted, validated, missing), off-track indicators, overdue department tasks, due/overdue donor reports, projects that started or ended. Email (translated per recipient) + in-app notification, links only, no money.
- **Part E — `EventCatalogCoverageTest`** + wiring: every catalog event has a notification and recipients; every domain event dispatched in code (`ProposalStatusChanged`, `ProjectActivated`, `ProjectPeriodChanged`, `FundingAgreementRecorded`, …) and every activity event that the catalog covers is in the catalog. Adding a movement without a notification must fail CI.

Each task emits its own events (Definition of Done); this task owns the catalog, oversight recipients, coverage, scanners and digest — it does not rewrite every module's notifications.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (Boost tools, Pest, Pint, `php artisan make:* --no-interaction`, no new dependencies or base folders without approval).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md` — Arabic and authoritative; English names are exact. Read: section 0 (teams, phases, inter-team rules, risks R6 and R11), section 1.0, `### Task 21` in full including the **event catalog table** (keys, direct recipients, MEAL + management column, importance), the "تعديلات المراجعة 2" blocks of `### Task 08` (events `ProposalStatusChanged(proposalId, from, to, actorId)`, `ProjectActivated(projectId, proposalId, startDate, endDate, via)`, `ProjectPeriodChanged(...)`, after commit) and `### Task 09` (`FundingAgreementRecorded`, `transfer.generated`, no amounts for non-finance) and `### Task 17` (`monthly_reports.generated` grouped, `.due`, `.overdue`, `.submitted`, `.validated`, `.returned`, `.reopen_requested`, `.reopen_decided`; drafts generated before their month never alert), the outputs of `### Task 15`, `### Task 19`, `### Task 23`, `### Task 30`, `### Task 31`, `### Task 14` (`program.logframe_missing`), section 5 rows "البيانات المالية", "الإشعارات والبريد", "الأوامر المجدولة", "التوليد الآلي", section 7 (CP0–CP6), section 8 (DoD: "كل مهمة تطلق أحداثها، وأحداثها مسجّلة في كتالوج Task 21"), and section 9 rows D-04, D-12, D-13, D-21, D-22.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists — a recorded decision overrides the plan's default (D-04 start-day notice, D-12 MEAL officer per city, D-21 digest = email + in-app with links, D-22 working week).
4. Rule files (read all): `.ai/rules/alerts.md` (one delivery path, working days, admin thresholds, ISO weeks, ledger keys include the date), `.ai/rules/recipients.md`, `.ai/rules/notifications.md`, `.ai/rules/views-components-notifications.md`, `.ai/rules/components-notifications.md`, `.ai/rules/pages.md` ("Hide money, keep names" applies to email too), `.ai/rules/admin.md`, `.ai/rules/ui.md`, `.ai/rules/views-pages.md`, `.ai/rules/views.md`, `.ai/rules/views-enums.md`, `.ai/rules/models.md`, `.ai/rules/concerns-factories.md` (HR read-only), `.ai/rules/providers.md`, `.ai/rules/tests.md`; for the command, `.ai/rules/commands.md`, `.ai/rules/commands-seeders.md`, `.ai/rules/console-commands.md`, `.ai/rules/middleware-actions-fortify.md`, `.ai/rules/enums.md`. Then run `grep -rin 'digest\|ledger\|working day\|mail\|money\|mute\|watcher\|top_roles' .ai/rules`.
5. Existing code to study first (verified paths):
   - Activity-driven path: `app/Notifications/{TracksActivity,ActivityRecorder,RecipientResolver,RecipientFilter,ResolvedRecipients,EmployeeRecipients,NotificationGrouper,NotificationPayload,NotificationPresenter,AppNotification,AppDatabaseChannel,SubjectLinks}.php`; module maps `app/Notifications/Recipients/{ModuleRecipients,ProjectRecipients,ProjectOwnedRecipients,ProposalRecipients,ReportRecipients,FundingAgreementRecipients,TransferRecipients,TaskRecipients,MilestoneRecipients,WatcherRecipients,ManagedRecipients}.php`; `config/notifications.php` (`modules`, `view_permissions`, `top_roles`, `scanners`, `defaults`). `RecipientResolver::resolve()` merges direct + program managers + watcher roles + `top_roles` for key events, then `RecipientFilter::apply()` (view permission, actor excluded, mutes for non-direct, exempt ids).
   - Scanner path: `app/Notifications/Alerts/{AlertDispatcher,AlertLedger,DeadlineCatalog,DeadlineItem,DailyBriefScanner,WeeklyDigestScanner,DueSoonScanner,OverdueScanner,EscalationScanner,StalledScanner,OffTrackIndicatorScanner,DataGapScanner,WorkingCalendar,NotificationSettings,Scanner}.php`. `AlertDispatcher::send(rule, stage, payload, directUserIds, otherUserIds, exemptUserIds)` = `RecipientFilter` → `AlertLedger::claim` → notify. `WeeklyDigestScanner` is the model for a digest (program managers + `RecipientResolver::usersWithRoles()`); `DailyBriefScanner` sends counts per user.
   - Mutes: `app/Models/NotificationMute.php` ("Direct notifications ignore it"), `tests/Feature/Notifications/NotificationMutesTest.php`.
   - Settings UI: `resources/views/pages/admin/⚡notification-settings.blade.php` (**Notification Rules**, route `admin.notification-settings.edit`, gate `notification-settings.manage`) with partials `_rules-row`, `_rules-week`, `_rules-warnings`; per-user page `resources/views/pages/notifications/⚡settings.blade.php`.
   - Scheduling: `routes/console.php` (`notifications:scan daily` at 06:00, `notifications:digests` every minute), `app/Jobs/RunScanner.php` (`$tries = 3`, backoff).
   - Mail: no `app/Mail` folder; the pattern is a Notification with `toMail()` + markdown view (`app/Notifications/EmailLoginCode.php`, `resources/views/mail/login-code.blade.php`); `resources/views/vendor/mail/html/layout.blade.php` sets `lang`/`dir`; `User` implements `HasLocalePreference` and reads `email` from HR (empty string when none).
   - Money: `app/Models/TransfersTracking.php`, `app/Support/AgreementMoney.php`, `app/Models/DonorReport.php` (never put amounts in payloads).
   - Tests: `tests/Feature/Notifications/{RecipientResolverTest,FanOutActivityTest,AlertDispatcherTest,DailyBriefTest,WeeklyDigestTest,ScannerSchedulingTest,DueSoonScannerTest,DeadlineCatalogTest,NotificationGroupingTest,NotificationMutesTest,NotificationSettingsAdminTest,NotificationPerformanceTest,FinanceActivityTest,UnnotifiedWarningTest,WorkingCalendarTest}.php`, `tests/Feature/LocalizationTest.php`.
6. Activate skills: `laravel-best-practices`, `laravel-security`, `livewire-development` and `fluxui-development` (settings section), `testing-best-practices`.
7. Use Boost `search-docs` for events and listeners (`ShouldDispatchAfterCommit`, `ShouldQueue`), queued notifications (`afterCommit`, `$tries`, `backoff`), `HasLocalePreference`, markdown mail, scheduling (`dailyAt`, `withoutOverlapping`, `onOneServer`), `Notification::fake()` / `Event::fake()`. Use `database-schema` on `activities`, `notification_alert_ledger`, `notification_settings`, `notification_mutes`, `projects`, `proposals`, and on `meal_coverages`, `monthly_reports`, `donor_reports`, `tasks` where they exist.

## Preconditions to verify
- Task 02 merged (permission names). The **Event Notifications** section uses the existing `notification-settings.manage`, as the plan states.
- Part B: role `MEAL` exists; `meal_coverages` (Task 15) may not exist yet — then oversight = role holders only, and the city officer is added when Task 15 merges (follow-up). `EmployeeProfile::employeeIdsInDepartment()` (Task 30) for department recipients.
- Part C: `ProposalStatus::OnHold` and `proposals.status_remind_on` (Task 04); `ProjectStatus` v2 with `active` and dates set at approval (Tasks 05, 08). Check whether Task 17 already added "monthly report due/overdue" to `DeadlineCatalog`; if so, only register its keys in the catalog.
- Part D: `monthly_reports` + `MonthlyReportStatus` (Task 17, CP3), `donor_reports.responsible_employee_id` and `reporting-duties.index` (Task 19), department tasks with `kind`/`department_id` and `department-tasks.index` (Task 31). Build the frame with the sections whose data exists; never add runtime `Schema::hasTable()` guards.
- Part E: event classes from CP2 exist (`ProposalStatusChanged`, `ProjectActivated`, `ProjectPeriodChanged`, `FundingAgreementRecorded`).
- Mail works locally (`MAIL_MAILER=log`); queue connection `database` (`jobs`, `failed_jobs`).
If a required item fails, stop and report instead of improvising.

## Revision 4 changes (2026-10-12)
Source: plan header paragraph «المراجعة 4», the «تعديلات المراجعة 4» subsection of this task, `### Task 08` and `### Task 33`. Where this block conflicts with the base sections, it wins.
- **New catalog events (keys frozen with the others):** `project.funded` (project became `approved`, awaiting its template; to MEAL and the project's MEAL officer), `report_template.drafted`, `report_template.items_pending` (a new proposal on an active project; to MEAL), `report_template.confirmed` (to the project manager, the program manager and management, listing the new items and their months), `report_template.item_changed`, `report_template.item_retired`.
- `project.activated` now comes from Task 33 (template confirmation, `via: template_confirmation`), not from Task 08's approval.
- **Escalation:** an `approved` project without a confirmed template after `report_template_due_working_days` working days (new `NotificationSettings` threshold, default 5) → reminder to the project's MEAL officer, then escalation to the MEAL Manager and management (reuse the existing due/overdue/escalation scanners through a `DeadlineCatalog` source).
- The project manager's "new indicators" notification carries no amounts.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-02, D-09, D-22, D-26, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- Per-project working days `projects.working_days` (D-22) via `WorkingCalendar::forProject()`.
- New catalog events: `proposal.final_report_accepted` (D-02b), `proposal.approved_without_agreement` (D-26; PR, Finance, CEO), `monthly_report.reopen_escalated` (trigger as amended by Revision 9 below).
- Also from the other tasks' Revisions 6–9: `department_task.*` (Task 31), `meal_coverage.*` (Task 15), `library_indicator.*` (Task 11), `proposal.reviewer_added` / `proposal.reviewer_seen` (Task 07), `proposal.targets_saved` (Task 13). If a task added keys in its own PR, reconcile them here; never keep two names for one movement.

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **D-09a:** reopen requests go to the MEAL Manager slot holder, or to Public Relations Manager holders when the slot is empty. `monthly_report.reopen_escalated` fires only when the slot is empty and no active PR Manager exists; recipient General Manager.
- **D-12a:** `OversightRecipients` adds the project's MEAL officer (`MealCoverage::responsibleFor($project)`: project officer → governorate officer → MEAL Manager) instead of the city officer, so its signature takes the project: `userIdsFor(string $eventKey, ?Project $project): list<int>`. The base text's "city officer" and "fallback = the whole MEAL role" are replaced. Register `meal_coverage.project_assigned` (digest).
- **D-44a:** new event `annual_plan.missing` (from 2027) to the program manager and MEAL, high importance.
- **D-25:** new event `proposal.beneficiaries_changed` to MEAL when PR changes beneficiaries after approval.

## Scope
### In scope
- `App\Notifications\EventCatalog` (`final`, pure PHP, no DB): every key from the plan's catalog table, e.g. `project.created`, `project.owner_acknowledged`, `project.owner_declined`, `proposal.created`, `proposal.status_changed`, `proposal.reviewer_added`, `proposal.reviewer_seen`, `proposal.on_hold_reminder`, `proposal.approved`, `project.activated`, `project.period_changed`, `project.started`, `project.ended`, `project.closed`, `project.archived`, `agreement.recorded`, `transfer.generated`, `transfer.received`, `transfer.late`, `monthly_reports.generated`, `monthly_report.due`, `.overdue`, `.submitted`, `.validated`, `.returned`, `.reopen_requested`, `.reopen_decided`, `donor_report.saved`, `.due`, `.overdue`, `.submitted`, `department_task.created`, `.due`, `.overdue`, `.delivered`, `.returned`, `program.logframe_missing`. Each entry: module, importance, direct-recipient resolver key, oversight mode (`all`, `management_only` for money events, `on_overdue_only` where the table says so), critical flag (approval, rejection, return — bypasses mutes for direct recipients), and the matching activity event or domain event class.
- `App\Enums\EventImportance` (`immediate`, `digest`) and `App\Enums\OversightMode` with `HasTranslatedLabel`.
- `App\Notifications\Recipients\OversightRecipients`: `userIdsFor(string $eventKey, ?int $cityId): list<int>`; MEAL = role holders + city officer (Task 15, fallback = whole role, D-12); management = `General Manager` + configured roles; money events → management only.
- Extend `RecipientResolver` (activity path) and `AlertDispatcher` callers (scanner path) so catalog events add oversight recipients; oversight users are non-direct (mutes apply) unless the event is critical.
- Digest importance: oversight recipients of `digest` events get them in one daily oversight summary instead of immediately (recommended: `App\Notifications\Alerts\OversightDigestScanner` in the `daily` group, counting yesterday's catalog activities per event key from `activities`; propose in plan mode). Direct recipients still get them immediately.
- Part C scanners: `App\Notifications\Alerts\ProjectDatesScanner` and `App\Notifications\Alerts\OnHoldReminderScanner` in the `daily` group of `config/notifications.php`.
- **Event Notifications** section on `resources/views/pages/admin/⚡notification-settings.blade.php`, stored through `NotificationSettings` (one key, e.g. `event_overrides`, default in `config/notifications.php` `defaults`).
- Part D: command `reports:monthly-digest {--dry-run} {--month=}` (`App\Console\Commands\SendMonthlyDigest`) scheduled in `routes/console.php` `->dailyAt('08:00')->withoutOverlapping()->onOneServer()`, exits unless today is the first working day of the month (or `--month` is given), then queues `RunScanner` for `App\Notifications\Alerts\MonthlyDigestScanner`; mail via a queued Notification `App\Notifications\MonthlyDigestMail` + `resources/views/mail/monthly-digest.blade.php` (the plan says "Mailable": a Mailable needs a new `app/Mail` folder — ask in plan mode; default to the existing Notification pattern).
- `tests/Feature/Notifications/EventCatalogCoverageTest.php`.
- Lang keys in `lang/{en,ar}/notifications.php` (`events.*` labels and messages, `alerts.project_started`, `alerts.project_ended`, `alerts.on_hold_reminder`, `digests.monthly`, `digests.oversight`, `mail.monthly_digest.*`) and `enums.php`.
### Out of scope
Writing each module's own events or activity entries (the owning tasks do it; you register keys and fix gaps found by the coverage test as follow-ups to their owners). Monthly reports (Task 17), donor reports (Task 19), department tasks (Task 31), MEAL coverage (Task 15), supervision map (Task 23). Changing the existing weekly digest, daily brief content, desktop banners or broadcasting. Attachments of any kind.

## Business rules
- **MEAL + General Management receive every catalog event** except where the catalog says otherwise (money events `transfer.*`: management only; department-task events: oversight on overdue only).
- **One notification per person**: someone who is both direct and oversight receives one (the stronger priority).
- **The actor is never notified of their own action** (existing `RecipientFilter` behaviour).
- `digest` events go to oversight recipients in the daily oversight summary, not immediately (R11); direct recipients get them immediately.
- Grouped events: `monthly_reports.generated` for 12 months at activation = **one** grouped notification, never 12 (use `NotificationGrouper`).
- Mutes (`NotificationMute`) are respected for oversight recipients; **direct** recipients of critical status events (approval, rejection, return) are never muted.
- Ledger keys include the event, the subject and the date or month, so re-runs and retries never duplicate (alerts.md).
- Working days only (`WorkingCalendar`): a date that falls on a non-working day is announced on the next working day; scanners catch up on every date since the previous working day.
- Only `active` projects get start/end notices; activation after the start date sends no start notice (Task 33's `project.activated` covers it). On-hold reminder fires once per `status_remind_on` value and only while still `on hold`. Monthly-report drafts generated for future months never alert (Task 17).
- No amounts for anyone without money permissions, in payloads, in-app text or email; the monthly digest carries counts, names and links only (no amounts for anyone).
- Settings overrides change recipients and importance without code; a disabled event sends nothing but stays in the catalog (coverage still passes).
- Monthly digest: one per user per month; a program manager sees their programs, management all; sections only when the recipient holds the module's view permission. Month rule to confirm in plan mode: on the first working day of M, month M-1 reports are not late yet (`monthly_report_due_working_days`, default 5) — show M-1 as submitted / validated / "not submitted yet (due {date})" and count earlier unsent months as missing. Default: send even when all counts are zero ("nothing pending"); confirm.
- Overlap to raise: `DeadlineCatalog::projects()` already sends a day-0 "due today" on `end_date`; `project.ended` duplicates it for the owner. Default: keep both and list it.
- Recipients are resolved from roles, module maps, HR links and settings — never from person names.

## Security and authorization
- `RecipientFilter` drops users without the module's view permission; the only exemptions are documented direct responsibles (e.g. a donor-report responsible without `funding-agreements.view`, routed to **My Reporting Duties**), passed as `exemptUserIds`.
- Oversight never bypasses the view permission: a `General Manager` or `MEAL` user without `transfers.view` gets `transfer.*` only if management-only mode allows it and the payload has no amounts.
- **Event Notifications** settings: `notification-settings.manage`, authorized inside the Livewire action, re-validated on the server.
- Emails carry absolute `route()` links to pages that keep their own auth checks, never attachments; nothing is sent from inside a web request or a transaction — notifications are queued `afterCommit`.
- Do not widen any module's watcher roles to "fix" oversight; oversight is a separate, testable layer.

## Validation
- Event settings: `events.*.key` `Rule::in(EventCatalog::keys())`; `importance` Enum(`EventImportance`); `enabled` boolean; `oversight_roles.*` must exist in the roles table (`Rule::exists('roles', 'name')`); messages in `lang/{en,ar}/notifications.php`.
- Command options: `--month` `Y-m`, not in the future, else a translated error and `self::FAILURE`; `--dry-run` flag.

## Edge cases and concurrency
- Command run twice, overlapping runs, or a retried job: `withoutOverlapping()`, `onOneServer()` and the atomic ledger claim prevent duplicates.
- Mail failure: in-app already sent; the mail job retries 3 times with backoff, then lands in `failed_jobs`; rerun with `--month` never duplicates the in-app part (separate ledger rules per channel).
- Recipient without an email in HR (`$user->email === ''`) or HR unreachable: in-app only, no exception.
- MEAL officer for the city not assigned (Task 15) or inactive in HR: fall back to the whole `MEAL` role.
- Same person as program manager and General Manager (or MEAL/PR Manager): one digest, wider scope.
- Event fired inside a transaction that rolls back: nothing sent (after-commit dispatch, tested).
- Project dates changed after a notice: new date → new stage key → announced again on its day.
- Proposal leaves `on hold` before its date: nothing sent.
- A disabled event in settings or a deleted role in overrides: ignored safely; the settings page warns.

## Data integrity
- No new tables expected: reuse `notification_alert_ledger`, `notification_settings`, `activities`. If you need a table, stop and ask.
- Ledger claim before each send; in-app and mail use separate rules so each channel is idempotent on its own.
- Catalog keys are stable strings: never rename after CP0 without both teams' approval and a plan update.
- Settings defaults live in `config/notifications.php` `defaults`; the admin page writes only overrides.

## Implementation guidance
- `php artisan make:class`, `make:enum`, `make:command SendMonthlyDigest`, `make:notification MonthlyDigestMail --markdown=mail.monthly-digest`, all `--no-interaction`; `declare(strict_types=1);`, `final` classes like the other alert classes, typed signatures, PHPDoc array shapes.
- Skeleton:
```php
final class EventCatalog
{
    /** @return array<string, array{module: string, importance: EventImportance, direct: string, oversight: OversightMode, critical: bool, activity: string|null, event: class-string|null}> */
    public static function all(): array {}

    /** @return list<string> */
    public static function keys(): array {}

    /** Merged with the admin overrides from NotificationSettings. */
    public function effective(string $key): ?array {}
}

final class OversightRecipients
{
    /** @return list<int> user ids */
    public function userIdsFor(string $eventKey, ?int $cityId): array {}
}

final class MonthlyDigestScanner implements Scanner
{
    public function scan(CarbonImmutable $now): int {}

    /** @return array<int, array{programs: list<int>, sections: array<string, array<string, int>>, links: array<string, string>}> user id => digest */
    public function preview(CarbonImmutable $month): array {}
}
```
- Build digest sections with one grouped query per section for all programs, then slice per recipient in PHP; resolve HR names in one batched lookup.
- In-app digest payload like `WeeklyDigestScanner` (`NotificationKind::Digest`, module `system`, rule `monthly_digest`, stage `YYYY-MM`, recipient exempt from the module permission). Mail: `MailMessage` with a translated subject, numbers in `dir="ltr"` spans, buttons only for routes that exist and that the recipient may open (**Monthly Reports**, **Monthly Matrix**, **Department Tasks**, **My Reporting Duties**).
- Settings section: reuse `_rules-row` patterns, Flux components, `wire:model` booleans for modals, all text through `__()`.
- After merging, record "every movement must be in EventCatalog; oversight = MEAL + General Management" with Boost `record-rule` (glob `app/Notifications/**`).

## Tests (Pest)
Follow `.ai/rules/tests.md` and `.ai/rules/alerts.md`: fixtures inside `Activity::withoutRecording`, `userWithRole()`, `$this->travelTo()`, `Notification::fake()` / `Event::fake()`, assert on the records you created.
- `tests/Feature/Notifications/EventCatalogTest.php` (Part A/B): for each catalog event (dataset), the direct recipients **and** MEAL + General Management receive it and nobody else; a dual-role user gets one; the actor gets none; money events reach management only and contain no amount for non-finance; `digest` events reach oversight only via the daily summary; a muted oversight user gets nothing while a muted direct recipient of a critical event still does; city MEAL officer vs role fallback; settings override changes recipients and importance; a disabled event sends nothing.
- `tests/Feature/Notifications/ProjectDatesScannerTest.php` and `OnHoldReminderTest.php` (Part C): start/end day for active projects only; non-working-day catch-up; second run sends nothing; changed date re-arms; on-hold reminder once per date, not after leaving `on hold`; oversight included.
- `tests/Feature/Notifications/EventNotificationsSettingsTest.php`: only `notification-settings.manage` may save (403 otherwise, fresh Livewire instance); unknown key / unknown role / invalid importance rejected.
- `tests/Feature/Notifications/MonthlyDigestTest.php` (Part D): only on the first working day (frozen dates around a weekend start of month); program manager vs management scope; one per user per month (rerun and `--month` send nothing); sections hidden without view permission; no amount or currency in params or mail; `MonthlyDigestMail` locale `ar` with Arabic body for an Arabic user; no HR email → in-app only; `--dry-run` sends and claims nothing; invalid `--month` fails.
- `tests/Feature/Notifications/EventCatalogCoverageTest.php` (Part E): every catalog key has lang keys, a direct-recipient resolver and an oversight mode; every class under `app/Events` used for domain movements maps to a catalog key; every activity event listed in a model's `activityEvents()` for projects, proposals, monthly reports, donor reports, agreements, transfers and department tasks is either in the catalog or explicitly ignored with a reason; grouped activation sends one notification.
- Extend `ScannerSchedulingTest` (command at `0 8 * * *`; new scanners in `daily`), and run `RecipientResolverTest`, `FanOutActivityTest`, `NotificationMutesTest`, `NotificationPerformanceTest`, `WeeklyDigestTest`, `DailyBriefTest`, `DeadlineCatalogTest`, `LocalizationTest`.
- Commands: `php artisan test --compact tests/Feature/Notifications/EventCatalogTest.php`, then each file / `--filter=...`.

## Acceptance criteria
- [ ] MEAL and General Management see every movement on projects, proposals and reports — immediately or in the daily oversight summary per importance.
- [ ] Program managers, management, the MEAL Manager and the PR Manager get exactly one monthly digest (email + in-app) on the first working day, in their language, with links and no amounts.
- [ ] Active projects announce their start and end days; on-hold proposals remind once on their date.
- [ ] Management can change any event's recipients or importance from **Notification Rules** without a developer.
- [ ] `EventCatalogCoverageTest` fails when a movement is added without a catalog entry; re-running any scanner or command sends nothing new.
- [ ] All listed tests pass; Pint clean.

## Do not
- Send email synchronously inside a request, or notify inside a transaction.
- Include amounts, budgets, currencies or attachments in any notification or email to someone without money permissions (and in the digest for anyone).
- Bypass `AlertDispatcher` / `AlertLedger` / `RecipientFilter`, count calendar days, use bare `startOfWeek()`, or hard-code thresholds.
- Send one notification per generated month instead of one grouped notification.
- Hard-code recipients by person name, or widen module watcher roles to fake oversight.
- Create `app/Mail`, add a dependency, add a table, or add runtime `Schema::hasTable()` guards without approval.
- Rename catalog keys after CP0.

## Quality bar (this task)
- **Event Notifications** settings: events grouped by module with search, toggles for enabled and immediate/digest, and an oversight-roles multi-select; saving confirms with a toast.
- Digest email: per-recipient language, scannable sections with counts and links, no amounts, and a plain-text alternative.
- Everything in `quality-bar.md` applies. Before reporting, open every new or changed screen in the browser (Arabic RTL and English, light and dark, desktop and 375 px), run `vendor/bin/phpstan analyse` on the touched paths, and list both in your report.

## Working method
- Start in plan mode: confirm which part (A–E) you are implementing, restate it in 5 bullets, list files you will touch, list open questions (oversight digest mechanism, Mailable vs mail Notification, digest month rule, always-send, end-day overlap, Task 15/17/19/31 availability); wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production (B4 has no production access).
- Stay inside Task 21's scope and the requested part; note anything else as a follow-up.

## Report back
Your final message must contain: the part implemented; a short summary; files created/changed; migrations (expected none); tests run with pass/fail output; decisions assumed (D-04, D-12, D-21, D-22 and the open questions); follow-ups (events still missing from other tasks, with the owning task; sections waiting on Tasks 15/17/19/31). Handoff facts: for every task owner — the catalog key to use and how to register a new event (CP0/CP2); for Task 26 — the event labels, settings section and email texts for the user guide; for Task 28 — how to trigger each part for UAT (`php artisan reports:monthly-digest --month=YYYY-MM --dry-run`, `notifications:scan daily` with frozen dates); for Task 29 — the new scheduled command and the queue worker requirement for mail.
````
