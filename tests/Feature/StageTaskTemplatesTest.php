<?php

use App\Actions\Proposals\CreateStageTasks;
use App\Enums\ProposalStatus;
use App\Enums\TaskKind;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\ProposalStageTaskTemplate;
use App\Models\ProposalStatusNote;
use App\Models\Task;
use App\Notifications\EventCatalog;
use App\Support\DefaultStageTaskTemplates;
use App\Support\EmployeeProfile;
use Illuminate\Support\Facades\DB;

/*
| W-12 (D-63): the department work a proposal stage needs comes from
| templates the PR Manager maintains, created by a queued listener when the
| proposal enters that status. Nobody is assigned (D-33); the one person
| named is the manager of the template's department, on the final-report
| milestone whose column requires one.
*/

/**
 * An HR department with a grade-5 "Department Manager" holder, which is what
 * D-65 means by its manager.
 *
 * @return array{department: int, manager: int}
 */
function departmentWithManager(string $name = 'Finance', ?string $startDate = null): array
{
    $department = insertHrDepartment($name);
    $manager = insertHrEmployee();
    insertHrContract($manager, insertHrPosition($department, 'Department Manager', jobLevelId: departmentManagerLevel()), startDate: $startDate);

    return ['department' => $department, 'manager' => $manager];
}

/**
 * The HR job level D-65 names, created once per test.
 */
function departmentManagerLevel(): int
{
    $existing = DB::connection('hr')->table('job_levels')->where('grade', 5)->value('id');

    return $existing === null ? insertHrJobLevel('Department Manager', 'مدير قسم', 5) : (int) $existing;
}

/**
 * A proposal sitting at the given status with the note that says when it got
 * there, which is what the templates count their due offset from.
 */
function stagedProposal(ProposalStatus $status, string $effectiveOn = '2026-11-04'): Proposal
{
    $proposal = Proposal::factory()->create(['status' => $status]);
    ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => $status, 'context' => 'Moved.', 'date' => $effectiveOn]);

    return $proposal;
}

beforeEach(function () {
    $this->travelTo('2026-11-04 09:00:00');
    $this->actor = userWithRole('Public Relations Manager');
    $this->actingAs($this->actor);
    $this->run = fn (Proposal $proposal, ProposalStatus $status) => app(CreateStageTasks::class)->handle($proposal, $status);
});

test('a template creates its department task, due on its own offset, with nobody assigned', function () {
    $finance = departmentWithManager();
    $template = ProposalStageTaskTemplate::factory()->create([
        'status' => ProposalStatus::ProposalReview,
        'department_id' => $finance['department'],
        'kind' => TaskKind::Financial,
        'title_en' => 'Finance review of the proposal',
        'due_offset_days' => 7,
    ]);
    $proposal = stagedProposal(ProposalStatus::ProposalReview);

    $created = ($this->run)($proposal, ProposalStatus::ProposalReview);

    $task = Task::query()->withoutGlobalScopes()->sole();

    expect($created)->toBe([(int) $task->id])
        ->and($task->department_id)->toBe($finance['department'])
        ->and($task->kind)->toBe(TaskKind::Financial)
        ->and($task->stage_template_id)->toBe($template->id)
        ->and($task->title)->toBe('Finance review of the proposal')
        ->and($task->due_date->toDateString())->toBe('2026-11-11')
        ->and($task->source_type)->toBe('proposal')
        ->and($task->source_id)->toBe($proposal->id)
        ->and($task->taskAssignees()->count())->toBe(0);
});

test('every active template of the status fires, and none of another status', function () {
    foreach (['Media', 'Finance', 'MEAL'] as $index => $name) {
        ProposalStageTaskTemplate::factory()->create([
            'status' => ProposalStatus::ProposalReview,
            'department_id' => insertHrDepartment($name),
            'title_en' => $name.' review',
            'sort_order' => $index,
        ]);
    }

    ProposalStageTaskTemplate::factory()->create(['status' => ProposalStatus::ConceptNote, 'title_en' => 'Other stage']);

    ($this->run)(stagedProposal(ProposalStatus::ProposalReview), ProposalStatus::ProposalReview);

    expect(Task::query()->withoutGlobalScopes()->pluck('title')->all())
        ->toBe(['Media review', 'Finance review', 'MEAL review']);
});

test('a deactivated template creates nothing', function () {
    ProposalStageTaskTemplate::factory()->inactive()->create(['status' => ProposalStatus::ProposalReview]);

    expect(($this->run)(stagedProposal(ProposalStatus::ProposalReview), ProposalStatus::ProposalReview))->toBe([]);
    expect(Task::query()->withoutGlobalScopes()->count())->toBe(0);
});

test('running twice over the same proposal creates nothing the second time', function () {
    ProposalStageTaskTemplate::factory()->create(['status' => ProposalStatus::ProposalReview]);
    $proposal = stagedProposal(ProposalStatus::ProposalReview);

    ($this->run)($proposal, ProposalStatus::ProposalReview);

    expect(($this->run)($proposal, ProposalStatus::ProposalReview))->toBe([]);
    expect(Task::query()->withoutGlobalScopes()->count())->toBe(1);
});

