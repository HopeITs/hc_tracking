# Task 07 — Concept note and proposal review loop

> **المهمة:** حلقة مراجعة المذكرة المفاهيمية والمقترح (**Reviewers**، **Mark as Seen**، **Default Proposal Reviewers**)
> **المطوّر:** Dev 2 · **الفريق:** A · **الأيام:** 7–9 (الثلاثاء 2026-10-20 – الخميس 2026-10-22) · **الجهد:** 3 أيام · **يعتمد على:** Task 02، Task 04، Task 06، Task 30 (المعنيون من HR)
> **متى تستخدمه:** بعد توقيع CP2 (نهاية اليوم 6، الاثنين 2026-10-19: Task 04 مدموج، وTask 06 منتهٍ، وTask 02 وTask 30 مدموجتان)؛ ويُسلَّم قبل CP3 (نهاية اليوم 9، الخميس 2026-10-22).
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 07 of the HC Tracking proposal-lifecycle correction plan (Laravel 13, PHP 8.5, Livewire 4, Flux UI 2, Pest). The outcome: a server-enforced review loop per proposal stage. When a proposal enters `concept note review` or `proposal review`, the default reviewers (CEO, MEAL Manager, Finance Manager, Projects & Fundraising Manager — D-01), the owner of every linked project and each project's program manager become reviewers and are notified (with the meeting time if one was set); each reviewer can **Mark as Seen** with an optional note; holders of `proposals.manage-reviewers` add or remove extra reviewers; MEAL attaches a Need Assessment. In the corrected story (draft project without dates → concept note → internal review → submitted to donor → proposal → internal review → submitted → donor approval fixes dates and funds the projects → MEAL confirms the report template and activates them → one agreement) this is the internal-review step. The **MEAL Manager** slot you build is also the authority that Tasks 15, 17 and 33 check (coverage, reopen decisions, template confirmation), so its resolution must be one reusable method; Task 08 notifies these reviewers on approval and Task 26 adds the guided tour.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`, section `### Task 07` — Arabic and authoritative; English table, column, enum, permission and UI names are exact. Also read its «تعديلات المراجعة 2» block, sections 1–2 (especially 1.0), `### Task 04`, `### Task 06`, `### Task 30` and `### Task 21` (the contracts you consume), section 5 (security matrix), section 7 (CP2/CP3) and rows D-14, D-15, D-16 of section 9.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists; a recorded decision overrides the plan's default for D-14 / D-15 / D-16.
4. Rule files: `.ai/rules/index.md`, `database-migrations.md`, `migrations.md`, `feature.md`, `models.md`, `factories.md`, `seeders.md`, `database-seeders.md`, `enums.md`, `views-enums.md`, `recipients.md`, `views-components-notifications.md`, `alerts.md`, `notifications.md`, `pages.md`, `proposals.md`, `proposalsfunding-agreementstransfersdonors.md`, `admin.md`, `ui.md`, `views.md`, `views-pages.md`, `providers.md`, `routes.md`, `tests.md`. Then run `grep -rin 'reviewer\|notification_settings\|withoutRecording\|permissionExempt\|Hide money' .ai/rules`.
5. Study first: `app/Actions/Proposals/{TransitionProposal,SaveProposal,SaveProposalFile}.php`, `app/Support/ProposalTransitions.php`, `app/Policies/ProposalPolicy.php`, `app/Models/{Proposal,ProposalStatusNote,Project,Program,HrEmployee,Media}.php`, `app/Concerns/{RecordsActivity,RecordsParentActivity}.php`, `app/Notifications/{ActivityRecorder,EmployeeRecipients,SubjectLinks}.php`, `app/Http/Controllers/{OpenNotificationController,ProposalFileDownloadController,ProposalFilePreviewController}.php`, `app/Notifications/Recipients/{ModuleRecipients,WatcherRecipients,ProposalRecipients}.php`, `app/Notifications/Alerts/NotificationSettings.php`, `config/{notifications,access-control}.php`, `app/Enums/MediaCollection.php`, `routes/web.php`, `resources/views/pages/proposals/⚡show.blade.php`, `resources/views/pages/admin/⚡notification-settings.blade.php`, `resources/views/pages/calendar/⚡index.blade.php`, `resources/views/components/ui/form-select.blade.php` (its `employees` mode), `app/Providers/AppServiceProvider.php` (`configureMorphMap`), `tests/Feature/AccessControl/{ActionAuthorizationTest,PolicyCoverageTest,ModuleAccessTest}.php`, `tests/Feature/Notifications/{NotificationSettingsAdminTest,UnnotifiedWarningTest}.php`, `tests/Feature/DatabaseSeederTest.php`.
6. Activate skills: `laravel-best-practices`, `livewire-development`, `fluxui-development`, `database-migrations`, `laravel-security`, `testing-best-practices`.
7. Use Laravel Boost `search-docs` before relying on framework APIs (unique indexes, `afterCommit`, Livewire `#[Locked]`, uploads) and `database-schema` on `proposals`, `proposal_status_notes`, `media`, `notification_settings`, `projects`, `programs` before writing the migration.

