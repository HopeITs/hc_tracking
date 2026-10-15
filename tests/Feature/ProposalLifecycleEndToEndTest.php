<?php

use App\Actions\FundingAgreements\SaveAgreementInstallments;
use App\Actions\FundingAgreements\SaveFundingAgreement;
use App\Actions\Projects\ActivateProject;
use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\TransitionProposal;
use App\Enums\AssignmentStatus;
use App\Enums\Currency;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\DonorProposal;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use App\Models\UserNotification;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;

/*
| CP4: one proposal walked from a draft project to money expected from the donor.
| Steps whose task has not merged are separate skipped tests naming the task, so
| the CP4 run shows exactly what is still missing.
*/

beforeEach(function () {
    $this->travelTo('2026-10-14 10:00:00');
    $this->seed(RolesAndPermissionsSeeder::class);
});

test('a proposal goes from a draft project to an approved, active grant with an agreement and expected transfers', function () {
    $prManager = userWithRole('Public Relations Manager');
    $meal = userWithRole('MEAL');
    $generalManager = userWithRole('General Manager');
    $finance = userWithRole('Financial');

    // 1. A draft project (no dates) and a concept note that funds it.
    $project = Project::factory()->create();
    $project->currentAssignment('user_id')?->forceFill(['status' => AssignmentStatus::Acknowledged])->save();
    $proposal = Proposal::factory()->create([
        'status' => ProposalStatus::ConceptNote,
        'requested_budget' => '30000.00',
        'requested_currency' => Currency::Usd,
        'planned_date_begin' => '2026-11-01',
        'planned_date_end' => '2027-10-31',
    ]);
    $proposal->projects()->attach($project->id, ['target_beneficiaries' => 120]);

    expect($project->status)->toBe(ProjectStatus::Draft)
        ->and($project->start_date)->toBeNull()
        ->and($project->end_date)->toBeNull();

    // 2. Concept note → review → submitted to the donor → proposal draft → review → submitted.
    $this->actingAs($prManager);
    $today = '2026-10-14';
    $moves = [
        [ProposalStatus::ConceptNoteReview, ProposalStatus::ConceptNote, []],
        [ProposalStatus::ConceptNoteSubmitted, ProposalStatus::ConceptNoteReview, ['effective_date' => $today]],
        [ProposalStatus::ProposalDraft, ProposalStatus::ConceptNoteSubmitted, ['effective_date' => $today]],
        [ProposalStatus::ProposalReview, ProposalStatus::ProposalDraft, []],
        [ProposalStatus::Submitted, ProposalStatus::ProposalReview, ['effective_date' => $today]],
    ];

    foreach ($moves as [$to, $from, $input]) {
        app(TransitionProposal::class)->handle($proposal->fresh(), $to, $from, $input);
    }

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Submitted)
        ->and($proposal->statusNotes()->count())->toBe(5);

    // 3. Approval: the project is approved and takes the grant dates.
    $result = app(ApproveProposal::class)->handle($proposal->fresh(), ProposalStatus::Submitted, [
        'actual_date_begin' => '2026-11-01',
        'actual_date_end' => '2027-10-31',
        'decision_date' => $today,
        'approval_note' => 'The donor confirmed the grant by email on 13 October.',
    ]);

    expect($result->approvedProjectIds)->toBe([$project->id])
        ->and($result->warnings)->toBe(['no_agreement'])
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Approved)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Approved)
        ->and($project->fresh()->start_date->toDateString())->toBe('2026-11-01')
        ->and($project->fresh()->end_date->toDateString())->toBe('2027-10-31');

    // 4. MEAL confirms the report template: until T33 merges, activation is called directly.
    $this->actingAs($meal);
    app(ActivateProject::class)->handle($project->fresh(), 'template_confirmation');

    expect($project->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Active);

    // 5. The agreement and its installments: three expected transfers, one agreement only.
    $this->actingAs($finance);
    $agreement = app(SaveFundingAgreement::class)->handle(null, [
        'agreementProposalId' => (string) $proposal->id,
        'budget' => '30000.00',
        'agreementCurrency' => 'USD',
        'externalProjectNumber' => '',
    ]);

    expect($agreement->donor_id)->toBe($proposal->donor_id);

    app(SaveAgreementInstallments::class)->handle($agreement, collect([1, 2, 3])->map(fn (int $sequence): array => [
        'sequence' => $sequence,
        'amount' => '10000.00',
        'currency' => 'USD',
        'scheduled_date' => ['2026-11-15', '2027-02-15', '2027-05-15'][$sequence - 1],
    ])->all());

    $transfers = TransfersTracking::query()->where('donor_proposal_id', $agreement->id)->orderBy('scheduled_date')->get();

    expect($transfers)->toHaveCount(3)
        ->and($transfers->pluck('source')->unique()->all())->toBe(['auto'])
        ->and($transfers->pluck('payment_amount')->all())->toBe(['10000.00', '10000.00', '10000.00']);

    try {
        app(SaveFundingAgreement::class)->handle(null, [
            'agreementProposalId' => (string) $proposal->id,
            'budget' => '1.00',
            'agreementCurrency' => 'USD',
            'externalProjectNumber' => '',
        ]);
        $refused = null;
    } catch (ValidationException $exception) {
        $refused = $exception->errors()['agreementProposalId'][0];
    }

    expect($refused)->toBe(__('funding_agreements.agreement_exists'))
        ->and(DonorProposal::query()->count())->toBe(1);

    // 6. The approval reached MEAL and General Management.
    expect(UserNotification::query()->forUser($meal)->count())->toBeGreaterThan(0)
        ->and(UserNotification::query()->forUser($generalManager)->count())->toBeGreaterThan(0);
});

test('template confirmation activates the project and generates its monthly report months')
    ->skip('T33 (ConfirmReportTemplate) and T17A (monthly report generation) are not merged yet; step 4 above calls ActivateProject directly.');
