# Wave 4 prompts

One prompt per item in `docs/lifecycle/wave4/README.md §2`. Run with `/lifecycle Y-NN [slice]`; the command loads `docs/lifecycle/AGENT.md`, then `docs/lifecycle/wave4/AGENT.md`, `contracts.md`, `quality-bar.md`, and the prompt. Handoffs go to `docs/lifecycle/wave4/handoffs/`.

| Order | Prompt | Owner | Size | Needs | Contract change |
|---|---|---|---|---|---|
| 1 | [Y-01](Y-01.md) Location rows with centers in the proposal form | Dev 2 (slices 1, 3) + Dev 1 (slice 2) | L · 3 slices | D-80, **D-88**; slice 2 **D-83** | §2, §7 (slice 1), §6 (slice 2) |
| — | *Human step: the team gives each row of the existing proposals its centers or marks it city-wide* | PR / MEAL | — | Y-01 | — |
| 2 | [Y-02](Y-02.md) Template independent of `projects` | Dev 1 | L · 2 slices | Y-01, **D-81, D-82, D-85, D-87, D-88, D-90** | §12.4 |
| 3 | [Y-08](Y-08.md) Per-center targets move to places | Dev 1 | L · 2 slices | Y-02, **D-85, D-88, D-90** | §12.1, §12.4 |
| 3b | [Y-10](Y-10.md) Targets grid: one target per location row | Dev 1 + Dev 2 | M · 2 slices | Y-01, Y-08, **D-90, D-91** | §12.1 |
| 4 | [Y-03](Y-03.md) Monthly report follows its template | Dev 1 | XL · 3 slices | Y-02, Y-08, **D-84, D-88, D-90** | §13, §13.1 |
| 5 | [Y-04](Y-04.md) MEAL joins a template or creates a new one | Dev 1 + Dev 2 | L · 2 slices | Y-02, Y-03, **D-86, D-88** | §12.4, §15.2 |
| 6 | [Y-05](Y-05.md) Extraction: combined filter and export | Dev 2 | L · 2 slices | Y-01, Y-03, **D-88, D-90** | §14.1 |
| 7 | [Y-06](Y-06.md) Activity calendar in the template and the report | Dev 2 | M | Y-02 | — |
| 8 | [Y-07](Y-07.md) MEAL edits the template's narrative questions | Dev 1 + Dev 2 | M · 2 slices | Y-02 | §12.4 |
| 9 | [Y-09](Y-09.md) Change request on an approved proposal | Dev 1 + Dev 2 | L · 2 slices | Y-01, Y-02, **D-89, D-89a** | §6, §7, §15.2 |

No decision is open (D-74…D-94 signed): Y-01 slice 1 starts now. Y-04 and Y-05 may run side by side (different pages). Y-02, Y-08, Y-10 and Y-03 run alone and in that order: they change the tables every report reads, and Y-03 matches template lines to targets by place.

## Stage B (Y-11…Y-18): leaving `projects`
Plan and measurements in `wave4/README.md §2`; common rules in `wave4/AGENT.md §8` (read it before any Stage B prompt). Starts after Y-03 is merged.

| Order | Prompt | Owner | Size | Needs | Contract change |
|---|---|---|---|---|---|
| 10 | [Y-11](Y-11.md) The location row carries what the project carried | Dev 1 | XL · 2 slices | Y-01, Y-03, **D-92, D-93, D-94** | §7 |
| 11 | [Y-12](Y-12.md) Milestones, tasks and the schedule move to the row | Dev 1 + Dev 2 | XL · 3 slices | Y-11 | §11.2, §12.2 |
| 11 | [Y-13](Y-13.md) Risks move to the row | Dev 1 + Dev 2 | L · 2 slices | Y-11 | §7, §8 |
| 12 | [Y-14](Y-14.md) MEAL coverage moves to places | Dev 1 + Dev 2 | L · 2 slices | Y-02, Y-03, Y-11 | §12.3, §12.4, §13, §13.1 |
| 13 | [Y-15](Y-15.md) Activity plan pieces move to the row | Dev 1 + Dev 2 | L · 3 slices | Y-06, Y-11, Y-14 | §11.2, §13 |
| 14 | [Y-16](Y-16.md) Every remaining reader of `projects` | Dev 1 + Dev 2 | XL · 4 slices | Y-11…Y-15 | §6/§7, §11, §14 |
| 15 | [Y-17](Y-17.md) The row becomes the writer; approval stops creating projects | Dev 1 | XL · 3 slices | Y-11…Y-16 | §2, §3, §5, §6, §8, §12.4 |
| 16 | [Y-18](Y-18.md) Drop `projects` | Dev 1 | XL · 3 slices | Y-17, deployed | §7, §12.1, all |

Y-12 and Y-13 may run side by side. Every Stage B task starts and ends with `php artisan locations:verify-mirror` (from Y-12 to Y-16). Y-18 is not reversible: Dev 1 runs it in production after a backup.

## How a prompt is built
Goal → Sources of truth (reading order) → Start state (checks before plan mode) → `[contract]` (verbatim text, with the D-number) → Rules → Build order (test first) → Quality bar → Definition of done → Stop and escalate → Do not.
