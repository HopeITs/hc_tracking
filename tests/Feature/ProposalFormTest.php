<?php

use App\Actions\Proposals\SaveProposal;
use App\Enums\AssignmentStatus;
use App\Enums\Currency;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\Assignment;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\MealCoverageAssignment;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use App\Models\UserNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * Valid create data for SaveProposal: one donor, the given projects, planned
 * dates and a concept note start.
 *
 * @param  list<int>  $projectIds
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function proposalFormData(array $projectIds, array $overrides = []): array
{
    return [
        'name' => 'Winter Shelter Response',
        'internalProjectNumber' => 'HC-2026-951',
        'donorId' => Donor::factory()->create()->id,
        'projectIds' => $projectIds,
        'beneficiaries' => [],
        'plannedDateBegin' => '2026-11-01',
        'plannedDateEnd' => '2027-10-31',
        'notes' => '',
        'startingStage' => 'concept note',
        'skipReason' => null,
        ...$overrides,
    ];
}

/**
 * Edit data for an existing proposal, starting from its stored values.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function proposalEditData(Proposal $proposal, array $overrides = []): array
{
    return [
        'name' => $proposal->name,
        'internalProjectNumber' => $proposal->internal_project_number,
        'donorId' => $proposal->donor_id,
        'plannedDateBegin' => $proposal->planned_date_begin->toDateString(),
        'plannedDateEnd' => $proposal->planned_date_end->toDateString(),
        'notes' => $proposal->notes,
        ...$overrides,
    ];
}

/**
 * Run SaveProposal as the given user, who is also the authenticated user.
 *
 * @param  array<string, mixed>  $data
 */
function saveProposal(User $actor, ?Proposal $existing, array $data): Proposal
{
    test()->actingAs($actor);

    return app(SaveProposal::class)->handle($existing, $data, $actor);
}

/**
 * The validation errors a refused save reports.
 *
 * @return array<string, list<string>>
 */
