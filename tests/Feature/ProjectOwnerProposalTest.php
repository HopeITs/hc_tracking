<?php

use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\UserNotification;
use App\Support\ProposalOwnerNotifier;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

beforeEach(function () {
    $this->ownerEmployee = insertHrEmployee(firstNameEn: 'Karam', lastNameEn: 'Nassar');
    $this->owner = userWithRole('Team Member', $this->ownerEmployee);
    $this->proposal = Activity::withoutRecording(fn () => Proposal::factory()->create(['status' => ProposalStatus::ProposalDraft->value]));
    $this->project = Activity::withoutRecording(fn () => Project::factory()->create(['user_id' => $this->ownerEmployee]));
    DB::table('project_proposal')->insert(['project_id' => $this->project->id, 'proposal_id' => $this->proposal->id]);
});

test('the owner of a project the proposal funds can work on it and move it on', function () {
    expect($this->owner->fresh()->can('update', $this->proposal))->toBeTrue()
        ->and($this->owner->fresh()->can('transition', [$this->proposal, ProposalStatus::ProposalReview]))->toBeTrue();
});

test('the project owner opens the proposal from Needs you without a 403', function () {
    $this->actingAs($this->owner->fresh())
        ->followingRedirects()
        ->get(route('proposals.show', $this->proposal))
        ->assertOk()
        ->assertSee($this->proposal->name);
});

test('a redirect inside a desk tab keeps the tab marker, so the page never draws the whole app inside the tab', function () {
    $response = $this->actingAs($this->owner->fresh())->get(route('proposals.show', [$this->proposal, '_tab' => 1]));

    $response->assertRedirect();
    expect($response->headers->get('Location'))->toContain('_tab=1');

    $outside = $this->actingAs($this->owner->fresh())->get(route('proposals.show', $this->proposal));
    expect($outside->headers->get('Location'))->not->toContain('_tab=1');
});

test('someone who owns none of its projects cannot, and recording the donor decision stays with proposals.approve', function () {
    $other = userWithRole('Team Member', insertHrEmployee(firstNameEn: 'Other'));

    expect($other->can('update', $this->proposal))->toBeFalse()
        ->and($this->owner->fresh()->can('approve', $this->proposal))->toBeFalse();
});

test('the proposal waiting on the project owner shows in their Needs you panel', function () {
    Livewire::actingAs($this->owner->fresh())->test('desk.panels.needs')
        ->assertSee($this->proposal->name)
        ->assertSee(__('desk.decision.proposal.finish_proposal'));

    $other = userWithRole('Team Member', insertHrEmployee(firstNameEn: 'Other'));
    Livewire::actingAs($other)->test('desk.panels.needs')->assertDontSee($this->proposal->name);
});

test('the project owner is told the proposal waits on them, once per step', function () {
    Cache::flush();

    expect(ProposalOwnerNotifier::notifyAllWaiting())->toBe(1)
        ->and(ProposalOwnerNotifier::notifyAllWaiting())->toBe(0);

    $rows = UserNotification::query()->forUser($this->owner)->get()
        ->filter(fn (UserNotification $row): bool => $row->data['key'] === 'notifications.events.proposal.waiting_on_you.finish_proposal');

    expect($rows)->toHaveCount(1);

    $this->proposal->forceFill(['status' => ProposalStatus::ProposalReview->value])->save();

    expect(UserNotification::query()->forUser($this->owner)->get()->contains(fn (UserNotification $row): bool => $row->data['key'] === 'notifications.events.proposal.waiting_on_you.send_proposal'))->toBeTrue();
});

test('the project owner hears when the proposal moves on', function () {
    $actor = userWithRole('Public Relations', insertHrEmployee(firstNameEn: 'Writer'));
    $this->actingAs($actor);

    $this->proposal->forceFill(['status' => ProposalStatus::ProposalReview->value])->save();

    $rows = UserNotification::query()->forUser($this->owner)->get()
        ->filter(fn (UserNotification $row): bool => $row->data['key'] === 'notifications.events.proposal.status_changed');

    expect($rows)->toHaveCount(1);
});
