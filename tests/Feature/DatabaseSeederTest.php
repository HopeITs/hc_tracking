<?php

use App\Enums\UserRole;
use App\Models\Account;
use App\Models\City;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Result;
use App\Models\Risk;
use App\Models\Strategy;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TaskUpdate;
use App\Models\TransfersTracking;
use App\Models\User;
use App\Models\YearlyPlan;
use Database\Seeders\CoreDataSeeder;

test('the seeders populate every table in the schema', function () {
    $this->seed();

    $models = [
        User::class,
        City::class,
        Strategy::class,
        Donor::class,
        Program::class,
        YearlyPlan::class,
        Project::class,
        Proposal::class,
        DonorProposal::class,
        Risk::class,
        Milestone::class,
        Task::class,
        TaskUpdate::class,
        TaskAttachment::class,
        Result::class,
        Indicator::class,
        PlanIndicator::class,
        Report::class,
        ReportFile::class,
        ProposalStatusNote::class,
        Account::class,
        TransfersTracking::class,
    ];

    foreach ($models as $model) {
        expect($model::query()->count())->toBeGreaterThan(0, $model.' was not seeded');
    }

    $this->assertDatabaseCount('project_proposal', Project::query()->count());
});

test('demo staff cover every role and programmes have managers', function () {
    $this->seed();

    expect(User::query()->where('email', 'test@example.com')->firstOrFail()->role)->toBe(UserRole::Admin);

    foreach (UserRole::cases() as $role) {
        expect(User::query()->where('role', $role)->exists())->toBeTrue();
    }

    foreach (Program::query()->with('manager', 'projects', 'yearlyPlans')->get() as $program) {
        expect($program->manager->role)->toBe(UserRole::Manager);

        foreach ($program->projects as $project) {
            expect($project->name)->toStartWith($program->name.' Community Response ');
        }

        foreach ($program->yearlyPlans as $plan) {
            expect($plan->manager_id)->toBe($program->manager_id);
        }
    }
});

test('lookup seeding reuses existing named cities and donors', function () {
    $city = City::factory()->create(['name' => 'Damascus']);
    $donor = Donor::factory()->create(['name' => 'UNICEF']);

    $this->seed(CoreDataSeeder::class);
    $this->seed(CoreDataSeeder::class);

    $this->assertDatabaseCount('cities', 8);
    $this->assertDatabaseCount('donors', 5);
    expect(City::query()->where('name', 'Damascus')->sole()->id)->toBe($city->id);
    expect(Donor::query()->where('name', 'UNICEF')->sole()->id)->toBe($donor->id);
});

test('demo targets cover annual plans and proposals in the same programme', function () {
    $this->seed();

    expect(PlanIndicator::query()->where('source_type', 'yearly_plan')->exists())->toBeTrue();
    $targets = PlanIndicator::query()->where('source_type', 'proposal')
        ->with('source.projects', 'project', 'indicator.result')->get();

    expect($targets)->not->toBeEmpty();

    foreach ($targets as $target) {
        expect($target->source)->toBeInstanceOf(Proposal::class)
            ->and($target->source->projects->contains('id', $target->project_id))->toBeTrue()
            ->and($target->project->program_id)->toBe($target->indicator->result->program_id);
    }
});

test('demo proposal dates fall within their programme strategy', function () {
    $this->seed();

    foreach (Proposal::query()->with('projects.program.strategy')->get() as $proposal) {
        $strategy = $proposal->projects->first()->program->strategy;

        expect($proposal->planned_date_begin->year)->toBe($strategy->start_year)
            ->and($proposal->planned_date_end->toDateString())->toBe($strategy->start_year.'-12-31')
            ->and($proposal->actual_date_begin->toDateString())->toBe($strategy->start_year.'-01-01');
    }
});

test('the seeded graph is navigable end to end', function () {
    $this->seed();

    $report = Report::query()->with('planIndicator.source', 'planIndicator.indicator.result.program')->firstOrFail();

    expect($report->planIndicator->source)->not->toBeNull()
        ->and($report->planIndicator->indicator->result->program)->not->toBeNull();

    $tranche = TransfersTracking::query()->whereNotNull('account_id')->with('donorProposal.donor', 'account')->firstOrFail();

    expect($tranche->donorProposal->donor)->not->toBeNull()
        ->and($tranche->account->is_active)->toBeTrue();
});

/**
 * Financial rows must add up. A factory state that derives fees or the net
 * from another column is easy to get subtly wrong \u2014 this fails loudly if the
 * seeded money ever stops reconciling.
 */
test('every seeded transfer reconciles', function () {
    $this->seed();

    $tranches = TransfersTracking::query()->get();

    expect($tranches)->not->toBeEmpty();

    foreach ($tranches as $tranche) {
        $expectedNet = round((float) $tranche->amount_received - (float) $tranche->fees, 2);

        expect(round((float) $tranche->net_amount_received, 2))
            ->toBe($expectedNet, "tranche {$tranche->id} does not reconcile")
            ->and((float) $tranche->net_amount_received)
            ->toBeLessThanOrEqual((float) $tranche->amount_received)
            ->and((float) $tranche->fees)->toBeGreaterThanOrEqual(0.0);
    }
});
