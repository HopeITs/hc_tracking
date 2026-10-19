<?php

use App\Enums\MilestoneStatus;
use App\Enums\ResultType;
use App\Models\ActivitySchedule;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Result;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->viewer = User::factory()->role('Public Relations Manager')->create();

    $this->program = Program::factory()->create();
    $this->proposal = Proposal::factory()->create(['name' => 'Water Access Proposal', 'requested_budget' => 987654.32]);

    $cityId = insertHrCity('Idlib', 'إدلب');
    $this->centerA = Project::factory()->create([
        'program_id' => $this->program->id,
        'center_id' => insertHrCenter($cityId, 'Hope Center Sarmada', 'مركز الأمل سرمدا'),
        'city_id' => $cityId,
    ]);
    $this->centerB = Project::factory()->create(['program_id' => $this->program->id, 'name' => 'Unmapped Water Project']);
    DB::table('project_proposal')->insert([
        ['project_id' => $this->centerA->id, 'proposal_id' => $this->proposal->id, 'target_beneficiaries' => 180],
        ['project_id' => $this->centerB->id, 'proposal_id' => $this->proposal->id, 'target_beneficiaries' => 90],
    ]);
});

function chainNode(?Proposal $proposal, ResultType $type, ?Result $parent = null, ?int $programId = null, ?string $statement = null): Result
{
    return Result::factory()->type($type)->create([
        'proposal_id' => $proposal?->id,
        'program_id' => $parent?->program_id ?? $programId,
        'parent_id' => $parent?->id,
        'statement' => $statement ?? fake()->unique()->sentence(),
    ]);
}

function chainIndicator(Result $result, ?string $name = null): Indicator
{
    $name ??= fake()->unique()->words(3, true);
    $entry = LibraryIndicator::factory()->create(['program_id' => null, 'name_ar' => $name, 'name_en' => $name]);

    return Indicator::factory()->create([
        'result_id' => $result->id,
        'library_indicator_id' => $entry->id,
        'code' => $entry->code,
        'name' => $entry->name_ar,
        'measure' => 'numeric',
    ]);
}

function chainTarget(Indicator $indicator, $source, ?Project $project, float $target): PlanIndicator
{
    return PlanIndicator::factory()->create([
        'source_type' => $source instanceof AnnualPlan ? 'annual_plan' : 'proposal',
        'source_id' => $source->id,
        'indicator_id' => $indicator->id,
        'project_id' => $project?->id,
        'target' => $target,
    ]);
}

function chainMilestone(Project $project, ?Result $activity, string $start = '2026-03-01', ?Proposal $proposal = null): Milestone
{
    $milestone = Milestone::factory()->create([
        'project_id' => $project->id,
        'proposal_id' => $proposal?->id,
        'planned_start_date' => $start,
        'planned_end_date' => $start,
        'status' => MilestoneStatus::Planned,
    ]);
    $milestone->forceFill(['result_id' => $activity?->id])->saveQuietly();

    return $milestone;
}

function chainTab(User $user, Proposal $proposal): Testable
{
    return Livewire::actingAs($user)->test('proposals.logframe', ['proposalId' => $proposal->id])->set('view', 'chain');
}

/**
 * Impact → outcome → output → activity of the proposal, an indicator with
 * one target per center, and a cross-cutting root.
 *
 * @return array<string, mixed>
 */
function chainProposalFramework(Proposal $proposal, Project $centerA, Project $centerB): array
{
    $impact = chainNode($proposal, ResultType::Impact, statement: 'Families live in safety.');
    $outcome = chainNode($proposal, ResultType::Outcome, $impact, statement: 'Water is reliably available.');
    $output = chainNode($proposal, ResultType::Output, $outcome, statement: 'Wells are rehabilitated.');
    $activity = chainNode($proposal, ResultType::Activity, $output, statement: 'Rehabilitate the village wells.');
    $crossCutting = chainNode($proposal, ResultType::CrossCutting, statement: 'Gender is mainstreamed.');

    $indicator = chainIndicator($output, 'Wells rehabilitated');
    $targetA = chainTarget($indicator, $proposal, $centerA, 100);
    $targetB = chainTarget($indicator, $proposal, $centerB, 50);

    return compact('impact', 'outcome', 'output', 'activity', 'crossCutting', 'indicator', 'targetA', 'targetB');
}

