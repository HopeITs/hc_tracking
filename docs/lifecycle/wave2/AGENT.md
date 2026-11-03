# Wave 2 · session addendum to `docs/lifecycle/AGENT.md`

`/lifecycle W-NN [slice]` loads `docs/lifecycle/AGENT.md` first, then this file, then `contracts.md`, `quality-bar.md`, the prompt. Everything in `AGENT.md` still applies; this file only adds or tightens.

## 1. Sources of truth for wave 2
1. `docs/lifecycle/contracts.md` (frozen, as before).
2. `docs/lifecycle/decisions.md` D-01…D-54 **plus** the two "Management rulings" tables in `docs/lifecycle/wave2/README.md` (D-55…D-67). Those tables are final; the "Decisions needed first" table above them is history, not a source.
3. The task prompt `docs/lifecycle/wave2/prompts/W-NN.md`.
4. `docs/lifecycle/wave2/W-34.md` for W-34a/b (the only wave-2 item with its own spec).
5. Upstream handoffs: `docs/lifecycle/handoffs/TNN.md` (October) and `docs/lifecycle/wave2/handoffs/W-NN.md` (this wave).
6. `CLAUDE.md` and the matching `.ai/rules` files.

## 2. Contract changes are allowed only where a prompt says so
A prompt that changes `contracts.md` carries a section named **`[contract]`** with the exact text to add or replace, and the D-number that authorises it. Apply that text to `contracts.md` **in the same PR**, as the first commit the developer reviews, and nothing beyond it. A needed change the prompt does not list is still an escalation (`AGENT.md §6`).

## 3. Branches and ClickUp
- Branch from `main`: `wave2/W-NN-<slug>` (for a later slice, reuse it).
- Every item has a ClickUp task in the list **PT New** (folder Tracking). Put the task URL in the PR description and the handoff header. Do not change the ClickUp task yourself; the developer moves it.

## 4. Running tests on this machine
`php artisan test` over the whole suite exhausts memory (Windows paging). Run only the files the prompt names, with `php -d memory_limit=1536M vendor/bin/pest --compact <files>`. The developer runs the suite in batches before merging.

## 5. Two developers, one reviewer
The team is Dev 1 (backend, reviewer of every PR) and Dev 2 (UI). "Stop and escalate to Dev 1" means: write the question in chat and wait; never pick a contract answer yourself.

## 6. Finish
`AGENT.md §9`, with the handoff written to `docs/lifecycle/wave2/handoffs/W-NN.md` from `docs/lifecycle/handoffs/_TEMPLATE.md`.
