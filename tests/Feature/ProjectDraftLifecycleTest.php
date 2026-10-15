<?php

use App\Actions\Projects\ArchiveProject;
use App\Actions\Projects\RestoreProject;
use App\Actions\Projects\SaveProject;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| contracts.md §3 (T05): a new project is a dateless draft; dates arrive only
| with approval; restore follows D-27; one open project per (center, program).
*/

/**
 * Drafts that hold dates without a donor-approved proposal behind them.
 *
 * The invariant of §3: a draft holds dates only while it is linked to a
 * donor-approved proposal and waits for its owner's acknowledgement. T08
 * extends the exemption when it writes that path; until then no draft may
 * hold dates at all.
 *
 * @return list<int>
 */
function draftsHoldingDatesWithoutApproval(): array
{
    $approved = array_map(
        fn (ProposalStatus $status): string => $status->value,
        array_filter(ProposalStatus::cases(), fn (ProposalStatus $status): bool => $status->isApprovedByDonor()),
    );

    return Project::query()
        ->where('status', ProjectStatus::Draft->value)
        ->where(fn ($query) => $query->whereNotNull('start_date')->orWhereNotNull('end_date'))
        ->whereNotIn('id', DB::table('project_proposal')
            ->join('proposals', 'proposals.id', '=', 'project_proposal.proposal_id')
            ->whereIn('proposals.status', $approved)
            ->select('project_proposal.project_id'))
        ->orderBy('id')
        ->pluck('id')
        ->all();
}

