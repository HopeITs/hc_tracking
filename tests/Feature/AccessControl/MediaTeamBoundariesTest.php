<?php

use App\Enums\MilestoneStatus;
use App\Enums\ReportStatus;
use App\Enums\TaskStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Result;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TransfersTracking;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

dataset('media money pages', [
    'proposals' => [fn (): string => route('proposals.index')],
    'proposal' => [fn (): string => route('proposals.show', Proposal::factory()->create())],
    'donors' => [fn (): string => route('donors.index')],
    'donor' => [fn (): string => route('donors.show', Donor::factory()->create())],
    'funding agreements' => [fn (): string => route('funding-agreements.index')],
    'funding agreement' => [fn (): string => route('funding-agreements.show', DonorProposal::factory()->create())],
    'transfers' => [fn (): string => route('transfers.index')],
    'accounts' => [fn (): string => route('admin.accounts.index')],
]);

test('Media Team is refused every money page by direct URL (MD-02)', function (Closure $url) {
    $this->actingAs(userWithRole('Media Team'))->get($url())->assertForbidden();
})->with('media money pages');

test('the sidebar hides every money link from Media Team but keeps tasks (MD-02)', function () {
    $this->actingAs(userWithRole('Media Team'))->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('tasks.index'))
        ->assertDontSee(route('admin.accounts.index'))
        ->assertDontSee(route('transfers.index'))
        ->assertDontSee(route('funding-agreements.index'))
        ->assertDontSee(route('proposals.index'))
        ->assertDontSee(route('donors.index'));
});

test('Media Team cannot create a project or open a logframe create form (MD-03)', function (string $page) {
    Livewire::actingAs(userWithRole('Media Team'))->test($page)->call('openCreateModal')->assertForbidden();
})->with(['pages::projects.index', 'pages::results.index', 'pages::indicators.index', 'pages::targets.index']);

test('Media Team cannot approve another author\'s reviewed report (MD-03)', function () {
    $report = Report::factory()->create(['user_id' => userWithRole('Media Team')->id, 'status' => ReportStatus::Reviewed, 'submit_date' => today()]);

    Livewire::actingAs(userWithRole('Media Team'))->test('pages::reports.show', ['report' => $report])->call('approve')->assertForbidden();

    expect($report->fresh()->status)->toBe(ReportStatus::Reviewed);
});

test('Media Team holds no AI drafting permission (MD-03)', function () {
    $user = userWithRole('Media Team');

    expect($user->can('ai.draft'))->toBeFalse()
        ->and(userWithRole('MEAL')->can('ai.draft'))->toBeTrue();
});

/**
 * One project funded by one proposal, with figures no other fixture on these pages can produce.
 *
 * @return array{project: Project, transfer: TransfersTracking, report: DonorReport}
 */
function seedMediaMoneyFixture(): array
{
    $project = Project::factory()->create(['start_date' => '2026-06-01', 'end_date' => '2027-06-30']);
    $donor = Donor::factory()->create(['name' => 'Mediaview Foundation', 'next_step_due_on' => '2026-06-15']);
    $proposal = Proposal::factory()->create(['name' => 'Media Money Proposal', 'donor_id' => $donor->id, 'planned_date_end' => '2026-06-15']);
    $project->proposals()->attach($proposal);
    $agreement = DonorProposal::factory()->create(['donor_id' => $donor->id, 'proposal_id' => $proposal->id, 'budget' => '864209.00']);
    $transfer = TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'payment_amount' => '135791.00', 'scheduled_date' => '2026-06-15']);
    $report = DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Mediaview interim report', 'due_on' => '2026-06-15']);

    return compact('project', 'transfer', 'report');
}

test('Media Team sees no funding figures on the project page or its proposals tab (MD-02)', function () {
    ['project' => $project] = seedMediaMoneyFixture();

    Livewire::actingAs(userWithRole('Media Team'))->test('pages::projects.show', ['project' => $project])
        ->assertOk()
        ->assertDontSee('864,209')
        ->assertDontSee('135,791')
        ->assertDontSee('Funding envelope')
        ->set('tab', 'proposals')
        ->assertDontSee('864,209')
        ->assertDontSee('135,791');
});

test('Media Team sees no money items on the calendar (MD-02)', function () {
    ['transfer' => $transfer, 'report' => $report] = seedMediaMoneyFixture();

    Livewire::actingAs(userWithRole('Media Team'))->test('pages::calendar.index')
        ->set('month', '2026-06')
        ->set('showProposals', true)
        ->set('showTransfers', true)
        ->set('showDonors', true)
        ->assertDontSee('135,791')
        ->assertDontSee('Mediaview Foundation')
        ->assertDontSee('Mediaview interim report')
        ->assertDontSeeHtml('data-test="calendar-transfer-'.$transfer->id.'"')
        ->assertDontSeeHtml('data-test="calendar-donor-report-'.$report->id.'"');
});

test('the desk money panel is empty for Media Team (MD-02)', function () {
    seedMediaMoneyFixture();

    expect(Livewire::actingAs(userWithRole('Media Team', insertHrEmployee()))->test('desk.panels.money', ['size' => 'L'])->instance()->rows)->toBeEmpty();
});

test('Media Team cannot edit or delete framework records (MD-03)', function (Closure $case) {
    [$page, $id] = $case();
    $attempt = fn () => Livewire::actingAs(userWithRole('Media Team'))->test($page);

    $attempt()->call('openEditModal', $id)->assertForbidden();
    $attempt()->call('confirmDelete', $id)->assertForbidden();
})->with([
    'result' => [fn (): array => ['pages::results.index', Result::factory()->create()->id]],
    'indicator' => [fn (): array => ['pages::indicators.index', Indicator::factory()->create()->id]],
    'target' => [fn (): array => ['pages::targets.index', PlanIndicator::factory()->create()->id]],
]);

