---
paths:
  - '{resources/views/components/ui/gantt.blade.php,resources/js/gantt/**,app/Livewire/Concerns/*Gantt.php}'
---

# Livewire Concerns

## Gantt engine: one custom component, server is the source of truth
x-ui.gantt + resources/js/gantt (Alpine, no library) draws every Gantt: project Plan tab (editable), /timeline (portfolio + pipeline) and the funding-agreement timeline (read-only). Payloads come from BuildsProjectGantt / BuildsPortfolioGantt; edits go through EditsProjectGantt gantt* actions, which answer {ok, payload, changes, previous} — the chart redraws from payload and builds its undo from changes/previous (ganttRestore reverts auto-scheduled ripples). Wrap the component in wire:ignore and re-key a wrapper to reset it. Scheduling (SchedulesProjectGantt) works in integer day numbers on the project's schedule_weekend calendar; links only push successors later, never pull. Trap: Alpine <template x-for> does not work inside <svg> — arrows are rendered as markup by linksMarkup(). Links to a summary's own sub-items are refused, as in MS Project.