beforeEach(function () {
    $this->user = User::factory()->role('General Manager')->create();
    $this->cityId = insertHrCity('Idlib', 'إدلب');
    $this->centerId = insertHrCenter($this->cityId, 'Hope Center Idlib', 'مركز الأمل إدلب');
    $this->owner = insertHrEmployee();
    $this->program = Program::factory()->create(['manager_id' => $this->owner]);
    $this->data = fn (array $fields = []): array => [
        'name' => 'Idlib Micro',
        'centerId' => (string) $this->centerId,
        'programId' => (string) $this->program->id,
        'userId' => (string) $this->owner,
        ...$fields,
    ];
    $this->create = fn (array $fields = []) => Livewire::actingAs($this->user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('sectorIds', newProjectSectorIds())
        ->set(($this->data)($fields))
        ->call('saveProject');
});

test('creating a project makes a dateless draft', function () {
    ($this->create)()->assertHasNoErrors();

    expect(Project::query()->sole())
        ->status->toBe(ProjectStatus::Draft)
        ->start_date->toBeNull()
        ->end_date->toBeNull();
    expect(draftsHoldingDatesWithoutApproval())->toBe([]);
});

test('dates sent for a draft are refused and nothing is written', function (array $dates) {
    ($this->create)($dates)
        ->assertHasErrors()
        ->assertSee('Project dates are set when the proposal is approved.');

    expect(Project::query()->count())->toBe(0);
})->with([
    'both' => [['startDate' => '2026-11-01', 'endDate' => '2027-11-01']],
    'start only' => [['startDate' => '2026-11-01']],
    'end only' => [['endDate' => '2027-11-01']],
]);

test('the action refuses dates for a draft whoever calls it', function () {
    $save = fn () => app(SaveProject::class)->handle(null, ($this->data)(['startDate' => '2026-11-01']), $this->user);

    $this->actingAs($this->user);

    expect($save)->toThrow(ValidationException::class);
    expect(Project::query()->count())->toBe(0);
});

test('a status sent with the form never reaches the project', function () {
    $project = Project::factory()->active()->create([
        'center_id' => $this->centerId,
        'city_id' => $this->cityId,
        'program_id' => $this->program->id,
        'user_id' => $this->owner,
    ]);

    Livewire::actingAs($this->user)->test('pages::projects.index')
        ->call('openEditModal', $project->id)
        ->set('status', 'archived')
        ->set('name', 'Renamed')
        ->call('saveProject')
        ->assertHasNoErrors();

    expect($project->fresh())->name->toBe('Renamed')->status->toBe(ProjectStatus::Active);
});

test('the date inputs are not rendered for a draft', function () {
    $draft = Project::factory()->create([
        'center_id' => $this->centerId,
        'city_id' => $this->cityId,
        'program_id' => $this->program->id,
        'user_id' => $this->owner,
    ]);

    Livewire::actingAs($this->user)->test('pages::projects.index')
        ->call('openCreateModal')
        ->set('sectorIds', newProjectSectorIds())
        ->assertDontSeeHtml('wire:model="startDate"')
        ->assertDontSeeHtml('wire:model="endDate"')
        ->call('closeProjectModal')
        ->call('openEditModal', $draft->id)
        ->assertDontSeeHtml('wire:model="startDate"');
});

test('the dates of an active project are editable', function () {
    $project = Project::factory()->active()->create([
        'center_id' => $this->centerId,
        'city_id' => $this->cityId,
        'program_id' => $this->program->id,
        'user_id' => $this->owner,
    ]);

    Livewire::actingAs($this->user)->test('pages::projects.index')
        ->call('openEditModal', $project->id)
        ->assertSeeHtml('wire:model="startDate"')
        ->set('startDate', '2026-03-01')
        ->set('endDate', '2027-02-28')
        ->call('saveProject')
        ->assertHasNoErrors();

    expect($project->fresh())
        ->start_date->toDateString()->toBe('2026-03-01')
        ->end_date->toDateString()->toBe('2027-02-28')
        ->status->toBe(ProjectStatus::Active);
});

test('editing a draft keeps its status and writes no dates', function () {
    $project = Project::factory()->create([
        'center_id' => $this->centerId,
        'city_id' => $this->cityId,
        'program_id' => $this->program->id,
        'user_id' => $this->owner,
    ]);

    Livewire::actingAs($this->user)->test('pages::projects.index')
        ->call('openEditModal', $project->id)
        ->set('name', 'Renamed')
        ->call('saveProject')
        ->assertHasNoErrors();

    expect($project->fresh())->name->toBe('Renamed')->status->toBe(ProjectStatus::Draft)->start_date->toBeNull();
});

test('restoring an archived project follows D-27', function (bool $dated, ProjectStatus $expected) {
    $project = Project::factory()->status(ProjectStatus::Archived)->create([
        'center_id' => $this->centerId,
        'city_id' => $this->cityId,
        'program_id' => $this->program->id,
        ...($dated ? ['start_date' => '2026-01-01', 'end_date' => '2026-12-31'] : []),
    ]);

    $this->actingAs($this->user);

    expect(app(RestoreProject::class)->handle($project))->toBe($expected);
    expect($project->fresh()->status)->toBe($expected);
    expect(draftsHoldingDatesWithoutApproval())->toBe([]);
})->with([
    'dated' => [true, ProjectStatus::Closed],
    'dateless' => [false, ProjectStatus::Draft],
]);

test('restore is refused while another open project holds the center and program', function () {
    $archived = Project::factory()->status(ProjectStatus::Archived)->create([
        'center_id' => $this->centerId,
        'city_id' => $this->cityId,
        'program_id' => $this->program->id,
    ]);
    Project::factory()->create([
        'name' => 'Idlib Micro',
        'center_id' => $this->centerId,
        'city_id' => $this->cityId,
        'program_id' => $this->program->id,
    ]);

    $this->actingAs($this->user);

    expect(fn () => app(RestoreProject::class)->handle($archived))->toThrow(ValidationException::class);
    expect($archived->fresh()->status)->toBe(ProjectStatus::Archived);
});

test('restore needs the archive right', function () {
    $archived = Project::factory()->status(ProjectStatus::Archived)->create();

    $this->actingAs(User::factory()->create());

    expect(fn () => app(RestoreProject::class)->handle($archived))->toThrow(AuthorizationException::class);
});

test('restoring a project that is not archived changes nothing', function () {
    $project = Project::factory()->active()->create();

    $this->actingAs($this->user);

    expect(fn () => app(RestoreProject::class)->handle($project))->toThrow(ValidationException::class);
    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('archiving keeps the dates for a later restore', function () {
    $project = Project::factory()->active()->create();

    $this->actingAs($this->user);
    app(ArchiveProject::class)->handle($project);

    expect($project->fresh())->status->toBe(ProjectStatus::Archived)->start_date->not->toBeNull();
});

test('the retire migration maps legacy submitted and approved projects', function () {
    $row = fn (string $status, ?string $start) => Project::factory()->create([
        'status' => $status,
        'start_date' => $start,
        'end_date' => $start === null ? null : '2027-01-01',
    ])->id;

    $ids = [
        'submitted undated' => $row('submitted', null),
        'submitted dated' => $row('submitted', '2026-01-01'),
        'approved undated' => $row('approved', null),
        'approved dated' => $row('approved', '2026-01-01'),
        'active' => $row('active', '2026-01-01'),
        'draft' => $row('draft', null),
    ];

    (require database_path('migrations/2026_10_14_121002_retire_submitted_project_status.php'))->up();

    $status = fn (string $key): string => DB::table('projects')->where('id', $ids[$key])->value('status');

    expect($status('submitted undated'))->toBe('draft')
        ->and($status('submitted dated'))->toBe('active')
        ->and($status('approved undated'))->toBe('draft')
        ->and($status('approved dated'))->toBe('active')
        ->and($status('active'))->toBe('active')
        ->and($status('draft'))->toBe('draft');
    expect(DB::table('projects')->where('status', 'submitted')->count())->toBe(0);
});

test('a draft with dates is only allowed behind a donor-approved proposal', function () {
    $dated = Project::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    $approved = Project::factory()->create(['start_date' => '2026-01-01', 'end_date' => '2026-12-31']);
    Proposal::factory()->create(['status' => ProposalStatus::Approved])->projects()->attach($approved->id);

    expect(draftsHoldingDatesWithoutApproval())->toBe([$dated->id]);
});

test('a draft project page shows the plan message and the no-active-proposal badge', function () {
    $draft = Project::factory()->create();

    Livewire::actingAs($this->user)->test('pages::projects.show', ['project' => $draft])
        ->assertSeeHtml('data-test="no-active-proposal"')
        ->set('tab', 'plan')
        ->assertSeeHtml('data-test="plan-after-approval"')
        ->assertSee('The schedule is activated once the proposal is approved.')
        ->assertDontSeeHtml('data-gantt');

    Proposal::factory()->create(['status' => ProposalStatus::ProposalDraft])->projects()->attach($draft->id);

    Livewire::actingAs($this->user)->test('pages::projects.show', ['project' => $draft])
        ->assertDontSeeHtml('data-test="no-active-proposal"');
});

test('the draft messages read in Arabic', function () {
    app()->setLocale('ar');

    ($this->create)(['startDate' => '2026-11-01'])
        ->assertHasErrors(['startDate'])
        ->assertSee('تُحدَّد تواريخ المشروع عند اعتماد المقترح');

    $draft = Project::factory()->create();

    Livewire::actingAs($this->user)->test('pages::projects.show', ['project' => $draft])
        ->set('tab', 'plan')
        ->assertSee('تُفعَّل الخطة الزمنية بعد اعتماد المقترح')
        ->assertSee('لا يوجد مقترح فعّال');
});
