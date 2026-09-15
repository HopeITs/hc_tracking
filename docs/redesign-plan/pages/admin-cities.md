# P28 · Admin · Cities

**Route:** `admin.cities.index` · **Group:** K · Admin · **Wave:** 1 · **Depends on:** 02 (`data-table`), 03 (optional chart)

## Files you own

- `resources/views/pages/admin/⚡cities.blade.php`
- `lang/*/cities.php`
- `tests/Feature/Admin/CityDirectoryTest.php`

## Current state

Read-only directory (cities are managed in the HR system, model `HrCity`): search only; bespoke header + duplicated table/card markup; columns City, Arabic/English name (locale-dependent), Projects count, Status (active/inactive plain text). No actions.

## Target layout

1. `x-ui.page-header`: title, count, description "Cities are managed in the HR system" (keep the existing meaning), filters: search + new `#[Url] reach` select (`with_projects` | `without_projects`).
2. **Summary slot:** "{n} of {total} cities have projects" + `x-ui.progress-bar` (same notion as the executive summary's Geographic Reach, labelled "Portfolio").
3. **Projects by city chart** (panel, collapsible): `x-ui.chart type="bar"` horizontal, top 10 cities by projects count.
4. **One** `x-ui.data-table`: City (name + secondary_name meta, following `name`/`secondary_name` locale rules) · Projects (numeric; links to `projects.index?city={id}` when > 0) · Status (Active good chip / Inactive neutral chip). No actions column.
5. Remove per-file class maps and `admin-directory` wrapper for this page.

## Tests (`Admin/CityDirectoryTest.php`)

- Existing tests green (read-only, locale name).
- Add: reach filter; projects link; reach summary count.

## Acceptance

- [ ] Still no create/edit/delete affordances.
- [ ] Conventions §10 checklist.
