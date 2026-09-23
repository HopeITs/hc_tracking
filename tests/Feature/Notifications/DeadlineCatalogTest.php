<?php

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Activity;
use App\Models\DonorReport;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Notifications\Alerts\DeadlineCatalog;
use Carbon\CarbonImmutable;

test('the catalog lists open dated items with their direct people and closes the finished ones', function () {
    $employee = insertHrEmployee();
    $assignee = userWithRole('Managers', $employee);

    // Factories create related records with random dates, so every check
    // below targets the records made here rather than "nothing else".
    $closed = Activity::withoutRecording(function () use ($employee): array {
        $open = Task::factory()->create(['due_date' => '2026-09-30', 'title' => 'Open task']);
        $open->taskAssignees()->delete();
        $open->taskAssignees()->create(['employee_id' => $employee]);
        Task::factory()->create(['due_date' => '2026-09-30', 'status' => TaskStatus::Done, 'title' => 'Done task']);
        Task::factory()->create(['due_date' => '2026-12-31', 'title' => 'Far task']);
        DonorReport::factory()->create(['due_on' => '2026-09-28', 'title' => 'Interim report']);
        Proposal::factory()->create(['planned_date_end' => '2026-09-28', 'name' => 'Ending proposal']);
        TransfersTracking::factory()->create(['scheduled_date' => '2026-09-28', 'payment_amount' => '4321.00']);

        return [
            'milestone' => Milestone::factory()->create(['planned_end_date' => '2026-09-29', 'status' => MilestoneStatus::Cancelled])->id,
            'report' => Report::factory()->reviewed()->create(['planned_date' => '2026-09-29'])->id,
            'project' => Project::factory()->status(ProjectStatus::Closed)->create(['end_date' => '2026-09-28'])->id,
        ];
    });

    $items = app(DeadlineCatalog::class)->items(CarbonImmutable::parse('2026-10-15'));
    $labels = $items->pluck('label')->all();

    expect($labels)->toContain('Open task', 'Interim report')
        ->not->toContain('Done task', 'Far task')
        ->and($items->firstWhere('label', 'Open task')->directUserIds)->toContain($assignee->id)
        ->and($items->firstWhere('label', 'Interim report')->watcherRoles)->toBe(['Financial', 'Public Relations Manager']);

    foreach ($closed as $type => $id) {
        expect($items->where('subjectType', $type)->pluck('subjectId')->all())->not->toContain($id);
    }

    expect($items->where('subjectType', 'proposal')->pluck('label')->all())->toContain('Ending proposal')
        ->and($items->where('subjectType', 'transfer')->pluck('label')->filter(fn (string $label): bool => str_contains($label, '4321'))->count())->toBe(1);
});
