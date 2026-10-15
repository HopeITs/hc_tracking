<?php

use App\Actions\Proposals\AddProposalReviewer;
use App\Actions\Proposals\MarkProposalReviewed;
use App\Actions\Proposals\SaveProposalFile;
use App\Actions\Proposals\TransitionProposal;
use App\Enums\Currency;
use App\Enums\MediaCollection;
use App\Enums\ProposalStatus;
use App\Enums\ReviewerSlot;
use App\Enums\ReviewStage;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\User;
use App\Models\UserNotification;
use App\Notifications\Alerts\NotificationSettings;
use App\Support\Slots;
use App\Support\TransitionResult;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/**
 * Point a default-reviewer slot (contracts §9) at a new HR position held by
 * the given employee (or a new one), keeping the other slots, and return the
 * holder's employee id.
 */
function holdReviewSlot(ReviewerSlot $slot, ?int $employeeId = null): int
{
    $employeeId ??= insertHrEmployee(firstNameEn: ucfirst(str_replace('_', ' ', $slot->value)), lastNameEn: 'Holder');
    $positionId = insertHrPosition(insertHrDepartment('Management'), $slot->value);
    insertHrContract($employeeId, $positionId);
    configureReviewSlot($slot, $positionId);

    return $employeeId;
}

/**
 * Store an HR position (or null) for one slot, keeping the others.
 */
function configureReviewSlot(ReviewerSlot $slot, ?int $positionId): void
{
    $settings = app(NotificationSettings::class);
    $slots = $settings->get('proposal_default_reviewers') ?? [];

    $settings->save(['proposal_default_reviewers' => [...['meal_manager' => null, 'finance_manager' => null, 'fundraising_manager' => null, 'ceo' => null], ...$slots, $slot->value => $positionId]]);
}

/**
 * A proposal at the given status with one linked project that meets every
 * transition requirement.
 */
function reviewableProposal(ProposalStatus $status = ProposalStatus::ConceptNote): Proposal
{
    $proposal = Proposal::factory()->create(['status' => $status, 'requested_budget' => '120000.00', 'requested_currency' => Currency::Usd]);
    $proposal->projects()->attach(Project::factory()->create()->id, ['target_beneficiaries' => 400]);

    return $proposal;
}

/**
 * Move a proposal as the given user.
 *
 * @param  array<string, string>  $input
 */
function moveProposal(User $actor, Proposal $proposal, ProposalStatus $to, array $input = []): TransitionResult
{
    test()->actingAs($actor);

    return app(TransitionProposal::class)->handle($proposal, $to, $proposal->status, $input);
}

/**
 * The reviewers of one stage as employee id => slot value.
 *
 * @return array<int, string>
 */
function reviewerSlotsOf(Proposal $proposal, ReviewStage $stage): array
{
    return DB::table('proposal_reviewers')->where('proposal_id', $proposal->id)->where('stage', $stage->value)
        ->orderBy('employee_id')->pluck('slot', 'employee_id')->all();
}

/**
 * The notification keys a user received, oldest first.
 *
 * @return list<string>
 */
function notificationKeysOf(User $user): array
{
    return UserNotification::query()->forUser($user)->orderBy('created_at')->get()->pluck('data.key')->all();
}

/**
 * The owner and program manager of a project, as HR employee ids.
 *
 * @return array{owner: int, programManager: int}
 */
function projectPeople(Project $project): array
{
    return ['owner' => (int) $project->user_id, 'programManager' => (int) Program::query()->whereKey($project->program_id)->value('manager_id')];
}

beforeEach(fn () => $this->travelTo('2026-10-13 10:00:00'));

const ReviewRequestedForYou = 'notifications.events.proposal.review_requested_you';

test('entering concept note review invites the four slots, the project owner and the program manager, one request each', function () {
    $holders = [];

    foreach (ReviewerSlot::defaultSlots() as $slot) {
        $holders[$slot->value] = holdReviewSlot($slot);
    }

    $proposal = reviewableProposal();
    $people = projectPeople($proposal->projects()->sole());
    $actor = userWithRole('Financial');
    $reviewerUsers = array_map(fn (int $employeeId): User => userWithRole('Team Member', $employeeId), [...array_values($holders), ...array_values($people)]);

    moveProposal($actor, $proposal, ProposalStatus::ConceptNoteReview);

    $expected = [
        $holders['meal_manager'] => 'meal_manager',
        $holders['finance_manager'] => 'finance_manager',
        $holders['fundraising_manager'] => 'fundraising_manager',
        $holders['ceo'] => 'ceo',
        $people['owner'] => 'project_owner',
        $people['programManager'] => 'program_manager',
    ];
    ksort($expected);

    expect(reviewerSlotsOf($proposal, ReviewStage::ConceptNote))->toBe($expected)
        ->and(DB::table('proposal_reviewers')->pluck('added_by')->unique()->all())->toBe([$actor->id])
        ->and(DB::table('proposal_reviewers')->whereNotNull('seen_at')->exists())->toBeFalse();

    foreach ($reviewerUsers as $user) {
        expect(notificationKeysOf($user))->toBe([ReviewRequestedForYou]);
    }
});

