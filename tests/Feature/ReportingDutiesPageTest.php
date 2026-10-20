<?php

use App\Models\Activity;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/*
| My Reporting Duties (contracts §14.3): the donor reports a person is
| responsible for, with no money at all; donor and proposal names are plain
| text and nothing links to a page the person cannot open.
*/

beforeEach(function () {
    $this->travelTo('2026-11-15 10:00:00');
    $this->employeeId = insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Owner');
    $this->responsible = userWithRole('Team Member', $this->employeeId);

    [$this->agreement, $this->mine, $this->theirs] = Activity::withoutRecording(function (): array {
        $donor = Donor::factory()->create(['name' => 'Global Fund for Children']);
        $proposal = Proposal::factory()->running()->create(['donor_id' => $donor->id, 'name' => 'Safe Schools']);
        $agreement = DonorProposal::factory()->create([
            'proposal_id' => $proposal->id, 'donor_id' => $donor->id, 'budget' => '987654.32', 'external_project_number' => 'GF-991',
        ]);

        return [
            $agreement,
            DonorReport::factory()->composed()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Q1 narrative', 'due_on' => '2026-11-20', 'responsible_employee_id' => $this->employeeId]),
            DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Someone else report', 'due_on' => '2026-11-20', 'responsible_employee_id' => insertHrEmployee()]),
        ];
    });
});

test('the page lists only my reports, open ones by default', function () {
    $late = Activity::withoutRecording(fn () => DonorReport::factory()->create(['donor_proposal_id' => $this->agreement->id, 'title' => 'Late financial', 'type' => 'financial', 'due_on' => '2026-11-01', 'responsible_employee_id' => $this->employeeId]));
    $sent = Activity::withoutRecording(fn () => DonorReport::factory()->submitted()->create(['donor_proposal_id' => $this->agreement->id, 'title' => 'Sent last year', 'due_on' => '2025-12-01', 'responsible_employee_id' => $this->employeeId]));

    $page = Livewire::actingAs($this->responsible)->test('pages::reporting-duties.index')
        ->assertSeeText('Q1 narrative')
        ->assertSeeText('Late financial')
        ->assertSeeHtml('data-duty-overdue')
        ->assertSeeHtml('data-duty-due')
        ->assertDontSeeText('Someone else report')
        ->assertDontSeeText('Sent last year');

    $page->set('state', 'all')->assertSeeText('Sent last year')->assertDontSeeText('Someone else report');

    expect($late->id)->toBeInt()->and($sent->id)->toBeInt();
});

test('without funding-agreements.view there is no amount, currency or link to a money page', function () {
    expect($this->responsible->can('funding-agreements.view'))->toBeFalse();

    $this->actingAs($this->responsible)->get(route('reporting-duties.index'))
        ->assertOk()
        ->assertSee('Global Fund for Children')
        ->assertSee('Safe Schools')
        ->assertDontSee('987,654')
        ->assertDontSee('987654')
        ->assertDontSee('GF-991')
        ->assertDontSee($this->agreement->fresh()->currency->value)
        ->assertDontSee(route('funding-agreements.show', $this->agreement), false)
        ->assertDontSee(route('proposals.show', $this->agreement->proposal), false)
        ->assertDontSee(route('donors.show', $this->agreement->donor), false)
        ->assertSee(route('donor-reports.show', $this->mine), false);

    $this->actingAs($this->responsible)->get(route('funding-agreements.show', $this->agreement))->assertForbidden();
});

test('the responsible person submits from the page', function () {
    // A Livewire test upload fails its file rules under travelTo(), so this one runs on the real clock.
    $this->travelBack();
    Storage::fake('local');

    Livewire::actingAs($this->responsible)->test('pages::reporting-duties.index')
        ->call('openSubmit', $this->mine->id)
        ->assertSet('showSubmitModal', true)
        ->set('submissionFile', validUpload('q1.pdf'))
        ->call('submitReport')
        ->assertHasNoErrors()
        ->assertDontSeeText('Q1 narrative');

    expect($this->mine->fresh()->isSubmitted())->toBeTrue();
});

test('a report of someone else cannot be reached from the page', function () {
    Livewire::actingAs($this->responsible)->test('pages::reporting-duties.index')
        ->call('openSubmit', $this->theirs->id)
        ->assertNotFound();

    expect($this->theirs->fresh()->submitted_on)->toBeNull();
});

test('a user without an employee record has no duties', function () {
    $noEmployee = Activity::withoutRecording(fn (): User => User::factory()->create(['employee_id' => null]));

    $this->actingAs($noEmployee)->get(route('reporting-duties.index'))
        ->assertOk()
        ->assertSee(__('reporting_duties.none_open'))
        ->assertDontSee('Q1 narrative');
});

test('the page renders in Arabic from the lang files', function () {
    $this->responsible->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($this->responsible)->get(route('reporting-duties.index'))
        ->assertOk()
        ->assertSee('dir="rtl"', false)
        ->assertSee(__('titles.reporting_duties', [], 'ar'))
        ->assertSee(__('reporting_duties.state_open', [], 'ar'))
        ->assertSee(__('donor_reports.mark_submitted', [], 'ar'));
});

test('the board splits my duties into late, to submit and submitted, and the calendar shows due dates', function () {
    $late = Activity::withoutRecording(fn () => DonorReport::factory()->create(['donor_proposal_id' => $this->agreement->id, 'title' => 'Late financial', 'type' => 'financial', 'due_on' => '2026-11-01', 'responsible_employee_id' => $this->employeeId]));
    $sent = Activity::withoutRecording(fn () => DonorReport::factory()->submitted()->create(['donor_proposal_id' => $this->agreement->id, 'title' => 'Sent report', 'due_on' => '2026-11-10', 'responsible_employee_id' => $this->employeeId]));

    $page = Livewire::actingAs($this->responsible)->test('pages::reporting-duties.index')
        ->set('state', 'all')
        ->set('view', 'board')
        ->assertSeeHtml('data-test="duty-board-card-'.$this->mine->id.'"')
        ->assertDontSee('Someone else report');

    $columns = collect($page->instance()->boardColumns)->keyBy('key');
    expect($columns['late']['items']->pluck('id')->all())->toBe([$late->id])
        ->and($columns['open']['items']->pluck('id')->all())->toBe([$this->mine->id])
        ->and($columns['submitted']['items']->pluck('id')->all())->toBe([$sent->id]);

    $page->set('view', 'calendar')
        ->assertSeeHtml('data-desk-calendar="2026-11"')
        ->assertSeeHtml('href="'.route('donor-reports.show', $this->mine).'"');
});