function refusedSave(callable $save): array
{
    try {
        $save();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The save was not refused.');
}

/**
 * Put a proposal at a status without going through a writer, for setup.
 */
function placeProposalAt(Proposal $proposal, ProposalStatus $status): Proposal
{
    DB::table('proposals')->where('id', $proposal->id)->update(['status' => $status->value]);

    return $proposal->fresh();
}

/**
 * A proposal with one linked project carrying beneficiaries and a budget.
 */
function linkedProposal(ProposalStatus $status = ProposalStatus::ConceptNote, int $beneficiaries = 400): Proposal
{
    return Activity::withoutRecording(function () use ($status, $beneficiaries): Proposal {
        $proposal = Proposal::factory()->create(['requested_budget' => '120000.00', 'requested_currency' => Currency::Usd]);
        $proposal->projects()->attach(Project::factory()->create(['name' => 'Water Network Rehabilitation'])->id, ['target_beneficiaries' => $beneficiaries]);

        return placeProposalAt($proposal, $status);
    });
}

beforeEach(fn () => $this->travelTo('2026-10-14 10:00:00'));

test('creating a proposal stores its donor, projects, beneficiaries and first note', function () {
    $actor = userWithRole('Financial');
    $first = Project::factory()->create();
    $second = Project::factory()->status(ProjectStatus::Approved)->create();
    $data = proposalFormData([$second->id, $first->id], ['beneficiaries' => [$first->id => 250, $second->id => '']]);

    $proposal = saveProposal($actor, null, $data);

    $note = DB::table('proposal_status_notes')->where('proposal_id', $proposal->id)->sole();

    expect(DB::table('proposals')->find($proposal->id))
        ->name->toBe('Winter Shelter Response')
        ->internal_project_number->toBe('HC-2026-951')
        ->donor_id->toBe($data['donorId'])
        ->status->toBe('concept note')
        ->planned_date_begin->toBe('2026-11-01')
        ->planned_date_end->toBe('2027-10-31')
        ->notes->toBeNull()
        ->and(DB::table('project_proposal')->where('proposal_id', $proposal->id)->orderBy('project_id')->pluck('target_beneficiaries', 'project_id')->all())
        ->toBe([$first->id => 250, $second->id => null])
        ->and($note)
        ->status->toBe('concept note')
        ->context->toBe(__('proposals.proposal_recorded_note'))
        ->date->toBe('2026-10-14')
        ->changed_by->toBe($actor->id)
        ->is_correction->toBe(0);
});

test('skipping the concept note without a reason is refused and writes nothing', function () {
    $project = Project::factory()->create();

    $errors = refusedSave(fn () => saveProposal(userWithRole('Financial'), null, proposalFormData([$project->id], ['startingStage' => 'proposal draft', 'skipReason' => 'Too short'])));

    expect($errors)->toHaveKey('skipReason')
        ->and($errors['skipReason'])->toBe([__('proposals.explain_why_the_concept_note_is_skipped')])
        ->and(DB::table('proposals')->count())->toBe(0);
});

test('skipping the concept note with a reason records the concept note and then the proposal draft', function () {
    $project = Project::factory()->create();

    $proposal = saveProposal(userWithRole('Financial'), null, proposalFormData([$project->id], [
        'startingStage' => 'proposal draft',
        'skipReason' => 'The donor already asked for the full proposal.',
    ]));

    $notes = DB::table('proposal_status_notes')->where('proposal_id', $proposal->id)->orderBy('id')->get();

    expect($proposal->status)->toBe(ProposalStatus::ProposalDraft)
        ->and($notes->pluck('status')->all())->toBe(['concept note', 'proposal draft'])
        ->and($notes->last()->context)->toBe('The donor already asked for the full proposal.');
});

test('a project that cannot take a proposal is refused and nothing is created', function (Closure $makeProjectId) {
    $errors = refusedSave(fn () => saveProposal(userWithRole('Financial'), null, proposalFormData([$makeProjectId()])));

    expect($errors['projectIds'])->toBe([__('proposals.that_project_is_no_longer_available')])
        ->and(DB::table('proposals')->count())->toBe(0)
        ->and(DB::table('proposal_status_notes')->count())->toBe(0);
})->with([
    'archived' => fn (): int => Project::factory()->status(ProjectStatus::Archived)->create()->id,
    'owner rejected' => function (): int {
        $project = Project::factory()->create();
        Assignment::query()->create([
            'assignable_type' => 'project',
            'assignable_id' => $project->id,
            'field' => 'user_id',
            'employee_id' => $project->user_id,
            'status' => AssignmentStatus::Rejected,
        ]);

        return $project->id;
    },
    'tampered id' => fn (): int => 999999,
]);

test('creating a proposal without any project is refused', function () {
    $errors = refusedSave(fn () => saveProposal(userWithRole('Financial'), null, proposalFormData([])));

    expect($errors['projectIds'])->toBe([__('proposals.link_at_least_one_project')]);
});

test('linking a closed project reopens it as a draft without dates', function () {
    $project = Project::factory()->closed()->create(['center_id' => insertHrCenter(insertHrCity())]);

    $proposal = saveProposal(userWithRole('Financial'), null, proposalFormData([$project->id]));

    expect(DB::table('project_proposal')->where('proposal_id', $proposal->id)->pluck('project_id')->all())->toBe([$project->id])
        ->and(DB::table('projects')->find($project->id))
        ->status->toBe('draft')
        ->start_date->toBeNull()
        ->end_date->toBeNull();
});

test('a closed project is refused when its center already has an open project for that program', function () {
    $centerId = insertHrCenter(insertHrCity());
    $program = Program::factory()->create();
    Project::factory()->create(['center_id' => $centerId, 'program_id' => $program->id]);
    $closed = Project::factory()->closed()->create(['center_id' => $centerId, 'program_id' => $program->id]);

    expect(fn () => saveProposal(userWithRole('Financial'), null, proposalFormData([$closed->id])))
        ->toThrow(ValidationException::class);

    expect(DB::table('proposals')->count())->toBe(0)
        ->and(DB::table('projects')->find($closed->id)->status)->toBe('closed');
});

test('a user without money access cannot change the budget and the stored one stays', function () {
    $proposal = linkedProposal();
    $actor = userWithRole(fundraiserWithoutAgreements());

    saveProposal($actor, $proposal, proposalEditData($proposal, ['name' => 'Renamed', 'requestedBudget' => 'not a number', 'requestedCurrency' => 'EUR']));

    expect(DB::table('proposals')->find($proposal->id))
        ->name->toBe('Renamed')
        ->requested_budget->toBe('120000.00')
        ->requested_currency->toBe('USD');
});

test('a money holder saves the budget and no amount reaches the activity log', function () {
    $proposal = linkedProposal();

    saveProposal(userWithRole('Financial'), $proposal, proposalEditData($proposal, ['name' => 'Renamed', 'requestedBudget' => '987654.32', 'requestedCurrency' => 'EUR']));

    expect(DB::table('proposals')->find($proposal->id))
        ->requested_budget->toBe('987654.32')
        ->requested_currency->toBe('EUR')
        ->and(Activity::query()->get()->toJson())->not->toContain('987654');
});

test('a negative budget is refused', function () {
    $proposal = linkedProposal();

    $errors = refusedSave(fn () => saveProposal(userWithRole('Financial'), $proposal, proposalEditData($proposal, ['requestedBudget' => '-1', 'requestedCurrency' => 'USD'])));

    expect($errors['requestedBudget'])->toBe([__('proposals.the_budget_cannot_be_negative')]);
});

test('the donor cannot change once the concept note is submitted', function () {
    $proposal = linkedProposal(ProposalStatus::ConceptNoteSubmitted);
    $donorId = $proposal->donor_id;

    $errors = refusedSave(fn () => saveProposal(userWithRole('Financial'), $proposal, proposalEditData($proposal, ['donorId' => Donor::factory()->create()->id])));

    expect($errors['donorId'])->toBe([__('proposals.the_donor_cannot_change_after_the_concept_note')])
        ->and(DB::table('proposals')->find($proposal->id)->donor_id)->toBe($donorId);
});

test('the donor cannot change once an agreement exists', function () {
    $proposal = linkedProposal();
    DonorProposal::factory()->create(['proposal_id' => $proposal->id]);

    $errors = refusedSave(fn () => saveProposal(userWithRole('Financial'), $proposal, proposalEditData($proposal, ['donorId' => Donor::factory()->create()->id])));

    expect($errors['donorId'])->toBe([__('proposals.the_donor_cannot_change_after_the_concept_note')]);
});

test('the donor changes while the concept note is still being written', function () {
    $proposal = linkedProposal(ProposalStatus::ConceptNoteReview);
    $donorId = Donor::factory()->create()->id;

    saveProposal(userWithRole('Financial'), $proposal, proposalEditData($proposal, ['donorId' => $donorId]));

    expect(DB::table('proposals')->find($proposal->id)->donor_id)->toBe($donorId);
});

test('zero beneficiaries are refused', function () {
    $proposal = linkedProposal();
    $projectId = $proposal->projects()->sole()->id;

    $errors = refusedSave(fn () => saveProposal(userWithRole('Financial'), $proposal, proposalEditData($proposal, ['beneficiaries' => [$projectId => 0]])));

    expect($errors["beneficiaries.{$projectId}"])->toBe([__('proposals.enter_at_least_one_beneficiary')]);
});

test('beneficiaries for a project that is not linked are refused', function () {
    $proposal = linkedProposal();
    $foreignId = Project::factory()->create()->id;

    $errors = refusedSave(fn () => saveProposal(userWithRole('Financial'), $proposal, proposalEditData($proposal, ['beneficiaries' => [$foreignId => 50]])));

    expect($errors)->toHaveKey('beneficiaries')
        ->and(DB::table('project_proposal')->where('project_id', $foreignId)->exists())->toBeFalse();
});

test('beneficiaries for a project outside the chosen ones are refused on create', function () {
    $project = Project::factory()->create();
    $other = Project::factory()->create();

    $errors = refusedSave(fn () => saveProposal(userWithRole('Financial'), null, proposalFormData([$project->id], ['beneficiaries' => [$other->id => 50]])));

    expect($errors)->toHaveKey('beneficiaries')
        ->and(DB::table('proposals')->count())->toBe(0);
});

test('before approval the beneficiaries change without a reason or a special event', function () {
    $proposal = linkedProposal(ProposalStatus::ProposalDraft);
    $projectId = $proposal->projects()->sole()->id;

    saveProposal(userWithRole('Financial'), $proposal, proposalEditData($proposal, ['beneficiaries' => [$projectId => 650]]));

    expect(DB::table('project_proposal')->where('proposal_id', $proposal->id)->value('target_beneficiaries'))->toBe(650)
        ->and(Activity::query()->where('event', 'proposal.beneficiaries_changed')->exists())->toBeFalse();
});

test('after approval a beneficiary change without a reason is refused', function () {
    $proposal = linkedProposal(ProposalStatus::Approved);
    $projectId = $proposal->projects()->sole()->id;

    $errors = refusedSave(fn () => saveProposal(userWithRole('Financial'), $proposal, proposalEditData($proposal, ['beneficiaries' => [$projectId => 650], 'beneficiariesReason' => ' '])));

    expect($errors['beneficiariesReason'])->toBe([__('proposals.explain_why_the_beneficiaries_changed')])
        ->and(DB::table('project_proposal')->where('proposal_id', $proposal->id)->value('target_beneficiaries'))->toBe(400);
});

test('after approval a beneficiary change with a reason is logged and reaches MEAL', function () {
    $proposal = linkedProposal(ProposalStatus::Approved);
    $project = $proposal->projects()->sole();
    $officer = insertHrEmployee(firstNameEn: 'Nour', lastNameEn: 'Ali');
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forProject($project->id)->create(['employee_id' => $officer]));
    $officerUser = userWithRole('Team Member', $officer);
    $mealUser = userWithRole('MEAL');
    $bystander = userWithRole('Media Team');
    $actor = userWithRole('Financial');

    saveProposal($actor, $proposal, proposalEditData($proposal, [
        'beneficiaries' => [$project->id => 650],
        'beneficiariesReason' => 'The donor widened the target group.',
    ]));

    $activity = Activity::query()->where('event', 'proposal.beneficiaries_changed')->sole();
    $key = 'notifications.events.proposal.beneficiaries_changed';

    expect(DB::table('project_proposal')->where('proposal_id', $proposal->id)->value('target_beneficiaries'))->toBe(650)
        ->and($activity->subject_id)->toBe($proposal->id)
        ->and($activity->changes)->toBe([])
        ->and($activity->properties['reason'])->toBe('The donor widened the target group.')
        ->and($activity->properties['projects'])->toEqual([['project_id' => $project->id, 'name' => 'Water Network Rehabilitation', 'from' => 400, 'to' => 650]])
        ->and(Activity::query()->get()->toJson())->not->toContain('120000')
        ->and(UserNotification::query()->forUser($mealUser)->get()->pluck('data.key')->all())->toContain($key)
        ->and(UserNotification::query()->forUser($officerUser)->get()->pluck('data.key')->all())->toContain($key)
        ->and(UserNotification::query()->forUser($bystander)->get()->pluck('data.key')->all())->not->toContain($key);
});