test('re-entering a review adds only newly linked people and re-notifies only those who have not seen it', function () {
    $mealManager = holdReviewSlot(ReviewerSlot::MealManager);
    $proposal = reviewableProposal();
    $first = projectPeople($proposal->projects()->sole());
    $actor = userWithRole('Financial');
    $mealUser = userWithRole('Team Member', $mealManager);
    $ownerUser = userWithRole('Team Member', $first['owner']);

    moveProposal($actor, $proposal, ProposalStatus::ConceptNoteReview);
    ProposalReviewer::query()->where('employee_id', $mealManager)->update(['seen_at' => now()]);
    moveProposal($actor, $proposal->fresh(), ProposalStatus::ConceptNote, ['reason' => 'Rework the problem statement.']);

    $second = Project::factory()->create();
    $proposal->projects()->attach($second->id, ['target_beneficiaries' => 120]);
    $financeManager = holdReviewSlot(ReviewerSlot::FinanceManager);

    moveProposal($actor, $proposal->fresh(), ProposalStatus::ConceptNoteReview);

    $added = projectPeople($second);
    $expected = [
        $mealManager => 'meal_manager',
        $first['owner'] => 'project_owner',
        $first['programManager'] => 'program_manager',
        $added['owner'] => 'project_owner',
        $added['programManager'] => 'program_manager',
    ];
    ksort($expected);

    $asked = array_keys($expected);
    unset($asked[array_search($mealManager, $asked, true)]);
    $requests = Activity::query()->where('event', 'proposal.review_requested')->orderBy('id')->get();

    // The second request merges into the owner's unread first one (NotificationGrouper).
    expect(reviewerSlotsOf($proposal, ReviewStage::ConceptNote))->toBe($expected)
        ->and($expected)->not->toHaveKey($financeManager)
        ->and($requests)->toHaveCount(2)
        ->and($requests[1]->properties['employee_ids'])->toBe(array_values($asked))
        ->and(UserNotification::query()->forUser($mealUser)->sole()->data['count'])->toBe(1)
        ->and(UserNotification::query()->forUser($ownerUser)->sole()->data['count'])->toBe(2);
});

test('the review rows of one stage never count for the other', function () {
    $mealManager = holdReviewSlot(ReviewerSlot::MealManager);
    $proposal = reviewableProposal();
    $actor = userWithRole('Financial');

    moveProposal($actor, $proposal, ProposalStatus::ConceptNoteReview);
    ProposalReviewer::query()->update(['seen_at' => now()]);
    moveProposal($actor, $proposal->fresh(), ProposalStatus::ConceptNote, ['reason' => 'The donor asked for a full proposal.']);
    moveProposal($actor, $proposal->fresh(), ProposalStatus::ProposalDraft, ['reason' => 'المقترح موجود مسبقًا']);
    moveProposal($actor, $proposal->fresh(), ProposalStatus::ProposalReview);

    expect(DB::table('proposal_reviewers')->where('stage', 'proposal')->where('employee_id', $mealManager)->value('seen_at'))->toBeNull()
        ->and(DB::table('proposal_reviewers')->where('stage', 'proposal')->count())->toBe(3);
});

test('a person in two slots gets one row with the higher slot, and an inactive reviewer keeps the row but is not notified', function () {
    $proposal = reviewableProposal();
    $people = projectPeople($proposal->projects()->sole());
    holdReviewSlot(ReviewerSlot::Ceo, $people['owner']);
    DB::connection('hr')->table('employees')->where('id', $people['programManager'])->update(['is_active' => false]);
    $inactiveUser = userWithRole('Team Member', $people['programManager']);

    moveProposal(userWithRole('Financial'), $proposal, ProposalStatus::ConceptNoteReview);

    $activity = Activity::query()->where('event', 'proposal.review_requested')->sole();

    expect(reviewerSlotsOf($proposal, ReviewStage::ConceptNote))->toEqual([$people['owner'] => 'ceo', $people['programManager'] => 'program_manager'])
        ->and($activity->properties['employee_ids'])->toBe([$people['owner']])
        ->and(notificationKeysOf($inactiveUser))->toBe([]);
});

