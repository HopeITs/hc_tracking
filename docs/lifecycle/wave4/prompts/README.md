# Wave 4 prompts

One prompt per item in `docs/lifecycle/wave4/README.md §2`. Run with `/lifecycle Y-NN [slice]`; the command loads `docs/lifecycle/AGENT.md`, then `docs/lifecycle/wave4/AGENT.md`, `contracts.md`, `quality-bar.md`, and the prompt. Handoffs go to `docs/lifecycle/wave4/handoffs/`.

| Order | Prompt | Owner | Size | Needs | Contract change |
|---|---|---|---|---|---|
| 1 | [Y-01](Y-01.md) Location rows with centers in the proposal form | Dev 2 (slices 1, 3) + Dev 1 (slice 2) | L · 3 slices | D-80; slice 2 **D-83** | §6 (slice 2) |
| — | *Human step: the team enters the centers of the existing proposals* | PR / MEAL | — | Y-01 | — |
| 2 | [Y-02](Y-02.md) Template independent of `projects` | Dev 1 | L · 2 slices | Y-01, **D-81, D-82, D-85** | §12.4 |
| 3 | [Y-03](Y-03.md) Monthly report follows its template | Dev 1 | XL · 3 slices | Y-02, **D-84** | §13, §13.1 |
| 4 | [Y-04](Y-04.md) MEAL joins a template or creates a new one | Dev 1 + Dev 2 | L · 2 slices | Y-02, Y-03, **D-86** | §12.4, §15.2 |
| 5 | [Y-05](Y-05.md) Extraction: combined filter and export | Dev 2 | L · 2 slices | Y-01, Y-03 | §14.1 |
| 6 | [Y-06](Y-06.md) Activity calendar in the template and the report | Dev 2 | M | Y-02 | — |
| 7 | [Y-07](Y-07.md) MEAL edits the template's narrative questions | Dev 1 + Dev 2 | M · 2 slices | Y-02 | §12.4 |

Y-01 slice 1 needs no open decision: start now. Y-04 and Y-05 may run side by side (different pages). Y-02 and Y-03 run alone: they change the tables every report reads.

## How a prompt is built
Goal → Sources of truth (reading order) → Start state (checks before plan mode) → `[contract]` (verbatim text, with the D-number) → Rules → Build order (test first) → Quality bar → Definition of done → Stop and escalate → Do not.
