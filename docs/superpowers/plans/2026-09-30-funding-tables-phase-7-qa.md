# Funding tables — Phase 7: Finish, check and record

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Prove all eight Funding pages work for the finance manager in every mode (light/dark, English/Arabic, phone/desktop, keyboard), teach the guided tour the new tables, and record the durable rules for the next developer.

**Depends on:** Phases 0–6 done.

## Global Constraints

Same as Phases 0–6: `.\php.cmd`, no commits, no full suite, Pint after PHP changes, lang keys in en+ar, Graphite dark tokens.

---

### Task 1: Tour steps for the tables

**Files:** Modify `config/tour.php`, `lang/en/tour.php`, `lang/ar/tour.php`.

- [x] **Step 1:** Read `lang/en/tour.php` to see the shape of `blocks.<id>` entries (title/body keys) and copy it exactly.
- [x] **Step 2:** In `config/tour.php` `blocks`, after the `table` block, add:

```php
        ['id' => 'finance_filters', 'targets' => ['[data-finance-filters]']],
        ['id' => 'finance_totals', 'targets' => ['[data-register-totals]']],
```

- [x] **Step 3:** Add `blocks.finance_filters` and `blocks.finance_totals` to both lang files, in the same shape as the existing blocks. English copy: filters — "Narrow the table: pick a year, a status, a currency or a flag. Choose the same pill again to clear it."; totals — "Totals for exactly the rows shown, one line per currency. Currencies are never added together." Arabic: "ضيّق الجدول: اختر سنة أو حالة أو عملة أو مؤشراً. اختر الزر نفسه مرة أخرى لإلغائه." / "مجاميع الصفوف المعروضة فقط، سطر لكل عملة. لا تُجمع العملات معاً أبداً."
- [x] **Step 4:** Page steps in `config/tour.php` for `transfers.index`, `funding-agreements.index`, `donors.index`, `donors.show`, `proposals.index`, `funding-agreements.show` point at Overview-only hooks; they keep working because a step only shows when its element renders. Leave them.
- [x] **Step 5:** Run `.\php.cmd artisan test --compact tests/Feature/GuidedTourTest.php tests/Feature/LocalizationTest.php` — PASS.

### Task 2: Visual and behaviour check of every page

Render each page as in Phase 1 Task 5 (`.ai/rules/pages.md` → preview an auth-gated page) as a `Financial` user. Pages: `/transfers`, `/funding-agreements`, `/funding-agreements/{slug}`, `/donors`, `/donors/{slug}`, `/proposals`, `/proposals/{slug}?tab=funding`, `/admin/accounts`.

- [x] For each page, check and fix:

| Check | Expected |
| --- | --- |
| Opens on the table | `view` absent from URL; Overview one click away and identical to before. |
| Light / dark (`resize_window` colorScheme) | No white boxes in dark; heads on surface-2; red text readable. |
| Arabic (user `locale` = `ar`) | Columns mirrored; amounts left-to-right with the minus in front; pills and totals translated. |
| 1440px, 1024px, 390px | Wide: no sideways scroll, sticky header. Laptop: table scrolls inside itself, first column pinned. Phone: labelled blocks; page never scrolls sideways. |
| Keyboard | Tab reaches every pill, sort heading, per-page button, export; focus ring visible; Enter activates. |
| Export | The CSV opens in Excel with Arabic names intact (BOM) and amounts as numbers. |
| Year 2026 + "2026 only" | Columns and totals change as the spec says; no "Outstanding" in year-only on Agreements. |

- [x] Check the browser console (`read_console_messages`) has no errors on any page.

### Task 3: Query count

- [x] On `/transfers`, `/funding-agreements`, `/donors`, `/proposals` with 100 rows per page, enable the query log in the preview script (`DB::enableQueryLog()` before handling the request, `count(DB::getQueryLog())` after). Expected: the count does not grow with the number of rows (no N+1). If it does, add the missing `with()`; `Model::shouldBeStrict()` will also have thrown on lazy loading in tests.

### Task 4: Full affected test run

- [x] Run every test file the phases touched, in one command:

```
.\php.cmd artisan test --compact tests/Feature/Models/TransfersTrackingTest.php tests/Feature/AgreementMoneyTest.php tests/Feature/TranchesTest.php tests/Feature/CsvExportTest.php tests/Feature/FinanceRegisterTraitTest.php tests/Feature/FinanceKitComponentsTest.php tests/Feature/TrancheComponentsTest.php tests/Feature/AgreementComponentsTest.php tests/Feature/TransferRegisterTest.php tests/Feature/TransferTrackingTest.php tests/Feature/FundingAgreementRegisterTest.php tests/Feature/FundingAgreementTest.php tests/Feature/AccountMoneyTest.php tests/Feature/Admin/AccountRegisterTest.php tests/Feature/DonorRegisterTest.php tests/Feature/DonorDirectoryTest.php tests/Feature/ProposalRegisterTest.php tests/Feature/ProposalPipelineTest.php tests/Feature/ProposalDetailTest.php tests/Feature/ProposalDetailActionsTest.php tests/Feature/FundingDetailTablesTest.php tests/Feature/FundingWorkflowTest.php tests/Feature/GanttTimelineTest.php tests/Feature/AccessControl tests/Feature/LocalizationTest.php tests/Feature/GuidedTourTest.php
```

Expected: PASS. Then tell the user the full suite is theirs to run (`.\php.cmd artisan test --compact`, about 10 minutes) — do not run it.

### Task 5: Record the rules

Use the Boost `record-rule` tool (never native memory):

- [x] glob `{app/Support/AgreementMoney.php,app/Support/Tranches.php,app/Models/TransfersTracking.php}` — "Funding money has one definition": scheduled/received/outstanding/not scheduled are defined only in AgreementMoney and TransfersTracking::stateSql(); never recompute a money figure inline on a page; currencies never added.
- [x] glob `resources/views/components/funding/**` — "Register hooks": `data-register` (table only), `data-register-totals`, `data-finance-filters` (must not contain `data-register`: Overview tests assert its absence), `<th scope="col"` first, literal `sortRegister('key')`.
- [x] glob `resources/views/pages/{transfers,funding-agreements,donors,proposals}/**,resources/views/pages/admin/⚡accounts.blade.php` — "Funding pages open on the table": old visuals live in `_overview` partials behind `view=overview`; tests that assert visuals call `->set('view', 'overview')`.
- [x] glob `app/Livewire/Concerns/FiltersFinanceRegister.php` — "Register trait hooks run after the page's own mount()/updated(); the trait never owns currency; year-only needs a year."

### Task 6: Hand over

- [x] `.\php.cmd vendor/bin/pint --dirty --format agent`. Do not commit. Give the user the list of changed files per phase for review.
