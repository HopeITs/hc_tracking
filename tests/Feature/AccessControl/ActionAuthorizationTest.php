<?php

use Illuminate\Support\Facades\File;

/*
| Every class in app/Actions/{Reports,Transfers,Donors,FundingAgreements,Tasks,Projects,Proposals} is the single
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
    return [
        'Proposals/TransitionProposal.php', // TODO T04
        'Proposals/CorrectProposalStatus.php', // TODO T04
        'Proposals/RecordFinalReportAcceptance.php', // TODO T04
        'Proposals/SaveProposal.php', // TODO T06
        'Proposals/LinkProposalProject.php', // TODO T06
        'Proposals/UnlinkProposalProject.php', // TODO T06
        'Projects/ReopenProjectAsDraft.php', // TODO T06
        'Proposals/InviteDefaultReviewers.php', // TODO T07
        'Proposals/ApproveProposal.php', // TODO T08
        'Projects/ApproveProjectFromProposal.php', // TODO T08
        'Projects/ActivateProject.php', // TODO T08
        'Projects/SaveProject.php', // TODO T05
        'Projects/CloseProject.php', // TODO T05
        'FundingAgreements/SaveAgreementInstallments.php', // TODO T09
    ];
}

it('authorizes inside every sensitive action', function (string $directory) {
    $files = File::files(app_path("Actions/{$directory}"));
    $stubs = lifecycleActionStubs();

    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        if (in_array("{$directory}/{$file->getFilename()}", $stubs, true)) {
            continue;
        }

        expect(File::get($file->getPathname()))->toContain('Gate::authorize(');
    }
})->with(['Reports', 'Transfers', 'Donors', 'FundingAgreements', 'Tasks', 'Projects', 'Proposals']);

it('exempts only actions that are still contract stubs', function (string $stub) {
    expect(File::get(app_path("Actions/{$stub}")))->toContain("throw new LogicException('Implemented in T");
})->with(lifecycleActionStubs());

it('keeps deletes out of the Livewire form traits', function (string $file) {
    expect(File::get(app_path("Livewire/Concerns/{$file}")))
        ->not->toMatch('/->(delete|forceDelete)\(/');
})->with(['ManagesReportForm.php', 'ManagesTransferForm.php', 'ManagesAgreementForm.php', 'ManagesDonorForm.php']);