## Preconditions to verify
- `proposals.manage-reviewers` exists in `config/access-control.php` and in the local `permissions` table (Task 02).
- `ProposalStatus::ConceptNoteReview`, `ConceptNoteSubmitted`, `ProposalReview`, `OnHold` exist; `App\Actions\Proposals\TransitionProposal` and `App\Support\ProposalTransitions` exist, lock the proposal row and run in one transaction (Task 04).
- `App\Actions\Proposals\SaveProposal` and `LinkProposalProject` exist; every proposal has `donor_id` and at least one project (Tasks 03, 06).
- `App\Support\EmployeeProfile` (current position from the active contract, department, centre) and the HR position/department models exist (Task 30).
- `proposal_reviewers` and `App\Models\ProposalReviewer` do not exist yet.
- If any check fails, stop and report instead of improvising.

## Revision 2 changes (2026-10-08)
- Timeline: Team A, days 7–9 (2026-10-20 → 2026-10-22), after CP2; the loop must be merged before CP3 (end of day 9) and is part of the CP4 end-to-end path (end of day 11).
- Default reviewer slots are resolved to people through HR (Task 30), by department and current position (active contract via `EmployeeProfile`), never by name: the **Default Proposal Reviewers** setting stores the HR position (or department + position) per slot, not a person; at invite time it resolves to whoever currently holds that position. A vacant position → a visible warning on the panel and the slot is skipped (no row, no error).
- The loop's events are entries in Task 21's catalog with these exact keys: `proposal.reviewer_added` and `proposal.reviewer_seen` (use `proposal.reviewer_seen`, not `proposal.review_seen`). Through `OversightRecipients` (Task 21) MEAL and General Management receive them as a digest; the reviewer gets `proposal.reviewer_added` immediately.
- Section 1.0 fact 8: every movement on the proposal reaches MEAL and General Management; do not suppress these events for oversight recipients.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-01, D-09, D-12, D-15, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- D-01: new **CEO** slot in **Default Proposal Reviewers** next to MEAL Manager, Finance Manager and Projects & Fundraising Manager. No board gate; the «seen» flag warns before sending, it does not block (D-15).
- The **MEAL Manager** slot now drives several decisions: report reopen (D-09; when the slot is vacant or its holder inactive, **Public Relations Manager** decides — D-09a as settled in Revision 9, the Revision 8 "project MEAL officer" fallback is dropped), MEAL coverage (D-12), evidence types and narrative questions (Task 33). Changing the slot's configured position is logged and notifies the previous and the new holder.
- **T-08 (decision log section 10):** add `Ceo = 'ceo'` to `ReviewerSlot` and a CEO picker to **Default Proposal Reviewers**.
- **Super Admin is for developers only (D-20a).** It keeps every permission technically (`Role::booted()`), but never appears in a business default list, is never a fallback decision-maker, and is never the only account a test uses.

