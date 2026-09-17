# Full CRUD for project files (Files tab)

Date: 2026-09-17
Branch: youssef-interfaces-edits

## Problem

The Projects/show "Files" tab (`resources/views/pages/projects/⚡show.blade.php`,
card grid in `resources/views/components/ui/file-card.blade.php`) currently only
**displays** `media` rows. Nothing in the app creates them outside seeders and
test factories — there is no upload form, no edit, no delete, and no real file
is ever written to disk. The panel subtitle is honest about this today:
"File records only. The files themselves are not stored in this system yet."

The `media` table (`database/migrations/2026_09_07_131500_create_media_table.php`)
was already designed for real storage — it has `path`, `disk`, `mime_type` and
`size` columns — but the storage pipeline itself (upload → disk → download)
has never been built. This spec closes that gap for **project-level** files
only.

## Scope

**In scope:** create (real upload), edit (metadata only), delete (row + disk
file), download — for files attached directly to a **project**
(`mediable_type = 'project'`), across the existing categories: Proposal,
Agreement, MOU, Photo, Other.

**Out of scope (explicitly deferred):**
- Milestone **evidence** files (`mediable_type = 'milestone'`). These are a
  different workflow — attaching proof at the moment a milestone is marked
  reached — and belong on the milestone UI, not this tab. Not touched here.
- Replacing/re-uploading the underlying file on edit. Editing changes category
  and display name only; swapping the actual document is delete + re-upload.
- Any role/permission gate beyond "must be an authenticated user" — this app
  has no policy layer for project sub-resources yet (risks, proposals, etc.
  all follow the same no-policy convention), so file CRUD matches that.
- Cloud storage (S3). No S3 driver is installed
  (`league/flysystem-aws-s3-v3` is absent); everything stays on the `local`
  disk, which is already private (`storage_path('app/private')`, not
  publicly served).

## Decisions

Made collaboratively during brainstorming; recorded here so implementation
doesn't have to re-litigate them.

| Decision | Choice | Why |
| --- | --- | --- |
| Storage | Real file, written to disk | "Upload a file" should really store a file — the schema was already built for it. |
| Disk / privacy | `local` disk (private), authenticated download route | These are signed agreements, MOUs, donor-adjacent documents — not safe as a guessable public URL. |
| Download | A dedicated download button/link | Storing real files without a way to retrieve them is pointless. |
| Edit scope | Metadata only (category + display name) | Re-uploads are rare; delete + re-create covers that case without extra replace-in-place logic. |
| Max file size | **5MB** | User's explicit choice — keeps storage lean; scanned agreements over 5MB are the rare case, not the common one. |
| Allowed types | Whatever `App\Enums\FileType` already allows: `pdf`, `docx`, `xlsx`, `jpg`, `png` | Reuses the existing exhaustive whitelist instead of inventing a second one. |
| Architecture | Extend the existing `projects/⚡show.blade.php` Volt component | This codebase has no nested-Livewire-component pattern; every other CRUD on a detail page (annual-plans' `savePlan`/`deletePlan`, this same page's `attachProposal`/`detachProposal`) lives directly in the page component. Consistency beats a "cleaner" abstraction that doesn't exist anywhere else here. |

## Architecture

### Where the code goes

- **`resources/views/pages/projects/⚡show.blade.php`** — add the Livewire
  state/methods for create, edit, delete (see below). Uses
  `Livewire\WithFileUploads`.
- **`app/Http/Controllers/ProjectFileDownloadController.php`** (new,
  single-action, same shape as `SwitchLocaleController`) — streams the file.
  A Livewire action can't return a binary file response, so download has to
  be a real HTTP route, not a Livewire method.
- **`routes/web.php`** — one new route inside the existing
  `auth+verified` group:
  ```php
  Route::get('projects/{project}/files/{media}/download', ProjectFileDownloadController::class)
      ->name('projects.files.download');
  ```
- **`resources/views/components/ui/file-card.blade.php`** — add a row of
  three icon actions (download / edit / delete).
