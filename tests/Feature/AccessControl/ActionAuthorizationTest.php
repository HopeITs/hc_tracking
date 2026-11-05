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
 * Helper actions that are only ever called from inside another action's
 * transaction, after that action has authorized the person (contracts.md §6:
 * "No Gate: the caller has already authorised"). They may read the signed-in
 * user for the activity actor, unlike a system action. The test below keeps
 * the exemption honest: it fails if any caller stops authorizing.
 *
 * @return list<string> action file => the actions that call it
 */
function callerAuthorizedActions(): array
{
    return [
        'Tasks/SyncReportTask.php' => [
            'FundingAgreements/MarkDonorReportSubmitted.php',
            'FundingAgreements/DeleteDonorReport.php',
            'DonorReports/SaveDonorReportSnapshot.php',
        ], // W-13 (D-64): keeps a donor report's task in step with the report
    ];
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
    $stubs = [...lifecycleActionStubs(), ...systemActions(), ...array_keys(callerAuthorizedActions())];

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

it('exempts a caller-authorized action only while every caller authorizes', function () {
    foreach (callerAuthorizedActions() as $action => $callers) {
        expect(File::get(app_path("Actions/{$action}")))->not->toContain('Gate::');

        foreach ($callers as $caller) {
            expect(File::get(app_path("Actions/{$caller}")))
                ->toMatch('/Gate::(forUser\([^)]*\)->)?authorize\(/')
                ->toContain(pathinfo($action, PATHINFO_FILENAME));
        }
    }
});

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
