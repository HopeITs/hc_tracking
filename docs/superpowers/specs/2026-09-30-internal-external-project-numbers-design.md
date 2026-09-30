# Internal and External Project Numbers — Design

Date: 2026-09-30
Status: Approved in chat, pending spec review

## Goal

Every piece of funded work carries two project numbers:

- **External project number**: set by the donor and tracked by the donor. It
  belongs to a **funding agreement** (`DonorProposal`, table `donor_proposal`).
- **Internal project number**: set by our PR, MEAL and funding team, who use it
  to track proposals. It belongs to a **proposal** (`Proposal`, table
  `proposals`).

A proposal has exactly one internal number. Each funding agreement under it
keeps its own external number, so a proposal funded by two donors carries one
internal number and two external numbers.

## Current state

- `donor_proposal.project_num` VARCHAR(50), nullable, non-unique, labelled
  "Donor reference" in the UI. This is already the external number.
- `proposals` has no number.
- 38 proposals and 38 agreements exist; 32 agreements carry a `project_num`.
  No `activities` row references `project_num`.

## Decisions (all confirmed by the user)

| Topic | Decision |
| --- | --- |
| Structure | One internal per proposal; one external per funding agreement |
| Internal: how | Typed by the team, free text |
| Internal: required | Required on create and on every save |
| Internal: unique | Unique across all proposals, case-insensitive |
| Internal: format | Any text, trimmed, max 50 characters |
| Internal: changeable | Yes; every change is recorded in the activity log and notifies followers |
| Internal: who sets it | Unchanged permissions: `proposals.create` / `proposals.update` |
| Internal: where typed | The existing proposal create/edit modal on the Proposals list only |
| External: rules | Unchanged: optional, non-unique, max 50 |
| Column names | Rename `project_num` → `external_project_number`; add `internal_project_number` |
| Existing proposals | Backfilled with unique placeholders `TEMP-0001`…; team replaces them |
| Placeholders | Shown with an amber "placeholder — replace" chip; `TEMP-` reserved for new input |
| Seed/factory pattern | `HC-2026-001` style |
| Labels | "Internal project number" / "External project number" |

## Part 1 — Data and rules

### Migration A: rename the external column

`database/migrations/<timestamp>_rename_project_num_on_donor_proposal_table.php`

- `up()`: rename `donor_proposal.project_num` → `external_project_number`.
  Type, length (50), nullability and absence of an index stay exactly as they
  are.
- `down()`: rename back.

### Migration B: add the internal column

`database/migrations/<timestamp>_add_internal_project_number_to_proposals_table.php`

`up()` runs in three steps:

1. Add `proposals.internal_project_number` VARCHAR(50), **nullable**, after
   `name`.
2. Backfill every existing row with `TEMP-` + its id left-padded to four digits
   (`TEMP-0001`, `TEMP-0038`). Ids are unique, so the placeholders are unique.
   - The padding is done in PHP with `str_pad`, which never shortens a longer
     id (`TEMP-12345` stays whole). SQL `LPAD(id, 4, '0')` would cut 12345
     down to `1234` and could create duplicates.
   - The rows are written through the query builder (`DB::table`), so the
     backfill records no activity and sends no notifications.
3. Change the column to **NOT NULL** and add a **UNIQUE** index
   (`proposals_internal_project_number_unique`).

`down()`: drop the unique index, then drop the column.

The table collation is `utf8mb4_unicode_ci` (see `.ai/rules/migrations.md`).
That makes the unique index case-insensitive and blind to trailing spaces, so
`HC-1`, `hc-1` and `HC-1 ` all collide. This is intended. The encoding test
(`tests/Feature/DatabaseEncodingTest.php`) must keep passing.

### Model: `Proposal`

- Add `internal_project_number` to `#[Fillable([...])]` and a
  `@property string $internal_project_number` docblock line.
