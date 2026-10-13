<?php

use App\Actions\FundingAgreements\SaveAgreementInstallments;
use App\Actions\Projects\ActivateProject;
use App\Actions\Projects\ApproveProjectFromProposal;
use App\Actions\Projects\CloseProject;
use App\Actions\Projects\ReopenProjectAsDraft;
use App\Actions\Projects\SaveProject;
use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\CorrectProposalStatus;
use App\Actions\Proposals\InviteDefaultReviewers;
use App\Actions\Proposals\LinkProposalProject;
use App\Actions\Proposals\RecordFinalReportAcceptance;
use App\Actions\Proposals\SaveProposal;
use App\Actions\Proposals\TransitionProposal;
use App\Actions\Proposals\UnlinkProposalProject;
use App\Enums\ReviewStage;
use App\Events\FundingAgreementRecorded;
use App\Events\ProjectActivated;
use App\Events\ProjectPeriodChanged;
use App\Events\ProposalStatusChanged;
use App\Support\ApprovalResult;
use App\Support\TransitionResult;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/*
| The drift alarm for docs/lifecycle/contracts.md §5 (events) and §6 (actions).
| Each expectation is the frozen signature rendered as "type $name = default".
| When this fails, the code and contracts.md disagree: fix the code, or ask
| Dev 1 to change the contract first.
*/

/**
 * Render a function's parameters as "type $name = default, ...".
 */
function renderedParameters(ReflectionFunctionAbstract $function): string
{
    return collect($function->getParameters())
        ->map(function (ReflectionParameter $parameter): string {
            $rendered = $parameter->getType().' $'.$parameter->getName();

            if ($parameter->isDefaultValueAvailable()) {
                $rendered .= ' = '.strtolower(var_export($parameter->getDefaultValue(), true));
            }

            return $rendered;
        })
        ->implode(', ');
}

it('freezes each domain event constructor (contracts.md §5)', function (string $event, string $parameters) {
    $class = new ReflectionClass($event);

    expect($class->isFinal())->toBeTrue()
        ->and($class->implementsInterface(ShouldDispatchAfterCommit::class))->toBeTrue()
        ->and(renderedParameters($class->getConstructor()))->toBe($parameters);

    foreach ($class->getConstructor()->getParameters() as $parameter) {
        expect($parameter->isPromoted())->toBeTrue()
            ->and($class->getProperty($parameter->getName())->isReadOnly())->toBeTrue();
    }
})->with([
    'ProposalStatusChanged' => [ProposalStatusChanged::class, 'int $proposalId, App\Enums\ProposalStatus $from, App\Enums\ProposalStatus $to, ?int $actorId, bool $isCorrection = false'],
    'ProjectActivated' => [ProjectActivated::class, 'int $projectId, ?int $proposalId, Carbon\CarbonImmutable $startDate, Carbon\CarbonImmutable $endDate, string $via'],
    'ProjectPeriodChanged' => [ProjectPeriodChanged::class, 'int $projectId, Carbon\CarbonImmutable $oldStart, Carbon\CarbonImmutable $oldEnd, Carbon\CarbonImmutable $newStart, Carbon\CarbonImmutable $newEnd'],
    'FundingAgreementRecorded' => [FundingAgreementRecorded::class, 'int $fundingAgreementId, int $proposalId, ?int $actorId'],
]);

it('freezes each result object (contracts.md §6)', function (string $result, string $parameters) {
    $class = new ReflectionClass($result);

    expect($class->isFinal())->toBeTrue()
        ->and($class->isReadOnly())->toBeTrue()
        ->and(renderedParameters($class->getConstructor()))->toBe($parameters);
})->with([
    'TransitionResult' => [TransitionResult::class, 'App\Models\ProposalStatusNote $note, array $warnings'],
    'ApprovalResult' => [ApprovalResult::class, 'array $approvedProjectIds, array $waitingProjectIds, array $skippedProjectIds, array $warnings'],
]);

it('freezes each Team A action entry point (contracts.md §6)', function (string $action, string $parameters, string $returns) {
    $class = new ReflectionClass($action);
    $handle = $class->getMethod('handle');

    expect($class->isFinal())->toBeTrue()
        ->and(renderedParameters($handle))->toBe($parameters)
        ->and((string) $handle->getReturnType())->toBe($returns);
})->with([
    'TransitionProposal' => [TransitionProposal::class, 'App\Models\Proposal $proposal, App\Enums\ProposalStatus $to, App\Enums\ProposalStatus $expectedFrom, array $input', TransitionResult::class],
    'CorrectProposalStatus' => [CorrectProposalStatus::class, 'App\Models\Proposal $proposal, App\Enums\ProposalStatus $to, App\Enums\ProposalStatus $expectedFrom, string $reason', TransitionResult::class],
    'RecordFinalReportAcceptance' => [RecordFinalReportAcceptance::class, 'App\Models\Proposal $proposal, Carbon\CarbonImmutable $acceptedOn', 'App\Models\Proposal'],
    'SaveProposal' => [SaveProposal::class, '?App\Models\Proposal $existing, array $data, App\Models\User $actor', 'App\Models\Proposal'],
    'LinkProposalProject' => [LinkProposalProject::class, 'App\Models\Proposal $proposal, int $projectId, ?int $targetBeneficiaries = null', 'void'],
    'UnlinkProposalProject' => [UnlinkProposalProject::class, 'App\Models\Proposal $proposal, int $projectId', 'void'],
    'InviteDefaultReviewers' => [InviteDefaultReviewers::class, 'App\Models\Proposal $proposal, App\Enums\ReviewStage $stage', 'void'],
    'ApproveProposal' => [ApproveProposal::class, 'App\Models\Proposal $proposal, App\Enums\ProposalStatus $expectedFrom, array $input', ApprovalResult::class],
    'ReopenProjectAsDraft' => [ReopenProjectAsDraft::class, 'App\Models\Project $project', 'void'],
    'SaveProject' => [SaveProject::class, '?App\Models\Project $existing, array $data, App\Models\User $actor', 'App\Models\Project'],
    'CloseProject' => [CloseProject::class, 'App\Models\Project $project', 'void'],
    'ApproveProjectFromProposal' => [ApproveProjectFromProposal::class, 'App\Models\Project $project, string $via', 'bool'],
    'ActivateProject' => [ActivateProject::class, 'App\Models\Project $project, string $via', 'void'],
    'SaveAgreementInstallments' => [SaveAgreementInstallments::class, 'App\Models\DonorProposal $agreement, array $installments', 'void'],
]);

it('freezes the review stages (contracts.md §9)', function () {
    expect(ReviewStage::values())->toBe(['concept_note', 'proposal']);
});
