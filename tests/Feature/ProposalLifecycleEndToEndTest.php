<?php

use App\Actions\FundingAgreements\SaveAgreementInstallments;
use App\Actions\FundingAgreements\SaveFundingAgreement;
use App\Actions\Locations\ActivateLocation;
use App\Actions\Proposals\ApproveProposal;
use App\Actions\Proposals\RecordFinalReportAcceptance;
use App\Actions\Proposals\SaveProposal;
use App\Actions\Proposals\TransitionProposal;
use App\Enums\AssignmentStatus;
use App\Enums\Currency;
use App\Enums\MediaCollection;
use App\Enums\ProjectStatus;
use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Media;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\TransfersTracking;
use App\Models\UserNotification;
use Carbon\CarbonImmutable;
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

test('a proposal goes from a draft place to an approved, active grant with an agreement and expected transfers', function () {
    $prManager = userWithRole('Public Relations Manager');
    $meal = userWithRole('MEAL');
    $generalManager = userWithRole('General Manager');
    $finance = userWithRole('Financial');

    // 1. A draft place (no dates) and a concept note that funds it. Y-17:
    // the place IS a location row of that proposal (D-93); no project exists
    // at any point of this run.
    $proposal = Proposal::factory()->create([
        'status' => ProposalStatus::ConceptNote,
        'requested_budget' => '30000.00',
        'requested_currency' => Currency::Usd,
        'planned_date_begin' => '2026-11-01',
        'planned_date_end' => '2027-10-31',
    ]);
    $cityId = insertHrCity('Aleppo', 'حلب');
    $employeeId = insertHrEmployee($cityId);
    $project = ProposalLocation::factory()->create([
        'proposal_id' => $proposal->id,
        'city_id' => $cityId,
        'program_id' => Program::factory()->create(['manager_id' => $employeeId])->id,
        'responsible_employee_id' => $employeeId,
        'target_beneficiaries' => 120,
    ]);
    $project->centers()->create(['center_id' => insertHrCenter($cityId, 'Hope Center Aleppo', 'مركز الأمل حلب')]);
    $project->currentAssignment('responsible_employee_id')?->forceFill(['status' => AssignmentStatus::Acknowledged])->save();
    $project = $project->fresh();

    expect($project->status)->toBe(ProjectStatus::Draft)
        ->and($project->start_date)->toBeNull()
        ->and($project->end_date)->toBeNull()
        ->and(Project::query()->count())->toBe(0);

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

    // 3. Approval: the place is approved and takes the grant dates.
    $result = app(ApproveProposal::class)->handle($proposal->fresh(), ProposalStatus::Submitted, [
        'actual_date_begin' => '2026-11-01',
        'actual_date_end' => '2027-10-31',
        'decision_date' => $today,
        'approval_note' => 'The donor confirmed the grant by email on 13 October.',
    ]);

    expect($result->approvedLocationIds)->toBe([$project->id])
        ->and($result->warnings)->toBe(['no_agreement'])
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Approved)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Approved)
        ->and($project->fresh()->start_date->toDateString())->toBe('2026-11-01')
        ->and($project->fresh()->end_date->toDateString())->toBe('2027-10-31');

    // 4. MEAL confirms the report template: activation is called directly here.
    $this->actingAs($meal);
    app(ActivateLocation::class)->handle($project->fresh(), 'template_confirmation');

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

test('a payroll proposal goes from draft to completed with the final report only (W-32)', function () {
    $finance = userWithRole('Financial');
    $this->actingAs($finance);
    $today = '2026-10-14';

    // 1. Created as payroll: straight to proposal draft, no project, no concept note to pass.
    $proposal = app(SaveProposal::class)->handle(null, [
        'kind' => ProposalKind::Payroll->value,
        'name' => 'Micro Aleppo payroll 2027',
        'internalProjectNumber' => 'HC-2026-971',
        'donorId' => Donor::factory()->create()->id,
        'narrative' => 'Volunteer salaries and equipment for the Micro Aleppo center during 2027.',
        'plannedDateBegin' => '2026-11-01',
        'plannedDateEnd' => '2027-10-31',
        'requestedBudget' => '48000.00',
        'requestedCurrency' => 'USD',
    ], $finance);

    expect($proposal->status)->toBe(ProposalStatus::ProposalDraft)
        ->and($proposal->transitionTargets())->toBe([ProposalStatus::Submitted]);

    // 2. Finance has entered the budget: it goes to the donor, then the donor approves.
    app(TransitionProposal::class)->handle($proposal->fresh(), ProposalStatus::Submitted, ProposalStatus::ProposalDraft, ['effective_date' => $today]);
    $this->actingAs(userWithRole('Public Relations Manager'));
    $result = app(ApproveProposal::class)->handle($proposal->fresh(), ProposalStatus::Submitted, [
        'decision_date' => $today,
        'approval_note' => 'The donor confirmed the payroll grant by email on 13 October.',
    ]);
    $this->actingAs($finance);

    expect($result->approvedLocationIds)->toBe([])
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Active)
        ->and(Project::query()->count())->toBe(0);

    // 3. The final report is filed and accepted: the proposal closes, with no milestone.
    Media::query()->findOrFail(insertMedia('proposal', $proposal->id, $finance->id))->update(['collection' => MediaCollection::FinalReport]);
    app(RecordFinalReportAcceptance::class)->handle($proposal->fresh(), CarbonImmutable::parse($today));
    app(TransitionProposal::class)->handle($proposal->fresh(), ProposalStatus::Completed, ProposalStatus::Active, ['effective_date' => $today]);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Completed)
        ->and($proposal->statusNotes()->pluck('status')->all())->toBe([
            ProposalStatus::ConceptNote,
            ProposalStatus::ProposalDraft,
            ProposalStatus::Submitted,
            ProposalStatus::Approved,
            ProposalStatus::Active,
            ProposalStatus::Completed,
        ]);
});
