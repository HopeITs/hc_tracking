<?php

use App\Enums\ArStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Milestone;
use App\Models\Risk;
use App\Models\TransfersTracking;
use App\Models\User;
use Livewire\Livewire;

beforeEach(function () {
    $this->travelTo('2026-09-15 09:00:00');
});

test('every built panel renders for a super admin with data', function (string $panel) {
    $agreement = DonorProposal::factory()->create();
    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'ar_status' => ArStatus::Pending, 'scheduled_date' => '2026-09-01']);
    Risk::factory()->create();
    Milestone::factory()->create(['planned_end_date' => '2026-09-20']);
    Donor::factory()->create();

    Livewire::actingAs(User::factory()->role('Super Admin')->create())
        ->test("desk.panels.{$panel}", ['size' => 'L'])
        ->assertOk()
        ->assertSeeHtml('data-panel="'.$panel.'"');
})->with(['glance', 'needs', 'waiting', 'coming', 'projects', 'pipeline', 'money', 'transfers', 'matrix', 'validate', 'templates', 'reviews', 'tasks', 'deliverables', 'myteam', 'risks', 'targets', 'duties', 'activity', 'inbox', 'milestones', 'plans', 'donors']);

test('the desk page renders every panel of the role layout', function (string $role) {
    $this->actingAs(User::factory()->role($role)->create())
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('data-panel="needs"', false);
})->with(['Super Admin', 'General Manager', 'MEAL', 'Financial', 'Team Member']);
