<?php

use App\Enums\ProposalStatus;
use App\Enums\ReviewStage;
use App\Models\DonorProposal;
use App\Models\Project;
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

test('the page lists only the reviews still waiting on the viewer, and the finished ones on Finished', function () {
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
        ->call('setState', 'done')
        ->assertDontSee('Water Access')
        ->assertSee('Already Read')
        ->assertSee(__('proposals.reviewer_seen_on', ['date' => now()->translatedFormat(__('formats.date'))]))
        ->assertSee('Moved On')
        ->assertSee('Not Sent Yet')
        ->assertSee(__('proposals.my_reviews_closed'))
        ->assertDontSee('Someone Else');
});

test('the three buttons show disjoint counts that match what each click lists, and the search narrows them', function () {
    [$employeeId, $user] = reviewerLogin();
    myReview($employeeId, 'Water Access', ProposalStatus::ConceptNoteReview);
    myReview($employeeId, 'School Meals', ProposalStatus::ConceptNoteReview);
    myReview($employeeId, 'Already Read', ProposalStatus::ConceptNoteReview, seen: true);

    $page = Livewire::actingAs($user)->test('pages::reviews.index');

    expect($page->instance()->counts)->toBe(['awaiting' => 2, 'done' => 1, 'asked' => 0]);
    $page->assertSeeHtml('data-test="review-tab-awaiting"')
        ->assertSeeHtml('aria-pressed="true"')
        ->set('search', 'water');

    expect($page->instance()->counts)->toBe(['awaiting' => 1, 'done' => 0, 'asked' => 0]);
    $page->assertSee('Water Access')
        ->assertDontSee('School Meals')
        ->set('search', 'zzz')
        ->assertSee(__('desk.register.nothing_matches'))
        ->call('clearSearch')
        ->assertSee('School Meals')
        ->call('setState', 'asked');

    expect($page->instance()->mode)->toBe('asked');
});

test('the search reads Arabic as the other pages do: tashkeel, alef forms and the wrong layout do not matter', function (string $search) {
    [$employeeId, $user] = reviewerLogin();
    myReview($employeeId, 'مشروع الأمل', ProposalStatus::ConceptNoteReview);
    myReview($employeeId, 'Water Access', ProposalStatus::ConceptNoteReview);

    Livewire::actingAs($user)->test('pages::reviews.index')
        ->set('search', $search)
        ->assertSee('مشروع الأمل')
        ->assertDontSee('Water Access');
})->with([
    'plain alef' => ['الامل'],
    'tashkeel' => ['مَشْرُوع'],
    'tatweel' => ['مشـــروع'],
    'English layout' => ['lav,u'],
]);

test('a stale link with the old All value opens on Waiting on me', function () {
    [$employeeId, $user] = reviewerLogin();
    myReview($employeeId, 'Water Access', ProposalStatus::ConceptNoteReview);

    $page = Livewire::actingAs($user)->test('pages::reviews.index')->set('state', 'all');

    expect($page->instance()->mode)->toBe('awaiting');
    $page->assertSee('Water Access');
});

test('the buttons and search sit inside the page root', function () {
    [, $user] = reviewerLogin();

    $html = Livewire::actingAs($user)->test('pages::reviews.index')->html();
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($dom);

    expect($xpath->query('//*[@data-my-reviews]//*[@data-test="review-tab-awaiting"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-my-reviews]//*[@data-test="review-tab-done"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-my-reviews]//*[@data-test="review-tab-asked"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-my-reviews]//*[@data-test="review-search"]')->length)->toBe(1);
});

