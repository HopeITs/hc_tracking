<?php

use App\Actions\Proposals\MarkProposalReviewed;
use App\Actions\Proposals\RemoveProposalReviewer;
use App\Enums\Currency;
use App\Enums\MediaCollection;
use App\Enums\ProposalStatus;
use App\Enums\ReviewerSlot;
use App\Enums\ReviewStage;
use App\Models\Activity;
use App\Models\DonorProposal;
use App\Models\Media;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

/**
 * A proposal at the given status with one linked project.
 */
function panelProposal(ProposalStatus $status = ProposalStatus::ConceptNoteReview): Proposal
{
    $proposal = Proposal::factory()->create(['status' => $status, 'requested_budget' => '120000.00', 'requested_currency' => Currency::Usd]);
    $proposal->projects()->attach(Project::factory()->create()->id, ['target_beneficiaries' => 400]);

    return $proposal;
}

/**
 * A reviewer row on the proposal for a new HR employee, and that employee's
 * login (no proposals.view).
 *
 * @return array{0: ProposalReviewer, 1: User}
 */
function reviewerWithLogin(Proposal $proposal, ReviewStage $stage = ReviewStage::ConceptNote, string $role = 'Team Member', string $name = 'Lina'): array
{
    $employeeId = insertHrEmployee(firstNameEn: $name, lastNameEn: 'Reviewer');
    $row = ProposalReviewer::factory()->create(['proposal_id' => $proposal->id, 'stage' => $stage, 'employee_id' => $employeeId]);

    return [$row, userWithRole($role, $employeeId)];
}

function reviewersPanel(User $user, Proposal $proposal): Testable
{
    return Livewire::actingAs($user)->test('pages::proposals.show', ['proposal' => $proposal]);
}

beforeEach(fn () => $this->travelTo('2026-10-13 10:00:00'));

test('the panel lists each reviewer with slot, state and note, and warns about a vacant slot', function (string $locale) {
    app()->setLocale($locale);
    $proposal = panelProposal();
    $seenId = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Seen');
    ProposalReviewer::factory()->seen()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::ConceptNote, 'employee_id' => $seenId, 'slot' => ReviewerSlot::FinanceManager, 'note' => 'Budget lines look fine.']);
    $inactiveId = insertHrEmployee(firstNameEn: 'Sami', lastNameEn: 'Gone', isActive: false);
    ProposalReviewer::factory()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::ConceptNote, 'employee_id' => $inactiveId]);

    reviewersPanel(userWithRole('Financial'), $proposal)
        ->assertSeeHtml('data-proposal-reviewers')
        ->assertSee(ReviewerSlot::FinanceManager->label())
        ->assertSee(__('proposals.reviewer_seen_on', ['date' => now()->translatedFormat(__('formats.date'))]))
        ->assertSee(__('proposals.reviewer_not_seen'))
        ->assertSee('Budget lines look fine.')
        ->assertSee(__('proposals.inactive_account'))
        ->assertSee(__('proposals.position_vacant'))
        ->assertSee(ReviewerSlot::MealManager->label());
})->with(['en', 'ar']);

test('a reviewer marks the proposal as seen with a note', function () {
    $proposal = panelProposal();
    [$row, $user] = reviewerWithLogin($proposal);

    reviewersPanel($user, $proposal)
        ->set("reviewerNotes.{$row->id}", 'Read it; the targets look ambitious.')
        ->call('markReviewed', $row->id)
        ->assertHasNoErrors();

    $stored = DB::table('proposal_reviewers')->find($row->id);

    expect($stored->seen_at)->toBe('2026-10-13 10:00:00')
        ->and($stored->note)->toBe('Read it; the targets look ambitious.')
        ->and(Activity::query()->where('event', 'proposal.reviewer_seen')->count())->toBe(1);
});

test('a second mark changes nothing', function () {
    $proposal = panelProposal();
    [$row, $user] = reviewerWithLogin($proposal);
    $component = reviewersPanel($user, $proposal)->call('markReviewed', $row->id);

    $this->travel(2)->hours();
    $component->call('markReviewed', $row->id);

    expect(DB::table('proposal_reviewers')->where('id', $row->id)->value('seen_at'))->toBe('2026-10-13 10:00:00')
        ->and(Activity::query()->where('event', 'proposal.reviewer_seen')->count())->toBe(1);
});

