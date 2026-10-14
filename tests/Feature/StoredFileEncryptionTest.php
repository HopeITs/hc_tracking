<?php

use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Enums\ProposalStatus;
use App\Enums\ReportStatus;
use App\Models\Media;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    Storage::fake('tmp-for-tests');
});

/**
 * Everything the encryption tests need to know about one upload surface: how
 * to open its page, who may upload, which routes stream its files, and the
 * same routes under a different parent (the IDOR probe).
 *
 * @return array{
 *     table: string,
 *     page: callable(): Testable,
 *     user: User,
 *     fill: callable(Testable): Testable,
 *     row: callable(): Model,
 *     download: callable(Model): string,
 *     preview: callable(Model): string,
 *     foreignDownload: callable(Model): string,
 *     edit: callable(Testable, Model): Testable,
 * }
 */
function uploadSurface(string $kind): array
{
    return match ($kind) {
        'project' => (function (): array {
            $project = Project::factory()->create();
            $user = User::factory()->role('General Manager')->create();

            return [
                'table' => 'media',
                'page' => fn () => Livewire::actingAs($user)->test('pages::projects.show', ['project' => $project])->set('tab', 'files')->call('openCreateFileModal'),
                'user' => $user,
                'fill' => fn (Testable $page) => $page->set('fileCategory', MediaCollection::Photo->value)->set('fileDisplayName', 'Site document'),
                'row' => fn () => Media::query()->where('mediable_type', 'project')->where('mediable_id', $project->id)->latest('id')->firstOrFail(),
                'download' => fn (Model $row) => route('projects.files.download', [$project, $row]),
                'preview' => fn (Model $row) => route('projects.files.download', [$project, $row]),
                'foreignDownload' => fn (Model $row) => route('projects.files.download', [Project::factory()->create(), $row]),
                'edit' => null,
            ];
        })(),
        'proposal' => (function (): array {
            $proposal = Proposal::factory()->create(['status' => ProposalStatus::Active]);
            $user = User::factory()->role('Financial')->create();

            return [
                'table' => 'media',
                'page' => fn () => Livewire::actingAs($user)->test('pages::proposals.show', ['proposal' => $proposal])->call('openCreateFileModal'),
                'user' => $user,
                'fill' => fn (Testable $page) => $page->set('fileCategory', MediaCollection::Evidence->value)->set('fileDisplayName', 'Evidence'),
                'row' => fn () => Media::query()->where('mediable_type', 'proposal')->where('mediable_id', $proposal->id)->latest('id')->firstOrFail(),
                'download' => fn (Model $row) => route('proposals.files.download', [$proposal, $row]),
                'preview' => fn (Model $row) => route('proposals.files.preview', [$proposal, $row]),
                'foreignDownload' => fn (Model $row) => route('proposals.files.download', [Proposal::factory()->create(), $row]),
                'edit' => fn (Testable $page, Model $row) => $page->call('openEditFileModal', $row->id)->set('fileDisplayName', 'Evidence'),
            ];
        })(),
        'report' => (function (): array {
            $report = Report::factory()->create(['status' => ReportStatus::Draft]);
            $user = reportAuthor($report);

            return [
                'table' => 'report_files',
                'page' => fn () => Livewire::actingAs($user)->test('pages::reports.show', ['report' => $report])->call('openCreateFileModal'),
                'user' => $user,
                'fill' => fn (Testable $page) => $page->set('fileDisplayName', 'Attendance')->set('fileDate', '2026-03-31'),
                'row' => fn () => ReportFile::query()->where('report_id', $report->id)->latest('id')->firstOrFail(),
                'download' => fn (Model $row) => route('reports.files.download', [$report, $row]),
                'preview' => fn (Model $row) => route('reports.files.preview', [$report, $row]),
                'foreignDownload' => fn (Model $row) => route('reports.files.download', [Report::factory()->create(), $row]),
                'edit' => fn (Testable $page, Model $row) => $page->call('openEditFileModal', $row->id)->set('fileDisplayName', 'Attendance')->set('fileDate', '2026-03-31'),
            ];
        })(),
        'task' => (function (): array {
            $task = Task::factory()->create();
            $user = User::factory()->role('Super Admin')->create();

            return [
                'table' => 'task_attachments',
                'page' => fn () => Livewire::actingAs($user)->test('pages::tasks.show', ['task' => $task])->call('openCreateFileModal'),
                'user' => $user,
                'fill' => fn (Testable $page) => $page->set('fileDisplayName', 'Minutes')->set('fileDate', '2026-03-31'),
                'row' => fn () => TaskAttachment::query()->where('task_id', $task->id)->latest('id')->firstOrFail(),
                'download' => fn (Model $row) => route('tasks.files.download', [$task, $row]),
                'preview' => fn (Model $row) => route('tasks.files.preview', [$task, $row]),
                'foreignDownload' => fn (Model $row) => route('tasks.files.download', [Task::factory()->create(), $row]),
                'edit' => fn (Testable $page, Model $row) => $page->call('openEditFileModal', $row->id)->set('fileDisplayName', 'Minutes')->set('fileDate', '2026-03-31'),
            ];
        })(),
    };
}

