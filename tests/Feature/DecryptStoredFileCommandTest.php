<?php

use App\Enums\FileType;
use App\Models\Media;
use App\Models\ReportFile;
use App\Models\TaskAttachment;
use App\Support\StoredFiles;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

beforeEach(function () {
    Storage::fake('local');
    $this->outDir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'hc-decrypt-'.Str::random(12);
    File::makeDirectory($this->outDir);
});

afterEach(fn () => File::deleteDirectory($this->outDir));

/**
 * A row whose file is encrypted with the key configured now.
 *
 * @param  class-string<Model>  $model
 */
function decryptableRow(string $model, string $contents): Model
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

test('an encrypted file is written to the destination byte for byte', function (string $model, string $table) {
    $bytes = random_bytes(2048);
    $row = decryptableRow($model, $bytes);
    $destination = $this->outDir.DIRECTORY_SEPARATOR.'extracted.pdf';

    $this->artisan('files:decrypt', ['table' => $table, '--id' => $row->id, '--to' => $destination])
        ->expectsOutputToContain($destination)
        ->assertSuccessful();

    expect(file_get_contents($destination))->toBe($bytes);
})->with([
    'media' => [Media::class, 'media'],
    'report_files' => [ReportFile::class, 'report_files'],
    'task_attachments' => [TaskAttachment::class, 'task_attachments'],
]);

test('a legacy plaintext file is copied as it is', function () {
    $row = Media::factory()->create(['type' => FileType::Pdf, 'path' => 'media/legacy.pdf']);
    Storage::disk('local')->put('media/legacy.pdf', 'plain bytes');
    $destination = $this->outDir.DIRECTORY_SEPARATOR.'legacy.pdf';

    $this->artisan('files:decrypt', ['table' => 'media', '--id' => $row->id, '--to' => $destination])->assertSuccessful();

    expect(file_get_contents($destination))->toBe('plain bytes');
});

test('it refuses to overwrite an existing file', function () {
    $row = decryptableRow(Media::class, 'secret');
    $destination = $this->outDir.DIRECTORY_SEPARATOR.'taken.pdf';
    file_put_contents($destination, 'keep me');

    $this->artisan('files:decrypt', ['table' => 'media', '--id' => $row->id, '--to' => $destination])->assertFailed();

    expect(file_get_contents($destination))->toBe('keep me');
});

test('it refuses a destination inside the upload folders or the public folder', function (callable $destination) {
    $row = decryptableRow(Media::class, 'secret');
    $path = $destination();

    $this->artisan('files:decrypt', ['table' => 'media', '--id' => $row->id, '--to' => $path])->assertFailed();

    expect(file_exists($path))->toBeFalse();
})->with([
    'upload folder' => [fn () => Storage::disk('local')->path('media').DIRECTORY_SEPARATOR.'out.pdf'],
    'upload folder via ..' => [fn () => Storage::disk('local')->path('media').DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR.'media'.DIRECTORY_SEPARATOR.'out.pdf'],
    'disk root' => [fn () => Storage::disk('local')->path('out.pdf')],
    'public folder' => [fn () => public_path('out.pdf')],
    'storage app folder' => [fn () => storage_path('app'.DIRECTORY_SEPARATOR.'out.pdf')],
]);

test('it refuses a destination whose folder does not exist', function () {
    $row = decryptableRow(Media::class, 'secret');
    $destination = $this->outDir.DIRECTORY_SEPARATOR.'missing'.DIRECTORY_SEPARATOR.'out.pdf';

    $this->artisan('files:decrypt', ['table' => 'media', '--id' => $row->id, '--to' => $destination])->assertFailed();

    expect(file_exists($destination))->toBeFalse();
});

test('an unknown table, an unknown id or missing options fail without writing', function (array $arguments) {
    $destination = $this->outDir.DIRECTORY_SEPARATOR.'out.pdf';
    $arguments = array_map(fn ($value) => $value === '{to}' ? $destination : $value, $arguments);

    $this->artisan('files:decrypt', $arguments)->assertFailed();

    expect(file_exists($destination))->toBeFalse();
})->with([
    'unknown table' => [['table' => 'users', '--id' => 1, '--to' => '{to}']],
    'unknown id' => [['table' => 'media', '--id' => 999999, '--to' => '{to}']],
    'no id' => [['table' => 'media', '--to' => '{to}']],
    'no destination' => [['table' => 'media', '--id' => 1]],
]);

test('a file that cannot be decrypted fails without writing or naming the path', function () {
    $row = decryptableRow(Media::class, 'secret');
    useFileKey(newFileKey(), 'k1');
    $destination = $this->outDir.DIRECTORY_SEPARATOR.'out.pdf';

    $this->artisan('files:decrypt', ['table' => 'media', '--id' => $row->id, '--to' => $destination])
        ->doesntExpectOutputToContain($row->path)
        ->assertFailed();

    expect(file_exists($destination))->toBeFalse();
});