- Add a constant `Proposal::PLACEHOLDER_NUMBER_PREFIX = 'TEMP-'`.
- Add a static `Proposal::isPlaceholderNumber(?string $number): bool`. It
  returns true when the number starts with `TEMP-`, compared
  case-insensitively, and false for null.
- Add an instance method `hasPlaceholderInternalNumber(): bool` that calls it
  with the proposal's own number.
- Add `'internal_project_number' => 'updated'` to `activityEvents()`, so a
  change is logged and notifies followers, like a rename.
- Update the `donors()` relation's `withPivot([...])` to
  `external_project_number`.

### Model: `DonorProposal` and `Donor`

- Rename `project_num` → `external_project_number` in `#[Fillable]`, the
  `@property` docblock, `activityEvents()` and `Donor::proposals()`'s
  `withPivot([...])`.
- Update the class docblock ("the project number the donor files it under") to
  name the external project number.

### Validation: proposal form (`proposals/⚡index.blade.php`, `saveProposal()`)

New Livewire property `public string $internalProjectNumber = ''`, trimmed
before validation. Rules:

- `required`, `string`, `max:50`
- `Rule::unique('proposals', 'internal_project_number')->ignore($editingId)`,
  so saving a proposal with its own number never raises a false duplicate
  error. The database unique index is the backstop.
- **`TEMP-` is reserved**: reject a value starting with `TEMP-`
  (case-insensitive) unless it equals the proposal's current stored number. A
  proposal still holding its placeholder can therefore be saved unchanged, but
  nobody can type a new placeholder.

**Simultaneous saves.** If two people save the same new number at the same
moment, both pass validation and the database rejects the second. The save
catches `Illuminate\Database\UniqueConstraintViolationException` and turns it
into the same duplicate error on `internalProjectNumber`, instead of a
500 page.

Error messages come from `lang/*/validation.php` (attribute name
`internalProjectNumber`) plus a custom message for the reserved prefix, in
English and Arabic.

The open-for-edit path loads the stored number into the property; the reset
path clears it.

### Validation: agreement form (`ManagesAgreementForm`)

The property `projectNum` is renamed `externalProjectNumber`. Its rules stay
`nullable`, `string`, `max:50`. `SaveFundingAgreement` writes
`external_project_number`, and an empty string still becomes null. The
`validation.attributes` key is renamed.

### Permissions

No change. Setting or changing the internal number is part of creating or
editing a proposal (`proposals.create` / `proposals.update`: Public Relations
Manager, Financial, General Manager, Super Admin). MEAL sees it but cannot set
it.

### Activity log labels

In `lang/en/activity.php` and `lang/ar/activity.php` (`fields`):

- remove `project_num`
- add `external_project_number` → "External project number" /
  "رقم المشروع الخارجي"
- add `internal_project_number` → "Internal project number" /
  "رقم المشروع الداخلي"

Removing `project_num` is safe because no stored activity row references it.

### Factories, test helpers and seeders

- **`ProposalFactory::definition()`** sets `internal_project_number` to
  `HC-2026-` + a unique three-digit number from **100–899**, built from
  `fake()->unique()->numberBetween(100, 899)`. It is NOT NULL with no default,
  so the factory must set it (`.ai/rules/seeders.md`).
- **`tests/Pest.php`**:
  - `insertProposal()` keeps its signature and lets the factory fill the
    number.
  - `insertDonorProposal()` renames its `$projectNum` parameter to
    `$externalProjectNumber`, which writes `external_project_number`.
- **`RealProposalsSeeder`**:
  - each proposal row carries an explicit number, `HC-2026-001` to
    `HC-2026-034`, in list order, passed in `firstOrCreate`'s create values
  - agreement rows rename their key to `external_project_number`
- **`ProgrammeSeeder`**:
  - its demo proposals get `HC-2026-901`, `HC-2026-902`, … by program index
  - the agreement key is renamed to `external_project_number`; its `PRJ-0001`
    values stay as they are

  **Numbering ranges** (so the three sources never collide in one database or
  one test run):

  | Source | Range |
  | --- | --- |
  | Real proposals (`RealProposalsSeeder`) | 001–034 |
  | Factory | 100–899 |
  | Demo proposals (`ProgrammeSeeder`) | 901+ |