- No new database migration needed — the `media` table already has every
  column this requires.
- No new model needed — `App\Models\Media` already exists with the right
  casts and relations.

### New Livewire state (on the show.blade.php component class)

```php
use Livewire\WithFileUploads;

// ...inside the class...

public bool $showFileModal = false;

public bool $confirmingFileDelete = false;

public ?int $editingFileId = null;

public ?int $deletingFileId = null;

public $newFile = null; // Livewire\Features\SupportFileUploads\TemporaryUploadedFile, create only

public string $fileCategory = '';

public string $fileDisplayName = '';
```

`editingFileId === null` means create mode (show the file picker); a non-null
value means edit mode (hide the file picker, show category + display name
only).

### Methods

- `openCreateFileModal(): void` — reset the file-modal fields, `editingFileId
  = null`, `showFileModal = true`.
- `openEditFileModal(int $mediaId): void` — load the `Media` row **scoped to
  this project** (`mediable_type = 'project'`, `mediable_id = $this->projectId`,
  `findOrFail`), populate `fileCategory`/`fileDisplayName`, `editingFileId =
  $mediaId`, `showFileModal = true`.
- `saveFile(): void` —
  - **Create branch** (`editingFileId === null`): validate `newFile`
    (`required|file|max:5120|mimes:pdf,docx,xlsx,jpg,png`) and `fileCategory`
    (`required`, `Rule::enum(MediaCollection::class)`). Store via
    `$this->newFile->store('media/projects/'.$this->projectId, 'local')` —
    Livewire generates a random filename on disk automatically, so the
    user's original filename is never used as a path (avoids path
    traversal/collisions). Derive `type` from the file's real extension
    (`FileType::from(strtolower($this->newFile->getClientOriginalExtension()))`).
    Create the `Media` row: `mediable_type = 'project'`, `mediable_id =
    $this->projectId`, `collection`, `disk = 'local'`, `path`, `original_name
    = $this->fileDisplayName ?: $this->newFile->getClientOriginalName()`,
    `mime_type = $this->newFile->getMimeType()`, `size =
    $this->newFile->getSize()`, `type`, `uploaded_at = now()`, `uploaded_by =
    auth()->id()`.
  - **Edit branch** (`editingFileId` set): re-fetch the `Media` row scoped to
    this project (same guard as `openEditFileModal`), validate
    `fileCategory` and `fileDisplayName` (`required|string|max:255`), update
    only `collection` and `original_name`.
  - Either branch: close the modal, reset fields, `unset($this->project)` so
    the media relation/counts refresh, `Flux::toast(variant: 'success', ...)`.
- `confirmDeleteFile(int $mediaId): void` — verify the row belongs to this
  project (same scoped lookup), set `deletingFileId`, `confirmingFileDelete =
  true`.
- `deleteFile(): void` — re-fetch scoped to this project, attempt
  `Storage::disk($media->disk)->delete($media->path)` (wrapped so a storage
  I/O failure is logged, not surfaced — don't block the user from clearing a
  broken record), then `$media->delete()`. Close the modal, reset,
  `unset($this->project)`, toast.
- `closeFileModal(): void` / implicit close on the delete-confirm modal —
  reset validation and fields, matching the `closePlanModal()` pattern in
  annual-plans.

Every method that takes a `$mediaId` from the client **must** re-scope the
lookup to this project (`mediable_type = 'project' AND mediable_id =
$this->projectId`) rather than trusting a bare `Media::findOrFail($mediaId)`.
The id comes from a client-clicked button, so without this guard a user could
edit/delete/download another project's file by tampering with the id
(IDOR). This is the one place this feature adds a check the rest of the page
doesn't need, because it's the first place on this page a raw foreign id
crosses the wire back from the client.

### Download controller

```php
class ProjectFileDownloadController extends Controller
{
    public function __invoke(Project $project, Media $media): StreamedResponse
    {
        abort_unless(
            $media->mediable_type === 'project' && $media->mediable_id === $project->id,
            404
        );

        return Storage::disk($media->disk)->download($media->path, $media->original_name);
    }
}
```

