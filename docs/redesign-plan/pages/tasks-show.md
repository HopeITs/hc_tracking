# P14 · Task detail

**Route:** `tasks.show` · **Group:** E · Tasks · **Wave:** 1 · **Depends on:** 02 (`x-ui.reference-list`)

## Files you own

- `resources/views/pages/tasks/⚡show.blade.php`
- `lang/*/tasks.php` (shared with P13)
- `tests/Feature/TaskDetailTest.php`, `tests/Feature/TaskUpdateTest.php`

## Decision

Attachments are **relabelled as referenced documents** via `x-ui.reference-list`. No download/upload.

## Current state

Detail header (title, proposal, milestone/project link, assignee, creator, status, priority); 4 stat tiles (progress, start, due, completed); description; 2-column: updates log (comment, user, date) with post form (textarea + submit with loading), attachments list (basename, type, date; not downloadable).

## Target layout

1. **Detail header:** `status` prop = task status; meta: priority badge, milestone → project link, proposal link, creator. `summary` slot: `x-ui.progress-ring size="lg"` (progress_percentage) + `x-ui.schedule-status` (due/completed/done).
2. **Date strip** (replaces the 4 tiles): a single horizontal "start → due → completed" line with three labelled points; missing dates shown as dashed points with "Not set".
3. **Main grid** (`@5xl:grid-cols-[1.6fr_1fr]`):
   - Left: Description panel (max 70ch, preserve line breaks with escaped output); **Updates** panel as a vertical conversation: newest at top, each entry avatar + name + relative date (absolute in `title`) + comment. Post form at the top: textarea label "Add an update", button "Post update", keep the existing Livewire action name, validation and loading state.
   - Right: **People** panel (assignee chip, creator chip); **Referenced documents** panel = `x-ui.reference-list`.

## Tests

- `TaskDetailTest`: keep the XSS assertions; add "reference list has no links" and progress ring aria value.
- `TaskUpdateTest`: posting still appends and orders newest first.

## Acceptance

- [ ] No element in the attachments area looks clickable.
- [ ] Conventions §10 checklist.
