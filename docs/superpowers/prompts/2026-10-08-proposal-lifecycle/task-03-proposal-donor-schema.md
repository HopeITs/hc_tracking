# Task 03 — Proposal schema: one donor, requested budget, one agreement

> **المهمة:** مخطط بيانات المقترح: مانح واحد، ميزانية مطلوبة، اتفاقية واحدة
> **المطوّر:** Dev 1 · **الفريق:** A · **الأيام:** 2–3 (الثلاثاء 2026-10-13 – الأربعاء 2026-10-14؛ الترحيلات في النصف الثاني من اليوم 2) · **الجهد:** يومان · **يعتمد على:** Task 01
> **متى تستخدمه:** في النصف الثاني من اليوم 2 بعد ورشة Task 01، وبعد أن تؤكد الإدارة أن استعلام التكرار في `donor_proposal` على نسخة الإنتاج فارغ، وحسم D-24 وD-28 وD-30؛ والمخطط يُجمَّد في CP1 (نهاية اليوم 3، الأربعاء 2026-10-14).
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 03 of the HC Tracking proposal-lifecycle correction plan. The outcome: the database itself guarantees "one donor → one proposal → at most one agreement, and the agreement's donor is the proposal's donor", and `proposals` gains the columns the new stages need (`donor_id`, `requested_budget`, `requested_currency`, `status_remind_on`). In the corrected story a concept note is sent to one specific donor, so the donor exists from creation; the donor's approval later fixes dates and activates projects, and exactly one agreement follows. Tasks 04, 06, 08 and 09 build on this schema, and it is frozen at CP1 (end of day 3, 2026-10-14): no column renames after you finish.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`, section `### Task 03` — Arabic and authoritative; table and column names are exact. Also its «تعديلات المراجعة 2» block, section 1.0 (domain facts 1–2), section 7 (CP1) and section 9 (D-24, D-28, D-30).
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` (if it exists; a recorded decision overrides the plan's default for D-xx, and its pre-check results tell you whether production has offenders).
4. Rules: `.ai/rules/database-migrations.md` (pivot naming; `donor_proposal.proposal_id` RESTRICT protects the money trail), `.ai/rules/migrations.md` (utf8mb4_unicode_ci / InnoDB DYNAMIC; `DatabaseEncodingTest`), `.ai/rules/feature.md`, `.ai/rules/pages-funding-agreements.md`, `.ai/rules/models.md` (strict_types, `#[Fillable]`, `casts()`, CarbonImmutable, TracksActivity), `.ai/rules/providers.md` (strict mode; mirror defaults, never make factories set defaulted columns), `.ai/rules/proposalsfunding-agreementstransfersdonors.md`, `.ai/rules/enums.md` (`Currency` values are UPPER case; pivot currency is a raw string), `.ai/rules/factories.md` and `.ai/rules/seeders.md` (per-attribute closures; omit default-bearing columns), `.ai/rules/database-seeders.md`, `.ai/rules/tests.md` (insertX helpers return ids, read back with `DB::table()`), `.ai/rules/pages.md` ("Hide money, keep names").
5. Source: `database/migrations/2026_09_04_154815_create_proposals_table.php`, `2026_09_04_155826_create_donor_proposal_table.php`, `2026_10_01_105937_add_internal_project_number_to_proposals_table.php` (backfill pattern), `app/Models/Proposal.php`, `app/Models/DonorProposal.php`, `app/Models/Donor.php`, `app/Models/Account.php` (`activityExcludedFields()` precedent), `app/Concerns/RecordsActivity.php`, `database/factories/ProposalFactory.php`, `database/factories/DonorProposalFactory.php`, `tests/Pest.php` (`insertProposal`, `insertDonor`, `insertDonorProposal`), seeders `CoreDataSeeder`, `RealProposalsSeeder`, `DemoDeliverySeeder`, `FinanceSeeder`, `NotificationDemoSeeder`, and `app/Actions/FundingAgreements/SaveFundingAgreement.php` + `app/Livewire/Concerns/ManagesAgreementForm.php`.
6. Skills: `database-migrations`, `laravel-best-practices`, `laravel-security`, `testing-best-practices`. Use Boost `database-schema` on `proposals`, `donor_proposal`, `donors` before writing migrations, and `search-docs` for composite foreign keys, `->change()` and `UniqueConstraintViolationException`.

## Preconditions to verify
- `proposals.donor_id`, `requested_budget`, `requested_currency`, `status_remind_on` do not exist yet (Boost `database-schema`). If any exists, stop and report.
- On the local DB: `SELECT proposal_id, COUNT(*) FROM donor_proposal GROUP BY proposal_id HAVING COUNT(*) > 1;` is empty, and every proposal has exactly one agreement. If not, stop and report the ids.
- The decision log (or the developer) confirms the production duplicate check came back empty. If unknown, say so in your plan; the migration still refuses to run on offenders.

