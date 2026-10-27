<?php

use App\Enums\ProposalStatus;
use App\Enums\ReviewStage;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\User;
use App\Support\Desk\Destinations;
use Livewire\Livewire;

/**
 * A reviewer row for the employee on a new proposal with the given name and status.
 */
function myReview(int $employeeId, string $name, ProposalStatus $status, ReviewStage $stage = ReviewStage::ConceptNote, bool $seen = false): ProposalReviewer
{
    $proposal = Proposal::factory()->create(['name' => $name, 'status' => $status]);
    $factory = $seen ? ProposalReviewer::factory()->seen() : ProposalReviewer::factory();

    return $factory->create(['proposal_id' => $proposal->id, 'stage' => $stage, 'employee_id' => $employeeId]);
}

/**
 * A Team Member login (no proposals.view) for a new HR employee.
 *
 * @return array{0: int, 1: User}
 */
function reviewerLogin(): array
{
    $employeeId = insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Reviewer');

    return [$employeeId, userWithRole('Team Member', $employeeId)];
}

beforeEach(fn () => $this->travelTo('2026-10-15 10:00:00'));

test('a reviewer without proposals.view can go to My reviews and the panel counts what waits on them', function () {
    [$employeeId, $user] = reviewerLogin();
    myReview($employeeId, 'Water Access', ProposalStatus::ConceptNoteReview);
    myReview($employeeId, 'School Meals', ProposalStatus::ProposalReview, ReviewStage::Proposal);
    myReview($employeeId, 'Already Read', ProposalStatus::ConceptNoteReview, seen: true);

    expect($user->can('proposals.view'))->toBeFalse();

    expect(array_column(app(Destinations::class)->for($user), 'route'))->toContain('reviews.index');

    Livewire::actingAs($user)->test('desk.panels.reviews', ['size' => 'M'])
        ->assertSeeHtml(route('reviews.index'))
        ->assertSeeHtml('<span class="dk-pc dk-num" data-panel-count>2</span>');
});

test('the panel lists each proposal still waiting on the viewer once, never ones they reviewed or other people\'s', function () {
    [$employeeId, $user] = reviewerLogin();
    $twice = myReview($employeeId, 'Water Access', ProposalStatus::ConceptNoteReview);
    ProposalReviewer::factory()->additional()->create(['proposal_id' => $twice->proposal_id, 'stage' => ReviewStage::ConceptNote, 'employee_id' => insertHrEmployee()]);
    myReview($employeeId, 'Already Read', ProposalStatus::ConceptNoteReview, seen: true);
    myReview($employeeId, 'Moved On', ProposalStatus::ConceptNoteSubmitted);
    myReview(insertHrEmployee(), 'Someone Else', ProposalStatus::ConceptNoteReview);
    $user->assignRole('Super Admin');

    $panel = Livewire::actingAs($user)->test('desk.panels.reviews', ['size' => 'M'])
        ->assertSee('Water Access')
        ->assertDontSee('Already Read')
        ->assertDontSee('Moved On')
        ->assertDontSee('Someone Else')
        ->assertSeeHtml('<span class="dk-pc dk-num" data-panel-count>1</span>');

    expect($panel->instance()->rows)->toHaveCount(1);
});

test('someone never asked to review sees no My reviews link', function () {
    [, $user] = reviewerLogin();
    myReview(insertHrEmployee(), 'Someone Else', ProposalStatus::ConceptNoteReview);

    expect(array_column(app(Destinations::class)->for($user), 'route'))->not->toContain('reviews.index');
});

test('the page lists only the viewer\'s reviews still waiting on them, and every one of theirs on All', function () {
    [$employeeId, $user] = reviewerLogin();
    myReview($employeeId, 'Water Access', ProposalStatus::ConceptNoteReview);
    myReview($employeeId, 'Already Read', ProposalStatus::ConceptNoteReview, seen: true);
    myReview($employeeId, 'Moved On', ProposalStatus::ConceptNoteSubmitted);
    myReview($employeeId, 'Not Sent Yet', ProposalStatus::ConceptNote);
    myReview(insertHrEmployee(), 'Someone Else', ProposalStatus::ConceptNoteReview);

    $this->actingAs($user)->get(route('reviews.index'))->assertOk();

    Livewire::actingAs($user)->test('pages::reviews.index')
        ->assertSee('Water Access')
        ->assertSee(__('proposals.my_reviews_waiting_on_you'))
        ->assertDontSee('Already Read')
        ->assertDontSee('Moved On')
        ->assertDontSee('Not Sent Yet')
        ->assertDontSee('Someone Else')
        ->set('state', 'all')
        ->assertSeeInOrder(['Water Access', 'Not Sent Yet'])
        ->assertSee('Already Read')
        ->assertSee(__('proposals.reviewer_seen_on', ['date' => now()->translatedFormat(__('formats.date'))]))
        ->assertSee('Moved On')
        ->assertSee(__('proposals.my_reviews_closed'))
        ->assertDontSee('Someone Else');
});

test('the page shows an empty state to someone with no reviews', function () {
    [, $user] = reviewerLogin();

    Livewire::actingAs($user)->test('pages::reviews.index')
        ->assertSee(__('proposals.my_reviews_none_awaiting'));
});

test('needs you leads with the reviews waiting on the viewer', function () {
    [$employeeId, $user] = reviewerLogin();
    $waiting = myReview($employeeId, 'Water Access', ProposalStatus::ConceptNoteReview);
    $waiting->forceFill(['created_at' => now()->subDays(3)])->save();
    myReview($employeeId, 'Already Read', ProposalStatus::ConceptNoteReview, seen: true);

    $dashboard = Livewire::actingAs($user)->test('desk.panels.needs', ['size' => 'L']);

    expect($dashboard->get('attentionItems')->where('group', 'reviews'))->toHaveCount(1)
        ->and($dashboard->get('attentionItems')->first())->toMatchArray([
            'key' => 'review-'.$waiting->id,
            'group' => 'reviews',
            'title' => 'Water Access',
            'href' => route('proposals.show', $waiting->proposal),
            'allRank' => 0,
        ]);

    $dashboard
        ->assertSee('Water Access')
        ->assertSee(trans_choice('dashboard.review_asked_days', 3, ['count' => 3]))
        ->assertDontSee('Already Read');
});

test('needs you holds no review for someone nothing waits on', function () {
    [$employeeId, $user] = reviewerLogin();
    myReview($employeeId, 'Already Read', ProposalStatus::ConceptNoteReview, seen: true);

    $dashboard = Livewire::actingAs($user)->test('desk.panels.needs', ['size' => 'L']);

    expect($dashboard->get('attentionItems')->where('group', 'reviews'))->toHaveCount(0);
    $dashboard->assertDontSee('Already Read');
});