test('a slot resolves to the active-contract holder of its position', function () {
    $positionId = insertHrPosition(insertHrDepartment('Finance'), 'Finance Manager');
    $former = insertHrEmployee(firstNameEn: 'Former');
    $current = insertHrEmployee(firstNameEn: 'Current');
    insertHrContract($former, $positionId, isActive: false, startDate: '2024-01-01');
    insertHrContract($current, $positionId, startDate: '2026-01-01');
    configureReviewSlot(ReviewerSlot::FinanceManager, $positionId);

    $proposal = reviewableProposal();
    moveProposal(userWithRole('Financial'), $proposal, ProposalStatus::ConceptNoteReview);

    expect(Slots::holderOf('finance_manager'))->toBe($current)
        ->and(reviewerSlotsOf($proposal, ReviewStage::ConceptNote))->toHaveKey($current)
        ->and(reviewerSlotsOf($proposal, ReviewStage::ConceptNote))->not->toHaveKey($former);
});

test('a vacant slot adds no reviewer and the review still starts', function (Closure $vacate) {
    $vacate();
    $proposal = reviewableProposal();

    moveProposal(userWithRole('Financial'), $proposal, ProposalStatus::ConceptNoteReview);

    expect(Slots::holderOf('ceo'))->toBeNull()
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::ConceptNoteReview)
        ->and(reviewerSlotsOf($proposal, ReviewStage::ConceptNote))->not->toContain('ceo');
})->with([
    'no position configured' => fn () => fn () => configureReviewSlot(ReviewerSlot::Ceo, null),
    'no active contract on the position' => fn () => fn () => configureReviewSlot(ReviewerSlot::Ceo, insertHrPosition(insertHrDepartment('Board'), 'CEO')),
    'the holder is inactive in HR' => fn () => fn () => holdReviewSlot(ReviewerSlot::Ceo, insertHrEmployee(firstNameEn: 'Retired', isActive: false)),
]);

test('only the four default slots can be resolved', function () {
    expect(fn () => Slots::holderOf('project_owner'))->toThrow(InvalidArgumentException::class);
});

test('sending to the donor warns about unseen reviewers by name but never blocks', function () {
    $proposal = reviewableProposal(ProposalStatus::ConceptNoteReview);
    $seen = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Seen');
    $unseen = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Unseen');
    ProposalReviewer::factory()->seen()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::ConceptNote, 'employee_id' => $seen]);
    ProposalReviewer::factory()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::ConceptNote, 'employee_id' => $unseen]);
    $actor = userWithRole('Financial');

    $modal = Livewire::actingAs($actor)->test('pages::proposals.index')
        ->call('openStatusModal', $proposal->id)
        ->set('newStatus', ProposalStatus::ConceptNoteSubmitted->value);

    expect($modal->instance()->unseenReviewerNames)->toBe(['Omar Unseen']);
    $modal->assertSee('Omar Unseen')->assertDontSee('Rana Seen');

    $result = moveProposal($actor, $proposal, ProposalStatus::ConceptNoteSubmitted, ['effective_date' => '2026-10-13']);

    expect($result->warnings)->toContain('reviewers_unseen')
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::ConceptNoteSubmitted);
});

test('a send with every reviewer seen raises no unseen warning', function () {
    $proposal = reviewableProposal(ProposalStatus::ProposalReview);
    ProposalReviewer::factory()->seen()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::Proposal]);
    ProposalReviewer::factory()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::ConceptNote]);

    $result = moveProposal(userWithRole('Financial'), $proposal, ProposalStatus::Submitted, ['effective_date' => '2026-10-13']);

    expect($result->warnings)->not->toContain('reviewers_unseen');
});

test('the meeting time is stored on the note that opens the review, sent with the request and shown in My Calendar', function () {
    $mealManager = holdReviewSlot(ReviewerSlot::MealManager);
    $mealUser = userWithRole('Team Member', $mealManager);
    $proposal = reviewableProposal();

    $result = moveProposal(userWithRole('Financial'), $proposal, ProposalStatus::ConceptNoteReview, ['meeting_at' => '2026-10-20 10:30']);

    expect($result->note->fresh()->meeting_at?->format('Y-m-d H:i'))->toBe('2026-10-20 10:30')
        ->and(sentencesFor($mealUser)[0])->toContain('Oct 20, 2026 · 10:30');

    Livewire::actingAs($mealUser)->test('pages::calendar.index')
        ->assertSee(__('calendar.review_meeting'))
        ->assertSee($proposal->name);
});