test('the chain view renders every level, each indicator with its code and the per-center targets', function () {
    $chain = chainProposalFramework($this->proposal, $this->centerA, $this->centerB);

    chainTab($this->viewer, $this->proposal)
        ->assertSeeHtml('data-chain-view')
        ->assertSeeInOrder([
            'Families live in safety.',
            'Water is reliably available.',
            'Wells are rehabilitated.',
            'Rehabilitate the village wells.',
            __('results_framework.cross_cutting_group'),
            'Gender is mainstreamed.',
        ])
        ->assertSeeHtml('data-result-type="activity"')
        ->assertSeeHtml('data-tree-depth="3"')
        ->assertSee('Wells rehabilitated')
        ->assertSee($chain['indicator']->code)
        ->assertSee('Hope Center Sarmada')
        ->assertSee('Unmapped Water Project');
});

test('achievement counts approved reports only, per target', function () {
    $chain = chainProposalFramework($this->proposal, $this->centerA, $this->centerB);
    Report::factory()->approved()->create(['plan_indicator_id' => $chain['targetA']->id, 'value' => 40]);
    Report::factory()->create(['plan_indicator_id' => $chain['targetA']->id, 'value' => 50]);

    chainTab($this->viewer, $this->proposal)
        ->assertSeeHtml('data-chain-target="'.$chain['targetA']->id.'" data-percent="40"')
        ->assertSeeHtml('data-chain-target="'.$chain['targetB']->id.'" data-percent="0"')
        ->assertDontSeeHtml('data-percent="90"')
        ->assertDontSeeHtml('data-percent="60"');
});

test('the four gap markers flag what is missing in the chain', function () {
    $chain = chainProposalFramework($this->proposal, $this->centerA, $this->centerB);
    $bareIndicator = chainIndicator($chain['outcome'], 'Households served');
    chainMilestone($this->centerA, null, proposal: $this->proposal)->forceFill(['name' => 'Handover ceremony'])->saveQuietly();

    $html = chainTab($this->viewer, $this->proposal)->html();

    expect($html)
        ->toContain('data-chain-gap="no_indicators"')
        ->toContain('data-chain-gap="no_target"')
        ->toContain('data-chain-gap="no_milestone"')
        ->toContain('data-chain-gap="unlinked_milestone"')
        ->toContain(__('results_framework.chain_gap_no_indicators'))
        ->toContain(__('results_framework.chain_gap_no_target'))
        ->toContain(__('results_framework.chain_gap_no_milestone'))
        ->toContain('Handover ceremony')
        ->and(substr_count($html, 'data-chain-gap="no_target"'))->toBe(1);

    chainMilestone($this->centerA, $chain['activity']);

    expect(chainTab($this->viewer, $this->proposal)->html())->not->toContain('data-chain-gap="no_milestone"');
});

test('an indicator filter narrows the chain and promotes a node with its hidden lineage', function () {
    $chain = chainProposalFramework($this->proposal, $this->centerA, $this->centerB);
    $other = chainIndicator($chain['impact'], 'Lives protected');

    chainTab($this->viewer, $this->proposal)
        ->set('chainIndicator', (string) $chain['indicator']->library_indicator_id)
        ->assertSee('Wells are rehabilitated.')
        ->assertSeeHtml('data-chain-lineage')
        ->assertSeeInOrder(['Families live in safety.', 'Water is reliably available.', 'Wells are rehabilitated.'])
        ->assertDontSeeHtml('data-chain-indicator="'.$other->id.'"')
        ->assertDontSee('Rehabilitate the village wells.')
        ->assertSeeHtml('data-tree-depth="0" data-result-type="output"');
});

