<?php

use App\Enums\FileType;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

beforeEach(fn () => $this->seed(RolesAndPermissionsSeeder::class));

/**
 * Upload a real file to a task through the page and return its row.
 */
function uploadTaskAttachment(Task $task, User $user, UploadedFile $upload, string $displayName = ''): TaskAttachment
{
    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->call('openCreateFileModal')
        ->set('newFile', $upload)
        ->set('fileDisplayName', $displayName)
        ->set('fileDate', '2026-03-31')
        ->call('saveFile')
        ->assertHasNoErrors()
        ->assertSet('showFileModal', false);

    return TaskAttachment::query()->where('task_id', $task->id)->latest('id')->firstOrFail();
}

test('uploading a file stores it on the private disk with its metadata', function () {
    Storage::fake('local');
    $user = User::factory()->role('Super Admin')->create();
    $task = Task::factory()->create();

    $file = uploadTaskAttachment($task, $user, validUpload('attendance.pdf', 120), 'Attendance sheet');

    expect($file->type)->toBe(FileType::Pdf)
        ->and($file->original_name)->toBe('Attendance sheet.pdf')
        ->and($file->disk)->toBe('local')
        ->and($file->size)->toBe(120 * 1024)
        ->and($file->uploaded_by)->toBe($user->id)
        ->and($file->date->toDateString())->toBe('2026-03-31')
        ->and($file->path)->toStartWith('task-attachments/'.$task->id.'/');

    Storage::disk('local')->assertExists($file->path);
});

test('a blank display name falls back to the uploaded file name', function () {
    Storage::fake('local');
    $task = Task::factory()->create();

    $file = uploadTaskAttachment($task, User::factory()->role('Super Admin')->create(), UploadedFile::fake()->image('site-visit.png'));

    expect($file->original_name)->toBe('site-visit.png')
        ->and($file->type)->toBe(FileType::Png);
});

