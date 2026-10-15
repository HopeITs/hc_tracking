<?php

use App\Enums\ProposalStatus;
use App\Enums\ReviewStage;
use App\Models\Proposal;
use App\Models\ProposalReviewer;
use App\Models\User;
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

test('a reviewer without proposals.view gets a My reviews link counting what waits on them', function () {
    [$employeeId, $user] = reviewerLogin();
    myReview($employeeId, 'Water Access', ProposalStatus::ConceptNoteReview);
    myReview($employeeId, 'School Meals', ProposalStatus::ProposalReview, ReviewStage::Proposal);
    myReview($employeeId, 'Already Read', ProposalStatus::ConceptNoteReview, seen: true);

    expect($user->can('proposals.view'))->toBeFalse();

    $html = $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSeeHtml('href="'.route('reviews.index').'"')
        ->getContent();

    expect($html)->toMatch('/data-my-reviews-badge\s*>2</');
});

test('someone never asked to review sees no My reviews link', function () {
    [, $user] = reviewerLogin();
    myReview(insertHrEmployee(), 'Someone Else', ProposalStatus::ConceptNoteReview);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertDontSeeHtml('data-my-reviews-link');
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

test('the dashboard leads Needs attention with the reviews waiting on the viewer', function () {
    [$employeeId, $user] = reviewerLogin();
    $waiting = myReview($employeeId, 'Water Access', ProposalStatus::ConceptNoteReview);
    $waiting->forceFill(['created_at' => now()->subDays(3)])->save();
    myReview($employeeId, 'Already Read', ProposalStatus::ConceptNoteReview, seen: true);

    $dashboard = Livewire::actingAs($user)->test('pages::dashboard.index');

    expect($dashboard->get('attentionCounts')['reviews'])->toBe(1)
        ->and($dashboard->get('attentionItems')->first())->toMatchArray([
            'key' => 'review-'.$waiting->id,
            'group' => 'reviews',
            'title' => 'Water Access',
            'href' => route('proposals.show', $waiting->proposal),
            'allRank' => 0,
        ]);

    $dashboard
        ->assertSee(__('dashboard.reviews'))
        ->assertSee(trans_choice('dashboard.review_asked_days', 3, ['count' => 3]))
        ->assertDontSee('Already Read');
});

test('the dashboard has no reviews tab for someone nothing waits on', function () {
    [$employeeId, $user] = reviewerLogin();
    myReview($employeeId, 'Already Read', ProposalStatus::ConceptNoteReview, seen: true);

    $dashboard = Livewire::actingAs($user)->test('pages::dashboard.index');

    expect($dashboard->get('attentionCounts')['reviews'])->toBe(0);
    $dashboard->assertDontSee(__('dashboard.reviews'));
});