test('a user without proposals.create cannot create a proposal', function () {
    $project = Project::factory()->create();

    expect(fn () => saveProposal(userWithRole('Media Team'), null, proposalFormData([$project->id])))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('proposals')->count())->toBe(0);
});

test('a user without proposals.update cannot edit a proposal', function () {
    $proposal = linkedProposal();

    expect(fn () => saveProposal(userWithRole('Media Team'), $proposal, proposalEditData($proposal, ['name' => 'Renamed'])))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('proposals')->find($proposal->id)->name)->not->toBe('Renamed');
});

test('a creator without proposals.update starting at the proposal draft gets nothing half-created', function () {
    $project = Project::factory()->create();
    $actor = userWithRole('Media Team');
    $actor->givePermissionTo(['proposals.view', 'proposals.create']);

    expect(fn () => saveProposal($actor, null, proposalFormData([$project->id], [
        'startingStage' => 'proposal draft',
        'skipReason' => 'The donor already asked for the full proposal.',
    ])))->toThrow(AuthorizationException::class);

    expect(DB::table('proposals')->count())->toBe(0)
        ->and(DB::table('proposal_status_notes')->count())->toBe(0)
        ->and(DB::table('project_proposal')->count())->toBe(0);
});

/*
| The v2 form on the pages (slice 2): the register's create/edit modal and
| the proposal page's Edit details both save through SaveProposal.
*/