test('the page lists the oldest ask first with its wait, and turns red only after a week', function () {
    [$employeeId, $user] = reviewerLogin();
    $recent = myReview($employeeId, 'Recent Ask', ProposalStatus::ConceptNoteReview);
    $recent->forceFill(['created_at' => now()->subDays(2)])->save();
    $old = myReview($employeeId, 'Old Ask', ProposalStatus::ConceptNoteReview);
    $old->forceFill(['created_at' => now()->subDays(10)])->save();

    Livewire::actingAs($user)->test('pages::reviews.index')
        ->assertSeeInOrder(['Old Ask', 'Recent Ask'])
        ->assertSeeHtml('data-review-waiting="10"')
        ->assertSeeHtml('data-review-waiting="2"')
        ->assertSee(trans_choice('proposals.my_reviews_waiting_days', 10, ['count' => 10]))
        ->assertSee(ProposalStatus::ConceptNoteReview->label())
        ->assertSee(__('desk.review_action'));

    $html = Livewire::actingAs($user)->test('pages::reviews.index')->html();

    expect(substr_count($html, 'data-review-long'))->toBe(1);
});

test('asked by me lists other people who still owe a review on the viewer\'s projects, never the viewer\'s own row', function () {
    $ownerId = insertHrEmployee(firstNameEn: 'Omar', lastNameEn: 'Owner');
    $owner = userWithRole('Team Member', $ownerId);
    $project = Project::factory()->create(['user_id' => $ownerId, 'name' => 'Clean Water Homs']);
    $proposal = Proposal::factory()->create(['name' => 'Water Access', 'status' => ProposalStatus::ConceptNoteReview]);
    $proposal->projects()->attach($project->id);
    $late = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Late');
    $done = insertHrEmployee(firstNameEn: 'Sami', lastNameEn: 'Done');
    ProposalReviewer::factory()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::ConceptNote, 'employee_id' => $late]);
    ProposalReviewer::factory()->seen()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::ConceptNote, 'employee_id' => $done]);
    ProposalReviewer::factory()->create(['proposal_id' => $proposal->id, 'stage' => ReviewStage::ConceptNote, 'employee_id' => $ownerId]);

    $page = Livewire::actingAs($owner)->test('pages::reviews.index')->set('state', 'asked');

    expect($page->instance()->reviews)->toHaveCount(1);
    $page->assertSee('Water Access')
        ->assertSee('Clean Water Homs')
        ->assertSee('Rana Late')
        ->assertDontSee('Sami Done')
        ->assertDontSee('Omar Owner')
        ->assertSee(route('proposals.show', ['proposal' => $proposal, 'ptab' => 'status']).'#reviewers');
});

test('asked by me shows its own empty state', function () {
    [, $user] = reviewerLogin();

    Livewire::actingAs($user)->test('pages::reviews.index')
        ->set('state', 'asked')
        ->assertSee(__('proposals.my_reviews_none_asked'))
        ->assertSee(__('proposals.my_reviews_asked_empty_body'));
});

test('the page tour explains reviewing instead of the generic list steps', function () {
    [$employeeId, $user] = reviewerLogin();
    myReview($employeeId, 'Water Access', ProposalStatus::ConceptNoteReview);

    $this->actingAs($user)->get(route('reviews.index'))
        ->assertSee(__('tour_pages.pages.reviews_index.table.body'))
        ->assertSee(__('tour_pages.pages.reviews_index.filters.body'))
        ->assertDontSee(__('tour_pages.blocks.table.body'));
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

    expect($dashboard->get('decisions'))->toHaveCount(1)
        ->and($dashboard->get('decisions')->first())->toMatchArray([
            'key' => 'review-'.$waiting->id,
            'kind' => 'review',
            'title' => 'Water Access',
            'href' => route('proposals.show', ['proposal' => $waiting->proposal, 'ptab' => 'status']).'#my-review',
            'late' => false,
        ]);

    $dashboard
        ->assertSee('Water Access')
        ->assertSee(__('desk.decision_action.review'))
        ->assertDontSee('Already Read');
});

