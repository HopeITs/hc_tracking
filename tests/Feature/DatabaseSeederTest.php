<?php

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

test('the seeders populate every table in the schema', function () {
    $this->seed();

    $models = [
        User::class,
        City::class,
        Strategy::class,
        Donor::class,
        Program::class,
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