/**
 * The register with its create form open, as the given user.
 */
function openProposalCreateForm(User $actor): Testable
{
    return Livewire::actingAs($actor)->test('pages::proposals.index')->call('openCreateModal');
}

/**
 * Fill every required create field except the projects.
 */
function fillProposalBasics(Testable $form): Testable
{
    return $form
        ->set('name', 'Winter Shelter Response')
        ->set('internalProjectNumber', 'HC-2026-951')
        ->set('donorId', (string) Donor::factory()->create()->id)
        ->set('plannedDateBegin', '2026-11-01')
        ->set('plannedDateEnd', '2027-10-31');
}

test('the register creates a proposal with its projects and beneficiaries at the concept note', function () {
    $project = Project::factory()->create();

    fillProposalBasics(openProposalCreateForm(userWithRole('Financial')))
        ->set('projectIds', [(string) $project->id])
        ->set("beneficiaries.{$project->id}", '320')
        ->call('saveProposal')
        ->assertHasNoErrors()
        ->assertSet('showProposalModal', false);

    $proposal = Proposal::query()->sole();

    expect($proposal->status)->toBe(ProposalStatus::ConceptNote)
        ->and(DB::table('project_proposal')->where('proposal_id', $proposal->id)->pluck('target_beneficiaries', 'project_id')->all())->toBe([$project->id => 320])
        ->and(DB::table('proposal_status_notes')->where('proposal_id', $proposal->id)->count())->toBe(1);
});

