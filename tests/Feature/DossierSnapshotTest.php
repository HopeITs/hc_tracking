<?php

/**
 * TEMPORARY — not a real test. Renders the dossier with rich data and writes
 * the HTML to storage/app so it can be inspected in a browser without a login.
 * Delete once the design pass is done.
 */

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Report;
use App\Models\Result;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;

test('render the dossier to storage for visual inspection', function () {
    $user = User::factory()->create();

    $proposal = Proposal::factory()->create([
        'name' => 'Hope Center Emergency Response',
        'status' => ProposalStatus::Active,
        'planned_date_begin' => now()->subDays(140)->toDateString(),
        'planned_date_end' => now()->addDays(225)->toDateString(),
        'actual_date_begin' => now()->subDays(128)->toDateString(),
        'notes' => 'Co-funded with the 2026 resilience window. The second ECHO tranche is contingent on the interim narrative report clearing review.',
    ]);

    $echo = DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => Donor::factory()->create(['name' => 'ECHO'])->id,
        'budget' => '1102582.21',
        'currency' => Currency::Usd,
        'project_num' => 'PRJ-0001',
    ]);

    $account = Account::factory()->create(['account_name' => 'Main USD Account']);

    TransfersTracking::factory()->received($account)->create([
        'donor_proposal_id' => $echo->id,
        'payment_amount' => '551291.10',
        'amount_received' => '551291.10',
        'scheduled_date' => now()->subDays(120)->toDateString(),
    ]);
    TransfersTracking::factory()->received($account)->create([
        'donor_proposal_id' => $echo->id,
        'payment_amount' => '275645.55',
        'amount_received' => '275645.55',
        'scheduled_date' => now()->subDays(45)->toDateString(),
    ]);
    // Overdue: pending and already past its date.
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $echo->id,
        'payment_amount' => '175645.56',
        'scheduled_date' => now()->subDays(9)->toDateString(),
        'ar_status' => ArStatus::Pending,
    ]);
    TransfersTracking::factory()->create([
        'donor_proposal_id' => $echo->id,
        'payment_amount' => '100000.00',
        'scheduled_date' => now()->addDays(64)->toDateString(),
        'ar_status' => ArStatus::Pending,
    ]);

    DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => Donor::factory()->create(['name' => 'Dutch Relief Alliance'])->id,
        'budget' => '240000.00',
        'currency' => Currency::Eur,
        'project_num' => 'DRA-2026-114',
    ]);

    foreach (['Aleppo Water Points', 'Homs Shelter Repair'] as $name) {
        $proposal->projects()->attach(Project::factory()->create(['name' => $name]));
    }

    Task::factory()->completed()->create(['source_type' => 'proposal', 'source_id' => $proposal->id, 'title' => 'Signed agreement filed']);
    Task::factory()->completed()->create(['source_type' => 'proposal', 'source_id' => $proposal->id, 'title' => 'Baseline survey completed']);
    Task::factory()->create(['source_type' => 'proposal', 'source_id' => $proposal->id, 'title' => 'Interim narrative report', 'status' => TaskStatus::UnderReview, 'due_date' => now()->addDays(9)->toDateString()]);
    Task::factory()->create(['source_type' => 'proposal', 'source_id' => $proposal->id, 'title' => 'Procure water pumps', 'status' => TaskStatus::InProgress, 'due_date' => now()->addDays(21)->toDateString()]);
    Task::factory()->create(['source_type' => 'proposal', 'source_id' => $proposal->id, 'title' => 'Post-distribution monitoring', 'status' => TaskStatus::Todo, 'due_date' => now()->subDays(4)->toDateString()]);
    Task::factory()->create(['source_type' => 'proposal', 'source_id' => $proposal->id, 'title' => 'Verify beneficiary list', 'status' => TaskStatus::Todo, 'due_date' => now()->addDays(40)->toDateString()]);

    $result = Result::factory()->create(['statement' => 'Crisis-affected households in Aleppo and Homs meet their basic water and shelter needs.']);

    $households = PlanIndicator::factory()->forProposal($proposal)->create([
        'indicator_id' => Indicator::factory()->create(['name' => 'Households assisted with safe water', 'result_id' => $result->id])->id,
        'target' => '4500.00',
    ]);
    Report::factory()->approved()->create(['plan_indicator_id' => $households->id, 'value' => '2100.00']);
    Report::factory()->approved()->create(['plan_indicator_id' => $households->id, 'value' => '1250.00']);

    $shelters = PlanIndicator::factory()->forProposal($proposal)->create([
        'indicator_id' => Indicator::factory()->create(['name' => 'Shelters repaired to minimum standard', 'result_id' => $result->id])->id,
        'target' => '320.00',
    ]);
    Report::factory()->approved()->create(['plan_indicator_id' => $shelters->id, 'value' => '340.00']);

    foreach ([
        [ProposalStatus::ConceptNote, now()->subDays(210), 'Concept agreed with the country director.'],
        [ProposalStatus::ProposalDraft, now()->subDays(185), 'Full proposal drafted against the ECHO template.'],
        [ProposalStatus::Submitted, now()->subDays(170), 'Submitted through the donor portal.'],
        [ProposalStatus::Approved, now()->subDays(145), 'Approved at the reduced ceiling of 1.1M USD.'],
        [ProposalStatus::Active, now()->subDays(128), 'Implementation started once the first tranche cleared.'],
    ] as [$status, $date, $context]) {
        ProposalStatusNote::factory()->create([
            'proposal_id' => $proposal->id,
            'status' => $status,
            'date' => $date->toDateString(),
            'context' => $context,
        ]);
    }

    $html = $this->actingAs($user)
        ->get(route('proposals.dossier-preview', $proposal))
        ->assertOk()
        ->getContent();

    // Point relative asset URLs at the running dev server so the static copy is styled.
    $html = str_replace('="/build/', '="http://localhost:8000/build/', $html);
    $html = str_replace('="/fonts/', '="http://localhost:8000/fonts/', $html);
    $html = preg_replace('#(src|href)="http://localhost/#', '$1="http://localhost:8000/', $html);

    file_put_contents(storage_path('app/dossier-preview.html'), $html);

    expect(file_exists(storage_path('app/dossier-preview.html')))->toBeTrue();
});
