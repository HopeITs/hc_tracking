<?php

use Illuminate\Support\Facades\File;

/*
 * Alpine compiles an x-on / @event value as an expression, so a bare `try { }`
 * statement fails to compile ("Unexpected token 'try'") and the handler never
 * runs. That silently broke every date chip's picker (2026-11-16).
 */
test('no blade view starts an alpine event handler with a try statement', function () {
    $offenders = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'))
        ->flatMap(function (SplFileInfo $file): array {
            preg_match_all('/(?:x-on:|@)[\w.:-]+="\s*try\b/', File::get($file->getPathname()), $matches);

            return array_map(fn (string $match): string => $file->getRelativePathname().': '.$match, $matches[0]);
        })
        ->values()
        ->all();

    expect($offenders)->toBe([]);
});
