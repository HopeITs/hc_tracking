# Task 25 — Budget Lines by Category

> **خارج نافذة أكتوبر 2026 — لا تُشغَّل ضمن هذه الخطة** (المراجعة 2، 2026-10-08: محفوظة في الخطة كمرجع لخطة لاحقة؛ أيام الجدول ونقاط التكامل أدناه من النسخة السابقة ولا تنطبق).
>
> **المهمة:** بنود الميزانية حسب الفئة (مشروطة بالقرار D-17)
> **المطوّر:** Dev 2 · **الجهد:** يومان · **يعتمد على:** Task 03، Task 06
> **متى تستخدمه:** فقط إذا سجّل سجل القرارات أن D-17 يعتمد تنفيذ المهمة الآن، وبعد اكتمال Task 03 وTask 04 وTask 06 (بعد CP1)، وعادةً في اليومين 28–29 من الجدول.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
**Only proceed if decision D-17 approved this task; otherwise stop and report.** Open `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md`. If the file is missing, or D-17 is recorded as "later" (the plan's recommended default: later, unless Finance confirms the need), implement nothing and report that.

## Role and goal
You are implementing Task 25 of the HC Tracking proposal-lifecycle correction plan: budget lines per proposal, split across Finance's five categories (HR, Operational, Fixed, Running, Activity — the "study lines"). The outcome: on the **Proposal** page, a holder of money permissions enters lines per category in the proposal's own currency and sees their total against the requested budget (`proposals.requested_budget`, Task 03) and, once the agreement exists, against the agreement budget (`donor_proposal.budget`, Task 09). In the corrected story the concept note carries an estimated budget, the proposal is drafted, reviewed internally and submitted to the donor; the breakdown belongs to that preparation, so it freezes at `submitted` and reopens only when the proposal returns to `proposal draft`. This is money data: nobody without money permissions may query, see or change it.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (Laravel 13, PHP 8.5, Livewire 4 + Flux UI 2, Pest 5, Pint, Boost tools, `php artisan make:* --no-interaction`, narrow test runs, no new dependencies, no new base folders).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`, section `### Task 25`. The plan is Arabic and authoritative; English table, column, status and UI names in it are exact. Also read Task 03 (requested budget, money visibility), Task 04 (transition table), Task 06 (`SaveProposal`, budget hidden for non-money users), Task 09 (agreement budget/currency), and section 5, row "البيانات المالية".
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` — a recorded decision overrides the plan's default for D-xx (D-17, D-01).
4. Rules: `.ai/rules/database-migrations.md`, `.ai/rules/migrations.md`, `.ai/rules/feature.md`, `.ai/rules/pages-funding-agreements.md`, `.ai/rules/models.md`, `.ai/rules/views-pages.md`, `.ai/rules/enums.md`, `.ai/rules/views-enums.md`, `.ai/rules/proposals.md`, `.ai/rules/proposalsfunding-agreementstransfersdonors.md`, `.ai/rules/admin.md`, `.ai/rules/pages.md`, `.ai/rules/ui.md`, `.ai/rules/views.md`, `.ai/rules/support-models.md`, `.ai/rules/factories.md`, `.ai/rules/seeders.md`, `.ai/rules/database-seeders.md`, `.ai/rules/tests.md`. Also run `grep -rin 'budget\|currency\|money' .ai/rules`.
5. Study first: `app/Models/Proposal.php`, `app/Enums/ProposalStatus.php`, `app/Enums/Currency.php`, `app/Enums/DonorReportType.php` + `app/Enums/Concerns/HasTranslatedLabel.php` (enum shape), `app/Actions/FundingAgreements/SaveDonorReport.php` and `app/Actions/Proposals/ChangeProposalStatus.php` (Action shape), `app/Actions/Proposals/SaveProposal.php` and `app/Actions/Proposals/TransitionProposal.php` (writers of `requested_currency`), `app/Livewire/Concerns/ManagesAgreementForm.php` (`MAX_BUDGET`, the "currency is fixed once tranches exist" closure rule, per-field messages), `app/Concerns/RecordsActivity.php`, `app/Notifications/Recipients/ProposalRecipients.php`, `app/Support/AgreementMoney.php`, `app/Policies/ProposalPolicy.php`, `resources/views/pages/proposals/⚡show.blade.php` (`TABS`, `updatedTab()`, `canViewAgreements()`), `resources/views/components/ui/money.blade.php`, `database/seeders/FinanceSeeder.php`, `tests/Feature/AccessControl/PolicyCoverageTest.php`, `tests/Feature/AccessControl/FundraisingPagesMoneyTest.php`.
6. Skills: `laravel-best-practices`, `livewire-development`, `fluxui-development`, `database-migrations`, `laravel-security`, `testing-best-practices`.
7. Use Boost `search-docs` before framework APIs (e.g. `['decimal casts', 'validating arrays', 'pessimistic locking', 'foreign key constraints']`) and `database-schema` on `proposals` and `donor_proposal` before writing the migration.

## Preconditions to verify
- D-17 approves the task now (decision log).
- `proposals.donor_id`, `proposals.requested_budget` (decimal 14,2) and `proposals.requested_currency` exist — Task 03.
- `ProposalStatus` has `concept note review`, `concept note submitted`, `proposal review`, `on hold`; `App\Actions\Proposals\TransitionProposal` and `App\Support\ProposalTransitions` exist — Task 04.
- `App\Actions\Proposals\SaveProposal` exists — Task 06. List every writer of `requested_currency` (`grep -rn requested_currency app resources`).
- One shared money-visibility predicate for `requested_budget` exists (Tasks 03/06; holders of `funding-agreements.view`, `transfers.view` or `accounts.view`). If each call site repeats the permission checks inline, do not add another copy: propose one shared predicate in plan mode.
- `budget_lines`, `App\Models\BudgetLine` and `App\Enums\BudgetCategory` do not exist yet (create them).
If a check fails, stop and report instead of improvising.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-17, and the «تعديلات المراجعة 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- D-17: no date given; the default applies (after launch, November plan). Do not run this prompt in the October window.
- **D-31b changes the money personas below:** Public Relations Manager now holds `funding-agreements.view/create/update`, so it **sees** budget lines. Wherever this prompt uses Public Relations Manager as the "holds `proposals.update` but no money permission" example, use a role that truly lacks money permissions (e.g. a `MEAL` or `Public Relations` user given `proposals.update` in the test), and assert that Public Relations Manager does see the section.
- **D-20:** **Correct Status** belongs to General Manager and Public Relations Manager, not Super Admin.

## Scope
### In scope
- Create migration `create_budget_lines_table`: `id`, `proposal_id` (`constrained()->cascadeOnDelete()`), `category` varchar(50), `description` varchar(255), `amount` decimal(14,2), timestamps, index (`proposal_id`, `category`). No currency column: the currency is the proposal's `requested_currency`.
- Create `App\Enums\BudgetCategory` (string-backed, `HasTranslatedLabel`, `values()`): `Hr = 'hr'`, `Operational = 'operational'`, `Fixed = 'fixed'`, `Running = 'running'`, `Activity = 'activity'`.
- Create `App\Models\BudgetLine` + `BudgetLineFactory` (`php artisan make:model BudgetLine --factory --no-interaction`); add `Proposal::budgetLines(): HasMany`.
- Update `ProposalStatus` with `allowsBudgetLineEdits(): bool` (exhaustive `match`, no `default`).
- Create `App\Actions\Proposals\SaveProposalBudgetLines` (bulk save of the whole set).
- Update every writer of `requested_currency` (`SaveProposal`, and `TransitionProposal` if it writes it) to refuse a change while lines exist; prefer one shared guard used by all writers.
- Update `resources/views/pages/proposals/⚡show.blade.php`: a **Budget Lines** section. Decide placement in plan mode: inside the money-gated `funding` tab, or a new `budget` tab added to `TABS` with the same guard in `updatedTab()`.
- Lang: `lang/{en,ar}/proposals.php` (section copy, messages, warnings), `lang/{en,ar}/enums.php` (`budget_category.*`), `lang/{en,ar}/notifications.php` (`events.proposal.budget_lines_updated`).
- Seed a few lines in `database/seeders/FinanceSeeder.php`; add `BudgetLine` to `tests/Feature/DatabaseSeederTest.php` and to `modelsGuardedWithoutPolicy()` in `tests/Feature/AccessControl/PolicyCoverageTest.php` (module `proposals`, with the reason).
### Out of scope
- Budget lines on funding pages, **Executive Summary**, exports or the proposal directory (any such money figure must first be defined in `App\Support\AgreementMoney`).
- Copying lines in `CloneProposalForRenewal` (Task 10) — follow-up only.
- Changing `requested_budget` rules (Tasks 03/06), the transition table (Task 04) or agreement rules (Task 09). Currency conversion of any kind.

## Business rules
- Exactly five categories (`BudgetCategory`); several lines per category; each line has a description and an amount.
- Line currency = the proposal's `requested_currency`. While it is null, no line can be saved.
- Lines total ≠ `requested_budget`: show a warning with both figures; never block a save or a transition. No requested budget: show the total and "no requested budget recorded".
- Agreement comparison: compute a difference only when `donor_proposal.currency` equals `requested_currency` (read it through the `DonorProposal` model, not a pivot: see the pivot-currency trap in `.ai/rules/enums.md`); otherwise show both figures, each with its currency, and say they are not compared. Never add, convert or net amounts across currencies.
- Editable while status ∈ {`concept note`, `concept note review`, `concept note submitted`, `proposal draft`, `proposal review`}; locked for `submitted`, `on hold`, `approved`, `active`, `completed`, `rejected` (`final reports` was removed by Task 04, D-02). `submitted → proposal draft` (Task 04) reopens them. Confirm the `concept note submitted` reading in plan mode.
- Changing `requested_currency` while lines exist is refused with a field message, from every writer.
- Subtotals per category and the total come from SQL `SUM(amount)` (or decimal-safe arithmetic), never float accumulation; display through `x-ui.money` with the proposal currency.

## Security and authorization
- View: the shared money predicate. For everyone else the computed property returns nothing **without querying** `budget_lines`, and the section is not rendered (`.ai/rules/pages.md`, "Hide money, keep names"; `@can` alone is not enough).
- Edit: `Gate::authorize('update', $proposal)` (`proposals.update` via `ProposalPolicy`) **and** the money predicate, both inside `SaveProposalBudgetLines`, repeated at the top of the Livewire method so `AuthorizationCoverageTest` sees a check. A Public Relations Manager holds `proposals.update` but no money permission: 403.
- Object level: resolve the proposal from the page's locked id; load existing lines only via `$proposal->budgetLines()->whereKey($ids)`. Any line id that does not belong to this proposal rejects the whole batch.
- `#[Fillable(['category', 'description', 'amount'])]`; `proposal_id` is never read from input (create through the relation).
- Audit: one `proposal.budget_lines_updated` activity per save via `$proposal->recordActivity()`, properties = line counts per category, no amounts. If `ProposalRecipients` would notify a watcher without money permission (Public Relations Manager), keep the event out of notifications and explain how in the plan.
- Page helpers that return data stay `protected` (`BrowserCallableMethodsTest`).

## Validation
Messages go to `lang/ar/proposals.php` and `lang/en/proposals.php`:
- `lines`: present, array, max:200 (confirm the cap in plan mode).
- `lines.*.id`: nullable, integer (ownership enforced in the Action).
- `lines.*.category`: required, `new Enum(BudgetCategory::class)` — «اختر فئة البند» / "Choose the line's category".
- `lines.*.description`: required, string, max:255 — «اكتب وصف البند» / "Describe the line".
- `lines.*.amount`: required, numeric, min:0, max:999999999999.99 — «المبلغ لا يكون سالبًا» / "An amount cannot be negative".
- Server-side invariants thrown from the Action with `ValidationException::withMessages()`: currency missing «حدّد عملة المقترح أولًا»; status lock «بنود الميزانية مقفلة بعد تقديم المقترح للمانح. أعد المقترح مسودة لتعديلها.»; stale set «عُدّلت بنود الميزانية من مستخدم آخر، أعد التحميل.»; currency lock on `requestedCurrency` «لا يمكن تغيير عملة المقترح ما دامت له بنود ميزانية.» — each with an English equivalent.

## Edge cases and concurrency
- Double submit and two editors: the form sends the ids it loaded (`expectedLineIds`); inside the transaction lock the proposal and its lines (`lockForUpdate`) and reject the batch if the current id set differs. A repeated click after a successful save then fails gracefully instead of duplicating new rows; also disable the button with `wire:loading`.
- Status or currency changed between load and save: re-check `allowsBudgetLineEdits()` and `requested_currency` after the lock.
- Money permission revoked mid-edit: save → 403; the next render hides the section.
- **Correct Status** (General Manager or Public Relations Manager, D-20) moves a terminal status: the lock follows the new status.
- Deleting every line is allowed while editable. Proposal deletion cascades lines (a proposal with an agreement cannot be deleted: `donor_proposal.proposal_id` RESTRICT).
- Partial failure: any invalid row rolls back the whole batch.

## Data integrity
- One `DB::transaction` per save: lock proposal → lock lines → verify ids, status, currency → delete removed → update kept → insert new → record activity. Any notification `afterCommit`.
- FK `budget_lines.proposal_id` cascade; table and columns `utf8mb4_unicode_ci` on InnoDB DYNAMIC (`DatabaseEncodingTest`). New empty table: no data pre-check; `down()` drops it.

## Implementation guidance
- `declare(strict_types=1);`, `casts()` (`category` → `BudgetCategory::class`, `amount` → `'decimal:2'`), `@property` docblocks with `CarbonImmutable`, array-shape PHPDoc.
- Flux modal (if used) bound with `wire:model="showBudgetLinesModal"`, never `:open`; reuse `x-ui.money`, `x-ui.panel`, Flux inputs; logical classes only (`ms-*`, `pe-*`, `text-start`); amounts `tabular-nums`. Labels via `$case->label()`; stored values stay English. Full `@php … @endphp` blocks only.
```php
final class SaveProposalBudgetLines
{
    /**
     * @param  list<array{id: int|null, category: string, description: string, amount: string}>  $lines
     * @param  list<int>  $expectedLineIds  ids the form loaded
     */
    public function handle(Proposal $proposal, array $lines, array $expectedLineIds): void
    {
        Gate::authorize('update', $proposal);
        // + the shared money predicate (abort 403 when missing)
        DB::transaction(function () use ($proposal, $lines, $expectedLineIds): void {
            // lock, verify, apply, record one activity
        });
    }
}
```

## Tests (Pest)
- `tests/Feature/ProposalBudgetLinesTest.php` (Livewire on `pages::proposals.show`; seed `RolesAndPermissionsSeeder`; `User::factory()->role('Financial')`): save in all five categories (rows, one activity, subtotals and total rendered with the currency); warning when total ≠ requested budget while the save succeeds; agreement in another currency shows no difference; validation failures write nothing; null currency refused; locked for `submitted`, `on hold`, `approved` and unlocked after `submitted → proposal draft`; foreign line id rejects the batch with both proposals unchanged; stale set rejected and double submit creates no duplicates; currency change refused by `SaveProposal` (and any other writer) while lines exist, allowed when none.
- Extend `tests/Feature/AccessControl/FundraisingPagesMoneyTest.php`: Public Relations Manager sees no section and no amounts, the query log shows no query on `budget_lines`, and save is 403 (fresh Livewire instance per forbidden call, `.ai/rules/tests.md`).
- `tests/Feature/BudgetLineTest.php` (factory-backed `insertX()` helpers returning ids, `DB::table()` reads): cascade with the proposal, 2-decimal precision. `tests/Feature/Models/BudgetLineTest.php`: relation and casts.
- Keep green: `LocalizationTest.php`, `DatabaseEncodingTest.php`, `DatabaseSeederTest.php`, `AccessControl/PolicyCoverageTest.php`, `AccessControl/AuthorizationCoverageTest.php`, `AccessControl/BrowserCallableMethodsTest.php`, `ProposalDetailTest.php`, `ProposalDetailActionsTest.php`.
- Run: `php artisan test --compact tests/Feature/ProposalBudgetLinesTest.php`, then each file above, or `--filter=...`.

## Acceptance criteria
- [ ] Finance sees, per proposal, the split across the five categories with subtotals and a total in the proposal currency.
- [ ] A total different from the requested budget warns and never blocks; cross-currency figures are never combined or compared.
- [ ] Lines are locked from `submitted` on and reopen only after a return to `proposal draft`.
- [ ] A currency change is refused while lines exist, from every writer.
- [ ] Without money permissions: no `budget_lines` query, no amount in the HTML, 403 on save.
- [ ] All new text exists in Arabic and English; listed tests pass; Pint clean.

## Do not
- Add, convert or net amounts across currencies; store a currency per line.
- Read `proposal_id`, the status or the currency from the request.
- Rely on hidden buttons or Blade `@can` for money hiding.
- Save a batch partially.
- Put amounts in activity properties, notifications or tour copy.
- Use `:open` on `flux:modal`, the `@php(...)` shorthand, or physical `ml-/mr-/left-/right-` classes.
- Add dependencies or touch funding-page money definitions outside `AgreementMoney`.

## Quality bar (this task)
- (Out of the October window.) When run: the budget section is absent for non-money users, totals per currency are read-only and computed on the server, and the mismatch with the requested budget is a warning, not a block.
- Everything in `quality-bar.md` applies. Before reporting, open every new or changed screen in the browser (Arabic RTL and English, light and dark, desktop and 375 px), run `vendor/bin/phpstan analyse` on the touched paths, and list both in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions (D-17 status, section placement, `concept note submitted` lock, line cap, notification fan-out); wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside Task 25's scope; note anything else as a follow-up.

## Report back
- Summary; files created/changed; migration added; tests run with pass/fail output.
- Decisions assumed: D-17 approval reference, section placement, lock statuses, line cap, notification handling.
- Follow-ups: renewal cloning (Task 10), funding-page display through `AgreementMoney`, anything found in Tasks 03/06.
- Handoff: no task depends on Task 25. For the Task 26 run (`TASK_NUMBER` = 25) list the new lang keys, the page/tab and any data hooks; for Task 28 (Finance UAT scenario) state the permission rule, the lock rule and where the section lives.
````
