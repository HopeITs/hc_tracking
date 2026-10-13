# Task 32 — Strategic pillars and program reference frameworks

> **المهمة:** المحاور الاستراتيجية (الحد الأدنى) واستيراد الأطر المعيارية للبرامج
> **المطوّر:** B4 (مراجعة Dev 1 للـ backend وDev 2 للواجهة) · **الفريق:** B · **الأيام:** 6–7 (الاثنين 2026-10-19 ← الثلاثاء 2026-10-20) · **الجهد:** يومان · **يعتمد على:** Task 01 (D-35، D-37)، Task 11، Task 12 (CP2)
> **متى تستخدمه:** بعد CP2 (نهاية اليوم 6): `ResultType::Activity` و`ResultType::CrossCutting` و`results.proposal_id` و`copied_from_id` من Task 12 مدموجة، وحقول المراجعة 3 في `library_indicators` من Task 11 مدموجة، وملفات JSON لإطار Micro راجعها MEAL. أُضيفت في المراجعة 3 (2026-10-09).
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 32 of the HC Tracking proposal-lifecycle correction plan in this Laravel 13 / PHP 8.5 / Livewire 4 / Flux UI 2 repository. The outcome:
1. The 2026–2030 strategy is split into its three **strategic pillars** (economic empowerment & family support; youth & student empowerment; childhood development & positive parenting), and every program can be assigned to one pillar.
2. The real **Micro** program logical framework (two tracks: urban, and agricultural/livestock; 37 indicators) is imported into the Indicator Library and the program's Results Framework from committed JSON data files.
3. A reusable `App\Actions\Results\CopyResultTree` copies a program framework root into a proposal, driven by a **Start from Program Framework** button on the proposal's **Logical Framework** tab. Task 10 reuses it for renewals.

Strategic objectives, yearly strategic targets and the strategy scorecard are **Task 32b, out of the October window** — do not build them.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (Laravel Boost rules, Pest, Pint, `php artisan make:* --no-interaction`, no new dependencies or base folders).
2. The plan `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`: section `### Task 32` in full, section 1.0 facts 10–12, the «تعديلات المراجعة 3» subsections of Tasks 11, 12, 13 and 10, `### Task 32b` (to know what NOT to build), section 9 rows D-35 and D-37. The plan is Arabic and authoritative; English identifiers in it are exact.
3. The decision log `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists. A recorded D-35 (program → pillar mapping) or D-37 (default-target meanings) overrides the plan's default.
4. Rule files: `.ai/rules/strategies.md` (programs table paginated; totals from `allPrograms()`), `.ai/rules/results.md`, `.ai/rules/views-components-ui.md` (chain enforced in forms), `.ai/rules/observers.md`, `.ai/rules/concerns.md`, `.ai/rules/concerns-factories.md` (HR is read-only, never copied), `.ai/rules/models.md`, `.ai/rules/database-migrations.md`, `.ai/rules/migrations.md`, `.ai/rules/feature.md`, `.ai/rules/console-commands.md`, `.ai/rules/database-seeders.md`, `.ai/rules/seeders.md`, `.ai/rules/factories.md`, `.ai/rules/pages.md`, `.ai/rules/ui.md`, `.ai/rules/views.md`, `.ai/rules/views-enums.md`, `.ai/rules/tests.md`. Then run `grep -rin 'strateg\|program\|framework\|copied' .ai/rules`.
5. Existing code to study first: `app/Models/Strategy.php`, `app/Models/Program.php` (`syncFromHr()`, `strategy()`), `app/Models/Result.php`, `app/Models/Indicator.php`, the Task 11 `LibraryIndicator` model and `meal:seed-indicator-library` command, the Task 12 `ResultObserver` changes, `resources/views/pages/strategies/⚡show.blade.php`, the proposal **Logical Framework** tab from Task 12, `app/Console/Commands/SyncPermissions.php` (`--dry-run`, `Activity::withoutRecording` + `DB::transaction`), `config/access-control.php` (`strategies`, `results`).
6. Source documents (outside the repo, for the data files only): `D:\PT\Stratigy.pptx` and `D:\PT\micro logic.xlsx`. They are **not** read at runtime.
7. Skills to activate: `laravel-best-practices`, `livewire-development`, `fluxui-development`, `database-migrations`, `laravel-security`, `testing-best-practices`.
8. Use Laravel Boost `search-docs` before framework APIs you are unsure of (Artisan arguments/options, `insert()` with timestamps, `Rule::exists` with scopes). Use `database-schema` on `programs`, `strategies`, `results`, `indicators`, `library_indicators` before writing migrations.

## Preconditions to verify
- Task 12 merged: `ResultType::CrossCutting`, `ResultType::Activity`, `results.proposal_id`, `results.copied_from_id`, `results.contributes_to_result_id`.
- Task 11 merged with Revision 3 fields: `calculation_method`, `numerator_label`, `denominator_label`, `collected_by`, `frequency_note`, `follow_up_months`, `is_legacy`, `IndicatorMeasure::Average`.
- The JSON data files are approved by the MEAL manager (D-37). If not, build against the drafts and report it.
If any precondition fails, stop and report it instead of building around it.

## Revisions 6–8 changes (2026-10-12)
Source: decision-workshop answers (R6 file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.docx`) and two real M&E workbooks (`Syria Cross e.V._ME_Tool_IPTWP_V1_2026_Aug.xlsx`, `Children University - IPTT - 2026.xlsx`). Read the plan header paragraphs «المراجعة 6/7/8», decision rows D-35, and the «تعديلات المراجعة 6, 7 and 8» subsections of this task in the plan. Open questions still running on their documented default are listed in `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r7.ar.docx`. Where this block conflicts with text further down this prompt, this block wins.
- D-35/D-35a confirmed: HR adds the missing programs (wells, alternative energy, PSS & case management, parenting). DISC = Development Impact and Support Center. Pillar 1: Micro Project, Relief Programs, Family to Family; Pillar 2: Study Zone, LEAP, Entrepreneurship project, DISC; Pillar 3: Children University. New programs stay «no pillar» until MEAL links them.
- Optional import sources: the Logframe sheet of `Children University - IPTT - 2026.xlsx` (19 Arabic indicators with definition, calculation, timing, source, responsible) and the Indicator Performance Tracker of the Syria Cross workbook — imported as proposal frameworks (not program frameworks), with a conflicts report. *(Superseded by D-54 in Revision 9: these two files are design references only and are not imported in the October rehearsal.)*

