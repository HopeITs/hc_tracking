<?php

use Illuminate\Foundation\DevCommands;

test('artisan dev starts the reverb server beside the queue listener', function () {
    $commands = collect(DevCommands::commands())->map(fn ($command): string => json_encode($command));

    expect($commands->contains(fn (string $command): bool => str_contains($command, 'reverb:start')))->toBeTrue()
        ->and($commands->contains(fn (string $command): bool => str_contains($command, 'queue:')))->toBeTrue();
});
