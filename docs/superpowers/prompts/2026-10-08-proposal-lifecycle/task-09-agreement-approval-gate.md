# Task 09 — Agreement gate: approved proposals only, one agreement each

> **المهمة:** بوابة الاتفاقية: بعد الاعتماد فقط، واتفاقية واحدة (**Record Agreement**)
> **المطوّر:** Dev 1 · **الفريق:** A · **الأيام:** 11 (الاثنين 2026-10-26) · **الجهد:** يوم ونصف (مع توليد سجل التحويل) · **يعتمد على:** Task 03، Task 08
> **متى تستخدمه:** بعد دمج Task 03 وTask 08 (اليوم 10)؛ إنهاء هذه المهمة يغلق نقطة التكامل CP4 (نهاية اليوم 11، الاثنين 2026-10-26: مسار كامل من مشروع (مركز) مسودة إلى اعتماد وتفعيل وتوليد التقارير الشهرية واتفاقية وسجل تحويل مولَّد وإشعارات لـ MEAL والإدارة)، قبل تجميد الميزات في CP5 (نهاية اليوم 12).
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 09 of the HC Tracking proposal-lifecycle correction plan (Laravel 13, PHP 8.5, Livewire 4, Flux UI 2, Pest). The outcome: a funding agreement can be recorded only for a donor-approved proposal, only once per proposal, and its donor is always the proposal's donor, written by the server. This enforces "Approved Proposal → Record Agreements" and "One Donor → One Proposal → One Agreement" at the end of the corrected story (draft project without dates → concept note → proposal → donor approval fixes dates and funds the project → MEAL confirms the report template and the project becomes active → one agreement). The database constraints from Task 03 are the guarantee; this task makes every UI path respect them with friendly messages, adds the agreement's **tranche schedule** (entered by PR and Finance, D-31/D-31b), auto-generates one expected transfer per tranche once the agreement exists, adds the transfer's arrival date (D-31a), and closes CP4 with an end-to-end test. Task 19 (aggregated donor reports, financial section) and Task 21 (transfer events) build on the single agreement and the generated transfer.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`, section `### Task 09` — Arabic and authoritative; English table, column, enum, permission and UI names are exact. Also read its «تعديلات المراجعة 2» block, sections 1–2 (especially 1.0 fact 7), `### Task 03` and `### Task 08` (the event contract), the `transfer.*` rows of `### Task 21`, section 5 (security matrix), section 7 (CP4) and rows D-20, D-24, D-26, D-31 of section 9.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists; a recorded decision overrides the plan's default for D-20 / D-24 / D-26.
4. Rule files: `.ai/rules/index.md`, `funding.md`, `funding-agreements.md`, `components-funding.md`, `donors.md`, `admin.md`, `transfers.md`, `database-migrations.md`, `migrations.md`, `pages-funding-agreements.md`, `proposalsfunding-agreementstransfersdonors.md`, `support-models.md`, `pages.md`, `proposals.md`, `ui.md`, `views.md`, `views-enums.md`, `views-pages.md`, `tests.md`. Then run `grep -rin 'agreement\|donor_proposal\|isApprovedByDonor\|scope' .ai/rules`.
5. Study first: `app/Actions/FundingAgreements/{SaveFundingAgreement,DeleteFundingAgreement}.php`, `app/Livewire/Concerns/ManagesAgreementForm.php`, `resources/views/components/funding/agreement-modal.blade.php`, `resources/views/pages/funding-agreements/{⚡index,⚡show}.blade.php`, `resources/views/pages/proposals/⚡show.blade.php` (`agreementProposalScope`, `warnings()`), `resources/views/pages/donors/⚡show.blade.php` (`agreementDonorScope`), `resources/views/pages/proposals/⚡index.blade.php` (the narrowed `UniqueConstraintViolationException` catch), `app/Models/{DonorProposal,Proposal,Donor}.php`, `app/Enums/{ProposalStatus,Currency}.php`, `app/Actions/Proposals/ApproveProposal.php` (Task 08), the event class `FundingAgreementRecorded` (Dev 1, CP2), `app/Support/Tranches.php` (`timing()`), `app/Models/TransfersTracking.php` (`stateSql()`), `app/Support/AgreementMoney.php`, `app/Notifications/QuickActions.php`, `database/factories/{ProposalFactory,DonorProposalFactory}.php`, tests `FundingAgreementTest`, `FundingWorkflowTest`, `DonorProposalTest`, `FundingAgreementRegisterTest`, `AgreementComponentsTest`, `DonorRegisterTest`, `AccessControl/{FinanceAuthorizationTest,ActionAuthorizationTest,FundraisingPagesMoneyTest}`.
6. Activate skills: `laravel-best-practices`, `livewire-development`, `fluxui-development`, `laravel-security`, `testing-best-practices`.
7. Use Laravel Boost `search-docs` before relying on framework APIs (`Rule::exists` with closures, `UniqueConstraintViolationException`, Livewire computed properties) and `database-schema` on `proposals` and `donor_proposal` to confirm the Task 03 constraints.