## Revision 9 changes (2026-10-12)
Source: the corrected R6 answers file `docs/superpowers/specs/2026-10-12-proposal-lifecycle-open-questions-r6.ar.fixed.docx`, which is newer than the R7 questions file and replaces the defaults Revision 8 applied. Read the plan header paragraph «المراجعة 9», the decision rows in section 9 (and «الأسئلة المتبقية بعد المراجعة 9»), and the «تعديلات المراجعة 9» subsection of this task. **Where this block conflicts with any text further down, including the Revisions 6–8 block, this block wins.**
- **D-35a (replaces Revision 8):** pillar 1 = Micro Project, Relief Programs, Family to Family, **Wells**, **Alternative Energy**; pillar 2 = Study Zone, LEAP, Entrepreneurship project, DISC; pillar 3 = Children University, **PSS & Case Management**, **Parenting**. The four new programs are added by HR; map them by HR id when present and list missing ones in the command report.
- **D-10a on import:** every indicator of an imported program framework becomes a library entry of that program; `level_ref` comes from the node type and number (D-10b: `G`, `OC`, `OP`, `AC`, `CC`) and the code is generated as in Task 11. Micro's two tracks share the program code; serials continue within the level so codes never collide, and the same indicator in both tracks (same name and measure) is one entry. **D-54:** the Syria Cross / Children University files are not imported in the rehearsal (design reference only); if imported later, their frameworks link to their program's library (Children University = HR program `008`); unmatched indicators get new entries, and the file's original codes (`O1.1`, `Op1.2a`) are kept in `data_source`.
- Tests: pillar mapping as listed; two-track import never duplicates a code; a shared indicator is one entry.

