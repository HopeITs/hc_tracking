# Wave 2 prompts

One prompt per ClickUp task in **PT New**. Run with `/lifecycle W-NN [slice]`; the command loads `docs/lifecycle/AGENT.md`, then `docs/lifecycle/wave2/AGENT.md`, `contracts.md`, `quality-bar.md`, and the prompt. Handoffs go to `docs/lifecycle/wave2/handoffs/`.

| Order | Prompt | Owner | Size | Needs | Contract change |
|---|---|---|---|---|---|
| 1 | [W-01](W-01.md) Host center | Dev 1 | M | — | §3, §7 |
| 2 | [W-02](W-02.md) Add center form, inactive pause | Dev 2 | M · 2 slices | W-01 | — |
| 3 | [W-34a](W-34a.md) Location rows, projects on approval | Dev 1 + Dev 2 | XL · 3 slices | W-01 | §2, §5, §6, §7, §15.2 (from `W-34.md`) |
| 4 | [W-10](W-10.md) Form order | Dev 2 | M | W-34a s3 | — |
| 5 | [W-24](W-24.md) Concept note | Dev 2 | S | W-10 | §7 |
| 6 | [W-04](W-04.md) Donor request + GM approval | Dev 1 | M | W-10 | §7, §8, §15.2 |
| 7 | [W-35](W-35.md) Responsible retired alert | Dev 2 | S | W-34a s1 | §15.2 |
| 8 | [W-06](W-06.md) On hold flag (+ W-25) | Dev 1 + Dev 2 | L · 2 slices | — | §1, §2, §4, §6, §7 |
| 9 | [W-07](W-07.md) Negotiation notes | Dev 2 | S | — | §6, §7 |
| 10 | [W-08](W-08.md) Extension | Dev 1 | M | W-07 | §6, §7, §15.2 |
| 11 | [W-33](W-33.md) Extension alert | Dev 2 | M | W-08 | §9, §15.2 |
| 12 | [W-12](W-12.md) Stage task templates | Dev 1 | M · 2 slices | — | §6, §7, §8, §15.2 |
| 13 | [W-13](W-13.md) Report tasks | Dev 2 | M | — | §6, §12.2, §15.2 |
| 14 | [W-14](W-14.md) Tasks scope by HR job level | Dev 2 | S | — | — |
| 15 | [W-09](W-09.md) Risk library in the logframe | Dev 2 | L · 2 slices | — | §6, §7, §8, §15.2 |
| 16 | [W-32](W-32.md) Payroll proposal kind | Dev 1 + Dev 2 | L · 2 slices | W-06 | §1, §2, §6, §7 (Dev 1 writes it first) |
| 17 | [W-29](W-29.md) Sections and borders | Dev 2 | L · 3 slices | W-28 (done) | — |
| 18 | [W-27](W-27.md) To-do panel | Dev 2 | S | — | — |
| — | [W-25](W-25.md) Stepper | — | — | folded into W-06 slice 2 | — |
| — | [W-03](W-03.md) Auto number | Dev 1 | M | **D-56** | blocked |
| — | [W-34b](W-34b.md) Tracking-owned programs | Dev 1 | L | **D-68** | blocked |

Two sessions may run in parallel when neither prompt lists the other under "Needs" and they touch different modules (for example W-06 with W-12, or W-13 with W-14).

## How a prompt is built
Goal (the outcome, not the steps) → Sources of truth (exact files, in reading order) → Start state (checks that must pass before plan mode) → `[contract]` (verbatim text for `contracts.md`, with the ruling that authorises it) → Rules (server-side, one definition per rule) → Build order (test file first) → Quality bar additions → Definition of done (named test files, pasted output) → Stop and escalate → Do not.
