---
paths:
  - 'resources/views/pages/programs/**'
---

# Programs

## Programs are tracking-owned (D-68); money stays off the directory
Programs live in tracking's own `programs` table (see .ai/rules/models.md). The directory creates and renames them through App\Actions\Programs\SaveProgram (`programs.create` for create, `update` policy for rename); the slug is fixed at creation and never changes. The show page edits only the strategy and manager links. The "no proposal" (not funded yet) signal is offered only to users with `proposals.view`; money never appears on the directory page.

## The directory kept the program-page redesign (merge decision 2026-11-06)
When main was merged into scenarios-workflow, both branches had redesigned programs/⚡index.blade.php (main: one list with three status buttons and `_index-focus-bar` / `_index-list`; this branch: the program-page redesign). The user chose this branch's page; main's two partials and its tests for them were dropped. Do not reintroduce them without asking.