## Scope
### In scope
- Migrations: `strategic_pillars` (`id`, `strategy_id` FK `restrictOnDelete`, `code`, `name_ar`, `name_en` nullable, `goal_ar` nullable text, `goal_en` nullable text, `sort_order`, timestamps; unique `(strategy_id, code)`); `programs.strategic_pillar_id` (FK `nullOnDelete`, nullable); `indicators.is_tracking_only` (boolean, default false); `library_indicators.imported_at` (nullable timestamp).
- Model `StrategicPillar` (factory, `TracksActivity`), `Strategy::pillars()`, `Program::pillar()`, `StrategicPillarPolicy` on `strategies.view/update`.
- Actions: `SaveStrategicPillar`, `DeleteStrategicPillar` (refuses while programs are assigned), `AssignProgramToPillar` (pillar must belong to the program's strategy; a program without a strategy takes the pillar's), `CopyResultTree`.
- Data files under `database/seeders/data/`: `strategy-2026-2030.json` (pillars, goals, objective texts and yearly targets kept for Task 32b — only pillars are imported now) and `logframes/micro-urban.json`, `logframes/micro-agri.json` with explicit per-indicator fields: `code`, `level`, `parent`, `statement_ar`, `name_ar`, `definition`, `calculation_method`, `numerator_label`, `denominator_label`, `measure`, `unit`, `frequency`, `frequency_note`, `follow_up_months`, `collected_by`, `default_target` (raw), and per output an `activities` list.
- Commands: `strategy:import-pillars {file} {--strategy=} {--dry-run}` and `meal:import-framework {file} {--program=} {--dry-run}`.
- **Strategy** page: a **Pillars** section, programs grouped by pillar with a «بلا محور» badge, modals to create/edit a pillar and assign a program.
- Proposal **Logical Framework** tab: **Start from Program Framework** (pick a root = track from the programs of the proposal's projects).
- Translations (ar/en) for every new string.
### Out of scope
- `strategic_objectives`, `strategic_targets`, scorecards (Task 32b). Annual plans (Task 24). Writing to any HR table. Reading `.xlsx`/`.pptx` at runtime or adding a spreadsheet package.

## Business rules
- One pillar per program at most, from the program's own strategy: «المحور من استراتيجية أخرى».
- A pillar with programs cannot be deleted; move its programs first.
- D-35a mapping (decided, Revision 9), seeded by `strategy:import-pillars`: pillar 1 = Micro Project, Relief Programs, Family to Family, Wells, Alternative Energy; pillar 2 = Study Zone, LEAP (Leadership Empowerment & Achievement Program), Entrepreneurship project, DISC (Development Impact and Support Center); pillar 3 = Children University, PSS & Case Management, Parenting. Programs not yet in HR are listed in the command report. Hope Center, Earthquake Assistance, School to School, Homework Room, Sha8er and CYG stay unassigned. Match programs by HR id, never by name text.
- Library codes come only from Task 11's generator (`{program code}.{level}.{serial}`, e.g. `002.OP1.2.3`); the Revision 3 track prefixes (`MIC-U.G1`, `MIC-A.G1`) are dropped by Revision 9. Imports are idempotent by a stable match, not by the generated code: propose in plan mode (recommended: program + normalized Arabic name + measure, with the file's own code and track kept in `data_source`). Update an entry from the file unless it was edited by hand after the last import (`updated_at > imported_at`), in which case report a conflict and leave it.
- Default targets (D-37):

  | In the source | Becomes |
  |---|---|
  | `0.8` | `80` with measure `percentage` |
  | `-` | `target` NULL, `is_tracking_only = true` |
  | `**` | `target` NULL (set per proposal) |
  | `زيادة 20%` | `20`, `percentage`, note "change from baseline" |
- The activities column is split on `+` into `activity` results under the matching output, without indicators.
- Goal = `impact` root; the agricultural sheet's Cross-Cutting rows become a `cross-cutting` root with CC1, CC2, CC4.
- `CopyResultTree(Result $sourceRoot, Proposal $proposal)`: copies the subtree and its indicators (not `plan_indicators`, not reports) into the proposal's framework, sets `copied_from_id` on every copied result and indicator, and points the new root's `contributes_to_result_id` at the source root (not for `cross-cutting`). Allowed only before `submitted` (Task 12 lock). The same source root cannot be copied twice into one proposal: «هذا الإطار مستورد مسبقًا في المقترح».

