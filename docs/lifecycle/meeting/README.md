# Rami meeting plan (M-01…M-06)

Run with `/meeting M-NN [slice]`. Each prompt follows the lifecycle prompt shape (Goal → Sources → Start state → Rules → Build order → Definition of done → Stop and escalate → Do not). Branch from `main`: `meeting/M-NN-<slug>`. Put the ClickUp URL in the PR and the handoff header; do not change the ClickUp task yourself.

## Decisions from the meeting (signed by the user, 2026-11-23)
1. MEAL as a team needs a comfortable place to add the logical framework: a standalone page (one table per proposal, proposal picker on top) **and** the proposal's Logical Framework tab showing the same table.
2. Active Reports covers every report type (monthly, donor, existing reports) in one table.
3. A measurement (reading) is recorded inside the monthly report, with the permissions the site has today.
4. The Targets and Indicators pages go away; their jobs move into the logframe row and the monthly report.
5. Add-indicator is one form everywhere, and it is the indicator-library entry dialog (form B): adding an indicator is a MEAL-team job (and the CEO, who holds every permission); permissions are unchanged. The name is Arabic + English, picked from the list of existing names to prevent duplicates.
6. Every complex screen becomes a table with fixed, scrollable column names. One search and one filter stay visible; the rest sit behind "More filters".
7. Risks and assumptions can exist without being tied to an outcome/output/input, as activities can.
8. Failed requests show a plain message, not a 500; the user's role shows next to their name.

## Order
| Order | Prompt | ClickUp | Size | Needs |
|---|---|---|---|---|
| 1 | [M-01](prompts/M-01.md) Unified add-indicator form + MEAL logframe table | [task](https://app.clickup.com/t/123jdbau1qh) | L · 2 slices | — |
| 2 | [M-02](prompts/M-02.md) Active Reports: one table | [task](https://app.clickup.com/t/123jdbau1qj) | M | — |
| 3 | [M-03](prompts/M-03.md) Standalone risks and assumptions | [task](https://app.clickup.com/t/123jdbau1qk) | M | M-01 slice 2 |
| 4 | [M-04](prompts/M-04.md) Retire Targets and Indicators; readings in the monthly report | [task](https://app.clickup.com/t/123jdbau1qm) | L · 2 slices | M-01 |
| 5 | [M-05](prompts/M-05.md) Friendly errors + role beside the name | [task](https://app.clickup.com/t/123jdbau1qn) | S | — |
| 6 | [M-06](prompts/M-06.md) Site-wide simplification pass | [task](https://app.clickup.com/t/123jdbau1qp) | L · per page | M-01…M-04 |

M-02 and M-05 touch different files from the rest and may run beside any other task.

## Handoff
`handoffs/M-NN.md`: ClickUp URL, what changed (files), tests run with their result, what you did not verify, follow-ups.