- **`CoreDataSeeder`**: update the comment that mentions `project_num`.

## Part 2 — UI

### Labels (English / Arabic)

| Key | English | Arabic |
| --- | --- | --- |
| internal project number | Internal project number | رقم المشروع الداخلي |
| external project number | External project number | رقم المشروع الخارجي |
| empty external | No external project number | لا يوجد رقم مشروع خارجي |
| placeholder chip | Placeholder — replace | رقم مؤقت — يرجى الاستبدال |

Changes to the existing keys:

- `funding_agreements.donor_reference` → `funding_agreements.external_project_number`
- `common.no_donor_reference` → `common.no_external_project_number`
- New shared keys `common.internal_project_number`,
  `common.external_project_number` and `common.placeholder_number`
- Three keys keep their names, but their text changes to name the external
  number:
  - `proposals.agreement_number` ("Agreement :number") → "External no.
    :number" / "رقم خارجي :number". Used on the proposals list, the proposal
    detail page and the transfers list.
  - `donors.agreement_ref` ("Agreement :number") → "External no. :number" /
    "رقم خارجي :number". Used on the donor detail page.
  - `transfers.reference_num` ("Ref :reference") → "External no. :reference" /
    "رقم خارجي :reference". Used on the transfers list.

Every key goes into both `lang/en` and `lang/ar`; `LocalizationTest` enforces
this.

### Shared component: `x-ui.project-number`

A small Blade component that renders one number with its label and, for a
placeholder internal number, the amber chip. It exists so the chip logic isn't
copied onto every page.

- **Props:**
  - `number` (string|null)
  - `kind` (`internal` | `external`)
  - `label` (bool, default false): shows the label before the value
- **Empty external number:** renders `common.no_external_project_number` in
  muted text.
- **Chip condition:** the chip shows only when `kind = internal` and
  `Proposal::isPlaceholderNumber($number)` is true, so the prefix lives in one
  place.
- **Styling:** tabular numerals, and the existing status-warn tokens for the
  chip (`.ai/rules/css.md`: no raw greys in dark mode).
- **Arabic layout:** the number is wrapped in `<bdi dir="ltr">`, so
  `HC-2026-001` never flips to `001-2026-HC` on an Arabic (right-to-left)
  page. The form input for the number also gets `dir="ltr"`. This matches the
  way phone numbers are handled in `donors/⚡show.blade.php`.

### Pages

1. **Proposal form modal** (`proposals/⚡index.blade.php`): a new required
   `x-ui.form-input` labelled "Internal project number", directly after the
   name field, `maxlength="50"`, `dir="ltr"`. `openEditModal()` adds the
   column to its select and loads it; `resetProposalForm()` clears it;
   `saveProposal()` fills it.
2. **Proposals list** (`proposals/⚡index.blade.php`): each row shows the
   internal number (with its chip) beside the name. Search also matches
   `internal_project_number`. Agreement links in the row show the external
   number, relabelled.
3. **Proposal detail** (`proposals/⚡show.blade.php`): the header meta shows
   the internal number and its chip. The funding panel keeps each agreement's
   external number, relabelled.
4. **Funding agreements list** (`funding-agreements/⚡index.blade.php`):
   - each card shows the external number and the proposal's internal number
     side by side
   - search matches either one (`external_project_number`, or the proposal's
     `internal_project_number` through the existing proposal-name
     `whereHas`)
   - the proposal select adds `internal_project_number`
5. **Funding agreement detail** (`funding-agreements/⚡show.blade.php`): shows
   both numbers side by side.
6. **Donor detail** (`donors/⚡show.blade.php`): both places that show the
   external number (the lane label and the agreement link) show the proposal's
   internal number beside it. The **donors list** (`donors/⚡index.blade.php`)
   loads the external number but never displays it, so only its select is
   renamed. No visible change there.
