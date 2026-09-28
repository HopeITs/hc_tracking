<?php

use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/*
| The private disk is not served over signed storage/{path} URLs: nothing in
| the app uses them, and every private file already has its own controller
| that checks the record's policy (see the *FileDownloadController routes).
*/
test('the private disk has no storage download or upload routes', function () {
    expect(Route::has('storage.local'))->toBeFalse()
        ->and(Route::has('storage.local.upload'))->toBeFalse();

    $this->get('/storage/anything.pdf')->assertNotFound();
    $this->put('/storage/anything.pdf')->assertNotFound();
});

/*
| No disk may publish files. Every upload is a record's document, so a public
| disk or a storage folder linked into public/ would hand files to anyone
| holding the URL, past the record's policy.
*/
test('no filesystem disk is public and nothing is linked into public/', function () {
    $publicDisks = collect(config('filesystems.disks'))
        ->filter(fn (array $disk) => ($disk['visibility'] ?? null) === 'public' || isset($disk['url']));

    expect($publicDisks->keys()->all())->toBe([])
        ->and(config('filesystems.disks.s3.visibility'))->toBe('private')
        ->and(config('filesystems.links'))->toBe([])
        ->and(fn () => Storage::disk('public'))->toThrow(InvalidArgumentException::class, 'Disk [public] does not have a configured driver.');
});

test('temporary upload previews never serve svg, which can run script in the app origin', function () {
    expect(config('livewire.temporary_file_upload.preview_mimes'))->not->toContain('svg');
});
