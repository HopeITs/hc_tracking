<?php

use Illuminate\Support\Facades\File;

/*
| Since Livewire 4.1 the .blur, .change and .enter modifiers on wire:model only
| delay the client-side sync; without .live nothing reaches the server until
| the next action. Every such binding here autosaves (an updated* hook) or
| refreshes a preview, so a deferred one says "Saved" while the figure is lost
| on reload. Livewire::test()->set() bypasses the browser and cannot see it,
| which is why this reads the views.
*/

test('no view binds wire:model on blur, change or enter without live', function () {
    $deferred = collect(File::allFiles(resource_path('views')))
        ->filter(fn (SplFileInfo $file): bool => str_ends_with($file->getFilename(), '.blade.php'))
        ->flatMap(function (SplFileInfo $file): array {
            preg_match_all('/wire:model((?:\.[\w-]+)*)=/', File::get($file->getPathname()), $matches);

            return collect($matches[1])
                ->map(fn (string $modifiers): array => explode('.', ltrim($modifiers, '.')))
                ->filter(fn (array $modifiers): bool => array_intersect($modifiers, ['blur', 'change', 'enter']) !== [] && ! in_array('live', $modifiers, true))
                ->map(fn (array $modifiers): string => str_replace(base_path().DIRECTORY_SEPARATOR, '', $file->getPathname()).': wire:model.'.implode('.', $modifiers))
                ->values()
                ->all();
        })
        ->values()
        ->all();

    expect($deferred)->toBe([]);
});
