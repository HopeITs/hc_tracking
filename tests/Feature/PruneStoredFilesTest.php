<?php

use App\Models\Media;
use App\Models\ReportFile;
use App\Models\TaskAttachment;
use Illuminate\Support\Facades\Storage;

/**
 * Write a file to a faked disk and backdate it by the given number of hours.
 */
function putAgedFile(string $disk, string $path, int $hoursOld): void
{
    Storage::disk($disk)->put($path, 'content');
    touch(Storage::disk($disk)->path($path), now()->subHours($hoursOld)->getTimestamp());
}

beforeEach(function () {
    Storage::fake('local');
    Storage::fake('tmp-for-tests');

    // Owned by a row: kept whatever their age.
    putAgedFile('local', 'media/projects/1/owned.pdf', 48);
    putAgedFile('local', 'report-files/1/owned.pdf', 48);
    putAgedFile('local', 'task-attachments/1/owned.pdf', 48);
    Media::factory()->create(['path' => 'media/projects/1/owned.pdf']);
    ReportFile::factory()->create(['path' => 'report-files/1/owned.pdf', 'disk' => 'local']);
    TaskAttachment::factory()->create(['path' => 'task-attachments/1/owned.pdf', 'disk' => 'local']);

    // Left behind by deleted records.
    putAgedFile('local', 'media/proposals/9/orphan.pdf', 48);
    putAgedFile('local', 'report-files/9/orphan.docx', 48);
    putAgedFile('local', 'task-attachments/9/orphan.png', 48);

    // An upload written moments before its row: still inside the grace period.
    putAgedFile('local', 'task-attachments/2/just-uploaded.pdf', 1);

    // Outside the upload folders: never touched.
    putAgedFile('local', 'exports/old.csv', 48);

    putAgedFile('tmp-for-tests', 'livewire-tmp/abandoned.pdf', 48);
    putAgedFile('tmp-for-tests', 'livewire-tmp/in-progress.pdf', 1);
});

test('it deletes old files no record points at and abandoned temporary uploads, and keeps everything else', function () {
    $this->artisan('files:prune')->assertSuccessful();

    $local = Storage::disk('local');
    $local->assertMissing(['media/proposals/9/orphan.pdf', 'report-files/9/orphan.docx', 'task-attachments/9/orphan.png']);
    $local->assertExists([
        'media/projects/1/owned.pdf',
        'report-files/1/owned.pdf',
        'task-attachments/1/owned.pdf',
        'task-attachments/2/just-uploaded.pdf',
        'exports/old.csv',
    ]);

    Storage::disk('tmp-for-tests')->assertMissing('livewire-tmp/abandoned.pdf');
    Storage::disk('tmp-for-tests')->assertExists('livewire-tmp/in-progress.pdf');
});

test('a file whose row points at another disk is not treated as owned', function () {
    ReportFile::factory()->create(['path' => 'report-files/9/orphan.docx', 'disk' => 's3']);

    $this->artisan('files:prune')->assertSuccessful();

    Storage::disk('local')->assertMissing('report-files/9/orphan.docx');
});

test('a dry run lists what would go and deletes nothing', function () {
    $this->artisan('files:prune', ['--dry-run' => true])
        ->expectsOutputToContain('media/proposals/9/orphan.pdf')
        ->expectsOutputToContain('livewire-tmp/abandoned.pdf')
        ->assertSuccessful();

    Storage::disk('local')->assertExists('media/proposals/9/orphan.pdf');
    Storage::disk('tmp-for-tests')->assertExists('livewire-tmp/abandoned.pdf');
});
