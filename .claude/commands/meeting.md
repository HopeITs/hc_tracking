---
description: Run one task of the Rami meeting plan (M-01…M-06)
argument-hint: M-NN [slice]
---
You are starting meeting task `$ARGUMENTS`. The first word is the task id (M-01…M-06); the optional second word is the slice number (default 1).

The prompt lives in `docs/lifecycle/meeting/prompts/<task id>.md`, the handoff goes to `docs/lifecycle/meeting/handoffs/<task id>.md`, and `docs/lifecycle/meeting/README.md` holds the order and the meeting's decisions.

1. Read `docs/lifecycle/AGENT.md` and follow it for the whole session (this plan has no contracts of its own: where a prompt touches `docs/lifecycle/contracts.md`, treat the file as frozen and escalate).
2. Read `docs/lifecycle/meeting/README.md`, then `docs/lifecycle/quality-bar.md`.
3. Read the prompt. If it has slices, work **only** on the requested slice. Read the `.ai/rules` files and skills it lists (CLAUDE.md requires the rules index first).
4. Read every upstream handoff the prompt lists.
5. Run the prompt's **Start state** checks. If any fails, report it and stop.
6. Enter plan mode and present the plan (AGENT.md §3). Do not edit files before approval.

If the prompt file does not exist, list the prompts directory and stop.