test('nobody marks or edits a row that is not theirs', function () {
    $proposal = panelProposal();
    [$row] = reviewerWithLogin($proposal);
    [, $other] = reviewerWithLogin($proposal, name: 'Other');

    reviewersPanel($other, $proposal)->call('markReviewed', $row->id)->assertForbidden();
    reviewersPanel($other, $proposal)->set("reviewerNotes.{$row->id}", 'Not mine.')->call('saveReviewerNote', $row->id)->assertForbidden();

    expect(DB::table('proposal_reviewers')->find($row->id)->seen_at)->toBeNull();
});

test('a user without an HR employee never matches a reviewer row', function () {
    $proposal = panelProposal();
    $row = ProposalReviewer::factory()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::ConceptNote]);
    $this->actingAs(userWithRole('Financial'));

    expect(fn () => app(MarkProposalReviewed::class)->handle($proposal, $row->id, null))
        ->toThrow(AuthorizationException::class);
});

test('a reviewer row of another proposal is not found', function () {
    $proposal = panelProposal();
    $other = panelProposal();
    [$row, $user] = reviewerWithLogin($other);
    ProposalReviewer::factory()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::ConceptNote, 'employee_id' => $user->employee_id]);
    $this->actingAs($user);

    expect(fn () => app(MarkProposalReviewed::class)->handle($proposal, $row->id, null))->toThrow(ModelNotFoundException::class);
    $this->actingAs(userWithRole('Financial'));
    expect(fn () => app(RemoveProposalReviewer::class)->handle($proposal, $row->id))->toThrow(ModelNotFoundException::class);

    expect(DB::table('proposal_reviewers')->find($row->id)->seen_at)->toBeNull();
});

test('the note is editable only while its stage is under review', function () {
    $proposal = panelProposal(ProposalStatus::ConceptNoteSubmitted);
    [$row, $user] = reviewerWithLogin($proposal);
    ProposalReviewer::query()->whereKey($row->id)->update(['seen_at' => now()->subDay(), 'note' => 'First read.']);

    reviewersPanel($user, $proposal)
        ->set("reviewerNotes.{$row->id}", 'Changed my mind.')
        ->call('saveReviewerNote', $row->id)
        ->assertHasErrors(["reviewerNotes.{$row->id}" => __('proposals.reviewer_note_stage_closed')]);

    expect(DB::table('proposal_reviewers')->where('id', $row->id)->value('note'))->toBe('First read.');
});

test('a note longer than 2000 characters is refused', function () {
    $proposal = panelProposal();
    [$row, $user] = reviewerWithLogin($proposal);

    reviewersPanel($user, $proposal)
        ->set("reviewerNotes.{$row->id}", str_repeat('a', 2001))
        ->call('markReviewed', $row->id)
        ->assertHasErrors(["reviewerNotes.{$row->id}" => 'max']);

    expect(DB::table('proposal_reviewers')->find($row->id)->seen_at)->toBeNull();
});

test('a manager adds a reviewer to the stage derived from the status', function (ProposalStatus $status, ReviewStage $stage) {
    $proposal = panelProposal($status);
    $employeeId = insertHrEmployee(firstNameEn: 'Extra');
    $manager = userWithRole('Public Relations Manager');

    reviewersPanel($manager, $proposal)->set('reviewerEmployeeId', (string) $employeeId)->call('addReviewer')->assertHasNoErrors();

    $row = DB::table('proposal_reviewers')->sole();

    expect($row->stage)->toBe($stage->value)
        ->and($row->slot)->toBe('additional')
        ->and($row->added_by)->toBe($manager->id)
        ->and(Activity::query()->where('event', 'proposal.reviewer_added')->count())->toBe(1);
})->with([
    'concept note' => [ProposalStatus::ConceptNote, ReviewStage::ConceptNote],
    'concept note review' => [ProposalStatus::ConceptNoteReview, ReviewStage::ConceptNote],
    'proposal draft' => [ProposalStatus::ProposalDraft, ReviewStage::Proposal],
    'proposal review' => [ProposalStatus::ProposalReview, ReviewStage::Proposal],
]);

test('no reviewer can be added outside the review stages', function () {
    $proposal = panelProposal(ProposalStatus::Submitted);

    reviewersPanel(userWithRole('Financial'), $proposal)
        ->set('reviewerEmployeeId', (string) insertHrEmployee())
        ->call('addReviewer')
        ->assertHasErrors(['reviewerEmployeeId' => __('proposals.reviewers_closed_for_status')]);

    expect(DB::table('proposal_reviewers')->count())->toBe(0);
});