/**
 * Upload a PDF through the surface's page and return its row plus the bytes sent.
 *
 * @param  array<string, mixed>  $surface
 * @return array{0: Model, 1: string}
 */
function uploadThrough(array $surface, string $name = 'document.pdf', int $kilobytes = 20): array
{
    $upload = validUpload($name, $kilobytes);
    $original = (string) file_get_contents($upload->getRealPath());

    $page = $surface['page']()->set('newFile', $upload);

    $surface['fill']($page)->call('saveFile')->assertHasNoErrors();

    return [$surface['row'](), $original];
}

/**
 * The bytes of a stored file as they sit on disk.
 */
function bytesOnDisk(Model $row): string
{
    return (string) Storage::disk($row->disk)->get($row->path);
}

dataset('upload surfaces', ['project', 'proposal', 'report', 'task']);

test('a new upload is written encrypted with the configured key id and keeps its original size', function (string $kind) {
    useFileKey(newFileKey(), 'k7');
    $surface = uploadSurface($kind);

    [$row, $original] = uploadThrough($surface);

    expect(bytesOnDisk($row))->not->toBe($original)
        ->and(str_contains(bytesOnDisk($row), '%PDF-1.4'))->toBeFalse()
        ->and($row->encryption_key_id)->toBe('k7')
        ->and($row->size)->toBe(strlen($original))
        ->and($row->type)->toBe(FileType::Pdf)
        ->and($row->path)->toMatch('~/[A-Za-z0-9]{40}\.pdf$~')
        ->and($row->isEncrypted())->toBeTrue();
})->with('upload surfaces');

test('downloads and previews return the exact original bytes with the type taken from FileType', function (string $kind) {
    $surface = uploadSurface($kind);
    [$row, $original] = uploadThrough($surface);

    $download = $this->actingAs($surface['user'])->get($surface['download']($row))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    expect(hash('sha256', $download->streamedContent()))->toBe(hash('sha256', $original))
        ->and($download->headers->get('Content-Type'))->toStartWith('application/pdf');

    $preview = $this->actingAs($surface['user'])->get($surface['preview']($row))->assertOk();

    expect(hash('sha256', $preview->streamedContent()))->toBe(hash('sha256', $original))
        ->and($preview->headers->get('Content-Type'))->toStartWith('application/pdf');
})->with('upload surfaces');

test('a legacy plaintext row still downloads and previews as stored', function (string $kind) {
    $surface = uploadSurface($kind);
    [$row] = uploadThrough($surface);
    $legacy = validUpload('legacy.pdf', 5);
    $bytes = (string) file_get_contents($legacy->getRealPath());

    Storage::disk($row->disk)->put($row->path, $bytes);
    DB::table($surface['table'])->where('id', $row->id)->update(['encryption_key_id' => null]);

    $download = $this->actingAs($surface['user'])->get($surface['download']($row->fresh()))->assertOk();
    $preview = $this->actingAs($surface['user'])->get($surface['preview']($row->fresh()))->assertOk();

    expect($download->streamedContent())->toBe($bytes)
        ->and($preview->streamedContent())->toBe($bytes);
})->with('upload surfaces');

test('a wrong key renders the friendly page and logs only the table, row id and key id', function (string $kind) {
    $surface = uploadSurface($kind);
    [$row] = uploadThrough($surface);

    useFileKey(newFileKey(), 'k1');
    Log::spy();

    $response = $this->actingAs($surface['user'])->get($surface['download']($row));

    $response->assertStatus(500)->assertSee(__('errors.file_unreadable'));

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context = []): bool => $context === ['table' => $surface['table'], 'id' => $row->id, 'key_id' => 'k1'],
    )->once();
})->with('upload surfaces');