test('a center filter keeps only that center\'s targets', function () {
    $chain = chainProposalFramework($this->proposal, $this->centerA, $this->centerB);

    chainTab($this->viewer, $this->proposal)
        ->set('chainCenter', (string) $this->centerB->id)
        ->assertSeeHtml('data-chain-target="'.$chain['targetB']->id.'"')
        ->assertDontSeeHtml('data-chain-target="'.$chain['targetA']->id.'"');
});

test('an unknown filter value falls back to all', function (string $property) {
    chainProposalFramework($this->proposal, $this->centerA, $this->centerB);

    chainTab($this->viewer, $this->proposal)
        ->set($property, '999999')
        ->assertSee('Families live in safety.')
        ->assertSee('Rehabilitate the village wells.')
        ->assertSee('Gender is mainstreamed.');
})->with(['chainIndicator', 'chainCenter', 'chainDonor']);

test('the chain reads a flat number of queries for 3 or 30 nodes', function (string $page) {
    $plan = AnnualPlan::factory()->create(['program_id' => $this->program->id, 'year' => 2026]);
    $programImpact = chainNode(null, ResultType::Impact, programId: $this->program->id);
    $proposalImpact = chainNode($this->proposal, ResultType::Impact, programId: $this->program->id);
    $proposalImpact->forceFill(['contributes_to_result_id' => $programImpact->id])->saveQuietly();

    $seed = function (int $branches) use ($plan, $programImpact, $proposalImpact): void {
        foreach (range(1, $branches) as $index) {
            foreach ([[$this->proposal, $proposalImpact, $this->proposal], [null, $programImpact, $plan]] as [$proposal, $root, $source]) {
                $outcome = chainNode($proposal, ResultType::Outcome, $root);
                $activity = chainNode($proposal, ResultType::Activity, chainNode($proposal, ResultType::Output, $outcome));
                $target = chainTarget(chainIndicator($outcome), $source, $this->centerA, 10);
                Report::factory()->approved()->create(['plan_indicator_id' => $target->id, 'value' => 4]);
                chainMilestone($this->centerA, $activity);
                ActivitySchedule::factory()->create(['result_id' => $activity->id, 'month' => '2026-03-01']);
            }
        }
    };

    $render = fn (): Testable => match ($page) {
        'proposal' => chainTab($this->viewer, $this->proposal),
        'program' => Livewire::actingAs($this->viewer)->test('pages::programs.show', ['program' => $this->program])->set('tab', 'results'),
        'annual plan' => Livewire::actingAs($this->viewer)->test('pages::annual-plans.show', ['annualPlan' => $plan]),
    };

    $countQueries = function () use ($render): array {
        $render();
        DB::flushQueryLog();
        DB::enableQueryLog();
        $render();
        $queries = array_column(DB::getQueryLog(), 'query');
        DB::disableQueryLog();

        return $queries;
    };

    $seed(1);
    $small = $countQueries();
    $seed(9);
    $large = $countQueries();

    expect(count($large))->toBe(count($small))
        ->and(collect($large)->filter(fn (string $sql): bool => str_contains($sql, 'amount') || str_contains($sql, 'budget'))->all())->toBe([]);
})->with(['proposal', 'program', 'annual plan']);