test('adding someone already on the stage is refused with a clear message', function () {
    $proposal = panelProposal();
    [$row] = reviewerWithLogin($proposal);

    reviewersPanel(userWithRole('Financial'), $proposal)
        ->set('reviewerEmployeeId', (string) $row->employee_id)
        ->call('addReviewer')
        ->assertHasErrors(['reviewerEmployeeId' => __('proposals.reviewer_already_added')]);

    expect(DB::table('proposal_reviewers')->count())->toBe(1);
});

test('the reviewer to add must exist in HR', function (string $value, string $rule) {
    reviewersPanel(userWithRole('Financial'), panelProposal())
        ->set('reviewerEmployeeId', $value)
        ->call('addReviewer')
        ->assertHasErrors(['reviewerEmployeeId' => $rule]);
})->with([
    'empty' => ['', 'required'],
    'not a number' => ['abc', 'integer'],
    'unknown in HR' => ['999999', 'exists'],
]);

test('only an unseen, unnoted additional reviewer can be removed', function (array $state, bool $removable) {
    $proposal = panelProposal();
    $row = ProposalReviewer::factory()->additional()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::ConceptNote, ...$state]);

    $component = reviewersPanel(userWithRole('Financial'), $proposal)
        ->call('confirmRemoveReviewer', $row->id)
        ->call('removeReviewer');

    if ($removable) {
        $component->assertHasNoErrors();
        expect(DB::table('proposal_reviewers')->count())->toBe(0)
            ->and(Activity::query()->where('event', 'proposal.reviewer_removed')->count())->toBe(1);
    } else {
        $component->assertHasErrors(['removeReviewer' => __('proposals.reviewer_cannot_be_removed')]);
        expect(DB::table('proposal_reviewers')->count())->toBe(1);
    }
})->with([
    'unseen additional' => [[], true],
    'seen' => [['seen_at' => '2026-10-12 09:00:00'], false],
    'with a note' => [['note' => 'Will read tonight.'], false],
    'a default slot' => [['slot' => ReviewerSlot::Ceo], false],
]);

test('without proposals.manage-reviewers nobody adds or removes a reviewer', function () {
    $proposal = panelProposal();
    [$row, $reviewer] = reviewerWithLogin($proposal);
    ProposalReviewer::query()->whereKey($row->id)->update(['slot' => 'additional']);
    $fresh = fn () => reviewersPanel($reviewer, $proposal);

    $fresh()->set('reviewerEmployeeId', (string) insertHrEmployee())->call('addReviewer')->assertForbidden();
    $fresh()->call('confirmRemoveReviewer', $row->id)->assertForbidden();

    expect(DB::table('proposal_reviewers')->count())->toBe(1);
});

test('a reviewer without proposals.view opens the proposal and its non-funding files but sees no money', function () {
    Storage::fake('local');
    $proposal = panelProposal();
    [, $user] = reviewerWithLogin($proposal);
    DonorProposal::factory()->create(['proposal_id' => $proposal->id, 'donor_id' => $proposal->donor_id, 'budget' => '987654.00']);
    $note = Media::factory()->inCollection(MediaCollection::Proposal)->create(['mediable_type' => 'proposal', 'mediable_id' => $proposal->id]);
    $agreement = Media::factory()->inCollection(MediaCollection::Agreement)->create(['mediable_type' => 'proposal', 'mediable_id' => $proposal->id]);
    Storage::disk('local')->put($note->path, 'plain');

    $this->actingAs($user)->get(route('proposals.show', ['proposal' => $proposal, 'tab' => 'status']))
        ->assertOk()
        ->assertDontSee('987,654')
        ->assertDontSee('120,000');

    $this->actingAs($user)->get(route('proposals.files.download', [$proposal, $note]))->assertOk();
    $this->actingAs($user)->get(route('proposals.files.download', [$proposal, $agreement]))->assertForbidden();

    reviewersPanel($user, $proposal)->set('tab', 'funding')->assertSet('tab', 'status');
});

test('someone who is neither a proposals.view holder nor a reviewer cannot open the proposal', function () {
    $proposal = panelProposal();
    [, $elsewhere] = reviewerWithLogin(panelProposal());

    $this->actingAs($elsewhere)->get(route('proposals.show', $proposal))->assertForbidden();
    $this->actingAs(userWithRole('Team Member'))->get(route('proposals.show', $proposal))->assertForbidden();
});
