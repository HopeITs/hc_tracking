<?php

use App\Enums\FileType;
use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * Upload a real file to a draft report through the page and return its row.
 */
function uploadReportFile(Report $report, User $user, UploadedFile $upload, string $displayName = ''): ReportFile
{
    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
        ->call('openCreateFileModal')
        ->set('newFile', $upload)
        ->set('fileDisplayName', $displayName)
        ->set('fileDate', '2026-03-31')
        ->call('saveFile')
        ->assertHasNoErrors()
        ->assertSet('showFileModal', false);

    return ReportFile::query()->where('report_id', $report->id)->latest('id')->firstOrFail();
}

test('uploading a file stores it on the private disk with its metadata', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Draft]);

    $file = uploadReportFile($report, $user, UploadedFile::fake()->create('attendance.pdf', 120, 'application/pdf'), 'Attendance sheet');

    expect($file->type)->toBe(FileType::Pdf)
        ->and($file->original_name)->toBe('Attendance sheet.pdf')
        ->and($file->disk)->toBe('local')
        ->and($file->size)->toBe(120 * 1024)
        ->and($file->uploaded_by)->toBe($user->id)
        ->and($file->date->toDateString())->toBe('2026-03-31')
        ->and($file->path)->toStartWith('report-files/'.$report->id.'/');

    Storage::disk('local')->assertExists($file->path);
});

test('a blank display name falls back to the uploaded file name', function () {
    Storage::fake('local');
    $report = Report::factory()->create(['status' => ReportStatus::Draft]);

    $file = uploadReportFile($report, User::factory()->create(), UploadedFile::fake()->image('site-visit.png'));

    expect($file->original_name)->toBe('site-visit.png')
        ->and($file->type)->toBe(FileType::Png);
});

test('an upload is required, capped at 5MB and limited to the allowed types', function (?callable $makeFile) {
    Storage::fake('local');
    $report = Report::factory()->create(['status' => ReportStatus::Draft]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::reports.show', ['report' => $report])
        ->call('openCreateFileModal')
        ->set('newFile', $makeFile === null ? null : $makeFile())
        ->call('saveFile')
        ->assertHasErrors('newFile');

    expect(ReportFile::query()->where('report_id', $report->id)->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles('report-files'))->toBe([]);
})->with([
    'missing' => [null],
    'too large' => [fn () => UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf')],
    'disallowed type' => [fn () => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload')],
]);

test('editing updates the name and date without touching the stored file', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Draft]);
    $file = uploadReportFile($report, $user, UploadedFile::fake()->create('list.xlsx', 50));
    $originalPath = $file->path;

    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
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
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Draft]);
    $file = uploadReportFile($report, $user, UploadedFile::fake()->create('draft.docx', 50), 'Narrative');
    $oldPath = $file->path;

    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
        ->call('openEditFileModal', $file->id)
        ->set('newFile', UploadedFile::fake()->create('final.pdf', 80, 'application/pdf'))
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
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Draft]);
    $file = uploadReportFile($report, $user, UploadedFile::fake()->create('evidence.pdf', 10, 'application/pdf'));

    Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
        ->call('confirmDeleteFile', $file->id)
        ->assertSet('showDeleteFileModal', true)
        ->call('deleteFile')
        ->assertSet('showDeleteFileModal', false);

    $this->assertDatabaseMissing('report_files', ['id' => $file->id]);
    Storage::disk('local')->assertMissing($file->path);
});

test('a reference-only file record can still be deleted', function () {
    $report = Report::factory()->create(['status' => ReportStatus::Draft]);
    $file = ReportFile::factory()->create(['report_id' => $report->id]);

    Livewire::actingAs(User::factory()->create())
        ->test('pages::reports.show', ['report' => $report])
        ->call('confirmDeleteFile', $file->id)
        ->call('deleteFile');

    $this->assertDatabaseMissing('report_files', ['id' => $file->id]);
});

