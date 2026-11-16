# Wave 4 · session addendum to `docs/lifecycle/AGENT.md`

`/lifecycle Y-NN [slice]` loads `docs/lifecycle/AGENT.md` first, then this file, then `contracts.md`, `quality-bar.md`, the prompt. Everything in `AGENT.md`, `wave2/AGENT.md` §4–§5 and `wave3/AGENT.md` §4 still applies; this file only adds or tightens.

## 1. Sources of truth for wave 4
1. `docs/lifecycle/contracts.md` (frozen, as before).
2. `docs/lifecycle/decisions.md` D-01…D-54, the rulings in `wave2/README.md` (D-55…D-68), the signed rows of `wave3/README.md §1`, and the **Signed** rows of `docs/lifecycle/wave4/README.md §1` (D-74 and later). A row marked **Open** is not a source: a prompt that depends on it stops at Start state and reports which ruling is missing.
3. The task prompt `docs/lifecycle/wave4/prompts/Y-NN.md`.
4. Upstream handoffs: `handoffs/TNN.md`, `wave2/handoffs/W-NN.md`, `wave3/handoffs/X-NN.md`, `wave4/handoffs/Y-NN.md`.
5. `CLAUDE.md` and the matching `.ai/rules` files.

Where a wave 4 ruling contradicts an earlier one (D-76 against D-69, for example), the wave 4 ruling wins **only once it is Signed**.

## 2. The `projects` rule (D-74), checked in review
- Add **no** foreign key to `projects`, no `project_id` column, and no new `use App\Models\Project;` in a file wave 4 creates.
- Read no place, proposal link or person through `projects` or `project_proposal`. The single exception is `App\Support\Reporting\PlaceBridge` (created in Y-02): it is the only wave 4 class that may name `Project`, and every method on it says in its PHPDoc which later task removes it.
- Existing `project_id` columns are **kept and still written** where an untouched reader needs them. Never drop one, never stop filling one, without a prompt that says so.
- `tests/Unit/WaveFourProjectsGuardTest.php` (created in Y-02) fails when a wave 4 file names `Project` outside the bridge. Do not add a file to its allow-list without Dev 1.

**Stage B (D-92, D-93).** The table is dropped at the end of wave 4 (Y-18). That does not relax this section for Y-01…Y-10: keep writing the legacy columns until the prompt that removes them. From Y-11 on, a prompt names the module it moves to the location row and the columns it stops writing.

## 3. Contract changes are allowed only where a prompt says so
The prompt's **`[contract]`** section is the only text you may add to `contracts.md`, in the same PR, as the first commit the developer reviews. Anything else is an escalation (`AGENT.md §6`).

## 4. Migrations on tables that hold reports
- Additive first: add the new column nullable, backfill, then tighten in a **second** migration of the same slice. Never one migration that adds, fills and constrains.
- Every data migration has a `--dry-run` twin: an Artisan command that prints the counts the migration will write and writes nothing. Paste its output on local data into the handoff.
- A row that cannot be mapped is **reported and left alone**, never guessed (D-80).
- `down()` must work on a database that already holds backfilled rows.

## 5. Branches and ClickUp
- Branch from `main`: `wave4/Y-NN-<slug>` (reuse it for a later slice).
- Put the ClickUp task URL in the PR description and the handoff header. Do not change the ClickUp task yourself.

## 6. Tests on this machine
`php -d memory_limit=1536M vendor/bin/pest --compact <files>`, only the files the prompt names. The developer runs the suite in batches before merging.

## 7. Finish
`AGENT.md §9`, with the handoff written to `docs/lifecycle/wave4/handoffs/Y-NN.md` from `docs/lifecycle/wave4/handoffs/_TEMPLATE.md`.

## 8. Stage B rules (Y-11…Y-18, D-92, D-93, D-94)
Stage B moves everything off `projects` and ends by dropping it. These rules apply to every Stage B prompt and are not repeated in them.

1. **One mapper.** A project is turned into a location row only through `App\Support\Locations\ProjectSuccessor` (created in Y-11): `rowFor($projectId, $date)` implements D-94 (the row whose proposal period covers the date, else the row of the most recent proposal). No backfill invents its own rule. A record that cannot be mapped is listed and left, never guessed (D-80).
2. **Mirror first, flip later.** From Y-11 to Y-17 `projects` is still the writer and `ProjectLocationMirror` keeps the rows in step. `php artisan locations:verify-mirror` must exit 0 at the start and at the end of Y-12…Y-16; paste both. Y-17 makes the row the only writer; Y-18 drops the table.
3. **Legacy columns are still written until Y-18.** A task that adds `proposal_location_id` keeps writing the old `project_id` (from the row's `project_id`) so modules not yet moved keep working. Only Y-17 (null for new rows) and Y-18 (drop) change that.
4. **A row is of one proposal (D-93).** Nothing is shared between proposals: a milestone, a task, a risk, a file or a status belongs to exactly one row. Things that describe the place and carry no date (sectors, working days, targets of beneficiaries) are copied to each row; things with a date go to one row by D-94.
5. **Names.** The user-facing word stays "Project" / «مشروع»: one entry on the Projects screen is one location row. Route names (`projects.*`), permission keys (`projects.*`), notification keys (`project.*`), the enum `ProjectStatus` and `lang/*/projects.php` keep their names: they name a screen, an ability and a state, not the table. Do not rename one without a prompt that says so.
6. **Tests you touch move.** When a Stage B task edits a test file, that file stops building `Project::factory()` and builds a `ProposalLocation` with its proposal. Y-18 slice 1 converts whatever is left. No test is deleted without Dev 1's approval in the PR.
7. **Every backfill has its dry run** (`§4`), prints the counts decided "by date" and "by most recent" separately, and its output on local data goes in the handoff.
8. **Figures do not move.** A task that touches a number a user sees (matrix totals, donor report lines, rollups, counts on cards) records it before and pastes it after.