## Revision 2 changes (2026-10-08)
- Timeline: Team A, days 2–3 (2026-10-13 → 2026-10-14); the schema is frozen at CP1 (end of day 3). Team B (Tasks 12, 13, 31) reads `proposals` from CP1 on and never changes it; any change goes through Dev 1 and a plan update.
- Many-to-many funding (section 1.0, facts 1–2): a proposal has exactly one donor and one or more projects (centres); a project has no limit on its proposals, so it can be funded by several donors through several proposals, and one donor can fund several projects. Do not add any "one proposal per project" constraint.
- Add `Project::fundingSources()`: the project's donor-approved proposals (`status->isApprovedByDonor()`) with their donor, in ONE query (eager-loaded, no N+1), for a «مصادر التمويل» / "Funding sources" section on the **Project** page. Donor names are plain text for every viewer; amounts (requested budget, agreement budget) are queried only for holders of `funding-agreements.view`, `transfers.view` or `accounts.view`, skipped server-side for everyone else.
- `projects.center_id` is added by Task 30 (Dev 2) in parallel; this task's migrations must not touch `projects`.
- Funded = donor approval (D-28): nothing in this schema marks a proposal as funded at `submitted`.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-27, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- Management note on D-27: every project always has a proposal, donor-funded or self-funded. Working default (open question D-27a): one internal donor «تمويل ذاتي — Hope» flagged by a new column `donors.is_internal` (boolean, default false). A self-funded proposal follows the same state machine; its agreement is optional and no transfer rows are generated. Internal donors are excluded from donor KPIs in **Funding & Finance** and from **Donor Report Builder**.

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **D-25:** add `project_proposal.target_beneficiaries` (unsigned int, nullable) — the beneficiaries this project (center) serves under this proposal. It is the fixed denominator of percentage indicators in the monthly report (Tasks 17, 33). Nullable for existing rows; required (≥ 1) from `proposal review` onwards (Task 04 gate). Do not touch `projects.target_beneficiaries`. Factory fills it. Test: column exists, nullable, readable via `withPivot('target_beneficiaries')`. Effort +0.25 day.