## Scope
### In scope
- Migration `create_proposal_reviewers_table`: `id`, `proposal_id` (FK `cascadeOnDelete`), `stage` (VARCHAR(50), `ReviewStage`), `employee_id` (unsigned big int, NO FK — HR id), `slot` (VARCHAR(50), `ReviewerSlot`), `seen_at` (datetime null), `note` (text null), `added_by` (FK `users`, nullable, `nullOnDelete`), timestamps; UNIQUE `(proposal_id, stage, employee_id)`.
- Model `App\Models\ProposalReviewer` + `ProposalReviewerFactory` (states `seen()`, `additional()`); `Proposal::reviewers(): HasMany`; seed a few rows in the proposal seeder and add the model to `tests/Feature/DatabaseSeederTest.php`.
- Enums: `App\Enums\ReviewerSlot` (`Ceo`='ceo', `MealManager`='meal_manager', `FinanceManager`='finance_manager', `FundraisingManager`='fundraising_manager', `ProjectOwner`='project_owner', `ProgramManager`='program_manager', `Additional`='additional'; declaration order = priority — confirm the order in plan mode) and `App\Enums\ReviewStage` (`ConceptNote`='concept_note', `Proposal`='proposal') with a mapping to/from `ProposalStatus::ConceptNoteReview` / `ProposalReview`. `MediaCollection::NeedAssessment` (lower-case value, e.g. `'need assessment'`; confirm in plan mode).
- Actions in `app/Actions/Proposals/`: `InviteDefaultReviewers`, `AddProposalReviewer`, `RemoveProposalReviewer`, `MarkProposalReviewed` (also used to edit one's note).
- Hook `InviteDefaultReviewers` into `TransitionProposal` for targets `ConceptNoteReview` and `ProposalReview`, inside its transaction.
- D-15 warning in the **Change Status** modal for targets `ConceptNoteSubmitted` and `Submitted`: names of current-stage reviewers who have not seen it. Never blocks.
- `ProposalPolicy`: extend `view`; add `manageReviewers`, `markReviewed`, `uploadNeedAssessment`.
- Routes `proposals.show`, `proposals.files.download`, `proposals.files.preview`: replace `->can('proposals.view')` with `->can('view', 'proposal')` so the policy decides (Livewire re-runs route authorization on every update request). `proposals.index` keeps `proposals.view`.
- **Reviewers** panel on `proposals/⚡show`: name, slot, state (Not seen / Seen on date), note with its date, "inactive account" chip, actions.
- **Default Proposal Reviewers** section on `/admin/notification-settings`: four HR position pickers (revision 2 + D-01) stored as key `proposal_default_reviewers` (`{ceo, meal_manager, finance_manager, fundraising_manager}` → HR position id or null; confirm whether department + position is needed in plan mode) through `NotificationSettings::save`; null defaults in `config/notifications.php` `defaults`. Resolution to an employee happens at invite time through `EmployeeProfile` (active contract); a vacant position yields a warning and is skipped.
- Activity events on the Proposal: `proposal.review_requested`, `proposal.reviewer_added`, `proposal.reviewer_removed`, `proposal.reviewer_seen`, `proposal.need_assessment_added` (keys aligned with Task 21's catalog; register them there if Task 21 has not); sentences in `lang/{en,ar}/notifications.php` under `events.proposal.*`; recipients via `ProposalRecipients` (extend it — one recipient map per module).
- **My Calendar** (`calendar/⚡index`): a "Review meeting" item for each reviewer when the stage has a meeting time.
- One reusable slot resolver (e.g. `App\Support\DefaultReviewerSlots::holderEmployeeId(ReviewerSlot $slot): ?int` and `isVacant(ReviewerSlot $slot): bool`, "vacant" = no configured position, no active contract on it, or holder inactive in HR). Tasks 15, 17 and 33 call it for the MEAL Manager slot; never re-implement the lookup there.
- Lang keys in `lang/{en,ar}/proposals.php`, `enums.php`, `notifications.php`.

### Out of scope
- Transition table, Change Status modal mechanics, `proposal_status_notes` columns (Task 04). `EventCatalog`, `OversightRecipients` and the catalog coverage test (Task 21). HR models and `EmployeeProfile` (Task 30). Create/Edit form and project linking (Task 06). Approval notifications to reviewers (Task 08). Permission catalog (Task 02). Guided tour (Task 26). File encryption (Task 22). Note anything else as a follow-up.

## Business rules
- Review rows are per stage: `concept_note` rows never count for the `proposal` stage.
- `InviteDefaultReviewers` is idempotent. First entry into a stage: add the four default slots, each resolved from its HR position to the current holder (skip null settings; skip vacant positions with a warning), every linked project's owner (`projects.user_id`) and its program manager (`programs.manager_id`). Re-entry after a send-back: never remove or re-slot rows; add only owners/program managers of projects linked since; re-notify only reviewers with `seen_at IS NULL`. Settings changes are never applied retroactively (assumption — confirm in plan mode).
- Same person in two slots → one row with the highest-priority slot (enum order).
- **Mark as Seen** sets `seen_at` once and is never undone; a second click does not change it. The note is editable only while `proposals.status` equals the stage's review status; otherwise read-only.
- Remove: only slot `additional`, only while `seen_at IS NULL` and `note IS NULL`.
- Add: stage is derived server-side from the current status (`concept note`/`concept note review` → `concept_note`; `proposal draft`/`proposal review` → `proposal`); any other status refuses with a message (assumption — confirm).
- Moving to `concept note submitted` or `submitted` never requires all reviewers; it shows the unseen names (D-15).
- A reviewer with no login account appears with the existing "no account" warning (list them through `assignedEmployeeIds()` so `EmployeeRecipients` behaviour applies).

## Security and authorization
- Add/remove: `proposals.manage-reviewers` via `ProposalPolicy::manageReviewers`. Mark/edit note: only the row's own person — `reviewer.employee_id === auth()->user()->employee_id` (null employee_id never matches) AND the row loaded through `$proposal->reviewers()->whereKey($id)->firstOrFail()` (BOLA guard).
- `ProposalPolicy::view`: `proposals.view` OR a reviewer row for the user's `employee_id` on this proposal. Do NOT grant `proposals.view` to reviewers. A reviewer without money permissions sees no money: every money query on the page stays skipped server-side (`canViewAgreements()`, `canViewTransfers()`, `hiddenCollections()`, and `requested_budget` from Task 03/06 — verify it is not selected for them).
- `uploadNeedAssessment`: not specified by Task 02. Recommended default: `proposals.update` OR (a reviewer on this proposal AND `results.create`); confirm in plan mode. Upload goes through `SaveProposalFile` (or a sibling action) that authorizes this ability.
- Every Action calls `Gate::authorize(...)` first (`ActionAuthorizationTest` enforces it for `app/Actions/Proposals`); no write from the Blade page directly.
- Notifications carry no amounts. `ProposalRecipients::permissionExemptUserIds()` returns the reviewers' user ids for these events so reviewers without `proposals.view` still receive them; nobody else gains access. Verify `OpenNotificationController` / `SubjectLinks` let a reviewer open the link.
- Audit: every add, remove, mark and note edit is an activity on the Proposal (`RecordsParentActivity` on `ProposalReviewer`, or explicit `recordActivity()`).
- Admin section is gated by the existing `notification-settings.manage`.

## Validation
- `employeeId`: required, integer, `Rule::exists(HrEmployee::class, 'id')`, not already on `(proposal, stage)` — ar «هذا الشخص مضاف بالفعل» / en "This person is already a reviewer for this stage."
- `note`: nullable, string, max:2000.
- `meetingAt`: nullable, date, after:now (where Task 04 collects it).
- Need assessment file: the existing proposal-file rules (`SecureUpload`, 5 MB, pdf/docx/xlsx/jpg/png).
- Default reviewers: each of the four slots is a nullable integer existing as an HR position (Task 30 model), not an employee.
- Messages in `lang/ar/proposals.php` + `lang/en/proposals.php` (admin section in `notifications.php`); `LocalizationTest` must pass.

## Edge cases and concurrency
- Double click on **Mark as Seen**: `seen_at` unchanged, no second activity.
- Two managers add the same person at once: the unique index rejects the second; translate the `UniqueConstraintViolationException` (narrowed to this index name, like `proposals/⚡index`) into the "already added" message.
- Inactive or removed HR employee: row stays, shown as «حساب غير فعّال» / "Inactive account", never notified.
- Default slot's position is vacant (no active contract holds it): no row is created, the panel and the transition result show «المنصب شاغر» / "Position vacant" for that slot; the transition still succeeds.
- The position's holder changes mid-review: existing rows untouched (same as a settings change); the new holder appears only in later reviews.
- Default reviewer changed mid-review: existing rows untouched.
- Proposal leaves the stage while a reviewer edits: the action re-reads the status inside the transaction and refuses the edit.
- Reviewer's permissions change: authorization is re-evaluated per request; nothing is cached on the component.

## Data integrity
- `proposal_reviewers.proposal_id` cascades; `employee_id` has no FK (HR is read-only, `models.md`); `added_by` `nullOnDelete`; UNIQUE `(proposal_id, stage, employee_id)` is the real duplicate guard.
- `InviteDefaultReviewers` runs inside the transition transaction; the fan-out is queued `afterCommit` by `ActivityRecorder` — never notify synchronously.
- Migration: `utf8mb4_unicode_ci` / InnoDB DYNAMIC (`DatabaseEncodingTest`), working `down()`.
- Meeting time storage is not named by the plan. Reuse what Task 04 stored for the `concept note → concept note review` transition; if it stored nothing, propose (recommended: nullable `meeting_at` on the `proposal_status_notes` row that opened the stage) and wait for approval.

## Implementation guidance
- `declare(strict_types=1);`, `#[Fillable([...])]`, `casts()` with the enums, `CarbonImmutable`, `@property` docblocks, final Action classes with a `handle()` method like `SaveProposalFile`.
- `php artisan make:migration create_proposal_reviewers_table --no-interaction`, `make:model`, `make:factory`, `make:enum` / `make:class` with `--no-interaction`.
- New model needs a policy decision: either covered by `ProposalPolicy` abilities and listed in `tests/Feature/AccessControl/PolicyCoverageTest.php`, or a `ProposalReviewerPolicy`. Add a morph-map key only if the model itself is a notification subject (`MorphMapTest`).
- Reuse the cached employee picker (`x-ui.form-select ... employees`, `HrEmployee::pickerOptions()`); do not render all employees server-side.
- Flux modals bound with `wire:model` booleans (`showAddReviewerModal`), `#[Locked]` ids, `x-ui.*` components, logical Tailwind classes for RTL, `text-status-critical` for warnings.

## Tests (Pest)
- `tests/Feature/ProposalReviewLoopTest.php`: transition to `concept note review` adds defaults + owners + program managers, one `proposal.review_requested` notification per reviewer; re-entry adds no duplicates and re-notifies only unseen; same person in two slots → one row with the priority slot; inactive employee not notified; D-15 warning lists unseen names; a default slot resolves to the employee whose ACTIVE contract holds the configured position (not an ended contract); a vacant position is skipped with the warning; `proposal.reviewer_added` / `proposal.reviewer_seen` reach MEAL and General Management through the oversight recipients when Task 21 is merged.
- `tests/Feature/ProposalReviewersPanelTest.php` (Livewire, `pages::proposals.show`): reviewer marks seen; double mark keeps `seen_at`; another user marking someone else's row → 403; a row id from another proposal → 404/403; note editable only during the stage; remove additional without activity OK, with note refused, default slot refused; add duplicate refused with message; user without `proposals.manage-reviewers` → 403 (fresh Livewire instance per forbidden call, `tests.md`).
- Access: reviewer without `proposals.view` opens `proposals.show` and downloads a non-funding file, sees no amounts (`assertDontSee` budget), cannot open the funding tab; a non-reviewer without `proposals.view` still gets 403.
- `tests/Feature/Models/ProposalReviewerTest.php`: unique index, cascade on proposal delete, `added_by` null on user delete (id-returning insert helper, read back with `DB::table()`).
- `tests/Feature/Notifications/NotificationSettingsAdminTest.php`: section saves and reloads; non-admin 403. Need assessment upload notifies the loop.
- Run `php artisan test --compact tests/Feature/ProposalReviewLoopTest.php` (and each file) or `--filter=...`.

## Acceptance criteria
- [ ] The **Reviewers** panel shows each person's slot, state with date, and note.
- [ ] The warning lists unseen reviewers when sending to the donor; sending still works.
- [ ] **Default Proposal Reviewers** saves and works in Arabic and English.
- [ ] No duplicate rows after re-entering a stage; notifications only after commit.
- [ ] Every new action has a 403 test and every id-taking action a BOLA test.
- [ ] Default slots (CEO, MEAL Manager, Finance Manager, Projects & Fundraising Manager) resolve through HR position (no person stored in settings); vacant positions warn and skip; event keys match Task 21's catalog.
- [ ] The slot resolver is one public method that Tasks 15, 17 and 33 can call; changing a slot's position is logged and notifies both holders.
- [ ] `vendor/bin/pint --dirty --format agent` clean; touched test files green.

## Do not
- Store reviewers by name or email instead of `employee_id`.
- Grant `proposals.view` to a role or user because they are a reviewer.
- Notify inside the transaction or synchronously.
- Trust a reviewer id, slot or stage from the client.
- Use `<flux:modal :open=...>` or `@php(...)` shorthand; leave literal UI text.
- Run `RolesAndPermissionsSeeder` on a real database.

## Quality bar (this task)
- **Reviewers** panel: each row shows the employee chip, slot, a state badge (Not seen / Seen on date) and the note with its date; **Mark as Seen** is a single clear action for the current user; the note is editable inline while the stage is open; vacant slots show a warning row.
- The D-15 warning lists unseen names inside the **Change Status** modal without blocking.
- **Default Proposal Reviewers** settings: four position pickers with helper text explaining that a position, not a person, is stored.
- Everything in `quality-bar.md` applies. Before reporting, open every new or changed screen in the browser (Arabic RTL and English, light and dark, desktop and 375 px), run `vendor/bin/phpstan analyse` on the touched paths, and list both in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions (meeting time storage, `uploadNeedAssessment` rule, add-stage rule, re-entry rule); wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside Task 07's scope; note anything else as a follow-up.

## Report back
Summary; files changed; migrations added; tests run with pass/fail output; decisions assumed (D-14, D-15, D-16, the settings shape (position vs department + position) and the open questions above); follow-ups. Handoff facts: for Task 08 — how to read the current reviewers of a proposal and resolve the `meal_manager` slot of `proposal_default_reviewers` to a person, and the recipients exemption used; for Task 14 (B1) — the same `meal_manager` slot resolution (its alert adds the MEAL Manager slot); for Tasks 02, 15, 17 and 33 — the resolver's class, method names and the exact meaning of "vacant" (the reopen fallback to Public Relations Manager, D-09a, depends on it); for Task 21 — the event keys `proposal.reviewer_added` / `proposal.reviewer_seen` and their direct recipients; for Task 12 — that `ProposalPolicy::view` admits reviewers; for Task 26 — the panel's stable hooks (`data-*` attributes) for the tour.
````