test('proposal and donor names render as text without links for a user without proposals.view', function () {
    $programImpact = chainNode(null, ResultType::Impact, programId: $this->program->id, statement: 'Program impact.');
    $proposalImpact = chainNode($this->proposal, ResultType::Impact, programId: $this->program->id);
    $proposalImpact->forceFill(['contributes_to_result_id' => $programImpact->id])->saveQuietly();
    $donorName = $this->proposal->donor->name;
    $meal = User::factory()->role('MEAL')->create();

    Livewire::actingAs($meal)->test('pages::programs.show', ['program' => $this->program])->set('tab', 'results')
        ->assertSee('Water Access Proposal')
        ->assertSee($donorName)
        ->assertDontSeeHtml(route('proposals.show', $this->proposal))
        ->assertDontSeeHtml(route('donors.show', $this->proposal->donor))
        ->assertDontSee('987,654')
        ->assertDontSee('987654');

    Livewire::actingAs($this->viewer)->test('pages::programs.show', ['program' => $this->program])->set('tab', 'results')
        ->assertSeeHtml(route('proposals.show', $this->proposal))
        ->assertSeeHtml(route('donors.show', $this->proposal->donor));
});

test('the chain renders in Arabic with logical classes and keeps the tree order', function () {
    app()->setLocale('ar');
    chainProposalFramework($this->proposal, $this->centerA, $this->centerB);

    $html = chainTab($this->viewer, $this->proposal)
        ->assertSee(__('results_framework.chain_heading'))
        ->assertSee('مركز الأمل سرمدا')
        ->assertSeeInOrder(['Families live in safety.', 'Water is reliably available.', 'Wells are rehabilitated.', 'Rehabilitate the village wells.'])
        ->html();

    $chainHtml = substr($html, strpos($html, 'data-chain-view'));

    expect($chainHtml)->not->toMatch('/class="[^"]*\b(ml|mr|pl|pr|left|right)-\d/');
});

test('an empty framework offers Start Logical Framework only to results.create holders', function () {
    chainTab(User::factory()->role('MEAL')->create(), $this->proposal)
        ->assertSee(__('results_framework.chain_start'));

    chainTab($this->viewer, $this->proposal)
        ->assertSee(__('results_framework.chain_empty_heading'))
        ->assertDontSee(__('results_framework.chain_start'));
});

/**
 * A milestone with a status and a weight, linked to an activity.
 */
function chainWeightedMilestone(Project $project, Result $activity, string $name, MilestoneStatus $status, string $weight, ?Proposal $proposal = null): Milestone
{
    $milestone = chainMilestone($project, $activity, proposal: $proposal);
    $milestone->forceFill(['name' => $name, 'status' => $status, 'weight_percentage' => $weight])->saveQuietly();

    return $milestone;
}

test('an activity shows its milestones per center with status and weighted progress, cancelled ones left out', function () {
    $chain = chainProposalFramework($this->proposal, $this->centerA, $this->centerB);
    chainWeightedMilestone($this->centerA, $chain['activity'], 'Drill well one', MilestoneStatus::Reached, '30');
    chainWeightedMilestone($this->centerA, $chain['activity'], 'Drill well two', MilestoneStatus::Planned, '50');
    chainWeightedMilestone($this->centerA, $chain['activity'], 'Soil survey', MilestoneStatus::Cancelled, '20');
    chainWeightedMilestone($this->centerB, $chain['activity'], 'Pump installed', MilestoneStatus::Reached, '40', $this->proposal);

    chainTab($this->viewer, $this->proposal)
        ->assertSeeHtml('data-chain-milestones')
        ->assertSeeInOrder(['Rehabilitate the village wells.', 'Hope Center Sarmada', 'Drill well one', 'Drill well two', 'Soil survey', 'Unmapped Water Project', 'Pump installed'])
        ->assertSeeHtml('data-chain-milestone-progress="'.$this->centerA->id.'" data-percent="37.5"')
        ->assertSeeHtml('data-chain-milestone-progress="'.$this->centerB->id.'" data-percent="100"')
        ->assertSee(MilestoneStatus::Reached->label())
        ->assertSee(MilestoneStatus::Cancelled->label())
        ->assertSee(__('results_framework.chain_milestone_cancelled_excluded'))
        ->assertDontSeeHtml('data-chain-gap="no_milestone"');
});

