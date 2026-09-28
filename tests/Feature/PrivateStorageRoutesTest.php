<?php

use Illuminate\Support\Facades\Route;

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