test('Media Team cannot edit, archive or delete projects and milestones (MD-03)', function () {
    $project = Project::factory()->create();
    $milestone = Milestone::factory()->create();
    $projects = fn () => Livewire::actingAs(userWithRole('Media Team'))->test('pages::projects.index');
    $milestones = fn () => Livewire::actingAs(userWithRole('Media Team'))->test('pages::milestones.index');

    $projects()->call('openEditModal', $project->id)->assertForbidden();
    $projects()->call('confirmArchive', $project->id)->assertForbidden();
    $milestones()->call('quickSetStatus', $milestone->id, MilestoneStatus::Ongoing->value)->assertForbidden();
    $milestones()->call('confirmDelete', $milestone->id)->assertForbidden();

    expect(Project::query()->find($project->id))->not->toBeNull()
        ->and(Milestone::query()->find($milestone->id))->not->toBeNull();
});

test('Media Team cannot acknowledge a report for MEAL or hold the reopen and aggregate rights (MD-03)', function () {
    $report = Report::factory()->create(['status' => ReportStatus::Reviewed, 'submit_date' => today()]);
    $media = userWithRole('Media Team');

    Livewire::actingAs($media)->test('pages::reports.show', ['report' => $report])->call('markSeenByMeal')->assertForbidden();

    expect($media->can('reports.reopen'))->toBeFalse()
        ->and($media->can('reports.aggregate'))->toBeFalse();
});

test('Media Team cannot write on the money pages by calling the actions directly (MD-03)', function () {
    $attempt = fn (string $page) => Livewire::actingAs(userWithRole('Media Team'))->test($page);

    $attempt('pages::transfers.index')->call('openCreateModal')->assertForbidden();
    $attempt('pages::funding-agreements.index')->call('openCreateModal')->assertForbidden();
    $attempt('pages::donors.index')->call('confirmDelete', Donor::factory()->create()->id)->assertForbidden();
});

test('Media Team moves status and manages files on a colleague\'s department task but cannot edit or delete it (MD-01)', function () {
    $employeeId = insertHrEmployee();
    $media = userWithRole('Media Team', $employeeId);
    $colleagueEmployee = insertHrEmployee();
    $department = insertHrDepartment();
    insertHrContract($employeeId, insertHrPosition($department));
    insertHrContract($colleagueEmployee, insertHrPosition($department));

    $task = Task::factory()->create();
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $colleagueEmployee]);
    $gate = Gate::forUser($media->fresh());

    expect($gate->allows('updateStatus', $task))->toBeTrue()
        ->and($gate->allows('manageFiles', $task))->toBeTrue()
        ->and($gate->allows('update', $task))->toBeFalse()
        ->and($gate->allows('delete', $task))->toBeFalse();

    $own = Task::factory()->create(['created_by' => $media->id]);
    expect(Gate::forUser($media->fresh())->allows('update', $own))->toBeTrue();
});

test('Media Team cannot move status or add files on another department\'s task (MD-04)', function () {
    $media = userWithRole('Media Team', insertHrEmployee());
    $foreign = Task::factory()->create();

    $gate = Gate::forUser($media->fresh());

    expect($gate->allows('updateStatus', $foreign))->toBeFalse()
        ->and($gate->allows('manageFiles', $foreign))->toBeFalse();
});

test('Media Team works a colleague\'s department task on the page: sends it to review, cannot close it, and uploads a file (MD-01)', function () {
    Storage::fake('local');
    $employeeId = insertHrEmployee();
    $colleagueEmployee = insertHrEmployee();
    $department = insertHrDepartment();
    insertHrContract($employeeId, insertHrPosition($department));
    insertHrContract($colleagueEmployee, insertHrPosition($department));
    $media = userWithRole('Media Team', $employeeId);

    $task = Task::factory()->create(['title' => 'Colleague deliverable', 'status' => TaskStatus::InProgress]);
    $task->taskAssignees()->delete();
    $task->taskAssignees()->create(['employee_id' => $colleagueEmployee]);

    $page = Livewire::actingAs($media)->test('pages::tasks.show', ['task' => $task])
        ->assertOk()
        ->assertDontSeeHtml('data-test="task-edit-title-input"')
        ->call('changeStatus', TaskStatus::UnderReview->value)
        ->assertHasNoErrors()
        ->call('changeStatus', TaskStatus::Done->value)
        ->assertHasErrors('taskStatus')
        ->call('openCreateFileModal')
        ->set('newFile', validUpload('poster.pdf'))
        ->call('saveFile')
        ->assertHasNoErrors();

    expect($task->fresh())->status->toBe(TaskStatus::UnderReview)->title->toBe('Colleague deliverable')
        ->and(TaskAttachment::query()->where('task_id', $task->id)->count())->toBe(1);
});

test('the Ask box gives Media Team no money answers, money suggestions or money records (MD-02)', function () {
    seedMediaMoneyFixture();
    $ask = fn (string $query): array => Livewire::actingAs(userWithRole('Media Team'))->test('desk.ask')->set('query', $query)->instance()->results;
    $json = fn (array $results): string => json_encode($results, JSON_UNESCAPED_UNICODE);

    expect($ask('')['suggestions'])->not->toContain(__('desk.ask_suggest.money'))
        ->and($ask('money')['answers'])->toBeEmpty()
        ->and($json($ask('Mediaview')))->not->toContain('Mediaview')
        ->and($json($ask('Media Money Proposal')))->not->toContain('Media Money Proposal');
});