7. **Transfers** (`transfers/⚡index.blade.php`):
   - the agreement link on each row and the reference line in the expanded
     detail show the internal number beside the external one
   - search matches either number
   - the agreement filter chip (`agreementFilter()`) shows names only, so only
     its select is renamed
8. **Executive summary** (`executive-summary/⚡index.blade.php`): each
   agreement line's context becomes "Proposal name · internal number". The
   eager load swaps the unused `project_num` for `external_project_number` and
   adds `proposal:id,name,internal_project_number`.
9. **Agreement picker** (`components/funding/agreement-modal.blade.php`):
   - proposal options read "Name · HC-2026-001". The select in
     `ManagesAgreementForm::proposalOptions()` adds `internal_project_number`.
   - the locked-proposal box shows the same. `proposals/⚡show.blade.php`
     passes "name · number" as `locked-proposal-name`.
   - the field is renamed to `externalProjectNumber`, with the new label
10. **Transfer picker** (`components/funding/transfer-modal.blade.php`,
    `ManagesTransferForm`): agreement options read
    "Donor · Proposal · internal · external", leaving out an empty external
    number. The option query adds the proposal's internal number.
11. **Project detail** (`projects/⚡show.blade.php`):
    - the funding panel (`attachedProposals()`) shows each linked proposal's
      internal number, with its chip, beside the name. Its select adds
      `proposals.internal_project_number`.
    - the "attach proposal" picker (`attachableProposals()`) lists options as
      "Name · HC-2026-001"
    - the Dashboard, Calendar, Gantt, Tasks, Targets and Report pages stay
      unchanged (user decision)

### Access to money pages

Unchanged (`.ai/rules/pages.md`, "Hide money, keep names"):

- The external number belongs to an agreement, which is money data. It is
  queried and shown only where the page already gates agreements behind
  `funding-agreements.view`.
- The internal number belongs to the proposal. It shows wherever the proposal
  itself is shown on the pages listed above.

## Part 3 — Tests

Tests are added to the existing files for each area, following
`.ai/rules/tests.md`:

- id-returning helpers
- database-level assertions read back through `DB::table`
- write tests seed `RolesAndPermissionsSeeder` and act as `Financial`

### Database (`tests/Feature/ProposalTest.php`, `tests/Feature/DonorProposalTest.php`)

- A proposal cannot be stored without an internal number (NOT NULL).
- Two proposals cannot share an internal number, including a case-only
  difference (`HC-2026-001` vs `hc-2026-001`).
- The existing `project_num` tests are renamed to use
  `external_project_number`, with the same behaviour: nullable, stored
  verbatim, duplicates allowed.
- The three raw `DB::table('proposals')->insert()` calls in
  `tests/Feature/ProposalTest.php` (lines 23, 35 and 56) add an
  `internal_project_number`. Without one they fail on NOT NULL. They keep
  testing the other column defaults as before.

### Proposal form (`tests/Feature/ProposalPipelineTest.php`)

- Create fails without an internal number.
- Create fails with a duplicate number and shows the friendly message.
- The value is trimmed, and more than 50 characters is rejected.
- A new value starting with `TEMP-` is rejected.
- Editing a proposal that still holds its placeholder, without touching the
  number, saves.
- Editing a proposal and keeping its own number raises no duplicate error.
- Changing the number records an activity row.
- A duplicate that slips past validation (a `UniqueConstraintViolationException`
  from the database) shows the duplicate error, not a 500 page.

Existing tests that call `saveProposal` (`ProposalPipelineTest`,
`FundingWorkflowTest`) get an internal number set.

### Search and display

- The proposals list finds a proposal by its internal number.
- The funding agreements list finds an agreement by its external number and by
  its proposal's internal number.
- The transfers list finds a tranche by either number.
- The placeholder chip renders for a `TEMP-` number and not for a real one.
- The agreement picker lists "Name · number"; the transfer picker lists both
  numbers.