test('the download route streams a stored file under its display name', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Draft]);
    $file = uploadReportFile($report, $user, UploadedFile::fake()->createWithContent('sheet.pdf', 'PDF-CONTENT'), 'Signed sheet');

    $this->actingAs($user)
        ->get(route('reports.files.download', [$report, $file]))
        ->assertOk()
        ->assertDownload('Signed sheet.pdf');

    $this->actingAs($user)
        ->get(route('reports.show', $report))
        ->assertSee(route('reports.files.download', [$report, $file]), false);
});

test('the download route rejects guests, reference-only rows and files from another report', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Draft]);
    $otherReport = Report::factory()->create();
    $stored = uploadReportFile($report, $user, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
    $referenceOnly = ReportFile::factory()->create(['report_id' => $report->id]);

    auth()->forgetGuards();
    $this->get(route('reports.files.download', [$report, $stored]))->assertRedirect(route('login'));

    $this->actingAs($user)->get(route('reports.files.download', [$otherReport, $stored]))->assertNotFound();
    $this->actingAs($user)->get(route('reports.files.download', [$report, $referenceOnly]))->assertNotFound();
});

test('edit and delete refuse a file that belongs to another report', function (string $method) {
    $report = Report::factory()->create(['status' => ReportStatus::Draft]);
    $foreignFile = ReportFile::factory()->create();

    Livewire::actingAs(User::factory()->create())
        ->test('pages::reports.show', ['report' => $report])
        ->call($method, $foreignFile->id)
        ->assertNotFound();
})->with(['openEditFileModal', 'confirmDeleteFile']);

test('stored files stay downloadable once the report is locked', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Draft]);
    $file = uploadReportFile($report, $user, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
    $report->update(['status' => ReportStatus::Approved]);

    $this->actingAs($user)
        ->get(route('reports.show', $report))
        ->assertSee(route('reports.files.download', [$report, $file]), false)
        ->assertDontSee('wire:click="confirmDeleteFile', false);

    $this->actingAs($user)->get(route('reports.files.download', [$report, $file]))->assertOk();
});

test('the preview route serves pdfs and images inline with a safe content type', function (string $name, string $contentType) {
    Storage::fake('local');
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Draft]);
    $file = uploadReportFile($report, $user, UploadedFile::fake()->create($name, 10, $contentType));

    $response = $this->actingAs($user)->get(route('reports.files.preview', [$report, $file]))->assertOk();

    expect($response->headers->get('Content-Type'))->toBe($contentType)
        ->and($response->headers->get('Content-Disposition'))->toStartWith('inline')
        ->and($response->headers->get('X-Content-Type-Options'))->toBe('nosniff');
})->with([
    'pdf' => ['a.pdf', 'application/pdf'],
    'png' => ['a.png', 'image/png'],
]);

test('the preview route refuses office files, reference-only rows and files from another report', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Draft]);
    $docx = uploadReportFile($report, $user, UploadedFile::fake()->create('a.docx', 10));
    $pdf = uploadReportFile($report, $user, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
    $referenceOnly = ReportFile::factory()->create(['report_id' => $report->id, 'type' => FileType::Pdf]);

    $this->actingAs($user)->get(route('reports.files.preview', [$report, $docx]))->assertNotFound();
    $this->actingAs($user)->get(route('reports.files.preview', [$report, $referenceOnly]))->assertNotFound();
    $this->actingAs($user)->get(route('reports.files.preview', [Report::factory()->create(), $pdf]))->assertNotFound();
});

test('the preview modal renders the right viewer and steps between files, even on a locked report', function () {
    Storage::fake('local');
    $user = User::factory()->create();
    $report = Report::factory()->create(['status' => ReportStatus::Draft]);
    $pdf = uploadReportFile($report, $user, UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'));
    $docx = uploadReportFile($report, $user, UploadedFile::fake()->create('b.docx', 10));
    $report->update(['status' => ReportStatus::Approved]);

    $component = Livewire::actingAs($user)
        ->test('pages::reports.show', ['report' => $report])
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

test('opening a preview refuses a file from another report', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('pages::reports.show', ['report' => Report::factory()->create()])
        ->call('openPreview', ReportFile::factory()->create()->id)
        ->assertNotFound();
});
