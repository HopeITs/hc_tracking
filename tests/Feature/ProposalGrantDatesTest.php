<?php

use App\Actions\Proposals\SetGrantDates;
use App\Enums\ProposalStatus;
use App\Models\Activity;
use App\Models\Proposal;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| Page review follow-up, 6 Nov 2026: older grants were approved without their
| actual dates and no page could add them, so the donor report builder
| refused every period. "Set grant dates" fills in only what is missing.
*/

beforeEach(function () {
    $this->travelTo('2026-11-06 10:00:00');
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->editor = User::factory()->role('Public Relations Manager')->create();
    $this->proposal = Proposal::factory()->create([
        'status' => ProposalStatus::Approved,
        'planned_date_begin' => '2025-11-01',
        'planned_date_end' => '2026-12-31',
        'actual_date_begin' => null,
        'actual_date_end' => null,
    ]);
});

test('an editor sets the missing grant dates, starting from the planned ones, and it is logged', function () {
    Livewire::actingAs($this->editor)->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->assertSeeHtml('data-test="open-grant-dates-modal"')
        ->call('openGrantDatesModal')
        ->assertSet('grantDateBegin', '2025-11-01')
        ->assertSet('grantDateEnd', '2026-12-31')
        ->set('grantDateEnd', '2026-10-31')
        ->call('saveGrantDates')
        ->assertHasNoErrors()
        ->assertSet('showGrantDatesModal', false);

    $proposal = $this->proposal->fresh();

    expect($proposal->actual_date_begin->toDateString())->toBe('2025-11-01')
        ->and($proposal->actual_date_end->toDateString())->toBe('2026-10-31')
        ->and($proposal->lacksGrantDates())->toBeFalse()
        ->and(Activity::query()->where('subject_type', 'proposal')->where('subject_id', $proposal->id)->where('event', 'proposal.grant_dates_set')->exists())->toBeTrue();
});

test('an end before the start is refused', function () {
    Livewire::actingAs($this->editor)->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->call('openGrantDatesModal')
        ->set('grantDateBegin', '2026-05-01')
        ->set('grantDateEnd', '2026-04-30')
        ->call('saveGrantDates')
        ->assertHasErrors(['grantDateEnd' => __('proposals.grant_end_before_start')]);

    expect($this->proposal->fresh()->actual_date_end)->toBeNull();
});

test('a grant that already has its dates is neither offered nor changed', function () {
    $this->proposal->forceFill(['actual_date_begin' => '2025-11-01', 'actual_date_end' => '2026-12-31'])->saveQuietly();

    Livewire::actingAs($this->editor)->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->assertDontSeeHtml('data-test="open-grant-dates-modal"');

    expect(fn () => app(SetGrantDates::class)->handle($this->proposal->fresh(), ['begin' => '2025-01-01', 'end' => '2025-12-31'], $this->editor))
        ->toThrow(ValidationException::class, __('proposals.grant_dates_not_missing'));
});

test('someone who may not edit the proposal cannot set its dates', function () {
    $member = User::factory()->role('Team Member')->create();

    Livewire::actingAs($member)->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->call('openGrantDatesModal')
        ->assertForbidden();
});

test('?grant_dates=1 opens the dialog only for someone who may set the dates', function () {
    Livewire::withQueryParams(['grant_dates' => '1'])->actingAs($this->editor)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->assertSet('showGrantDatesModal', true)
        ->assertSet('grantDatesLink', false);

    Livewire::withQueryParams(['grant_dates' => '1'])->actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->assertSet('showGrantDatesModal', false);
});