test('a repeating template waits for its task to be done before firing again', function () {
    ProposalStageTaskTemplate::factory()->repeating()->create(['status' => ProposalStatus::ProposalReview]);
    $proposal = stagedProposal(ProposalStatus::ProposalReview);

    ($this->run)($proposal, ProposalStatus::ProposalReview);

    // Still open: a re-entry must not pile a second one on top.
    expect(($this->run)($proposal, ProposalStatus::ProposalReview))->toBe([]);

    Task::query()->withoutGlobalScopes()->sole()->forceFill(['completed_date' => '2026-11-05'])->save();

    expect(($this->run)($proposal, ProposalStatus::ProposalReview))->toHaveCount(1);
    expect(Task::query()->withoutGlobalScopes()->count())->toBe(2);
});

test('the approved template opens the final-report milestone under the department manager', function () {
    $meal = departmentWithManager('MEAL');
    ProposalStageTaskTemplate::factory()->finalReportMilestone()->create(['department_id' => $meal['department']]);
    $proposal = stagedProposal(ProposalStatus::Approved);

    $created = ($this->run)($proposal, ProposalStatus::Approved);

    $milestone = Milestone::query()->where('proposal_id', $proposal->id)->sole();

    expect($created)->toBe([(int) $milestone->id])
        ->and($milestone->is_final_report)->toBeTrue()
        ->and($milestone->responsible_user_id)->toBe($meal['manager'])
        ->and(DB::table('milestones')->where('id', $milestone->id)->value('stage_template_id'))->not->toBeNull()
        // SaveProposalMilestone's own path also makes the task that prepares it.
        ->and(Task::query()->withoutGlobalScopes()->where('source_type', 'milestone')->count())->toBe(1);
});

test('a second approval does not open a second final-report milestone', function () {
    $meal = departmentWithManager('MEAL');
    ProposalStageTaskTemplate::factory()->finalReportMilestone()->create(['department_id' => $meal['department']]);
    $proposal = stagedProposal(ProposalStatus::Approved);

    ($this->run)($proposal, ProposalStatus::Approved);

    expect(($this->run)($proposal, ProposalStatus::Approved))->toBe([]);
    expect(Milestone::query()->where('proposal_id', $proposal->id)->count())->toBe(1);
});

test('a department with no manager is skipped and nothing else breaks', function () {
    $withManager = departmentWithManager('MEAL');
    ProposalStageTaskTemplate::factory()->finalReportMilestone()->create([
        'department_id' => insertHrDepartment('Headless'),
        'title_en' => 'Headless final report',
        'sort_order' => 0,
    ]);
    ProposalStageTaskTemplate::factory()->create([
        'status' => ProposalStatus::Approved,
        'department_id' => $withManager['department'],
        'title_en' => 'A task that still runs',
        'sort_order' => 1,
    ]);
    $proposal = stagedProposal(ProposalStatus::Approved);

    $created = ($this->run)($proposal, ProposalStatus::Approved);

    expect($created)->toHaveCount(1)
        ->and(Milestone::query()->where('proposal_id', $proposal->id)->count())->toBe(0)
        ->and(Task::query()->withoutGlobalScopes()->sole()->title)->toBe('A task that still runs');
});

test('several department managers in HR resolve to the latest contract', function () {
    $department = insertHrDepartment('Finance');
    $level = departmentManagerLevel();

    $older = insertHrEmployee();
    insertHrContract($older, insertHrPosition($department, 'Department Manager', jobLevelId: $level), startDate: '2020-01-01 00:00:00');

    $newer = insertHrEmployee();
    insertHrContract($newer, insertHrPosition($department, 'Department Manager', jobLevelId: $level), startDate: '2025-01-01 00:00:00');

    expect(EmployeeProfile::managerOfDepartment($department))->toBe($newer);
});

test('someone who is not a department manager is not taken for one', function () {
    $department = insertHrDepartment('Finance');
    $officer = insertHrEmployee();
    insertHrContract($officer, insertHrPosition($department, 'Officer', jobLevelId: insertHrJobLevel('Team', 'فريق', 6)));

    expect(EmployeeProfile::managerOfDepartment($department))->toBeNull();
});

test('the seeder writes the configured templates and a re-run changes nothing', function () {
    $codes = config('stage-templates.departments');

    foreach ($codes as $code) {
        DB::connection('hr')->table('departments')->insert(['name' => $code, 'code' => $code]);
    }

    $first = DefaultStageTaskTemplates::ensure();
    $afterFirst = DB::table('proposal_stage_task_templates')->count();

    DefaultStageTaskTemplates::ensure();

    expect($first['skipped'])->toBe([])
        ->and($afterFirst)->toBe(count(config('stage-templates.templates')))
        ->and(DB::table('proposal_stage_task_templates')->count())->toBe($afterFirst);
});

test('the seeder skips a department HR does not have, and says which', function () {
    $result = DefaultStageTaskTemplates::ensure();

    expect($result['written'])->toBe(0)
        ->and($result['skipped'])->toHaveCount(count(config('stage-templates.templates')));
});

test('the stage-tasks event is in the catalog', function () {
    expect(app(EventCatalog::class)->effective('proposal.stage_tasks_created'))->not->toBeNull();
});
