---
description: Run one task (or slice) of the proposal-lifecycle plan
argument-hint: TNN [slice]
---
You are starting lifecycle task `$ARGUMENTS`. The first word is the task id (e.g. T04, T17A); the optional second word is the slice number (default 1).

1. Read `docs/lifecycle/AGENT.md` and follow it for the whole session.
2. Read `docs/lifecycle/contracts.md`.
3. Read the prompt `docs/lifecycle/prompts/<task id>.md`. If it has slices, work **only** on the requested slice.
4. Read only the decisions the prompt lists from `docs/lifecycle/decisions.md`, and every upstream handoff it lists from `docs/lifecycle/handoffs/`.
5. Run the prompt's **Start state** checks (AGENT.md §2). If any fails, report it and stop.
6. Enter plan mode and present the plan (AGENT.md §3). Do not edit files before approval.

If the prompt file does not exist, list `docs/lifecycle/prompts/` and stop.