## Security and authorization
- Pillar CRUD and program assignment: `Gate::authorize` on `strategies.update` inside each Action; viewing on `strategies.view`.
- Commands are console-only; no web route.
- Copy: `results.create` plus proposal visibility. Re-check on the server that `sourceResultId` is a root in a **program** framework (`proposal_id IS NULL`, `parent_id IS NULL`) whose program is one of the proposal's projects' programs. Any other id → reject (BOLA).
- `#[Fillable]` lists exclude `strategy_id`/`strategic_pillar_id` where they must come from the Action, not the request.

## Validation
- `name_ar`: required, max:255. `name_en`: nullable, max:255. `goal_ar`/`goal_en`: nullable, max:2000. `code`: required, max:20, unique per strategy.
- `strategic_pillar_id`: nullable, exists, same strategy as the program.
- `sourceResultId`: required, a permitted root — «اختر إطارًا من برامج مشاريع هذا المقترح».

## Edge cases and concurrency
- A program present in HR but missing locally: call `Program::syncFromHr()` before assignment, as existing code does.
- Placeholder Micro results already in the dev database (results 3, 4, 29–31): the import neither touches nor deletes them; list them in the command summary.
- Multi-program proposal: one root per program can be copied.
- Two users clicking **Start from Program Framework** at once: lock the proposal row (`lockForUpdate`) and re-check the "already imported" rule inside the transaction.

## Data integrity
- Each import runs in one transaction per file under `Activity::withoutRecording`, and prints created / updated / conflicts.
- `CopyResultTree` runs in one transaction with batched inserts; its query count must not grow with the number of nodes.

## Implementation guidance
- Follow `.ai/rules/strategies.md`: group by pillar from `allPrograms()`, never from the paginated `programs()`.
- Prepare the JSON files once from the source documents (a throwaway script outside the repo is fine) and let MEAL review them; commit only the JSON.
- Leave `name_en` empty in imported entries; MEAL completes it in **Indicator Library**.

## Tests (Pest)
- Assigning a program to a pillar of another strategy is rejected; deleting a pillar with programs is rejected.
- `--dry-run` writes nothing; running each import twice creates nothing new.
- `micro-urban.json` creates its 16 indicator nodes and activity nodes under their outputs; `micro-agri.json` creates 21, three of them under a `cross-cutting` root; every library entry gets a generated `002.…` code, an indicator present in both tracks (same name and measure) is one entry, and no code repeats.
- Default targets map per the table above, including `is_tracking_only`.
- `CopyResultTree`: sets `copied_from_id`, copies no targets, refuses the same root twice, refuses a root from an unrelated program, refuses after `submitted`; constant query count for 3 and 30 nodes.
- 403 without `strategies.update` / `results.create`.
- The **Strategy** page lists pillars with their programs and shows unassigned programs.

## Acceptance criteria
- The **Strategy** page shows the three pillars and their programs; unassigned programs are visible.
- Micro's two frameworks appear in **Results Framework**, and their 37 indicators in **Indicator Library**.
- MEAL starts a Micro proposal framework in one click and edits it independently of the program framework.

## Do not
- Add a spreadsheet dependency or parse text heuristically at runtime.
- Type, prefix or derive library codes yourself; every code comes from Task 11's generator.
- Copy targets or reports with a framework.
- Write a second copy routine for Task 10.
- Build strategic objectives, yearly targets or a scorecard (Task 32b).
- Write to HR tables.

## Quality bar (this task)
- **Pillars** on **Strategy**: one card per pillar listing its programs, a «بلا محور» group, assignment through a modal.
- **Start from Program Framework**: pick the root (track) with a preview count of nodes and indicators before copying, then a confirm modal; the import commands print created / updated / conflict tables.
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
Your final message must contain: a summary; files changed; migrations added; tests run with pass/fail output; decisions assumed (D-35 mapping actually applied, D-37 meanings); the import summaries (created / updated / conflicts) for both JSON files. Handoff facts for Task 10: the `CopyResultTree` signature and what it skips. For Task 13: how a copied indicator exposes its default target. For Task 18: the relation path from a project to its pillar. For Task 27: the exact import commands and their order.
````