Sits behind the existing `auth`/`verified` middleware group — no anonymous
access, no signed URL needed since the route itself isn't guessable/enumerable
without also knowing a valid project id and being logged in.

## UI

### Files panel toolbar

Add an "Add file" button (`x-ui.button tone="primary" icon="plus"`) to the
panel's `toolbar` slot, matching how the risks tab's "Manage risks" link and
the proposals tab's link-a-proposal control already sit in that slot.

### File card actions

Extend `file-card.blade.php` with a row of three icon buttons at the bottom of
the card (visible, not hover-only — this app favours direct affordances):

- **Download** — plain `<a href="{{ route('projects.files.download', [$projectId, $media]) }}">` with the `arrow-down-tray` icon. **No `wire:navigate`** — that would try to SPA-navigate a binary response instead of triggering a real download.
- **Edit** — `wire:click="openEditFileModal({{ $media->id }})"`, `pencil-square` icon.
- **Delete** — `wire:click="confirmDeleteFile({{ $media->id }})"`, `trash` icon, tone leaning toward the primary/red accent since it's destructive.

### Modals

Two `flux:modal`, following the exact `wire:model`-bound-boolean pattern from
`annual-plans/⚡show.blade.php` (per `.ai/rules/pages.md`: never bind
`flux:modal` with `:open`, always `wire:model`):

1. **File modal** (`wire:model="showFileModal"`) — title switches between
   "Add file" / "Edit file" based on `editingFileId`. Fields: file picker
   (create only), category select (`x-ui.select`, options from
   `MediaCollection::cases()`), display name text input. Save button calls
   `saveFile`.
2. **Delete confirmation** (`wire:model="confirmingFileDelete"`) — names the
   file being deleted, Cancel/Delete buttons, Delete calls `deleteFile`.

## Validation & error handling

- File: `required|file|max:5120|mimes:pdf,docx,xlsx,jpg,png` (create only).
  Laravel's `mimes` rule checks the file's real content via `finfo`, not just
  the extension string, so a renamed `.exe` won't pass as a `.pdf`.
- Category: `required`, must be a real `MediaCollection` case
  (`Rule::enum(MediaCollection::class)`).
- Display name: `required|string|max:255`.
- Validation errors surface inline in the modal via Flux's default error
  display, same as every other modal form in this app — no custom error UI.
- A disk-delete failure during `deleteFile()` is caught and logged, not
  shown to the user as an error — the row still gets removed so the record
  doesn't linger stuck in the UI over a storage hiccup.

## Testing plan (Pest, feature tests)

Add to `tests/Feature/ProjectDetailTest.php` (or a new
`ProjectFileCrudTest.php` if it grows past a handful of cases — call this
during implementation):

1. Uploading a valid file creates a `Media` row with the right
   `mediable_type`/`mediable_id`/`collection`/`type`/`uploaded_by`, and the
   file actually exists on the fake `local` disk (`Storage::fake('local')`).
2. Uploading a file over 5MB, or with a disallowed extension, fails
   validation and creates no row and no disk file.
3. Editing a file updates `collection`/`original_name` only — `path`/`disk`/
   `size` are unchanged.
4. Deleting a file removes both the `Media` row and the file from the fake
   disk.
5. The download route returns the file's content for a file that belongs to
   the project in the URL.
6. The download route (and the edit/delete actions) **reject** a media id
   that belongs to a different project — this is the IDOR guard, and it's
   the most important test in this set.
7. Existing test `project files list only this projects metadata without
   upload or download controls` (in `ProjectDetailTest.php`) will need
   updating — it currently asserts the page contains no `download`/`upload`
   text at all, which will no longer hold once this ships. Update it to
   assert the download link points at the authenticated route rather than a
   raw storage path, and that the raw `path`/`disk` values are still never
   printed directly.

## Follow-ups (not part of this work)

- Milestone evidence upload, triggered from the "mark milestone reached"
  action.
- Possible replace-file-in-place editing, if metadata-only edit turns out to
  be too limiting in practice.
