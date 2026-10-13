# Task 22 — Encrypt uploaded files at rest and migrate existing files

> **المهمة:** تشفير الملفات المرفوعة على القرص وترحيل الملفات القائمة
> **المطوّر:** B4 (مراجعة Dev 1) · **الفريق:** B · **الأيام:** 2–5 (الثلاثاء 2026-10-13 ← الجمعة 2026-10-16) · **الجهد:** 3.5 يوم · **يعتمد على:** Task 01 (D-19)
> **متى تستخدمه:** في اليوم 2 بعد CP0 (نهاية اليوم 2) وتسجيل القرار D-19 (نطاق التشفير ومكان حفظ المفتاح) في سجل القرارات. المهمة مستقلة عن عمود المسار وتتوازى مع MEAL؛ اليوم 2 التصميم و`StoredFiles`، واليوم 4 أمر ترحيل الملفات، واليوم 5 التدوير والطوارئ والاختبارات. يُفضَّل دمجها قبل Task 31 (اليوم 6) وTask 17 (اليوم 9) وTask 19 حتى ترث رفوعها التشفير مباشرة. ترحيل الملفات القديمة هو البند 5 في قائمة التقليص (R9): الجديدة تُشفَّر، والقديمة بعد الإطلاق.
> **طريقة الاستخدام:** افتح جلسة Claude Code جديدة على المستودع، فعّل Plan Mode، والصق البرومبت كما هو. راجع الخطة التي يقترحها Claude قبل الموافقة على التنفيذ.

````markdown
## Role and goal
You are implementing Task 22 of the HC Tracking proposal-lifecycle correction plan in this Laravel 13 / Livewire 4 / Flux UI 2 repository. The outcome: every uploaded document is written to disk encrypted (AES-256-GCM through `Illuminate\Encryption\Encrypter`, with a dedicated `FILE_ENCRYPTION_KEY` that is never `APP_KEY`) and decrypted only while the existing protected download and preview routes stream it; existing plaintext files are encrypted by a resumable command; the key can be rotated; one file can be extracted in an emergency. Today files sit in private storage behind policy-checked controllers, but anyone who reaches the server or its backups can read them. In the corrected story the concept note, the proposal file, the agreement, monthly-report evidence (Task 17), aggregated donor-report submissions (Task 19) and department-task deliveries such as media files (Task 31) are all uploads, and they inherit encryption through `App\Support\StoredFiles`. Authorization, URLs, upload rules and limits do not change.