## Preconditions to verify
- `proposals.donor_id` is NOT NULL with `restrictOnDelete`; `proposals.requested_budget` and `requested_currency` exist; UNIQUE on `donor_proposal.proposal_id`; composite FK `donor_proposal(proposal_id, donor_id)` → `proposals(id, donor_id)` (Task 03). Check with `database-schema` / `information_schema`.
- `ProposalFactory` creates a donor and `DonorProposalFactory` takes the proposal's donor (Task 03).
- `App\Actions\Proposals\ApproveProposal` exists (Task 08); `ProposalStatus::isApprovedByDonor()` covers approved, active, completed (`final reports` was removed by Task 04, D-02).
- Public Relations Manager holds `funding-agreements.view/create/update` (Task 02, D-31b).
- The `FundingAgreementRecorded` event class exists (written on day 6, CP2); `transfers_tracking.source` does not exist yet.
- If any check fails, stop and report instead of improvising.

## Revision 2 changes (2026-10-08)
- Timeline: Team A, day 11 (2026-10-26), 1.5 days of effort including transfer generation; this task closes CP4 (full end-to-end path, end of day 11). Feature freeze is CP5 (end of day 12).
- `SaveFundingAgreement` dispatches `FundingAgreementRecorded` after commit when an agreement is created (payload as frozen at CP1).
- New `App\Actions\FundingAgreements\GenerateExpectedTransfers`, a queued, idempotent listener of `FundingAgreementRecorded` (section 1.0 fact 7). *(Superseded in part: Revision 4 ties transfers to the agreement, not to activation, so it no longer listens to `ProjectActivated`; Revision 6 makes it one row per tranche — see below.)* Received amount, fees (`fees`, `fees_currency` = the commission) and exchange rate stay empty for Finance to complete.
- New column `transfers_tracking.source` (`manual` / `auto`, default `manual`) to mark generated rows.
- No money computed inside the action: states and amounts go through `AgreementMoney` and `TransfersTracking::stateSql()` (`support-models.md`).
- Notification `transfer.generated` (Task 21 catalog) to Finance and General Management, with no amounts for non-finance recipients.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-31, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- Tranche schedule `funding_agreement_tranches` (`funding_agreement_id`, `sequence`, `amount`, `currency`, `scheduled_date`; unique pair). Entered by **PR and Finance** (D-31b; permissions in Task 02). Sum = agreement budget, otherwise a warning that needs confirmation.
- `GenerateExpectedTransfers`: one `transfers_tracking` row per tranche, idempotent on `tranche_id`.
- Four dates: `scheduled_date`, `transfer_date`, new `arrival_date` (arrival at the bank or transfer company), `receive_date` (actually received into the organization's account or cash box) (D-31a). Order: transfer ≤ arrival ≤ receive. Show the definitions as hints under the fields.
- Commission = transfer fee in `fees` / `fees_currency`.
- Self-funded proposals (D-27a, internal donor): agreement optional, no transfer rows.
- **Revision 4 (D-39):** transfers stay tied to the agreement. `GenerateExpectedTransfers` listens to `FundingAgreementRecorded` (and to tranche-schedule saves), never to `ProjectActivated`, so it never waits for the monthly report template.
- **Tranche schedule details (plan Revision 6):** at least one tranche; each `scheduled_date` within the agreement term — «تاريخ الدفعة خارج مدة الاتفاقية»; sum ≠ budget → «مجموع الدفعات لا يساوي ميزانية الاتفاقية» as a warning the user must confirm explicitly (not a block). Editing the schedule after generation adds a transfer row for a new tranche only; a removed tranche with nothing received deletes its generated row, otherwise the removal is refused. Existing agreements with manual transfers get no schedule and no generation (D-31, Task 27).
- **Arrival date (D-31a):** new nullable `transfers_tracking.arrival_date`; order transfer ≤ arrival ≤ receive — «تاريخ الوصول لا يسبق تاريخ الإرسال». Update `TransfersTracking::stateSql()` and `Tranches::timing()` together (they are the single money/state definitions, `support-models.md`) so "in transit" lasts until arrival; add the PHP/SQL parity test the rule file asks for.

## Scope
### In scope
- `SaveFundingAgreement`: on create, load the proposal server-side, refuse unless approved and without an agreement, write `donor_id = proposal.donor_id`; on update, never change `donor_id` or `proposal_id`.
- `ManagesAgreementForm`: drop `agreementDonorId` from validation and from the request contract; `proposalOptions()` lists only donor-approved proposals without an agreement (plus the current one when editing), respecting `agreementProposalScope()` / `agreementDonorScope()`; donor shown read-only; budget and currency pre-filled from `requested_budget` / `requested_currency` when a proposal is chosen; remove `agreementProposalWarning`.
- `x-funding.agreement-modal`: donor always a locked, read-only field (derived from the chosen proposal); no donor select.
- Pages using the trait: `funding-agreements/⚡index`, `funding-agreements/⚡show`, `proposals/⚡show`, `donors/⚡show` (pinned to its donor: only that donor's approved proposals without an agreement).
- **Record Agreement** button on `proposals/⚡show` for an approved proposal without an agreement (holders of `funding-agreements.create` only), and on the Finance approval notification (an action in `QuickActions::forSubject()` that opens the proposal page with the form, or a deep link — choose in plan mode).
- Replace the "proposal not approved" warning on `funding-agreements/⚡show` and `proposals/⚡show` with «حالة المقترح لا تتسق مع وجود اتفاقية» / "The proposal status is not consistent with having an agreement" for legacy rows and **Correct Status** corrections.
- Dispatch `FundingAgreementRecorded` after commit from `SaveFundingAgreement` (create only).
- Migrations (`php artisan make:migration ... --no-interaction`, working `down()`): `create_funding_agreement_tranches_table`; `transfers_tracking.source` (default `manual`, existing rows stay `manual`), `transfers_tracking.tranche_id` (nullable FK, unique when not null) and `transfers_tracking.arrival_date` (nullable).
- Tranche schedule editor inside the shared agreement form (`ManagesAgreementForm` / `x-funding.agreement-modal`): rows of sequence, amount, currency (the agreement's), scheduled date; add/remove rows; live sum vs budget; the D-31 confirmation for a mismatch. Available to Financial and Public Relations Manager.
- `App\Actions\FundingAgreements\GenerateExpectedTransfers` registered as a queued listener of `FundingAgreementRecorded` (and called after a schedule save), one row per tranche, plus the `transfer.generated` activity/notification wiring.
- Arrival date on the transfer form with the D-31a hint under the arrival and receive fields.
- End-to-end CP4 test.
- Lang keys in `lang/{en,ar}/funding_agreements.php` (and `proposals.php` if needed); remove unused keys from both languages together.

### Out of scope
- Schema and constraints (Task 03). Approval and the event classes (Task 08 / day 6). Aggregated donor reports (Task 19). Department tasks (Task 31). Monthly report generation (Task 17). The event catalog and oversight recipients (Task 21). The **Transfers** page layout (unchanged; generated rows appear in the existing `transfers_tracking` register). Money definitions in `AgreementMoney` / `TransfersTracking::stateSql()` — untouched. Deletion rules — unchanged.

## Business rules
- Create allowed only when `proposal.status->isApprovedByDonor()`.
- `donor_id` = `proposal.donor_id`, always written by the server, never read from the request.
- At most one agreement per proposal (Task 03 unique index).
- `budget` defaults to `requested_budget` and stays editable (the signed amount may differ); `currency` defaults to `requested_currency` and stays locked once tranches exist (existing rule).
- Deleting an agreement keeps the existing rules (no delete with tranches or donor reports).
- Editing a legacy agreement whose proposal is not approved stays possible (only creation is gated) and shows the inconsistency warning.
- Expected transfers (D-31): one generated row per tranche (`payment_amount` = tranche amount, its currency, `scheduled_date` = tranche date, `tranche_id`), only when the proposal is donor-approved and the donor is not internal (D-27a); idempotent on `tranche_id`; manual rows already entered for the agreement are never touched or duplicated (an agreement with manual transfers and no schedule generates nothing).

## Security and authorization
- `funding-agreements.create` / `funding-agreements.update` (Financial, Public Relations Manager since D-31b, General Manager) checked in the Action with `Gate::authorize` and in the trait before any work; the tranche schedule uses the same abilities. Transfers and accounts stay Finance-only.
- The submitted proposal id is re-validated on the server: exists, donor-approved, no agreement, inside the page scope. List filtering is not a control.
- `donors/⚡show` stays pinned through `agreementDonorScope()`; `proposals/⚡show` and `funding-agreements/⚡show` through `agreementProposalScope()`.
- A forged `agreementDonorId` (or `donor_id`) in the payload is ignored; the saved donor is the proposal's.
- Money stays visible only to money-permission holders; the pre-fill reads `requested_budget` only for users already allowed to record agreements.
- `GenerateExpectedTransfers` runs as the system (queued listener, no user): it authorizes nothing from a request, takes every id from the event and re-reads the agreement and proposal; record it in **Activity Log** with the system actor and a reference to the agreement. Document its exemption in `ActionAuthorizationTest` (or authorize a system gate) — decide in plan mode, never weaken the test silently. `transfer.generated` carries no amount for non-finance recipients.

## Validation
- `agreementProposalId`: required, integer, exists, donor-approved — ar «لا تُسجَّل اتفاقية إلا لمقترح معتمد من المانح» / en "An agreement can only be recorded for a donor-approved proposal."
- `agreementProposalId` without an existing agreement — ar «لهذا المقترح اتفاقية مسجلة بالفعل» / en "This proposal already has a recorded agreement."
- `budget`, `agreementCurrency`, `externalProjectNumber`: existing rules and messages.
- Messages in `lang/ar/funding_agreements.php` + `lang/en/funding_agreements.php`.

## Edge cases and concurrency
- Two users record an agreement for the same proposal at once: the unique index rejects the second; catch `UniqueConstraintViolationException` narrowed to the `donor_proposal.proposal_id` unique index name (re-throw anything else) and show the "already recorded" message on `agreementProposalId`.
- Proposal corrected away from `approved` through **Correct Status** (General Manager or Public Relations Manager, D-20) after the agreement exists: the agreement stays; both pages show the inconsistency warning.
- Proposal changes donor after approval: impossible by Task 06's lock; the composite FK is the backstop.
- Co-funder: a separate proposal (D-24); never a second agreement.
- Listener runs twice (retry): the second run finds a row for each `tranche_id` and does nothing; lock the agreement row (`lockForUpdate`) before checking, and the unique `tranche_id` is the backstop, so two workers cannot both insert.
- Agreement recorded before or after the project's template is confirmed: the same rows either way (transfers never wait for activation).
- Agreement without a schedule, or a self-funded proposal: nothing is generated.

## Data integrity
- Unique index + composite FK (Task 03) are the guarantee; the Action's pre-check only produces a clearer message.
- Save stays inside one transaction; load the proposal with `lockForUpdate` before the pre-check so the check and the insert see the same row.
- Migrations only for the tranche table and the three `transfers_tracking` columns (utf8mb4_unicode_ci / InnoDB DYNAMIC, `DatabaseEncodingTest` green). Any other schema change: stop and ask.
- Generation runs in its own transaction after the agreement's commit; each generated row's amounts come from its tranche as stored, never recomputed.

## Implementation guidance
- Keep the form shared: never re-inline it on a page (`funding.md`); deletes stay out of the trait (`ActionAuthorizationTest`).
- `proposalOptions()` select only `id`, `name`, `internal_project_number`, `status`, `donor_id` (+ donor name via a narrow eager load); no money columns.
- Display numbers through `x-ui.project-number`; Flux modal bound with `wire:model`; all strings through `__()`.
- Update test factories/helpers so funding tests create an approved proposal with a donor first; keep `insertX()` helpers id-returning (`tests.md`).

## Tests (Pest)
- `tests/Feature/AgreementApprovalGateTest.php`: record for an approved proposal succeeds and the donor comes from the proposal; for `submitted` refused with the message; second agreement refused with the friendly message (sequential and simulated race); forged donor id ignored; proposal outside the page scope (proposal page, donor page) refused; user without `funding-agreements.create` → 403 (fresh Livewire instance per forbidden call); editing a legacy agreement on a non-approved proposal still works and shows the warning; pre-fill uses requested budget and currency.
- Update `FundingAgreementTest`, `FundingWorkflowTest`, `DonorProposalTest`, `FundingAgreementRegisterTest`, `AgreementComponentsTest`, `DonorRegisterTest` to create approved proposals with donors; keep their assertions.
- `tests/Feature/AgreementTranchesTest.php`: a schedule needs one tranche; a date outside the agreement term is refused; a sum different from the budget needs the explicit confirmation; Public Relations Manager and Financial can edit the schedule, MEAL gets 403; removing a tranche with a received transfer is refused.
- `tests/Feature/GenerateExpectedTransfersTest.php`: recording an agreement with three tranches creates exactly three `transfers_tracking` rows with `source = auto`, the tranche amounts, currency and dates and their `tranche_id`, with empty fees/received/rate; running the listener again creates nothing; adding a fourth tranche later adds one row; a self-funded proposal or an agreement without a schedule generates nothing; transfer ≤ arrival ≤ receive is enforced with the message and `stateSql()` / `Tranches::timing()` agree on "in transit until arrival"; `FundingAgreementRecorded` fires after commit only and not on a refused save; `transfer.generated` shows no amount to non-finance recipients.
- `tests/Feature/ProposalLifecycleEndToEndTest.php` (CP4): draft project (centre + program) without dates → concept note → review → submitted to donor → proposal draft → review → submitted → approve with evidence → project `approved` with dates → MEAL confirms the template and the project becomes `active` (when Task 33 is merged) → monthly report months generated (when Task 17 is merged) → record agreement with tranches → one expected transfer per tranche → second agreement refused → MEAL and General Management notified (when Task 21 is merged). Include the review loop if Task 07 is merged.
- Commands: `php artisan test --compact tests/Feature/AgreementApprovalGateTest.php`, then each updated file.

## Acceptance criteria
- [ ] No UI path can create an agreement for a non-approved proposal or a second one for a proposal.
- [ ] The donor field is never selectable in **Record Agreement**.
- [ ] All existing funding tests pass after factory updates.
- [ ] The CP4 end-to-end test passes.
- [ ] Recording an agreement produces exactly one generated transfer per tranche (`source = auto`), never a duplicate; manual rows are never touched; self-funded proposals generate nothing.
- [ ] Public Relations Manager and Finance can enter the tranche schedule; the arrival date and its hint appear on the transfer form; `stateSql()` and `Tranches::timing()` stay in parity.
- [ ] Pint clean; touched test files green.

## Do not
- Keep a donor select in the form, or read `donor_id` from the request.
- Rely on the warning alone, or on filtering the dropdown alone.
- Recompute any money figure inline (`support-models.md`), including inside `GenerateExpectedTransfers`.
- Generate transfers synchronously inside the agreement transaction, more than one row per tranche, or rows for a self-funded proposal.
- Change agreement delete rules or the currency lock.
- Use `:open` on a Flux modal or leave literal UI text.

## Quality bar (this task)
- **Record Agreement**: the donor is a read-only chip; the proposal picker lists only eligible proposals with number and donor; the tranche editor is an inline table (add/remove rows, running total against the budget, a clear mismatch warning with an explicit confirmation), with dates validated inline.
- Transfer form: the D-31a hint sits under the arrival and receive fields; date order errors appear on the field that breaks it.
- Money through `x-ui.money`; nothing financial reaches non-money users, including toasts and activity sentences.
- Everything in `quality-bar.md` applies. Before reporting, open every new or changed screen in the browser (Arabic RTL and English, light and dark, desktop and 375 px), run `vendor/bin/phpstan analyse` on the touched paths, and list both in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions (notification action vs deep link, removal of old lang keys, the listener's authorization exemption, `source` column type); wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory) — e.g. update the `funding.md` note that agreements on non-approved proposals are "allowed but warned".
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside Task 09's scope; note anything else as a follow-up.

## Report back
Summary; files changed; migrations added (tranches table and the `transfers_tracking` columns); tests run with pass/fail output; decisions assumed (D-20, D-24, D-26, D-27a, D-31, D-31a, D-31b); follow-ups. Handoff facts: for Task 19 — every agreement belongs to exactly one donor-approved proposal and its donor equals the proposal's donor, generated transfers are marked `source = auto` with a `tranche_id`, and "in transit" now ends at `arrival_date`; for Task 21 — `FundingAgreementRecorded` (payload, dispatched after commit) and the `transfer.generated` event/recipients; the name of the CP4 end-to-end test and which steps it skips until Task 17 (B1) and Task 21 are merged; for Task 27 — the read-only queries that list legacy agreements on non-approved proposals and agreements without any transfer (candidates for generation at deploy).
````
