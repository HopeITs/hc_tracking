# P12 · Risk register

**Route:** `risks.index` · **Group:** D · Projects · **Wave:** 1 · **Depends on:** 02

## Files you own

- `resources/views/pages/risks/⚡index.blade.php`
- `lang/*/risks.php`
- `tests/Feature/RiskRegisterTest.php`

## Decision

**Visual-only.** No likelihood/impact scoring, no migration. Fields stay: `name`, `impact` (text), `solution` (text, nullable), `happened` (bool), `project_id`.

## Current state

Filters: search (name/impact/project), project, state (materialised/watching), reset; create/edit modal (name, impact, solution, project, "has materialised" checkbox); delete with confirm. Rows stacked: name + project/city, state badge + actions, impact, optional mitigation. Materialised sort first. 15/page.

## Target layout

1. **Header:** keep filters + "Record risk".
2. **Summary slot:** `x-ui.status-strip` with two segments: Materialised (critical) / Watching (warn), `wire-model` bound to the existing state filter property. Beside it: "{n} risks without a mitigation" button setting a new `#[Url] unmitigated` bool (`whereNull('solution')` or empty string).
3. **By-project rollup** (panel, collapsible, default open ≥ `@4xl`): `x-ui.chart type="bar"` horizontal stacked, categories = top 8 projects by risk count, series Materialised / Watching (one grouped query respecting search/state filters).
4. **Register list:** two-column card grid (`@4xl:grid-cols-2`) of risk sheets, materialised first then newest:
   - Top line: state chip (materialised: filled critical + `exclamation-triangle` icon + label; watching: warn outline + `eye` icon), project link + city.
   - Name (`type-heading`).
   - "Impact" label + text; "Mitigation" label + text, or a warn inline "No mitigation recorded" prompt with an edit link.
   - Actions: edit / delete (keep existing method names).
5. Pagination unchanged.

## Tests (`RiskRegisterTest.php`)

- Keep the XSS escaping assertions (lines asserting `<script>name()</script>` etc. are shown as text).
- Add: strip counts; `unmitigated` filter; chart categories respect state filter; materialised sort first.

## Acceptance

- [ ] No schema change.
- [ ] Conventions §10 checklist.
