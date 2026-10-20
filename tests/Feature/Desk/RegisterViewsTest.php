<?php

use App\Enums\ArStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReportStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Risk;
use App\Models\TransfersTracking;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-10-15 09:00:00');
});

test('the proposals board puts each proposal under its status, money only for agreement viewers', function () {
    $donor = Donor::factory()->create(['name' => 'Board Donor']);
    $proposal = Proposal::factory()->for($donor)->create([
        'name' => 'Board Proposal',
        'status' => ProposalStatus::ProposalDraft,
        'requested_budget' => 12500,
        'requested_currency' => 'EUR',
    ]);

    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::proposals.index')
        ->set('view', 'board')
        ->assertSet('view', 'board')
        ->assertSeeHtml('data-desk-board-column="'.ProposalStatus::ProposalDraft->value.'"')
        ->assertSeeHtml('data-test="proposal-board-card-'.$proposal->id.'"')
        ->assertSee('Board Donor')
        ->assertSee('12,500');

    Livewire::actingAs(userWithRole('Team Member')->givePermissionTo('proposals.view'))
        ->test('pages::proposals.index')
        ->set('view', 'board')
        ->assertSee('Board Proposal')
        ->assertDontSee('Board Donor')
        ->assertDontSee('12,500');
});

test('the proposals calendar shows follow-up dates and planned starts in the chosen month', function () {
    Proposal::factory()->create(['name' => 'Follow Me', 'status_remind_on' => '2026-10-20', 'planned_date_begin' => '2026-01-01']);
    Proposal::factory()->create(['name' => 'Starts Soon', 'planned_date_begin' => '2026-11-03']);

    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::proposals.index')
        ->set('view', 'calendar')
        ->assertSeeHtml('data-desk-calendar="2026-10"')
        ->assertSee('Follow Me')
        ->assertDontSee('Starts Soon')
        ->set('calendarMonth', '2026-11')
        ->assertSee('Starts Soon')
        ->set('calendarMonth', 'nonsense')
        ->assertSeeHtml('data-desk-calendar="2026-10"');
});

test('an unknown view in the URL falls back to the table', function () {
    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->withQueryParams(['view' => 'gallery'])
        ->test('pages::proposals.index')
        ->assertSet('view', 'table');
});

test('the transfers board sorts tranches by state and opens the form for a writer', function () {
    $agreement = DonorProposal::factory()->create();
    $late = TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'ar_status' => ArStatus::Pending, 'scheduled_date' => '2026-10-01', 'transfer_date' => null]);
    $received = TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'ar_status' => ArStatus::Received, 'scheduled_date' => '2026-09-01', 'receive_date' => '2026-10-08']);

    $page = Livewire::actingAs(User::factory()->role('Financial')->create())
        ->test('pages::transfers.index')
        ->set('view', 'board')
        ->assertSet('view', 'board')
        ->assertSeeHtml('wire:click="openTransferEdit('.$late->id.')"');

    $columns = collect($page->instance()->boardColumns)->keyBy('key');
    expect($columns['late']['items']->pluck('id')->all())->toBe([$late->id])
        ->and($columns['received']['items']->pluck('id')->all())->toBe([$received->id]);

    $page->set('view', 'calendar')
        ->assertSeeHtml('data-desk-calendar-day="2026-10-08"');
    expect(collect($page->instance()->calendarItems)->pluck('date')->sort()->values()->all())->toBe(['2026-10-01', '2026-10-08']);
});

test('the reports board and calendar list reports by status and planned date', function () {
    $draft = Report::factory()->create(['status' => ReportStatus::Draft, 'planned_date' => '2026-10-09']);
    $approved = Report::factory()->create(['status' => ReportStatus::Approved, 'planned_date' => '2026-08-01']);

    $page = Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test('pages::reports.index')
        ->set('view', 'board')
        ->assertSeeHtml('data-test="report-board-card-'.$draft->id.'"')
        ->assertSeeHtml('href="'.route('reports.show', $draft).'"');

    $columns = collect($page->instance()->boardColumns)->keyBy('key');
    expect($columns['draft']['items']->pluck('id')->all())->toBe([$draft->id])
        ->and($columns['approved']['items']->pluck('id')->all())->toBe([$approved->id]);

    $page->set('view', 'calendar')
        ->assertSeeHtml('data-desk-calendar="2026-10"');
    expect(collect($page->instance()->calendarItems)->pluck('date')->all())->toBe(['2026-10-09']);
});

test('the risks board separates potential risks from the ones that happened', function () {
    $potential = Risk::factory()->create(['name' => 'Road closure', 'happened' => false]);
    $happened = Risk::factory()->create(['name' => 'Flooded depot', 'happened' => true]);

    $page = Livewire::actingAs(User::factory()->role('Managers')->create())
        ->test('pages::risks.index')
        ->set('view', 'board')
        ->assertSeeHtml('wire:click="openEditModal('.$potential->id.')"');

    $columns = collect($page->instance()->boardColumns)->keyBy('key');
    expect($columns['potential']['items']->pluck('id')->all())->toBe([$potential->id])
        ->and($columns['happened']['items']->pluck('id')->all())->toBe([$happened->id]);

    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::risks.index')
        ->set('view', 'board')
        ->assertSee('Road closure')
        ->assertDontSeeHtml('wire:click="openEditModal('.$potential->id.')"');
});