- The project page shows a linked proposal's internal number, and its attach
  picker lists "Name · number" (`tests/Feature/ProjectDetailTest.php`).

### Other suites that must keep passing

- `tests/Feature/FundingAgreementTest.php` and
  `tests/Feature/Models/DonorTest.php`: updated for the rename.
- `DatabaseSeederTest`, `RealDataSeederTest`, `LocalizationTest`,
  `DatabaseEncodingTest`, `tests/Feature/Models/MorphMapTest.php`

### How to run

Only the affected test files run, never the full suite. Afterwards,
`vendor/bin/pint --dirty --format agent`.

## Out of scope

- Any change to who may edit proposals or agreements.
- Auto-generating or suggesting internal numbers.
- A uniqueness rule on external numbers.
- Editing the internal number anywhere except the proposal form.
- Data exports (none exist today).
- Searching notifications by project number.
- Older plan and redesign documents under `docs/` that mention `project_num`.
  They are historical records and stay as written.

## After deploying

- **Existing databases:** after `migrate`, the 38 existing proposals show
  `TEMP-0001`…`TEMP-0038` with the placeholder chip until the team types the
  real numbers.
- **Re-seeding:** `RealProposalsSeeder` uses `firstOrCreate` by name, so running
  it again does not overwrite a placeholder. Only a fresh database
  (`migrate:fresh --seed`) gets the `HC-2026-…` seed numbers.

## Files touched

**Migrations (new):**

- rename `project_num` on `donor_proposal`
- add `internal_project_number` to `proposals`

**Models:**

- `app/Models/Proposal.php`
- `app/Models/DonorProposal.php`
- `app/Models/Donor.php`

**Actions and concerns:**

- `app/Actions/FundingAgreements/SaveFundingAgreement.php`
- `app/Livewire/Concerns/ManagesAgreementForm.php`
- `app/Livewire/Concerns/ManagesTransferForm.php`

**Components:**

- new `resources/views/components/ui/project-number.blade.php`
- `resources/views/components/funding/agreement-modal.blade.php`
- `resources/views/components/funding/transfer-modal.blade.php`

**Pages:**

- `resources/views/pages/proposals/⚡index.blade.php` and `⚡show.blade.php`
- `resources/views/pages/funding-agreements/⚡index.blade.php` and `⚡show.blade.php`
- `resources/views/pages/donors/⚡index.blade.php` and `⚡show.blade.php`
- `resources/views/pages/transfers/⚡index.blade.php`
- `resources/views/pages/executive-summary/⚡index.blade.php`
- `resources/views/pages/projects/⚡show.blade.php`

**Language files** (`lang/en` and `lang/ar`):

- `activity.php`
- `common.php`
- `donors.php`
- `funding_agreements.php`
- `transfers.php`
- `validation.php`
- `proposals.php`

**Factories and seeders:**

- `database/factories/ProposalFactory.php`
- `database/seeders/RealProposalsSeeder.php`
- `database/seeders/ProgrammeSeeder.php`
- `database/seeders/CoreDataSeeder.php`

**Tests:**

- `tests/Pest.php`
- `tests/Feature/ProposalTest.php`
- `tests/Feature/DonorProposalTest.php`
- `tests/Feature/ProposalPipelineTest.php`
- `tests/Feature/FundingWorkflowTest.php`
- `tests/Feature/FundingAgreementTest.php`
- `tests/Feature/Models/DonorTest.php`
- `tests/Feature/TransferTrackingTest.php`
- `tests/Feature/ProjectDetailTest.php`

## Rules to record after implementation

One `.ai/rules` entry via `record-rule`, glob
`{app/Models/Proposal.php,app/Models/DonorProposal.php,resources/views/pages/{proposals,funding-agreements,transfers,donors}/**}`:

- the internal number lives on proposals and the external on agreements
- the internal number is unique and case-insensitive, and `TEMP-` is reserved
  for migration placeholders
- display goes through `x-ui.project-number`
