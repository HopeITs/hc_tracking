# Wave 4 prompts

One prompt per item in `docs/lifecycle/wave4/README.md §2`. Run with `/lifecycle Y-NN [slice]`; the command loads `docs/lifecycle/AGENT.md`, then `docs/lifecycle/wave4/AGENT.md`, `contracts.md`, `quality-bar.md`, and the prompt. Handoffs go to `docs/lifecycle/wave4/handoffs/`.

| Order | Prompt | Owner | Size | Needs | Contract change |
|---|---|---|---|---|---|
| 1 | [Y-01](Y-01.md) Location rows with centers in the proposal form | Dev 2 (slices 1, 3) + Dev 1 (slice 2) | L · 3 slices | D-80, **D-88**; slice 2 **D-83** | §2, §7 (slice 1), §6 (slice 2) |
| — | *Human step: the team gives each row of the existing proposals its centers or marks it city-wide* | PR / MEAL | — | Y-01 | — |
| 2 | [Y-02](Y-02.md) Template independent of `projects` | Dev 1 | L · 2 slices | Y-01, **D-81, D-82, D-85, D-87, D-88** | §12.4 |
| 3 | [Y-08](Y-08.md) Per-center targets move to places | Dev 1 | L · 2 slices | Y-02, **D-85, D-88** | §12.1, §12.4 |
| 4 | [Y-03](Y-03.md) Monthly report follows its template | Dev 1 | XL · 3 slices | Y-02, Y-08, **D-84** | §13, §13.1 |
| 5 | [Y-04](Y-04.md) MEAL joins a template or creates a new one | Dev 1 + Dev 2 | L · 2 slices | Y-02, Y-03, **D-86** | §12.4, §15.2 |
| 6 | [Y-05](Y-05.md) Extraction: combined filter and export | Dev 2 | L · 2 slices | Y-01, Y-03 | §14.1 |
| 7 | [Y-06](Y-06.md) Activity calendar in the template and the report | Dev 2 | M | Y-02 | — |
| 8 | [Y-07](Y-07.md) MEAL edits the template's narrative questions | Dev 1 + Dev 2 | M · 2 slices | Y-02 | §12.4 |
| 9 | [Y-09](Y-09.md) Change request on an approved proposal | Dev 1 + Dev 2 | L · 2 slices | Y-01, Y-02, **D-89, D-89a** | §6, §7, §15.2 |

No decision is open (D-74…D-89a signed): Y-01 slice 1 starts now. Y-04 and Y-05 may run side by side (different pages). Y-02, Y-08 and Y-03 run alone and in that order: they change the tables every report reads, and Y-03 matches template lines to targets by place.

## How a prompt is built
Goal → Sources of truth (reading order) → Start state (checks before plan mode) → `[contract]` (verbatim text, with the D-number) → Rules → Build order (test first) → Quality bar → Definition of done → Stop and escalate → Do not.
