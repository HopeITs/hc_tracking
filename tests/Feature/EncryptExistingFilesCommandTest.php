<?php

use App\Enums\FileType;
use App\Models\Media;
use App\Models\ReportFile;
use App\Models\TaskAttachment;
use App\Support\StoredFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * A legacy row: plaintext on disk, no key id.
 *
 * @param  class-string<Model>  $model
 * @param  array<string, mixed>  $attributes
 */
function plaintextRow(string $model, string $contents, array $attributes = []): Model
{
    $row = $model::factory()->create([
        'type' => FileType::Pdf,
        'path' => 'media/'.Str::random(20).'.pdf',
        'disk' => 'local',
        'original_name' => 'legacy.pdf',
        ...$attributes,
    ]);

    Storage::disk($row->disk)->put($row->path, $contents);

    return $row->fresh();
}

/**
 * @return list<string>
 */
function diskFiles(): array
{
    return collect(Storage::disk('local')->allFiles())->sort()->values()->all();
}

test('plaintext files in all three tables are encrypted and still read back unchanged', function () {
    $rows = [
        plaintextRow(Media::class, 'media bytes'),
        plaintextRow(ReportFile::class, 'report bytes'),
        plaintextRow(TaskAttachment::class, 'task bytes'),
    ];
    $oldPaths = array_map(fn (Model $row): string => $row->path, $rows);

    $this->artisan('files:encrypt-existing')
        ->expectsOutputToContain('media: 1 encrypted')
        ->expectsOutputToContain('report_files: 1 encrypted')
        ->expectsOutputToContain('task_attachments: 1 encrypted')
        ->assertSuccessful();

    foreach ($rows as $index => $row) {
        $fresh = $row->fresh();

        expect($fresh->encryption_key_id)->toBe('k1')
            ->and($fresh->path)->not->toBe($oldPaths[$index])
            ->and($fresh->path)->toEndWith('.pdf')
            ->and(Storage::disk('local')->get($fresh->path))->not->toContain('bytes');

        Storage::disk('local')->assertMissing($oldPaths[$index]);
    }

    expect(StoredFiles::contents('local', $rows[0]->fresh()->path, 'k1'))->toBe('media bytes')
        ->and(StoredFiles::contents('local', $rows[1]->fresh()->path, 'k1'))->toBe('report bytes')
        ->and(StoredFiles::contents('local', $rows[2]->fresh()->path, 'k1'))->toBe('task bytes')
        ->and(DB::table('media')->whereNull('encryption_key_id')->count())->toBe(0);
});

test('a second run changes nothing', function () {
    plaintextRow(Media::class, 'bytes');
    $this->artisan('files:encrypt-existing')->assertSuccessful();

    $files = diskFiles();
    $path = DB::table('media')->value('path');

    $this->artisan('files:encrypt-existing')
        ->expectsOutputToContain('media: 0 encrypted, 1 already encrypted')
        ->assertSuccessful();

    expect(diskFiles())->toBe($files)
        ->and(DB::table('media')->value('path'))->toBe($path);
});

test('a dry run writes nothing and prints the four counts per table', function () {
    $encrypted = plaintextRow(Media::class, 'already');
    $this->artisan('files:encrypt-existing')->assertSuccessful();

    plaintextRow(Media::class, 'one');
    plaintextRow(Media::class, 'two');
    $missing = Media::factory()->create(['type' => FileType::Pdf, 'path' => 'media/gone.pdf']);
    plaintextRow(ReportFile::class, 'refs', ['original_name' => null]);

    $files = diskFiles();
    $rows = DB::table('media')->orderBy('id')->get(['id', 'path', 'encryption_key_id'])->all();

    $this->artisan('files:encrypt-existing', ['--dry-run' => true])
        ->expectsOutputToContain('media: 2 to encrypt, 1 already encrypted, 1 missing on disk, 0 skipped')
        ->expectsOutputToContain('report_files: 0 to encrypt, 0 already encrypted, 0 missing on disk, 1 skipped')
        ->expectsOutputToContain('task_attachments: 0 to encrypt, 0 already encrypted, 0 missing on disk, 0 skipped')
        ->expectsOutputToContain('ids: '.$missing->id)
        ->assertSuccessful();

    expect(diskFiles())->toBe($files)
        ->and(DB::table('media')->orderBy('id')->get(['id', 'path', 'encryption_key_id'])->all())->toEqual($rows)
        ->and($encrypted->fresh()->encryption_key_id)->toBe('k1');
});

