---
paths:
  - '{resources/views/components/ui/gantt.blade.php,resources/js/gantt/**,app/Livewire/Concerns/*Gantt.php}'
---

# Livewire Concerns

## Gantt engine: one custom component, server is the source of truth
x-ui.gantt + resources/js/gantt (Alpine, no library) draws every Gantt: project Plan tab (editable), /timeline (portfolio + pipeline) and the funding-agreement timeline (read-only). Payloads come from BuildsProjectGantt / BuildsPortfolioGantt; edits go through EditsProjectGantt gantt* actions, which answer {ok, payload, changes, previous} — the chart redraws from payload and builds its undo from changes/previous (ganttRestore reverts auto-scheduled ripples). Wrap the component in wire:ignore and re-key a wrapper to reset it. Scheduling (SchedulesProjectGantt) works in integer day numbers on the project's schedule_weekend calendar; links only push successors later, never pull. Trap: Alpine <template x-for> does not work inside <svg> — arrows are rendered as markup by linksMarkup(). Links to a summary's own sub-items are refused, as in MS Project.

## Timeline explorer: opt-in features on the shared Gantt, relations are not links
x-ui.gantt takes `explorer` (the /timeline page only): signal line, horizon band, route tracing and the inspector. The project Plan tab and the agreement page do not pass it and keep working as before; any new feature for one page goes behind a prop, never on by default. Funding connections travel as payload `relations` ({from, to, type: funds|pays|reports}) built by BuildsPortfolioGantt::connectedGanttPayload — they are drawn by relationsMarkup() and walked by resources/js/gantt/trace.js, and must never be mixed into `links` (links are schedule dependencies the auto-scheduler and critical path read). Portfolio milestones get float/critical/predecessors per project from decoratePortfolioSchedule() on that project's own schedule_weekend. Group-by is server-side (`group` Url property, whitelist TIMELINE_GROUPINGS); the chart sets it through $wire.$set and the wire:key wrapper resets the chart. Derived data in the Alpine component (graph, visible rows, trace, signals, horizon) is memoised in a closure keyed by `rowsVersion` — bump it in load() whenever rows change, or those getters serve stale results.
