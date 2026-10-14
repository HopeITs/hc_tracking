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
    $this->k1 = newFileKey();
    $this->k2 = newFileKey();
});

/**
 * A stored row whose file is encrypted with whichever key is configured now.
 *
 * @param  class-string<Model>  $model
 */
function encryptedRow(string $model, string $contents): Model
{
    $row = $model::factory()->create([
        'type' => FileType::Pdf,
        'path' => 'media/'.Str::random(40).'.pdf',
        'disk' => 'local',
    ]);

    $keyId = StoredFiles::putEncrypted('local', $row->path, $contents);
    DB::table($row->getTable())->where('id', $row->id)->update(['encryption_key_id' => $keyId]);

    return $row->fresh();
}

/**
 * The bytes a row's file decrypts to with the keys configured now.
 */
function plainBytes(Model $row): string
{
    $fresh = $row->fresh();

    return StoredFiles::contents($fresh->disk, $fresh->path, $fresh->encryption_key_id);
}

test('files encrypted with a previous key are re-keyed to the current key and the old files are removed', function () {
    useFileKey($this->k1, 'k1');
    $rows = [
        'media' => encryptedRow(Media::class, 'media bytes'),
        'report' => encryptedRow(ReportFile::class, 'report bytes'),
        'task' => encryptedRow(TaskAttachment::class, 'task bytes'),
    ];
    $oldPaths = array_map(fn (Model $row): string => $row->path, $rows);

    useFileKey($this->k2, 'k2', ['k1' => $this->k1]);

    $this->artisan('security:reencrypt', ['--files' => true])
        ->expectsOutputToContain('media: 1 re-keyed')
        ->expectsOutputToContain('report_files: 1 re-keyed')
        ->expectsOutputToContain('task_attachments: 1 re-keyed')
        ->assertSuccessful();

    foreach ($rows as $name => $row) {
        $fresh = $row->fresh();

        expect($fresh->encryption_key_id)->toBe('k2')
            ->and($fresh->path)->not->toBe($oldPaths[$name])
            ->and($fresh->path)->toEndWith('.pdf');

        Storage::disk('local')->assertMissing($oldPaths[$name]);
        Storage::disk('local')->assertExists($fresh->path);
    }

    expect(plainBytes($rows['media']))->toBe('media bytes')
        ->and(plainBytes($rows['report']))->toBe('report bytes')
        ->and(plainBytes($rows['task']))->toBe('task bytes');

    useFileKey($this->k2, 'k2');

    expect(plainBytes($rows['media']))->toBe('media bytes')
        ->and(plainBytes($rows['task']))->toBe('task bytes');
});

test('a file already on the current key is left exactly as it is', function () {
    useFileKey($this->k1, 'k1');
    $row = encryptedRow(Media::class, 'current');
    $before = [$row->path, Storage::disk('local')->get($row->path)];

    $this->artisan('security:reencrypt', ['--files' => true])->assertSuccessful();

    expect([$row->fresh()->path, Storage::disk('local')->get($row->path)])->toBe($before);
});

test('a legacy plaintext file is not touched by the re-key', function () {
    useFileKey($this->k1, 'k1');
    $row = Media::factory()->create(['type' => FileType::Pdf, 'path' => 'media/legacy.pdf']);
    Storage::disk('local')->put('media/legacy.pdf', 'plain');

    $this->artisan('security:reencrypt', ['--files' => true])->assertSuccessful();

    expect($row->fresh()->encryption_key_id)->toBeNull()
        ->and(Storage::disk('local')->get('media/legacy.pdf'))->toBe('plain');
});

test('a dry run decrypts and counts without writing anything', function () {
    useFileKey($this->k1, 'k1');
    $row = encryptedRow(Media::class, 'bytes');
    $before = [$row->path, Storage::disk('local')->get($row->path)];

    useFileKey($this->k2, 'k2', ['k1' => $this->k1]);

    $this->artisan('security:reencrypt', ['--files' => true, '--dry-run' => true])
        ->expectsOutputToContain('media: 1 re-keyed (dry run)')
        ->assertSuccessful();

    expect($row->fresh()->encryption_key_id)->toBe('k1')
        ->and([$row->fresh()->path, Storage::disk('local')->get($row->path)])->toBe($before);
});

test('a file whose key is no longer configured is reported by id and left alone while the rest are re-keyed', function () {
    useFileKey($this->k1, 'k1');
    $lost = encryptedRow(Media::class, 'lost');

    useFileKey($this->k2, 'k2', ['k0' => newFileKey()]);
    DB::table('media')->where('id', $lost->id)->update(['encryption_key_id' => 'k0']);
    $lostPath = $lost->path;

    useFileKey($this->k1, 'k1');
    $good = encryptedRow(ReportFile::class, 'good');

    // k1 is now previous, k0's key is not configured at all.
    useFileKey($this->k2, 'k2', ['k1' => $this->k1]);

    $this->artisan('security:reencrypt', ['--files' => true])
        ->expectsOutputToContain('media: 0 re-keyed')
        ->expectsOutputToContain('ids: '.$lost->id)
        ->assertFailed();

    expect($lost->fresh()->encryption_key_id)->toBe('k0')
        ->and($lost->fresh()->path)->toBe($lostPath)
        ->and($good->fresh()->encryption_key_id)->toBe('k2');
});

test('a missing or invalid file key fails the command before anything is written', function () {
    useFileKey($this->k1, 'k1');
    $row = encryptedRow(Media::class, 'bytes');
    $before = $row->path;

    useFileKey(null, 'k2');

    $this->artisan('security:reencrypt', ['--files' => true])->assertFailed();

    expect($row->fresh()->path)->toBe($before)
        ->and($row->fresh()->encryption_key_id)->toBe('k1');
});

test('without --files the command leaves every stored file alone', function () {
    useFileKey($this->k1, 'k1');
    $row = encryptedRow(Media::class, 'bytes');
    $before = [$row->path, Storage::disk('local')->get($row->path)];

    useFileKey($this->k2, 'k2', ['k1' => $this->k1]);

    $this->artisan('security:reencrypt')->assertSuccessful();

    expect($row->fresh()->encryption_key_id)->toBe('k1')
        ->and([$row->fresh()->path, Storage::disk('local')->get($row->path)])->toBe($before);
});
