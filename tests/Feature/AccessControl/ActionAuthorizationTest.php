<?php

use Illuminate\Support\Facades\File;

/*
| Every class in app/Actions/{Reports,Transfers,Donors,FundingAgreements,Tasks,Projects,Proposals} is the single
| entry point for a sensitive write, so each must authorize itself: a caller
| (a Livewire page, a command, a job) can then never skip the check.
*/

it('authorizes inside every sensitive action', function (string $directory) {
    $files = File::files(app_path("Actions/{$directory}"));

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect(File::get($file->getPathname()))->toContain('Gate::authorize(');
    }
})->with(['Reports', 'Transfers', 'Donors', 'FundingAgreements', 'Tasks', 'Projects', 'Proposals']);

it('keeps deletes out of the Livewire form traits', function (string $file) {
    expect(File::get(app_path("Livewire/Concerns/{$file}")))
        ->not->toMatch('/->(delete|forceDelete)\(/');
})->with(['ManagesReportForm.php', 'ManagesTransferForm.php', 'ManagesAgreementForm.php', 'ManagesDonorForm.php']);
