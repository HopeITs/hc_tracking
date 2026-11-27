<?php

use App\Enums\Currency;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\DonorProposal;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\User;
use Livewire\Livewire;

/*
| Y-17: the agreement's Gantt (funding-agreements/show) lists the delivery
| its grant funds. That delivery is the proposal's own location rows (D-93) —
| it used to read the `project_proposal` pivot, which shows nothing at all
| for a proposal whose places have no project, and that is every proposal
| created from Y-17 on.
*/

beforeEach(fn () => $this->travelTo('2026-10-14 10:00:00'));

/**
 * An agreement on an active proposal with the given number of places.
 *
 * @return array{agreement: DonorProposal, proposal: Proposal, rows: list<ProposalLocation>}
 */
function agreementWithPlaces(int $places = 2): array
{
    $proposal = Proposal::factory()->create([
        'status' => ProposalStatus::Active,
        'planned_date_begin' => '2026-11-01',
        'planned_date_end' => '2027-10-31',
        'actual_date_begin' => '2026-11-01',
        'actual_date_end' => '2027-10-31',
    ]);

    $rows = [];

    for ($index = 0; $index < $places; $index++) {
        $cityId = insertHrCity('Place '.Str::random(5), 'مكان');
        $employeeId = insertHrEmployee($cityId);
        $row = ProposalLocation::factory()->active()->create([
            'proposal_id' => $proposal->id,
            'city_id' => $cityId,
            'program_id' => Program::factory()->create(['manager_id' => $employeeId])->id,
            'responsible_employee_id' => $employeeId,
            'start_date' => '2026-11-01',
            'end_date' => '2027-10-31',
            'sort' => $index,
        ]);
        $row->centers()->create(['center_id' => insertHrCenter($cityId, 'C'.Str::random(6))]);
        $rows[] = $row->fresh();
    }

    $agreement = DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => $proposal->donor_id,
        'budget' => '30000.00',
        'currency' => Currency::Usd,
    ]);

    return ['agreement' => $agreement, 'proposal' => $proposal, 'rows' => $rows];
}

/**
 * The Gantt rows of the agreement page, as the chart receives them.
 *
 * @return list<array<string, mixed>>
 */
function agreementGanttRows(DonorProposal $agreement): array
{
    $payload = Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->instance()
        ->ganttPayload;

    return array_values($payload['rows'] ?? []);
}

test('the agreement Gantt lists one delivery row per place, with no project anywhere', function () {
    ['agreement' => $agreement, 'rows' => $rows] = agreementWithPlaces(2);

    $gantt = collect(agreementGanttRows($agreement));
    $delivery = $gantt->where('kind', 'project')->values();

    expect(Project::query()->count())->toBe(0)
        ->and($gantt->firstWhere('id', 'delivery'))->not->toBeNull()
        ->and($delivery)->toHaveCount(2)
        ->and($delivery->pluck('id')->all())->toBe(['project-'.$rows[0]->id, 'project-'.$rows[1]->id])
        // A Gantt row is named by its proposal (`headline()`, Y-16's rule for
        // the shared branch), and every place of one agreement shares it.
        ->and($delivery->pluck('name')->unique()->all())->toBe([$rows[0]->headline()]);
});

test('a place carries its own period and status onto the chart', function () {
    ['agreement' => $agreement, 'rows' => $rows] = agreementWithPlaces(1);

    $delivery = collect(agreementGanttRows($agreement))->firstWhere('kind', 'project');

    expect($delivery['status'])->toBe(ProjectStatus::Active->value)
        ->and($delivery['start'])->not->toBeNull()
        ->and($delivery['end'])->not->toBeNull()
        ->and($delivery['name'])->toBe($rows[0]->headline());
});

test('an agreement whose proposal has no place shows the grant alone, and no delivery group', function () {
    $proposal = Proposal::factory()->create([
        'status' => ProposalStatus::Active,
        'planned_date_begin' => '2026-11-01',
        'planned_date_end' => '2027-10-31',
    ]);
    $agreement = DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => $proposal->donor_id,
        'budget' => '30000.00',
        'currency' => Currency::Usd,
    ]);

    $gantt = collect(agreementGanttRows($agreement));

    expect($gantt->where('kind', 'project'))->toHaveCount(0)
        ->and($gantt->firstWhere('id', 'delivery'))->toBeNull()
        ->and($gantt->firstWhere('id', 'proposal-'.$proposal->id))->not->toBeNull();
});

test('the places of another proposal never reach this agreement chart', function () {
    ['agreement' => $agreement, 'rows' => $rows] = agreementWithPlaces(1);
    $other = agreementWithPlaces(1);

    $delivery = collect(agreementGanttRows($agreement))->where('kind', 'project')->values();

    expect($delivery)->toHaveCount(1)
        ->and($delivery->first()['id'])->toBe('project-'.$rows[0]->id)
        ->and($delivery->pluck('id')->all())->not->toContain('project-'.$other['rows'][0]->id);
});

test('a viewer without money access still sees the delivery but no amount', function () {
    ['agreement' => $agreement] = agreementWithPlaces(1);

    $payload = Livewire::actingAs(User::factory()->role('Managers')->create())
        ->test('pages::funding-agreements.show', ['donorProposal' => $agreement])
        ->instance()
        ->ganttPayload;

    expect(json_encode($payload))->not->toContain('30000');
});
