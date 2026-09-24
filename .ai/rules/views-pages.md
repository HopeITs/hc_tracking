---
paths:
  - '{app/Models/**,routes/web.php,resources/views/pages/**}'
---

# Views Pages

## Show routes bind by slug via HasSlug, not id
Strategy, Project, Milestone, Task, Proposal, Donor, DonorProposal, Report and AnnualPlan use `App\Concerns\HasSlug` and route-bind on `slug`, not `id` — `projects/{project}` etc. resolve a readable slug. Program is the exception: its slug is read live from the HR database's own `programs.slug` column (`HrProgram::slugOf()`/`idBySlug()`), not a local column, since Program never copies HR data locally.

Consequences any new code touching these must respect:
- Always pass the model instance to `route('x.show', $model)`, never a raw id/array key (`$model->id`, `$row['id']`, a foreign-key column like `->proposal_id`). A raw int is not `UrlRoutable`, so it gets written into the URL literally and 404s. If only an id is on hand and no relation is loaded, build a cheap unsaved instance: `tap(new Project, fn ($p) => $p->id = $id)` — `HasSlug::getRouteKey()` resolves the real slug lazily via one query, no eager load required.
- `HasSlug::getRouteKey()` already falls back to a targeted `whereKey($this->getKey())->value('slug')` query when a partial `select()` omitted `slug`, so narrow selects don't need updating for correctness — but add `'slug'` to any `select()` feeding a *list* of `route(...show, $row)` links (list/directory pages, table rows) to avoid an N+1, same as the existing `id`/`name` columns there.
- The `slug` DB column is nullable, not NOT NULL: several tests insert rows straight through `DB::table(...)->insert()` bypassing Eloquent, and `HasSlug::save()` (not a `creating` event — DatabaseSeeder runs under `WithoutModelEvents`) is what actually populates it. A row without a slug just has no working show route until one exists.
- Composite-slug models (AnnualPlan: `program-slug-year`; DonorProposal: `donor-slug-proposal-slug`; Report: `indicator-name-planned-date`) have no title field of their own — don't add one to "simplify" the slug; the composite is deliberate.
- `App\Notifications\SubjectLinks::routeFor()` now queries the actual model per subject type (`showRoute()` helper) instead of building the URL from a bare id, because it must reach the record to read its slug — a truly nonexistent id now returns null there, which is correct (a stale notification linking to a deleted record).