## Read before writing any code
1. `CLAUDE.md` and `docs/superpowers/prompts/2026-10-08-proposal-lifecycle/quality-bar.md` (the code and UI/UX bar this work is reviewed against, and which revision block wins) (Boost tools, `php artisan make:* --no-interaction`, Pest, Pint, no new dependencies or base folders).
2. `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md`, section `### Task 22` — Arabic and authoritative; English names are exact. Also section 0 risks R4 and R9 and «قواعد العمل بين الفريقين», section 5 rows "الملفات (الرفع)", "الملفات (التخزين)", "الملفات (التنزيل)", "المفاتيح والأسرار", and section 9 D-19.
3. `docs/superpowers/specs/2026-10-12-proposal-lifecycle-decisions.md` if it exists; a recorded decision overrides the plan's default for D-19 (scope = every file; key kept in two places).
4. Rules: `.ai/rules/database-migrations.md` (report_files reference-only rows), `.ai/rules/migrations.md`, `.ai/rules/feature.md`, `.ai/rules/models.md`, `.ai/rules/providers.md` (strict mode: an unfillable attribute is a loud failure), `.ai/rules/enums.md` (the `FileType` trap: the stored path's extension comes from the type), `.ai/rules/console-commands.md`, `.ai/rules/commands.md`, `.ai/rules/config.md`, `.ai/rules/env-example-gitignore-htaccess-gitleaks-toml-gitleaksignore-gitlab-ci-yml.md` (`SecretHygieneTest`, gitleaks), `.ai/rules/views.md`, `.ai/rules/views-enums.md`, `.ai/rules/factories.md`, `.ai/rules/seeders.md`, `.ai/rules/tests.md`. Then `grep -rin 'encrypt\|upload\|storage\|file' .ai/rules`.
5. Study first:
   - `app/Support/StoredFiles.php` (`save()`, `nameWithExtension()`, `forget()`), `app/Support/UploadName.php`, `app/Rules/SecureUpload.php`, `app/Enums/FileType.php`.
   - Models `app/Models/Media.php`, `app/Models/ReportFile.php`, `app/Models/TaskAttachment.php` (`isStored()`, `isPreviewable()`, `displayName()`, `RecordsParentActivity`).
   - Writers: `app/Actions/Projects/SaveProjectFile.php`, `app/Actions/Proposals/SaveProposalFile.php`, `app/Actions/Reports/SaveReportFile.php`, `app/Actions/Tasks/SaveTaskAttachment.php`; deleters `DeleteProjectFile`, `DeleteProposalFile`, `DeleteReportFile`, `DeleteTaskAttachment`, `app/Actions/Reports/DeleteReport.php`. Upload validation lives in `pages/{projects,proposals,reports,tasks}/⚡show.blade.php` (`'newFile' => [..., 'max:5120', 'mimes:…', 'extensions:…', new SecureUpload]`).
   - Readers (all use `Storage::disk()->download()` / `->response()` today): `app/Http/Controllers/{Project,Proposal,Report,Task}FileDownloadController.php`, `{Proposal,Report,Task}FilePreviewController.php`; routes in `routes/web.php` (`throttle:files`); `resources/js/file-preview.js` (Office preview fetches the download route).
   - Commands: `app/Console/Commands/PruneStoredFiles.php` (`files:prune`), `app/Console/Commands/ReencryptSecrets.php` (`security:reencrypt`).
   - Config: `config/filesystems.php` (`local` disk: `'throw' => false`), `config/livewire.php` (`temporary_file_upload`), `config/app.php` (`previous_keys`), `.env.example`, `bootstrap/app.php` (`withExceptions`), `resources/views/errors/500.blade.php` + `resources/views/components/errors/page.blade.php`, `lang/{en,ar}/errors.php`.
   - Migrations `2026_09_07_131500_create_media_table.php`, `2026_09_07_140011_create_report_files_table.php`, `2026_09_07_130232_create_task_attachments_table.php` (+ their `add_storage_columns_*` follow-ups).
   - Tests: `tests/Feature/PrivateStorageRoutesTest.php`, `MediaTest.php`, `ProjectFileCrudTest.php`, `ReportFileCrudTest.php`, `TaskAttachmentCrudTest.php`, `ProposalDetailActionsTest.php`, `SecureUploadTest.php`, `PruneStoredFilesTest.php`, `ReencryptSecretsCommandTest.php` (`useAppKey()` pattern), `SecretHygieneTest.php`, `ErrorPagesTest.php`.
6. Skills: `laravel-best-practices`, `laravel-security`, `database-migrations`, `testing-best-practices`.
7. Use Boost `search-docs` before using framework APIs (`['encryption', 'encrypter', 'file storage', 'streamed downloads', 'isolatable commands', 'rendering exceptions']`) and `database-schema` on `media`, `report_files`, `task_attachments` before writing the migration.

## Preconditions to verify
- D-19 is recorded (or the developer confirms the plan's default). If the decision narrows the scope or changes the key strategy, stop and report.
- `encryption_key_id` does not exist on `media`, `report_files`, `task_attachments`; `files:encrypt-existing` and `files:decrypt` do not exist.
- `grep -rn "StoredFiles::save\|Storage::disk(" app resources/views` lists only the writers/readers above. If Tasks 07/17/19/31 already added a file table, writer or reader, include it and name it in your plan.
- The developer will put a local `FILE_ENCRYPTION_KEY` in `.env` (never write `.env` yourself; give them `php -r "echo 'base64:'.base64_encode(random_bytes(32)).PHP_EOL;"`).
If a check fails, stop and report instead of improvising.

## Revision 2 changes (2026-10-08)
- Owner is now **B4 (Team B)**, reviewed by Dev 1; days 2–5 (Tue 2026-10-13 → Fri 2026-10-16) of the 15-working-day window.
- Task 20 (media deliverables) is gone: media deliveries are now department tasks inside proposals (Task 31, `tasks.kind = media_deliverable`, files through `task_attachments`). Videos are links (`delivered_link`), not uploads. Read "Task 20" in older notes as Task 31.
- **R9 cut order, item 5:** if the plan is late at CP3, migrating existing files (`files:encrypt-existing`) moves after launch; new uploads must still be encrypted from deployment. Keep the command complete and tested so Dev 1 can run it after go-live.
- Work under the inter-team rules (plan section 0 «قواعد العمل بين الفريقين»): do not change Phase 1 tables (`proposals`, `projects`, `donor_proposal`, `proposal_status_notes`) or the proposal state machine — request any such change from Dev 1 so it is recorded in the plan; integrate with Phase 1 by listening to its events (`ProposalStatusChanged`, `ProjectActivated`, `ProjectPeriodChanged`, `FundingAgreementRecorded`, dispatched after commit) instead of calling Phase 1 Actions directly; every PR is reviewed by Dev 1 within one working day and merges only with this task's suite green; no production access — seed or masked data only.
- You have no production or staging access: Task 27 (Dev 1) rehearses `files:encrypt-existing` on the production copy and Task 29 runs it; give them exact steps and timings measured on local/masked data.
- Register this task's events (none user-facing are expected; if you add an admin alert for an unreadable file, register it) in the Task 21 event catalog `App\Notifications\EventCatalog` so MEAL and General Management are notified (plan section 1.0, fact 8; `EventCatalogCoverageTest` must stay green). If the catalog is not merged yet, add the keys in your PR and coordinate with Dev 3.

## Revisions 4–9 changes (new upload paths, 2026-10-12)
The task's own plan section has no later revision subsection, but later revisions added upload paths that must inherit encryption. Where this block conflicts with the base sections, it wins.
- Every one of these writes through `StoredFiles::putEncrypted()` and reads through `StoredFiles::stream()`: monthly report evidence files on a line or an activity (`report_files.monthly_report_line_id` / `monthly_report_activity_id`, typed by `evidence_types` — Tasks 17 and 33); the final report and the donor's acceptance email (`media` categories `final_report`, `final_report_acceptance` — Task 04); the donor-approval evidence (`media` category `donor_approval` — Task 08); the Need Assessment (Task 07); donor report submissions (`media` category `report_submission` — Task 19); department task deliveries (`task_attachments` — Task 31).
- Expose one obvious entry point and record it with `record-rule` (glob covering `app/Actions/**` and the file tables) so tasks merged after you cannot add an unencrypted path; add a guard test (source scan or behaviour test) that fails when a new file table lacks `encryption_key_id`.

## Scope
### In scope
- Migration `add_encryption_key_id_to_file_tables`: `encryption_key_id` varchar(20) nullable on `media`, `report_files`, `task_attachments` (and any file table already merged by another task).
- Config: an `encryption` block (propose `config/filesystems.php` or a new `config/files.php`): cipher `aes-256-gcm`, `key` (`FILE_ENCRYPTION_KEY`), `key_id` (`FILE_ENCRYPTION_KEY_ID`, falling back to a constant such as `k1` when empty), `previous_keys` (`FILE_ENCRYPTION_PREVIOUS_KEYS`, `id:key,id:key`).
- A small keyring (propose `App\Support\FileKeyring`): validates keys, returns the current `[id, Encrypter]` and an `Encrypter` by key id.
- Update `App\Support\StoredFiles`: `save()` encrypts every new upload; add `putEncrypted()`, `contents()`, `stream()`; one routine that rewrites one stored file (used by both commands).
- Update the seven controllers to read through `StoredFiles::stream()` with the same guards, headers, filenames and route names.
- Models: `encryption_key_id` in `#[Fillable]` and `@property`; optional `isEncrypted()`.
- `FileType::mimeType()` (or one shared map) so responses set `Content-Type` from the validated type.
- `App\Exceptions\UnreadableStoredFile` (`php artisan make:exception`) rendering a friendly page built on `x-errors.page`; keys in `lang/{en,ar}/errors.php`.
- Commands: `files:encrypt-existing {--dry-run} {--chunk=100}`; `security:reencrypt --files`; `files:decrypt` (signature below).
- `.env.example`: `FILE_ENCRYPTION_KEY=`, `FILE_ENCRYPTION_KEY_ID=`, `FILE_ENCRYPTION_PREVIOUS_KEYS=` with comments, all empty (the `SecretHygieneTest` regex also matches `*_KEY_ID`).
- Test bootstrap: a random key per Feature test (e.g. `beforeEach` in `tests/Pest.php`), never a committed key.
- Key backup/rotation text: no deployment guide exists yet (Task 29 writes the runbook). Propose where it goes; by default put it in your report for Tasks 27/29 and in `.env.example` comments; create no doc file without approval.
### Out of scope
Running the migration of existing files on staging/production (Task 27 rehearses, Task 29 runs it); upload rules, size, types, rate limits, routes, policies; new upload features (Tasks 07, 17, 19, 31); encrypting database columns (already `security:reencrypt` without `--files`).

## Business rules
- Every upload stored after deployment is encrypted, no exception. With no valid key the upload fails closed (friendly message on the file field, error logged); plaintext is never written as a fallback.
- `SecureUpload`, `max:5120`, `mimes` and `extensions` run on the original upload in the component's validation, before `StoredFiles` is called. Encryption happens only inside `StoredFiles`, after validation.
- `size` keeps the original byte count; the ciphertext (base64 JSON, about a third larger) never counts against the 5MB limit.
- `encryption_key_id` NULL = legacy plaintext, streamed as is until the command encrypts it.
- Download/preview URLs, route names, download filenames, IDOR guards, `isFundingPaper()` 403, `throttle:files` and headers (`X-Content-Type-Options: nosniff`, preview `Content-Type`, `Cross-Origin-Resource-Policy`, `Cache-Control: private`, `Content-Security-Policy: sandbox` for images) stay identical.
- Encrypt raw bytes: `$encrypter->encrypt($bytes, false)` / `decrypt($payload, false)` (no serialization), 32-byte key, cipher `aes-256-gcm` (GCM tag = integrity check).

## Security and authorization
- `FILE_ENCRYPTION_KEY` is independent from `APP_KEY`: rotating one never breaks the other. Never use the `Crypt` facade or the default encrypter for files.
- Never log, print, return or commit a key, plaintext or payload (not in tests, `phpunit.xml`, `.env.example` or exceptions). gitleaks runs in CI.
- Controllers keep `Gate::authorize('view', $parent)` and the ownership check first; decrypt only after both pass.
- `Content-Type` comes from the validated `FileType`, never from the stored bytes (ciphertext detects as text) or the client MIME.
- User-facing errors reveal no path and no crypto detail: «تعذّر فتح الملف، راجع قسم تقنية المعلومات» / "The file could not be opened. Please contact IT." `Log::error` carries table, row id and key id only.
- `files:decrypt` writes plaintext only to an explicit destination outside the upload folders and `public/`, refuses to overwrite, prints only the destination, and reminds the operator to delete it.
- Commands use `ConfirmableTrait` for real runs in production and print "run with --dry-run first".

## Validation
- No new user input. Each command exits with FAILURE, a clear message and no writes when: the key is missing; `Encrypter::supported($key, 'aes-256-gcm')` fails (accept the `base64:` prefix like `APP_KEY`); the key id is empty, longer than 20 characters or not `[A-Za-z0-9._-]`; a previous-key entry is malformed or reuses the current id.
- Upload with a misconfigured key: error on the file field «تخزين الملفات غير مهيأ، راجع قسم تقنية المعلومات» / "File storage is not configured. Please contact IT." in all four forms; propose the mechanism (e.g. a typed exception the shared forms translate).

## Edge cases and concurrency
- Wrong key, corrupt file or unknown key id: decrypt BEFORE building the response, catch `DecryptException`, throw `UnreadableStoredFile` → friendly page, error logged; never a raw 500 or a half-sent stream. The Office preview sees a non-OK response and shows its existing error state (no JS change).
- Command interrupted mid-batch: rows with `encryption_key_id` are skipped on rerun; leftover temp files are unreferenced and `files:prune` removes them after its grace period.
- Row without a file on disk, reference-only `report_files`/`task_attachments` (`original_name` NULL), or `disk` other than `local`: skip, count, log.
- A user replaces or deletes the file while the command processes it: lock the row, re-check `path` and `encryption_key_id` after the lock; if changed, discard the temp file and skip.
- Two command runs at once: implement `Illuminate\Contracts\Console\Isolatable` on all three commands.
- Livewire keeps the plaintext temporary upload in `livewire-tmp` (it copies on store). Delete the temporary file after a successful encrypted save; abandoned ones stay until `files:prune` (24h) — state this residual risk in your report.
- Replacing a file: the new upload is encrypted; the old file (plaintext or encrypted) is forgotten after the save, as today.
- Backups taken before the run still hold plaintext: handoff note for Tasks 27/29.

## Data integrity
- Per-file sequence from the plan: read original → encrypt → write `{path}.enc.tmp` → read back, decrypt, `hash_equals(sha256(original), sha256(decrypted))` → atomic replace → set `encryption_key_id` in a transaction. Never overwrite the original before the verified copy exists.
- Known gap: between the rename and the row update a concurrent download serves ciphertext as plaintext. Propose in plan mode the variant: write a new sibling path `{dir}/{random}.{type}`, verify, then in one transaction (row locked, unchanged) set `path` and `encryption_key_id`, and delete the old plaintext after commit (leftovers are caught by `files:prune`). Pick one with the developer.
- The `local` disk has `'throw' => false`: `put()`/`move()`/`delete()` return false instead of throwing. Check every return value and abort that file.
- Commands update rows through the query builder (no `updated_at` change, no activity) under `Activity::withoutRecording`, one row per transaction with `lockForUpdate`, like `ReencryptSecrets`.
- Migration: additive nullable column, no backfill; `down()` refuses (throws) when any row has a non-null `encryption_key_id`, then drops the column.
- `files:encrypt-existing --dry-run` reports per table: to encrypt, already encrypted, missing on disk, skipped. After a real run "to encrypt" must be 0 (the acceptance query).

## Implementation guidance
- `declare(strict_types=1);` everywhere; `final` support classes; `php artisan make:migration add_encryption_key_id_to_file_tables --no-interaction`, `php artisan make:command EncryptExistingFiles --no-interaction`, `php artisan make:command DecryptStoredFile --no-interaction`.
- New upload path: `{directory}/{Str::random(40)}.{type->value}` (extension from `FileType`, per `.ai/rules/enums.md`), contents from `$upload->get()`.
- Build responses with `response()->streamDownload(fn () => print($bytes), $name, $headers, $disposition)` after decryption; keep each controller's header set; downloads keep `assertDownload()` behaviour.
- Keyring: parse keys like `EncryptionServiceProvider` (`base64:` prefix), build one `Encrypter` per key id, memoize per request.
- `security:reencrypt` without `--files` behaves exactly as today (`ReencryptSecretsCommandTest` stays green); with `--files` re-keys every file whose `encryption_key_id` is not the current id, supports `--dry-run`. Confirm in plan mode whether `--files` also runs the database columns.
- `files:decrypt {--id=}` is ambiguous (ids repeat across the three tables): propose `files:decrypt {table : media|report_files|task_attachments} {--id=} {--to=}`.
- Console output in English (like existing commands), counts and ids only.
```php
final class StoredFiles
{
    /** Encrypt and write the bytes; returns the key id to store on the row. */
    public static function putEncrypted(string $disk, string $path, string $contents): string {}

    /** Plaintext bytes: decrypted when $keyId is set, raw for legacy rows. @throws UnreadableStoredFile */
    public static function contents(string $disk, string $path, ?string $keyId): string {}

    /** The caller has already authorized. Decrypts first, then builds the response. */
    public static function stream(string $disk, string $path, ?string $keyId, string $downloadName, FileType $type, array $headers = [], string $disposition = 'attachment'): StreamedResponse {}
}
```

## Tests (Pest)
- Create `tests/Feature/StoredFileEncryptionTest.php` (dataset over `pages::projects.show`, `pages::proposals.show`, `pages::reports.show`, `pages::tasks.show`): stored bytes differ from the upload, `encryption_key_id` = configured id, `size` = original; download and preview return the exact original (sha256 equal) with `Content-Type` from `FileType`; a legacy plaintext row still downloads and previews; wrong key and unknown key id render the friendly page and log the row id (`Log::spy()`); missing key refuses the upload with the message, writes no row and no file; forbidden user 403 and foreign id 404 on encrypted files; replacing an encrypted file removes the old one; the temporary upload is gone after save.
- Create `tests/Feature/EncryptExistingFilesCommandTest.php`: encrypts plaintext rows in all three tables and downloads still match; second run changes nothing; `--dry-run` writes nothing and prints the counts; skips missing, reference-only and non-local rows; fails without writes on a missing or invalid key; leaves no `.enc.tmp`.
- Create `tests/Feature/ReencryptFilesCommandTest.php`: k1 → k2 with k1 in previous keys re-keys every file; downloads work after k1 is removed; `security:reencrypt` without `--files` is unchanged.
- Create `tests/Feature/DecryptStoredFileCommandTest.php`: exact bytes written to the destination; refuses overwrite and forbidden destinations; unknown id fails.
- Keep green: `PrivateStorageRoutesTest.php`, `MediaTest.php`, `ProjectFileCrudTest.php`, `ReportFileCrudTest.php`, `TaskAttachmentCrudTest.php`, `ProposalDetailActionsTest.php`, `SecureUploadTest.php`, `PruneStoredFilesTest.php`, `ReencryptSecretsCommandTest.php`, `SecretHygieneTest.php`, `ErrorPagesTest.php`, `DatabaseEncodingTest.php`, `LocalizationTest.php`, `Models/{Media,ReportFile,TaskAttachment}Test.php`.
- Swap keys in tests by setting config and forgetting the memoized keyring (mirror `useAppKey()`). Run `php artisan test --compact tests/Feature/StoredFileEncryptionTest.php`, then each file above or `--filter=...`.

## Acceptance criteria
- [ ] New uploads in the project, proposal, report and task forms are ciphertext on disk with `encryption_key_id`; download and preview return the original bytes.
- [ ] Legacy rows download until encrypted; after `files:encrypt-existing` the dry run reports 0 plaintext files and a rerun is a no-op.
- [ ] `security:reencrypt --files` rotates the key and downloads still work after the old key is removed.
- [ ] Wrong or missing key gives a localized message and a log line, never a raw 500, a path or crypto details.
- [ ] `MediaTest`, `ProjectFileCrudTest`, `PrivateStorageRoutesTest`, `SecretHygieneTest` pass; Pint clean.
- [ ] Merged by end of day 5 so Tasks 31 and 17 inherit encryption.

## Do not
- Use `APP_KEY` or the `Crypt` facade for files, or add a dependency (the Encrypter is built in).
- Run `SecureUpload`, type or size checks after encryption or on ciphertext.
- Overwrite the original before the encrypted copy is verified, or ignore a `false` from `Storage`.
- Change routes, policies, rate limits, add public or signed URLs, or serve the detected MIME.
- Fall back to plaintext when the key is missing; log or print a key or plaintext; commit a key anywhere.

## Quality bar (this task)
- Users only ever see the friendly «تعذّر فتح الملف، راجع قسم تقنية المعلومات» on a decryption failure; downloads and previews look exactly as before.
- Commands print clear progress (processed / skipped / failed counts) and always show the `--dry-run` summary first.
- Everything in `quality-bar.md` applies. Before reporting, run `vendor/bin/phpstan analyse` on the touched paths and list the result in your report.

## Working method
- Start in plan mode: restate the task in 5 bullets, list files you will touch, list open questions (D-19, config location, key id default, same-path vs new-path rewrite, `--files` semantics, `files:decrypt` signature, where the key-backup text goes); wait for approval.
- Implement in small steps; after each step run the narrowest relevant tests.
- Run `vendor/bin/pint --dirty --format agent` after PHP changes.
- If you discover a durable rule or trap, record it with the Boost `record-rule` tool (not native memory).
- Do not commit, push, or run destructive commands (`migrate:fresh`, seeders that reset roles, deleting storage) unless the developer asks.
- Never run anything against production.
- Stay inside Task 22's scope; note anything else as a follow-up.

## Report back
Your final message must contain: summary; files changed; migration added; tests run with pass/fail output; decisions assumed (D-19, config location, key id default, rewrite variant, `--files` semantics, `files:decrypt` signature, temp-upload deletion); follow-ups (residual plaintext in `livewire-tmp` and old backups). Handoff facts: Tasks 07, 17, 19 and 31 must store through `StoredFiles::save()`/`putEncrypted()`, stream through `StoredFiles::stream()`, add `encryption_key_id` to any new file table and register it in the commands' table list; Task 27 (Dev 1, rehearsal on the production copy) needs the dry-run counts on local data, the time per 100 files and the backup-before-run step; Task 29 needs the env variables, the exact command sequence (`--dry-run`, real run, verification count), the rotation steps and where the key copy is kept, plus the fallback if the existing-file migration is deferred after launch (R9).
````