test('a meeting time in the past is refused and nothing moves', function () {
    $proposal = reviewableProposal();

    expect(fn () => moveProposal(userWithRole('Financial'), $proposal, ProposalStatus::ConceptNoteReview, ['meeting_at' => '2026-10-13 09:00']))
        ->toThrow(ValidationException::class, __('proposals.meeting_after_now'));

    expect($proposal->fresh()->status)->toBe(ProposalStatus::ConceptNote)
        ->and(DB::table('proposal_reviewers')->count())->toBe(0);
});

test('an added reviewer hears at once and General Management through oversight, but not MEAL', function () {
    $proposal = reviewableProposal(ProposalStatus::ConceptNoteReview);
    $employeeId = insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Extra');
    $reviewer = userWithRole('Team Member', $employeeId);
    $generalManager = userWithRole('General Manager');
    $meal = userWithRole('MEAL');
    $this->actingAs(userWithRole('Financial'));

    app(AddProposalReviewer::class)->handle($proposal, $employeeId);

    expect(notificationKeysOf($reviewer))->toBe(['notifications.events.proposal.reviewer_added_you'])
        ->and(notificationKeysOf($generalManager))->toBe(['notifications.events.proposal.reviewer_added'])
        ->and(notificationKeysOf($meal))->toBe([]);
});

test('marking as seen tells the project owner at once; it is a digest event, so oversight waits for the daily summary', function () {
    $proposal = reviewableProposal(ProposalStatus::ConceptNoteReview);
    $owner = projectPeople($proposal->projects()->sole())['owner'];
    ProposalReviewer::factory()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::ConceptNote, 'employee_id' => $owner, 'slot' => ReviewerSlot::ProjectOwner]);
    $ownerUser = userWithRole('Team Member', $owner);
    $readerId = insertHrEmployee(firstNameEn: 'Reader');
    $row = ProposalReviewer::factory()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::ConceptNote, 'employee_id' => $readerId]);
    $generalManager = userWithRole('General Manager');

    $this->actingAs(userWithRole('Team Member', $readerId));
    app(MarkProposalReviewed::class)->handle($proposal, $row->id, null);

    expect(notificationKeysOf($ownerUser))->toBe(['notifications.events.proposal.reviewer_seen'])
        ->and(notificationKeysOf($generalManager))->toBe([]);
});

test('a reviewer with results.create attaches a need assessment and the loop hears about it', function () {
    $mealManager = holdReviewSlot(ReviewerSlot::MealManager);
    $mealManagerUser = userWithRole('MEAL', $mealManager);
    $proposal = reviewableProposal(ProposalStatus::ConceptNoteReview);
    $owner = projectPeople($proposal->projects()->sole())['owner'];
    $ownerUser = userWithRole('Team Member', $owner);
    ProposalReviewer::factory()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::ConceptNote, 'employee_id' => $owner, 'slot' => ReviewerSlot::ProjectOwner]);
    $officerId = insertHrEmployee(firstNameEn: 'Officer');
    ProposalReviewer::factory()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::ConceptNote, 'employee_id' => $officerId]);

    $this->actingAs(userWithRole('MEAL', $officerId));
    $media = app(SaveProposalFile::class)->handle($proposal, null, validUpload('assessment.pdf'), ['fileCategory' => MediaCollection::NeedAssessment->value, 'fileDisplayName' => '']);

    $key = 'notifications.events.proposal.need_assessment_added';

    expect($media->collection)->toBe(MediaCollection::NeedAssessment)
        ->and(notificationKeysOf($ownerUser))->toBe([$key])
        ->and(notificationKeysOf($mealManagerUser))->toBe([$key]);
});

test('a need assessment needs proposals.update, or a reviewer who may create results', function (string $role, bool $isReviewer) {
    $proposal = reviewableProposal(ProposalStatus::ConceptNoteReview);
    $employeeId = insertHrEmployee();

    if ($isReviewer) {
        ProposalReviewer::factory()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::ConceptNote, 'employee_id' => $employeeId]);
    }

    $this->actingAs(userWithRole($role, $employeeId));

    expect(fn () => app(SaveProposalFile::class)->handle($proposal, null, validUpload('assessment.pdf'), ['fileCategory' => MediaCollection::NeedAssessment->value, 'fileDisplayName' => '']))
        ->toThrow(AuthorizationException::class);

    expect(DB::table('media')->count())->toBe(0);
})->with([
    'a reviewer without results.create' => ['Team Member', true],
    'MEAL who is not a reviewer' => ['MEAL', false],
]);
