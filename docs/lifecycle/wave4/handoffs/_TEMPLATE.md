# Handoff · Y-NN · <title>

Branch: `wave4/Y-NN-<slug>` · Owner: <dev> · Date: <yyyy-mm-dd> · Status: done | in progress (slice k/n) · ClickUp: <url>

## Delivered contracts (exactly as on the branch)
Class FQCNs, method signatures and return types, enum cases and values, table and column names, permission/ability names, lang keys. Copy the real code, not a paraphrase.

## Deviations from contracts.md / the prompt
Each one: what, why, and who approved it (Dev 1). An empty list is the goal.

## The `projects` rule (D-74)
Every place this slice still reads or writes `projects` / `project_id`, why it must, and the later task that removes it. "None" is the goal outside `PlaceBridge`.

## Tests
Files added or updated, plus the pasted `pest --compact` summary line.

## Migrations and commands
Names, `up`/`down` behaviour, pre-checks, `--dry-run` output on local data, and the rows that were reported and left alone.

## For downstream tasks
- Y-NN: what they can now rely on, and how to call it.

## Follow-ups (not done here)
- …

## In progress (only when the session stopped early)
What's done, what's next, and which test is red.
