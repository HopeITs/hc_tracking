<?php

use App\Models\MonthlyReport;
use App\Models\MonthlyReportRisk;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\Risk;
use App\Models\RiskLibrary;
use App\Support\DefaultRiskLibrary;
use Illuminate\Database\QueryException;

test('a register risk belongs to the location row that owns it', function () {
    $row = placeRow();
    $risk = Risk::factory()->onRow($row)->create();

    expect($risk->location)->toBeInstanceOf(ProposalLocation::class)
        ->and($risk->location->id)->toBe($row->id)
        ->and($risk->proposal_id)->toBe($row->proposal_id);
});

test('a risk casts happened to a boolean', function () {
    expect(Risk::factory()->create()->fresh()->happened)->toBeFalse()
        ->and(Risk::factory()->happened()->create()->fresh()->happened)->toBeTrue();
});

test('onProgram returns only that program\'s own risks', function () {
    $program = Program::factory()->create();
    $mine = Risk::factory()->onProgram($program)->create();
    Risk::factory()->onProgram(Program::factory()->create())->create();
    Risk::factory()->create();

    expect(Risk::onProgram($program->id)->pluck('id')->all())->toBe([$mine->id])
        ->and($mine->program->is($program))->toBeTrue();
});

test('onLogframeOf leaves out the proposal\'s register risks', function () {
    $proposal = Proposal::factory()->create();
    $framework = Risk::factory()->onProposalFramework($proposal)->create();
    Risk::factory()->onRow(ProposalLocation::factory()->create(['proposal_id' => $proposal->id]))->create();
    Risk::factory()->onProposalFramework(Proposal::factory()->create())->create();

    expect(Risk::onLogframeOf($proposal->id)->pluck('id')->all())->toBe([$framework->id])
        ->and($framework->isOnLogframe())->toBeTrue();
});

test('deleting a program deletes its risks', function () {
    $program = Program::factory()->create();
    $risk = Risk::factory()->onProgram($program)->create();

    $program->delete();

    expect(Risk::find($risk->id))->toBeNull();
});

test('deleting the source risk keeps the copy and clears copied_from_id', function () {
    $source = Risk::factory()->onProgram(Program::factory()->create())->create();
    $copy = Risk::factory()->onProposalFramework(Proposal::factory()->create())->create(['copied_from_id' => $source->id]);

    expect($copy->isCopied())->toBeTrue()
        ->and($copy->copiedFrom->is($source))->toBeTrue();

    $source->delete();

    expect($copy->fresh()->copied_from_id)->toBeNull()
        ->and($copy->fresh()->isCopied())->toBeFalse();
});

test('a program risk reports its program for the activity feed', function () {
    $program = Program::factory()->create();

    expect(Risk::factory()->onProgram($program)->create()->activityProgramId())->toBe($program->id);
});

test('a monthly report keeps one risk answer per risk', function () {
    $report = MonthlyReport::factory()->create();
    $risk = Risk::factory()->onProposalFramework(Proposal::factory()->create())->create();
    MonthlyReportRisk::factory()->create(['monthly_report_id' => $report->id, 'risk_id' => $risk->id, 'happened' => true]);

    expect($report->riskEntries)->toHaveCount(1)
        ->and($report->riskEntries->first()->happened)->toBeTrue()
        ->and($report->fresh()->updates_risks)->toBeFalse()
        ->and(fn () => MonthlyReportRisk::factory()->create(['monthly_report_id' => $report->id, 'risk_id' => $risk->id]))
        ->toThrow(QueryException::class);
});

test('the risk library starts empty and the default list adds nothing', function () {
    expect(RiskLibrary::count())->toBe(0)
        ->and(DefaultRiskLibrary::all())->toBe([])
        ->and(DefaultRiskLibrary::ensure())->toBe(0)
        ->and(RiskLibrary::count())->toBe(0);
});
