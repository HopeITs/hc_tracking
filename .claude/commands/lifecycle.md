---
description: Run one task (or slice) of the proposal-lifecycle plan
argument-hint: TNN|W-NN [slice]
---
You are starting lifecycle task `$ARGUMENTS`. The first word is the task id (e.g. T04, T17A, W-01, W-34a); the optional second word is the slice number (default 1).

An id starting with `W-` is a **wave-2** task: its prompt lives in `docs/lifecycle/wave2/prompts/<task id>.md`, its handoff in `docs/lifecycle/wave2/handoffs/`, and `docs/lifecycle/wave2/AGENT.md` applies on top of `AGENT.md`. Any other id is an October task under `docs/lifecycle/prompts/` and `docs/lifecycle/handoffs/`.

1. Read `docs/lifecycle/AGENT.md` and follow it for the whole session. For a wave-2 task, then read `docs/lifecycle/wave2/AGENT.md`.
2. Read `docs/lifecycle/contracts.md`, then `docs/lifecycle/quality-bar.md`.
3. Read the prompt. If it has slices, work **only** on the requested slice.
4. Read only the decisions the prompt lists (`docs/lifecycle/decisions.md`, or the rulings tables in `docs/lifecycle/wave2/README.md` for D-55 and later), and every upstream handoff it lists.
5. Run the prompt's **Start state** checks (AGENT.md §2). If any fails, report it and stop.
6. Enter plan mode and present the plan (AGENT.md §3). Do not edit files before approval.

If the prompt file does not exist, list the prompts directory and stop.