## Scope
### In scope
- Create migration `add_donor_and_budget_to_proposals_table` (`php artisan make:migration add_donor_and_budget_to_proposals_table --no-interaction`).
- Create migration `make_donor_proposal_one_per_proposal`.
- Update `app/Models/Proposal.php`: `#[Fillable]`, `casts()`, `donor(): BelongsTo`, `@property` docblocks, `activityExcludedFields()`.
- Update `ProposalFactory` (creates a donor), `DonorProposalFactory` (takes the proposal's donor), `tests/Pest.php` helpers and every test fixture that pairs a proposal with an unrelated donor.
- Update seeders that create proposals or agreements so each proposal gets the donor of its agreement.
- Minimal interim server guard: `SaveFundingAgreement` writes `donor_id` from the chosen proposal, never from the request (the form redesign is Task 09). Confirm this in plan mode.
- Interim donor input on the existing Create Proposal form (see Edge cases) — confirm in plan mode.
- `Project::fundingSources()` and the «مصادر التمويل» / "Funding sources" section on `projects/⚡show` (donor names as plain text, amounts only for money-permission holders) — confirm the section layout in plan mode.
### Out of scope
- Status machine, `requested_budget` gate before `proposal review` (Task 04).
- Create/Edit Proposal v2 form, budget field visibility (Task 06).
- Record Agreement form redesign, approved-only gate, friendly duplicate message (Task 09).
- Running anything on production (Task 27 rehearses, Task 29 deploys).
- `projects.center_id`, `HrCenter`, `EmployeeProfile` (Task 30).

## Business rules
- Every proposal has exactly one required donor from creation (`proposals.donor_id` NOT NULL).
- A proposal has at most one agreement (`donor_proposal.proposal_id` unique), and that agreement's donor equals the proposal's donor.
- A co-funder is recorded as a separate proposal (D-24).
- `requested_budget` (`decimal(14,2)`) and `requested_currency` (`Currency`) are optional now; Task 04 makes them mandatory from `proposal review` on.
- `status_remind_on` (`date`, nullable) holds the `on hold` reminder date; Task 04 sets and clears it.
- A project may have any number of proposals (different donors, renewals); funding sources shown on a project are its donor-approved proposals and their donors (D-28).

## Security and authorization
- `requested_budget` is money: it is queried and rendered only for holders of `funding-agreements.view`, `transfers.view` or `accounts.view`; skip the query server-side, `@can` alone is not enough. This task adds no UI, but do not add the column to any existing select, CSV export (`proposals/⚡index` `exportCsv()`) or notification payload.
- `proposals/⚡show` embeds `livewire:activity.timeline`, which renders raw old/new values to every proposal viewer (MEAL, Public Relations and added reviewers hold no money permission). Add `requested_budget` to `Proposal::activityExcludedFields()` so the amount never reaches `activities.changes`.
- Edits keep flowing through `ProposalPolicy`; add no new entry points.
- `SaveFundingAgreement` keeps `Gate::authorize('funding-agreements.create'|'funding-agreements.update')`; the donor written is the proposal's, so a tampered `agreementDonorId` cannot create a mismatched agreement.

## Validation
- Contract for Task 06 (do not build the form here): `donor_id` exists in `donors`; `requested_budget` numeric, ≥ 0, ≤ 999999999999.99 (DECIMAL(14,2)); `requested_currency` in `Currency`; `status_remind_on` after today.
- If you add the interim donor select, validate `required|integer|exists:donors,id` with messages in `lang/en/proposals.php` and `lang/ar/proposals.php` ("Choose the donor" / «اختر المانح»).

## Edge cases and concurrency
- Proposal without an agreement (zero locally): the migration stops BEFORE any DDL, prints the ids, and asks Finance to name the donor; then it is re-run.
- Proposal with more than one agreement: the migration stops BEFORE any DDL and prints the list; management decides.
- Agreement whose donor differs from the proposal's: impossible after the composite FK; before it, the pre-check reports it.
- Two agreements recorded at once for one proposal: the unique index rejects the second (Task 09 translates the exception into a message).
- Changing `proposals.donor_id` while an agreement exists: the composite FK uses `ON UPDATE RESTRICT`, so it fails; never use `CASCADE` (it would silently move the agreement to another donor).
- Known sequencing gap: the current Create Proposal form in `proposals/⚡index` (`saveProposal()`) has no donor field, so after NOT NULL it can no longer create proposals until Task 06. Propose in plan mode either (a) a minimal required donor select in the existing create modal (recommended; Task 06 replaces it) or (b) holding the NOT NULL step back until Task 06 ships. Never leave a form that throws a SQL error.
- Deleting a donor that has a proposal: refused by `restrictOnDelete`.

## Data integrity
- Pre-checks run first in `up()`, before any DDL, because MySQL does not roll DDL back: duplicates in `donor_proposal`, proposals without an agreement. Throw `RuntimeException` with the ids.
- Safe order in migration 1: add `donor_id` (unsigned big integer, nullable, no FK yet), `requested_budget`, `requested_currency` (string 10, nullable), `status_remind_on` → backfill `proposals.donor_id` from `donor_proposal` inside `DB::transaction()` (query builder, `lazyById` like the 2026_10_01 migration) → assert no NULL left → make `donor_id` NOT NULL → add FK to `donors` with `restrictOnDelete()` → add unique index `proposals(id, donor_id)`. If MySQL accepts adding the FK first, keep the order anyway; it avoids error 1832 on `->change()`.
- Migration 2: repeat the duplicate pre-check, check no agreement's donor differs from its proposal's, then add unique `donor_proposal.proposal_id` and the composite FK `donor_proposal(proposal_id, donor_id) → proposals(id, donor_id)` with `ON DELETE RESTRICT ON UPDATE RESTRICT`. Keep the existing single-column FKs (`donor_proposal.proposal_id` stays RESTRICT).
- Give indexes and FKs explicit names so `down()` and `information_schema` checks are readable.
- `down()` removes the composite FK, then the unique indexes, then the FK and columns, in reverse order. Ask the developer before running `php artisan migrate:rollback --step=2` locally to prove it.
- Every new column is utf8mb4_unicode_ci / InnoDB DYNAMIC through the connection config; `DatabaseEncodingTest` must stay green.

## Implementation guidance
- Every file keeps `declare(strict_types=1);`. Casts: `'requested_budget' => 'decimal:2'`, `'requested_currency' => Currency::class`, `'status_remind_on' => 'date'` (CarbonImmutable).
- Do not repurpose `Donor::proposals()` or `Proposal::donors()` / `donorNames()` (they mean "funded through an agreement"); add only `Proposal::donor()`.
- `ProposalFactory::definition()` gets `'donor_id' => Donor::factory()`; leave the new nullable columns out.
- `DonorProposalFactory`: list `proposal_id` before `donor_id`. `proposal_id` is a closure that creates `Proposal::factory()` for the caller's `donor_id` when one was passed as a plain value; `donor_id` is a closure that reads the proposal's `donor_id`. A caller passing a mismatched pair must fail loudly at the composite FK, not be "fixed" silently.
- `insertProposal()` gains an optional `?int $donorId = null` (creates a donor when null); keep it id-returning. Update the callers of `insertDonorProposal()` (`tests/Feature/DonorProposalTest.php`, `tests/Feature/TransfersTrackingTest.php`) so the donor passed is the proposal's.
- `RealProposalsSeeder` creates proposals before it maps donors: resolve each proposal's donor from its `donorProposals()` row first. `DemoDeliverySeeder` must create the agreement with the proposal's donor.

## Tests (Pest)
- Create `tests/Feature/ProposalDonorSchemaTest.php` in the `insertX()` + `DB::table()->find()` style:
  - the migration backfills `donor_id` from the existing agreement (build rows, run the backfill logic or the migration class, read back);
  - a second agreement for the same proposal throws `UniqueConstraintViolationException`;
  - an agreement with a donor different from the proposal's throws a foreign-key `QueryException`;
  - deleting a donor that has a proposal is refused;
  - changing `proposals.donor_id` while an agreement exists is refused;
  - `information_schema` shows the unique index and the composite FK by name.
- Update `tests/Feature/Models/ProposalTest.php` (relation `donor()`, casts) and `tests/Feature/Models/FactoryStateTest.php` if you add a derived closure.
- Add a test that a `requested_budget` change produces no amount in `activities.changes`.
- `Project::fundingSources()`: returns only donor-approved proposals with their donors for a project funded by two donors, in a fixed number of queries; on `projects/⚡show` a user without money permissions sees the donor names but no amount (`assertDontSee`), while Financial sees them.
- If you add the interim donor select: a Livewire test that creating without a donor shows the message, and with a donor saves it.
- Must stay green: `tests/Feature/DonorProposalTest.php`, `tests/Feature/Models/DonorProposalTest.php`, `tests/Feature/FundingWorkflowTest.php`, `tests/Feature/ProposalTest.php`, `tests/Feature/DatabaseEncodingTest.php`, `tests/Feature/DatabaseSeederTest.php`, `tests/Feature/RealDataSeederTest.php`.
- Run: `php artisan test --compact tests/Feature/ProposalDonorSchemaTest.php`, then the files above, then `--filter` on anything that fails after the factory change (`grep -rln "DonorProposal::factory\|insertDonorProposal" tests`).

## Acceptance criteria
- [ ] `proposals.donor_id` is NOT NULL and filled for every existing row.
- [ ] The unique index on `donor_proposal.proposal_id` and the composite FK are visible in `information_schema`.
- [ ] `DonorProposalTest`, `FundingWorkflowTest`, `ProposalTest`, `DatabaseEncodingTest`, `DatabaseSeederTest` pass after the factory changes.
- [ ] No amount from `requested_budget` appears in activities, exports or notifications.
- [ ] No form in the app throws a SQL error because of the new NOT NULL column.
- [ ] The **Project** page lists its funding sources (donor-approved proposals and donors) in one query, with amounts only for money-permission holders.

## Do not
- Rely on a Livewire uniqueness check instead of the database constraint.
- Use `cascadeOnDelete()` on `proposals.donor_id`, or `ON UPDATE CASCADE` on the composite FK.
- Leave the agreement's donor selectable on the server side (`SaveFundingAgreement` must take it from the proposal).
- Put a factory default on a default-bearing column, or make helpers return models.
- Rename existing columns or the `donor_proposal` table.

## Quality bar (this task)
- **Funding sources** section on **Project**: one eager-loaded query; donor names as plain text (linked only for `donors.view` holders); amounts through `x-ui.money`, right-aligned, only for money holders; an empty state «لا مصادر تمويل معتمدة بعد».
- Interim donor select: searchable, marked required, inline translated error; never a SQL error on save.
- Migrations print their pre-check results clearly (counts and ids) and stop before any DDL on offenders; constraint names are explicit and listed in the report.
- Everything in `quality-bar.md` applies. Before reporting, open every new or changed screen in the browser (Arabic RTL and English, light and dark, desktop and 375 px), run `vendor/bin/phpstan analyse` on the touched paths, and list both in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions; wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside this task's scope; note anything else as a follow-up.

## Report back
Your final message must contain: summary; files changed; migrations added (names, and the pre-check output on local data); tests run with pass/fail output; decisions assumed (D-24, the interim form option chosen); follow-ups (Task 09: friendly duplicate message and read-only donor on Record Agreement); and the handoff facts for the next tasks: exact column names and casts (`donor_id`, `requested_budget` decimal:2, `requested_currency` Currency, `status_remind_on` date), index/FK names, the factory behaviour (`Proposal::factory()` creates a donor; `DonorProposal::factory()` follows the proposal) — Tasks 04, 06, 08 and 09 rely on them, and the schema is frozen at CP1 (end of day 3); also the `Project::fundingSources()` signature and return shape, and the confirmation that `projects` was not touched (Task 30 owns `center_id`). Team B (Tasks 12, 13, 31) reads `proposals` from CP1 on.
````
