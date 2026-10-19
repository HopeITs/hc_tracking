<?php

use Illuminate\Support\Facades\File;

/*
| Every class in app/Actions/{Reports,Transfers,Donors,FundingAgreements,Tasks,Projects,Proposals,IndicatorLibrary,ReportTemplates} is the single
| entry point for a sensitive write, so each must authorize itself: a caller
| (a Livewire page, a command, a job) can then never skip the check.
*/

/**
 * contracts.md §6 stubs: frozen signatures whose body only throws, so they have
 * nothing to authorize yet. The task that implements one removes it here; the
 * stub test below fails as soon as a listed file stops being a stub.
 *
 * @return list<string>
 */
function lifecycleActionStubs(): array
{
    return [];
}

/**
 * Actions that run as the system, from a queue, with no signed-in user. They
 * take ids from an already-authorized event or job, re-read every row and
 * record their activity with the system actor, so they have no Gate to call.
 * GenerateExpectedTransfersTest proves each one works with nobody signed in.
 *
 * @return list<string>
 */
function systemActions(): array
{
    return [
        'FundingAgreements/GenerateExpectedTransfers.php', // T09: queued, after FundingAgreementRecorded / SaveAgreementInstallments
    ];
}

it('authorizes inside every sensitive action', function (string $directory) {
    $files = File::files(app_path("Actions/{$directory}"));
    $stubs = [...lifecycleActionStubs(), ...systemActions()];

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        if (in_array("{$directory}/{$file->getFilename()}", $stubs, true)) {
            continue;
        }

        // Gate::forUser($actor)->authorize( counts too: actions that take the
        // acting user as an argument (SaveProposal) authorize that user.
        expect(File::get($file->getPathname()))->toMatch('/Gate::(forUser\([^)]*\)->)?authorize\(/');
    }
})->with(['Reports', 'Transfers', 'Donors', 'FundingAgreements', 'Tasks', 'Projects', 'Proposals', 'IndicatorLibrary', 'ReportTemplates', 'DonorReports']);

it('exempts only queued system actions that never read the signed-in user', function (string $action) {
    $source = File::get(app_path("Actions/{$action}"));

    expect($source)->not->toContain('Auth::')->not->toContain('auth()')->not->toContain('Gate::');
})->with(systemActions());

// A loop, not a dataset: Pest rejects an empty dataset, and the list empties as tasks land.
it('exempts only actions that are still contract stubs', function () {
    foreach (lifecycleActionStubs() as $stub) {
        expect(File::get(app_path("Actions/{$stub}")))->toContain("throw new LogicException('Implemented in T");
    }

    expect(lifecycleActionStubs())->toBeArray();
});

it('keeps deletes out of the Livewire form traits', function (string $file) {
    expect(File::get(app_path("Livewire/Concerns/{$file}")))
        ->not->toMatch('/->(delete|forceDelete)\(/');
})->with(['ManagesReportForm.php', 'ManagesTransferForm.php', 'ManagesAgreementForm.php', 'ManagesDonorForm.php']);