test('needs you holds no review for someone nothing waits on', function () {
    [$employeeId, $user] = reviewerLogin();
    myReview($employeeId, 'Already Read', ProposalStatus::ConceptNoteReview, seen: true);

    $dashboard = Livewire::actingAs($user)->test('desk.panels.needs', ['size' => 'L']);

    expect($dashboard->get('decisions'))->toHaveCount(0);
    $dashboard->assertDontSee('Already Read');
});

test('my reviews card marks a review asked over a week ago and links straight to it', function () {
    [$employeeId, $user] = reviewerLogin();
    $old = myReview($employeeId, 'Old Ask', ProposalStatus::ConceptNoteReview);
    $old->forceFill(['created_at' => now()->subDays(10)])->save();

    Livewire::actingAs($user)->test('desk.panels.reviews', ['size' => 'M'])
        ->assertSee(__('desk.review_waiting_long'))
        ->assertSee(__('desk.review_action'))
        ->assertSee(route('proposals.show', ['proposal' => $old->proposal, 'ptab' => 'status']).'#my-review', false);
});

test('a review on a project opens straight on the viewer\'s own review in that project\'s Story, never through a redirect', function () {
    [$employeeId, $user] = reviewerLogin();
    $waiting = myReview($employeeId, 'Water Access', ProposalStatus::ConceptNoteReview);
    $project = Project::factory()->create();
    $waiting->proposal->projects()->attach($project->id);
    // The proposal page, not the project Story (user decision 2026-11-19).
    $onReview = route('proposals.show', ['proposal' => $waiting->proposal, 'ptab' => 'status']).'#my-review';

    Livewire::actingAs($user)->test('pages::reviews.index')->assertSee($onReview);
    Livewire::actingAs($user)->test('desk.panels.reviews', ['size' => 'M'])->assertSee($onReview);
    expect(Livewire::actingAs($user)->test('desk.panels.needs', ['size' => 'L'])->get('decisions')->first()['href'])->toBe($onReview);
});

test('any reviewer goes from My reviews to their open review box in one click, and sees amounts only with money permission', function (string $role, bool $seesMoney) {
    $employeeId = insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Reviewer');
    $user = userWithRole($role, $employeeId);
    $waiting = myReview($employeeId, 'Water Access', ProposalStatus::ConceptNoteReview);
    $project = Project::factory()->create();
    $waiting->proposal->projects()->attach($project->id);
    DonorProposal::factory()->create(['proposal_id' => $waiting->proposal_id, 'budget' => 987654]);
    $story = route('proposals.show', ['proposal' => $waiting->proposal, 'ptab' => 'status']);

    Livewire::actingAs($user)->test('pages::reviews.index')->assertSee($story.'#my-review');

    $this->actingAs($user)->get($story)
        ->assertSeeHtml('id="my-review"')
        ->assertSeeHtml('data-mark-seen')
        ->assertSee(__('proposals.mark_as_seen'));

    // Money lives on the proposal's Funding tab, offered only with money permission.
    $this->actingAs($user)->get(route('proposals.show', ['proposal' => $waiting->proposal, 'ptab' => 'funding']))
        ->{$seesMoney ? 'assertSeeHtml' : 'assertDontSeeHtml'}('data-tab-panel="funding"');
})->with([
    'MEAL Manager' => ['MEAL', false],
    // Holds funding-agreements.view, so the proposal page's Funding tab is open to them (as it always was there).
    'fundraising (Public Relations Manager)' => ['Public Relations Manager', true],
    'finance' => ['Financial', true],
]);

test('a finished review on All opens the proposal, not the review box', function () {
    [$employeeId, $user] = reviewerLogin();
    $done = myReview($employeeId, 'Already Read', ProposalStatus::ConceptNoteReview, seen: true);
    $project = Project::factory()->create();
    $done->proposal->projects()->attach($project->id);

    Livewire::actingAs($user)->test('pages::reviews.index')
        ->set('state', 'done')
        ->assertSee(route('proposals.show', ['proposal' => $done->proposal, 'ptab' => 'status']).'#proposal')
        ->assertDontSee('#my-review');
});