test('the form offers no free status, only the concept note or a skip to the proposal draft', function () {
    $project = Project::factory()->create();

    fillProposalBasics(openProposalCreateForm(userWithRole('Financial')))
        ->assertDontSeeHtml('wire:model="proposalStatus"')
        ->assertDontSeeHtml('wire:model="skipReason"')
        ->set('projectIds', [(string) $project->id])
        ->set('startingStage', 'approved')
        ->call('saveProposal')
        ->assertHasErrors(['startingStage']);

    expect(DB::table('proposals')->count())->toBe(0);
});

test('the project picker lists linkable projects with their center and city, and searches by name', function () {
    $cityId = insertHrCity('Idlib', 'إدلب');
    $centerId = insertHrCenter($cityId, 'Hope Center Idlib', 'مركز الأمل إدلب');
    $water = Project::factory()->create(['name' => 'Water Network Rehabilitation', 'city_id' => $cityId, 'center_id' => $centerId]);
    Project::factory()->status(ProjectStatus::Closed)->create(['name' => 'Bakery Support']);
    Project::factory()->status(ProjectStatus::Archived)->create(['name' => 'Old Mobile Clinic']);

    openProposalCreateForm(userWithRole('Financial'))
        ->assertSeeText('Water Network Rehabilitation')
        ->assertSeeText('Hope Center Idlib · '.$water->program->name.' · Idlib')
        ->assertSeeText('Bakery Support')
        ->assertDontSeeText('Old Mobile Clinic')
        ->set('projectSearch', 'Water')
        ->assertSeeText('Water Network Rehabilitation')
        ->assertDontSeeText('Bakery Support');
});