test('an upload is required, capped at 5MB and limited to the allowed types', function (?callable $makeFile) {
    Storage::fake('local');
    $task = Task::factory()->create();

    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::tasks.show', ['task' => $task])
        ->call('openCreateFileModal')
        ->set('newFile', $makeFile === null ? null : $makeFile())
        ->call('saveFile')
        ->assertHasErrors('newFile');

    expect(TaskAttachment::query()->where('task_id', $task->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles('task-attachments'))->toBe([]);
})->with([
    'missing' => [null],
    'too large' => [fn () => UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf')],
    'disallowed type' => [fn () => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload')],
]);

test('editing updates the name and date without touching the stored file', function () {
    Storage::fake('local');
    $user = User::factory()->role('Super Admin')->create();
    $task = Task::factory()->create();
    $file = uploadTaskAttachment($task, $user, validUpload('list.xlsx', 50));
    $originalPath = $file->path;

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->call('openEditFileModal', $file->id)
        ->assertSet('fileDisplayName', 'list.xlsx')
        ->set('fileDisplayName', 'Distribution list Q1')
        ->set('fileDate', '2026-02-15')
        ->call('saveFile')
        ->assertHasNoErrors();

    $file->refresh();

    expect($file->original_name)->toBe('Distribution list Q1.xlsx')
        ->and($file->date->toDateString())->toBe('2026-02-15')
        ->and($file->path)->toBe($originalPath);

    Storage::disk('local')->assertExists($originalPath);
});

test('editing with a replacement file swaps the stored file and removes the old one', function () {
    Storage::fake('local');
    $user = User::factory()->role('Super Admin')->create();
    $task = Task::factory()->create();
    $file = uploadTaskAttachment($task, $user, validUpload('draft.docx', 50), 'Narrative');
    $oldPath = $file->path;

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->call('openEditFileModal', $file->id)
        ->set('newFile', validUpload('final.pdf', 80))
        ->set('fileDisplayName', 'Narrative')
        ->call('saveFile')
        ->assertHasNoErrors();

    $file->refresh();

    expect($file->type)->toBe(FileType::Pdf)
        ->and($file->original_name)->toBe('Narrative.pdf')
        ->and($file->size)->toBe(80 * 1024)
        ->and($file->path)->not->toBe($oldPath);

    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($file->path);
});

test('deleting a file removes its row and its stored file', function () {
    Storage::fake('local');
    $user = User::factory()->role('Super Admin')->create();
    $task = Task::factory()->create();
    $file = uploadTaskAttachment($task, $user, validUpload('evidence.pdf', 10));

    Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->call('confirmDeleteFile', $file->id)
        ->assertSet('showDeleteFileModal', true)
        ->call('deleteFile')
        ->assertSet('showDeleteFileModal', false);

    $this->assertDatabaseMissing('task_attachments', ['id' => $file->id]);
    Storage::disk('local')->assertMissing($file->path);
});

test('a reference-only attachment record can still be deleted', function () {
    $task = Task::factory()->create();
    $file = TaskAttachment::factory()->create(['task_id' => $task->id]);

    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::tasks.show', ['task' => $task])
        ->call('confirmDeleteFile', $file->id)
        ->call('deleteFile');

    $this->assertDatabaseMissing('task_attachments', ['id' => $file->id]);
});

test('a user without update permission cannot open the upload, edit or delete actions', function (string $method, array $args) {
    // An assignee sees the task but holds no right to change its files.
    $employeeId = insertHrEmployee();
    $assignee = User::factory()->role('Team Member')->create(['employee_id' => $employeeId]);
    $task = Task::factory()->create();
    $task->taskAssignees()->create(['employee_id' => $employeeId]);

    Livewire::actingAs($assignee)
        ->test('pages::tasks.show', ['task' => $task])
        ->call($method, ...$args)
        ->assertForbidden();
})->with([
    'create' => ['openCreateFileModal', []],
    'edit' => ['openEditFileModal', [0]],
    'delete' => ['confirmDeleteFile', [0]],
]);

test('the download route streams a stored file under its display name', function () {
    Storage::fake('local');
    $user = User::factory()->role('Super Admin')->create();
    $task = Task::factory()->create();
    $file = uploadTaskAttachment($task, $user, validUpload('sheet.pdf'), 'Signed sheet');

    $this->actingAs($user)
        ->get(route('tasks.files.download', [$task, $file]))
        ->assertOk()
        ->assertDownload('Signed sheet.pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff');

    $this->actingAs($user)
        ->get(route('tasks.show', $task))
        ->assertSee(route('tasks.files.download', [$task, $file]), false);
});

test('the download route rejects guests, reference-only rows and files from another task', function () {
    Storage::fake('local');
    $user = User::factory()->role('Super Admin')->create();
    $task = Task::factory()->create();
    $otherTask = Task::factory()->create();
    $stored = uploadTaskAttachment($task, $user, validUpload('a.pdf', 10));
    $referenceOnly = TaskAttachment::factory()->create(['task_id' => $task->id]);

    auth()->forgetGuards();
    $this->get(route('tasks.files.download', [$task, $stored]))->assertRedirect(route('login'));

    $this->actingAs($user)->get(route('tasks.files.download', [$otherTask, $stored]))->assertNotFound();
    $this->actingAs($user)->get(route('tasks.files.download', [$task, $referenceOnly]))->assertNotFound();
});

test('edit and delete refuse an attachment that belongs to another task', function (string $method) {
    $task = Task::factory()->create();
    $foreignFile = TaskAttachment::factory()->create();

    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::tasks.show', ['task' => $task])
        ->call($method, $foreignFile->id)
        ->assertNotFound();
})->with(['openEditFileModal', 'confirmDeleteFile']);

test('the preview route serves pdfs and images inline with a safe content type', function (string $name, string $contentType, ?string $policy) {
    Storage::fake('local');
    $user = User::factory()->role('Super Admin')->create();
    $task = Task::factory()->create();
    $file = uploadTaskAttachment($task, $user, validUpload($name, 10));

    $response = $this->actingAs($user)->get(route('tasks.files.preview', [$task, $file]))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe($contentType)
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff')
        ->and($response->headers->get('Content-Security-Policy'))->toBe($policy);
})->with([
    'pdf' => ['a.pdf', 'application/pdf', null],
    'png' => ['a.png', 'image/png', 'sandbox'],
]);

test('the preview route refuses office files, reference-only rows and files from another task', function () {
    Storage::fake('local');
    $user = User::factory()->role('Super Admin')->create();
    $task = Task::factory()->create();
    $docx = uploadTaskAttachment($task, $user, validUpload('a.docx', 10));
    $pdf = uploadTaskAttachment($task, $user, validUpload('a.pdf', 10));
    $referenceOnly = TaskAttachment::factory()->create(['task_id' => $task->id, 'type' => FileType::Pdf]);

    $this->actingAs($user)->get(route('tasks.files.preview', [$task, $docx]))->assertNotFound();
    $this->actingAs($user)->get(route('tasks.files.preview', [$task, $referenceOnly]))->assertNotFound();
    $this->actingAs($user)->get(route('tasks.files.preview', [Task::factory()->create(), $pdf]))->assertNotFound();
});

test('the preview modal renders the right viewer and steps between files', function () {
    Storage::fake('local');
    $user = User::factory()->role('Super Admin')->create();
    $task = Task::factory()->create();
    $pdf = uploadTaskAttachment($task, $user, validUpload('a.pdf', 10));
    $docx = uploadTaskAttachment($task, $user, validUpload('b.docx', 10));

    $component = Livewire::actingAs($user)
        ->test('pages::tasks.show', ['task' => $task])
        ->call('openPreview', $pdf->id)
        ->assertSet('showPreviewModal', true)
        ->assertSeeHtml('data-test="pdf-preview"');

    $component->call('previewAdjacent', 1)
        ->assertSet('previewingFileId', $docx->id)
        ->assertSeeHtml('data-test="office-preview"')
        ->call('previewAdjacent', 1)
        ->assertSet('previewingFileId', $pdf->id)
        ->call('closePreview')
        ->assertSet('showPreviewModal', false);
});

test('opening a preview refuses an attachment from another task', function () {
    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::tasks.show', ['task' => Task::factory()->create()])
        ->call('openPreview', TaskAttachment::factory()->create()->id)
        ->assertNotFound();
});