test('an unknown key id renders the friendly page on download and preview', function (string $kind) {
    $surface = uploadSurface($kind);
    [$row] = uploadThrough($surface);

    DB::table($surface['table'])->where('id', $row->id)->update(['encryption_key_id' => 'gone']);
    Log::spy();

    $this->actingAs($surface['user'])->get($surface['download']($row->fresh()))
        ->assertStatus(500)
        ->assertSee(__('errors.file_unreadable'));
    $this->actingAs($surface['user'])->get($surface['preview']($row->fresh()))->assertStatus(500);

    Log::shouldHaveReceived('error')->withArgs(
        fn (string $message, array $context = []): bool => ($context['key_id'] ?? null) === 'gone' && $context['id'] === $row->id,
    )->twice();
})->with('upload surfaces');

test('a corrupt file renders the friendly page', function (string $kind) {
    $surface = uploadSurface($kind);
    [$row] = uploadThrough($surface);

    Storage::disk($row->disk)->put($row->path, substr(bytesOnDisk($row), 0, -5).'xxxxx');

    $this->actingAs($surface['user'])->get($surface['download']($row))
        ->assertStatus(500)
        ->assertSee(__('errors.file_unreadable'));
})->with('upload surfaces');

test('with no valid key the upload is refused on the file field and nothing is written', function (string $kind, ?string $key) {
    useFileKey($key);
    $surface = uploadSurface($kind);
    $before = count(Storage::disk('local')->allFiles());
    $table = $surface['table'];
    $rows = DB::table($table)->count();

    $page = $surface['page']()->set('newFile', validUpload('document.pdf', 20));
    $surface['fill']($page)->call('saveFile')->assertHasErrors('newFile');

    expect($page->errors()->get('newFile'))->toBe([__('errors.file_storage_not_configured')])
        ->and(DB::table($table)->count())->toBe($rows)
        ->and(count(Storage::disk('local')->allFiles()))->toBe($before);
})->with('upload surfaces')->with([
    'no key' => [null],
    'short key' => ['base64:'.'c2hvcnQ='],
]);

test('a forbidden user gets 403 and a foreign parent id gets 404 on an encrypted file', function (string $kind) {
    $surface = uploadSurface($kind);
    [$row] = uploadThrough($surface);

    $this->actingAs(User::factory()->create())->get($surface['download']($row))->assertForbidden();
    $this->actingAs($surface['user'])->get($surface['foreignDownload']($row))->assertNotFound();
})->with('upload surfaces');

test('replacing an encrypted file encrypts the new one and removes the old one', function (string $kind) {
    $surface = uploadSurface($kind);

    if ($surface['edit'] === null) {
        $this->markTestSkipped('Project files are replaced by delete and re-upload.');
    }

    [$row] = uploadThrough($surface);
    $oldPath = $row->path;
    $replacement = validUpload('final.pdf', 30);
    $replacementBytes = (string) file_get_contents($replacement->getRealPath());

    $page = $surface['edit']($surface['page'](), $row)->set('newFile', $replacement);
    $page->call('saveFile')->assertHasNoErrors();

    $row->refresh();

    expect($row->path)->not->toBe($oldPath)
        ->and($row->encryption_key_id)->toBe('k1')
        ->and(bytesOnDisk($row))->not->toBe($replacementBytes)
        ->and($row->size)->toBe(strlen($replacementBytes));

    Storage::disk('local')->assertMissing($oldPath);
})->with('upload surfaces');

test('the plaintext temporary upload is deleted after a successful save', function (string $kind) {
    $surface = uploadSurface($kind);

    uploadThrough($surface);

    expect(Storage::disk('tmp-for-tests')->allFiles())->toBe([]);
})->with('upload surfaces');

test('an upload refused by validation never reaches the keyring or the disk', function () {
    useFileKey(null);
    $surface = uploadSurface('report');

    $page = $surface['page']()->set('newFile', UploadedFile::fake()->create('too-big.pdf', 5121, 'application/pdf'));

    $page->assertHasErrors('newFile');

    expect($page->errors()->get('newFile'))->not->toBe([__('errors.file_storage_not_configured')]);
});