test('missing files, reference-only rows and other disks are skipped and counted, never touched', function () {
    $missing = Media::factory()->create(['type' => FileType::Pdf, 'path' => 'media/gone.pdf']);
    $referenceOnly = plaintextRow(TaskAttachment::class, 'ref', ['original_name' => null]);
    $otherDisk = plaintextRow(ReportFile::class, 'cloud');
    DB::table('report_files')->where('id', $otherDisk->id)->update(['disk' => 's3']);
    $files = diskFiles();

    $this->artisan('files:encrypt-existing')
        ->expectsOutputToContain('media: 0 encrypted, 0 already encrypted, 1 missing on disk, 0 skipped')
        ->expectsOutputToContain('report_files: 0 encrypted, 0 already encrypted, 0 missing on disk, 1 skipped')
        ->expectsOutputToContain('task_attachments: 0 encrypted, 0 already encrypted, 0 missing on disk, 1 skipped')
        ->assertSuccessful();

    expect(diskFiles())->toBe($files)
        ->and($missing->fresh()->encryption_key_id)->toBeNull()
        ->and($referenceOnly->fresh()->encryption_key_id)->toBeNull()
        ->and($otherDisk->fresh()->encryption_key_id)->toBeNull();
});

test('a missing or invalid file key fails before anything is read or written', function (?string $key) {
    $row = plaintextRow(Media::class, 'bytes');
    useFileKey($key);
    $files = diskFiles();

    $this->artisan('files:encrypt-existing')->assertFailed();
    $this->artisan('files:encrypt-existing', ['--dry-run' => true])->assertFailed();

    expect(diskFiles())->toBe($files)
        ->and($row->fresh()->encryption_key_id)->toBeNull()
        ->and($row->fresh()->path)->toBe($row->path);
})->with([
    'missing' => [null],
    'invalid' => ['base64:c2hvcnQ='],
]);

test('a failure while switching the row leaves the original and removes the new copy', function () {
    $row = plaintextRow(Media::class, 'bytes');
    $files = diskFiles();

    DB::beforeExecuting(function (string $query): void {
        if (str_starts_with($query, 'update `media`')) {
            throw new RuntimeException('simulated database failure');
        }
    });

    $this->artisan('files:encrypt-existing')
        ->expectsOutputToContain('ids: '.$row->id)
        ->assertFailed();

    expect(diskFiles())->toBe($files)
        ->and(Storage::disk('local')->get($row->path))->toBe('bytes')
        ->and($row->fresh()->encryption_key_id)->toBeNull();
});

test('a row changed between the scan and the lock is skipped and its new copy removed', function () {
    $row = plaintextRow(Media::class, 'bytes');
    $files = diskFiles();
    $changed = false;

    DB::beforeExecuting(function (string $query) use ($row, &$changed): void {
        if (! $changed && str_contains($query, 'for update')) {
            $changed = true;
            DB::table('media')->where('id', $row->id)->update(['path' => 'media/replaced.pdf']);
        }
    });

    $this->artisan('files:encrypt-existing')
        ->expectsOutputToContain('changed while running')
        ->assertSuccessful();

    expect(diskFiles())->toBe($files)
        ->and($row->fresh()->path)->toBe('media/replaced.pdf')
        ->and($row->fresh()->encryption_key_id)->toBeNull();
});

test('a chunk smaller than the number of rows still encrypts every file', function () {
    foreach (range(1, 5) as $number) {
        plaintextRow(Media::class, "bytes {$number}");
    }

    $this->artisan('files:encrypt-existing', ['--chunk' => 2])
        ->expectsOutputToContain('media: 5 encrypted')
        ->assertSuccessful();

    expect(DB::table('media')->whereNull('encryption_key_id')->count())->toBe(0)
        ->and(count(diskFiles()))->toBe(5);
});

test('after a real run a dry run finds nothing left to encrypt', function () {
    plaintextRow(Media::class, 'a');
    plaintextRow(ReportFile::class, 'b');

    $this->artisan('files:encrypt-existing')->assertSuccessful();

    $this->artisan('files:encrypt-existing', ['--dry-run' => true])
        ->expectsOutputToContain('media: 0 to encrypt')
        ->expectsOutputToContain('report_files: 0 to encrypt')
        ->assertSuccessful();
});