test('a milestone whose activity link no longer fits is shown with the flag', function () {
    $chain = chainProposalFramework($this->proposal, $this->centerA, $this->centerB);
    $milestone = chainWeightedMilestone($this->centerA, $chain['activity'], 'Borrowed delivery', MilestoneStatus::Planned, '10', Proposal::factory()->create());

    chainTab($this->viewer, $this->proposal)
        ->assertSee('Borrowed delivery')
        ->assertSeeHtml('data-chain-milestone="'.$milestone->id.'"')
        ->assertSee(__('milestones.activity_link_not_funding'));
});

test('an activity shows its planned months and the center calendar under a center filter', function () {
    $chain = chainProposalFramework($this->proposal, $this->centerA, $this->centerB);
    ActivitySchedule::factory()->create(['result_id' => $chain['activity']->id, 'month' => '2026-03-01']);
    ActivitySchedule::factory()->create(['result_id' => $chain['activity']->id, 'month' => '2026-04-01', 'weeks' => [1, 2]]);
    ActivitySchedule::factory()->create(['result_id' => $chain['activity']->id, 'project_id' => $this->centerB->id, 'month' => '2026-06-01']);
    chainMilestone($this->centerA, $chain['activity']);
    chainMilestone($this->centerB, $chain['activity']);

    chainTab($this->viewer, $this->proposal)
        ->assertSeeHtml('data-chain-schedule-month="2026-03"')
        ->assertSeeHtml('data-chain-schedule-month="2026-04"')
        ->assertSee(trans_choice('results_framework.chain_weeks', 2, ['weeks' => '1, 2']))
        ->assertDontSeeHtml('data-chain-schedule-month="2026-06"')
        ->set('chainCenter', (string) $this->centerB->id)
        ->assertSeeHtml('data-chain-schedule-month="2026-06"')
        ->assertDontSeeHtml('data-chain-schedule-month="2026-03"');

    chainTab($this->viewer, $this->proposal)
        ->set('chainCenter', (string) $this->centerA->id)
        ->assertSeeHtml('data-chain-schedule-month="2026-03"');
});

test('a center filter keeps only that center\'s milestones under an activity', function () {
    $chain = chainProposalFramework($this->proposal, $this->centerA, $this->centerB);
    chainWeightedMilestone($this->centerA, $chain['activity'], 'Drill well one', MilestoneStatus::Reached, '30');
    chainWeightedMilestone($this->centerB, $chain['activity'], 'Pump installed', MilestoneStatus::Reached, '40');

    chainTab($this->viewer, $this->proposal)
        ->set('chainCenter', (string) $this->centerB->id)
        ->assertSee('Pump installed')
        ->assertDontSee('Drill well one');
});

test('a proposal-wide milestone without a center shows in its own group, also under a center filter', function () {
    $chain = chainProposalFramework($this->proposal, $this->centerA, $this->centerB);
    chainWeightedMilestone($this->centerA, $chain['activity'], 'Drill well one', MilestoneStatus::Reached, '30');
    $shared = chainWeightedMilestone($this->centerA, $chain['activity'], 'Final donor report', MilestoneStatus::Planned, '20', $this->proposal);
    DB::table('milestones')->where('id', $shared->id)->update(['project_id' => null]);

    chainTab($this->viewer, $this->proposal)
        ->assertSeeInOrder([__('results_framework.chain_proposal_wide'), 'Final donor report', 'Hope Center Sarmada', 'Drill well one'])
        ->assertSeeHtml('data-chain-milestone-progress="proposal" data-percent="0"')
        ->set('chainCenter', (string) $this->centerB->id)
        ->assertDontSee('Drill well one');

    chainTab($this->viewer, $this->proposal)
        ->set('chainCenter', (string) $this->centerA->id)
        ->assertSee('Final donor report')
        ->assertSee('Drill well one');
});