test('a skipped concept note asks for the reason in Arabic and the preset reason saves the proposal draft', function () {
    $project = Project::factory()->create();
    $actor = userWithRole('Financial');
    $actor->forceFill(['locale' => 'ar'])->save();
    app()->setLocale('ar');

    $form = fillProposalBasics(openProposalCreateForm($actor))
        ->set('projectIds', [(string) $project->id])
        ->set('startingStage', 'proposal draft')
        ->assertSeeHtml('wire:model="skipReason"')
        ->call('saveProposal')
        ->assertHasErrors(['skipReason'])
        ->assertSeeText('اكتب سبب تخطّي المذكرة المفاهيمية (10 أحرف على الأقل)')
        ->assertSeeHtml('data-form-error-summary')
        ->assertSeeText('حقل واحد يحتاج إلى مراجعة');

    $form->call('useSkipPreset')
        ->assertSet('skipReason', 'المقترح موجود مسبقًا')
        ->call('saveProposal')
        ->assertHasNoErrors();

    $proposal = Proposal::query()->sole();

    expect($proposal->status)->toBe(ProposalStatus::ProposalDraft)
        ->and(DB::table('proposal_status_notes')->where('proposal_id', $proposal->id)->orderBy('id')->pluck('context')->all())
        ->toBe([__('proposals.proposal_recorded_note'), 'المقترح موجود مسبقًا']);
});

test('the budget fields are offered only to someone who may see money', function (Closure $role, bool $seesBudget) {
    $proposal = linkedProposal();
    $form = Livewire::actingAs(userWithRole($role()))->test('pages::proposals.index')->call('openEditModal', $proposal->id);

    $seesBudget
        ? $form->assertSeeHtml('wire:model="requestedBudget"')->assertSet('requestedBudget', '120000.00')
        : $form->assertDontSeeHtml('wire:model="requestedBudget"')->assertSet('requestedBudget', '');
})->with([
    'Financial' => [fn () => 'Financial', true],
    'fundraiser without agreements' => [fn () => fundraiserWithoutAgreements(), false],
]);

test('New Concept Note opens the create form with its project already chosen', function () {
    $project = Project::factory()->create();

    Livewire::withQueryParams(['for_project' => (string) $project->id])
        ->actingAs(userWithRole('Financial'))
        ->test('pages::proposals.index')
        ->assertSet('showProposalModal', true)
        ->assertSet('projectIds', [(string) $project->id])
        ->assertSet('forProject', '');
});

test('New Concept Note ignores a project that cannot take a proposal', function (Closure $makeProjectId) {
    Livewire::withQueryParams(['for_project' => (string) $makeProjectId()])
        ->actingAs(userWithRole('Financial'))
        ->test('pages::proposals.index')
        ->assertSet('showProposalModal', true)
        ->assertSet('projectIds', []);
})->with([
    'archived' => fn (): int => Project::factory()->status(ProjectStatus::Archived)->create()->id,
    'unknown id' => fn (): int => 999999,
]);

test('New Concept Note opens nothing for a user who cannot create proposals', function () {
    $project = Project::factory()->create();

    Livewire::withQueryParams(['for_project' => (string) $project->id])
        ->actingAs(userWithRole('Team Member'))
        ->test('pages::proposals.index')
        ->assertSet('showProposalModal', false)
        ->assertSet('projectIds', []);
});

test('editing from the register changes the beneficiaries and asks for a reason after approval', function () {
    $proposal = linkedProposal(ProposalStatus::Approved);
    $projectId = (int) $proposal->projects()->value('projects.id');

    $form = Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::proposals.index')
        ->call('openEditModal', $proposal->id)
        ->assertSet("beneficiaries.{$projectId}", '400')
        ->assertSeeText('Water Network Rehabilitation')
        ->assertSeeHtml('wire:model="beneficiariesReason"')
        ->set("beneficiaries.{$projectId}", '450')
        ->call('saveProposal')
        ->assertHasErrors(['beneficiariesReason']);

    $form->set('beneficiariesReason', 'The donor widened the target group.')
        ->call('saveProposal')
        ->assertHasNoErrors()
        ->assertSet('showProposalModal', false);

    expect(DB::table('project_proposal')->where('proposal_id', $proposal->id)->value('target_beneficiaries'))->toBe(450);
});
