<?php

use App\Actions\Proposals\ApproveProposalChange;
use App\Actions\Proposals\RequestProposalChange;
use App\Models\Activity;
use App\Models\MonthlyReport;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalChangeRequest;
use App\Models\ProposalLocation;
use App\Models\ReportTemplate;
use App\Support\Reporting\ReportPlace;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/*
| Y-09 (D-89, D-89a): a change request that moves or removes a center is
| applied only on approval, and is refused when the center already reports
| (a submitted or validated monthly report on a template of this proposal at
| that place), at request and again at approval. No slot is held here, so
| both reviews are born skipped and the PR Manager can decide at once.
*/

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->manager = userWithRole('Public Relations Manager');
    $this->actingAs($this->manager);

    $this->city = insertHrCity('Aleppo', 'حلب');
    $this->centerA = insertHrCenter($this->city, 'Center A', 'المركز أ');
    $this->centerB = insertHrCenter($this->city, 'Center B', 'المركز ب');
    $this->responsible = insertHrEmployee($this->city);
    $this->program = Program::factory()->create();

    $this->proposal = Proposal::factory()->running()->create();
    $this->project = Project::factory()->approved()->create(['city_id' => $this->city, 'program_id' => $this->program->id, 'center_id' => $this->centerA]);
    $this->row = ProposalLocation::factory()->create([
        'proposal_id' => $this->proposal->id,
        'city_id' => $this->city,
        'program_id' => $this->program->id,
        'responsible_employee_id' => $this->responsible,
        'project_id' => $this->project->id,
        'sort' => 0,
    ]);
    $this->row->centers()->create(['center_id' => $this->centerA]);

    $this->template = ReportTemplate::factory()
        ->forPlace(new ReportPlace($this->city, $this->program->id, $this->centerA, false), $this->proposal)
        ->create(['project_id' => $this->project->id]);
});

/**
 * The proposal's only row as the form sends it, with overrides.
 *
 * @param  array<string, mixed>  $overrides
 * @return array{locations: list<array<string, mixed>>}
 */
function locationChange(ProposalLocation $row, array $overrides): array
{
    return ['locations' => [[
        'city_id' => $row->city_id,
        'program_id' => $row->program_id,
        'responsible_employee_id' => $row->responsible_employee_id,
        'center_ids' => $row->fresh()->centerIds(),
        'is_new' => false,
        'city_wide' => false,
        ...$overrides,
    ]]];
}

function requestLocationChange(Proposal $proposal, array $data): ProposalChangeRequest
{
    return app(RequestProposalChange::class)->handle($proposal, $data, 'The donor moved the activity to another center.');
}

/**
 * @return array<string, list<string>>
 */
function refusedChange(Closure $call): array
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('The change was not refused.');
}

test('a center change is applied only on approval', function () {
    $request = requestLocationChange($this->proposal, locationChange($this->row, ['center_ids' => [$this->centerB]]));

    expect($this->row->fresh()->centerIds())->toBe([$this->centerA])
        ->and($request->fresh()->changes)->toEqual([[
            'field' => 'locations',
            'row' => ['city_id' => $this->city, 'program_id' => $this->program->id],
            'from' => ['responsible_employee_id' => $this->responsible, 'center_ids' => [$this->centerA], 'city_wide' => false, 'is_new' => false],
            'to' => ['responsible_employee_id' => $this->responsible, 'center_ids' => [$this->centerB], 'city_wide' => false, 'is_new' => false],
        ]]);

    app(ApproveProposalChange::class)->handle($request);

    expect($this->row->fresh()->centerIds())->toBe([$this->centerB])
        ->and($request->fresh()->status->value)->toBe('approved');
});

test('a center whose template has a submitted report is refused at request', function (string $state) {
    MonthlyReport::factory()->{$state}()->create(['project_id' => $this->project->id]);

    $errors = refusedChange(fn () => requestLocationChange($this->proposal, locationChange($this->row, ['center_ids' => [$this->centerB]])));

    expect($errors['locations'])->toBe([__('proposals.location_center_reports')])
        ->and(ProposalChangeRequest::query()->count())->toBe(0)
        ->and($this->row->fresh()->centerIds())->toBe([$this->centerA]);
})->with(['submitted', 'validated']);

test('a report submitted between the request and the approval refuses the approval', function () {
    $request = requestLocationChange($this->proposal, locationChange($this->row, ['center_ids' => [$this->centerB]]));
    MonthlyReport::factory()->submitted()->create(['project_id' => $this->project->id]);

    $errors = refusedChange(fn () => app(ApproveProposalChange::class)->handle($request));

    expect($errors['locations'])->toBe([__('proposals.location_center_reports')])
        ->and($request->fresh()->status->value)->toBe('pending')
        ->and($this->row->fresh()->centerIds())->toBe([$this->centerA]);
});

test('a center with only draft reports is applied and its template is named for MEAL, not moved', function () {
    MonthlyReport::factory()->create(['project_id' => $this->project->id]);

    $request = requestLocationChange($this->proposal, locationChange($this->row, ['center_ids' => [$this->centerB]]));
    app(ApproveProposalChange::class)->handle($request);

    $activity = Activity::query()->where('event', 'proposal.change_approved')->sole();

    expect($this->row->fresh()->centerIds())->toBe([$this->centerB])
        ->and($activity->properties['templates'])->toBe([$this->template->id])
        ->and($this->template->fresh()->center_id)->toBe($this->centerA);
});

test('a row turns city-wide through an approved change', function () {
    $request = requestLocationChange($this->proposal, locationChange($this->row, ['center_ids' => [], 'city_wide' => true]));
    app(ApproveProposalChange::class)->handle($request);

    $row = $this->row->fresh();

    expect($row->city_wide)->toBeTrue()
        ->and($row->centerIds())->toBe([])
        ->and(DB::table('proposal_locations')->where('proposal_id', $this->proposal->id)->count())->toBe(1);
});

test('a row that carries a project cannot move to another program', function () {
    $other = Program::factory()->create();

    $errors = refusedChange(fn () => requestLocationChange($this->proposal, locationChange($this->row, ['program_id' => $other->id])));

    expect($errors['locations'])->toBe([__('proposals.location_row_has_project')])
        ->and(ProposalChangeRequest::query()->count())->toBe(0);
});

test('a responsible-only change on a reporting center is not refused', function () {
    MonthlyReport::factory()->submitted()->create(['project_id' => $this->project->id]);
    $other = insertHrEmployee($this->city, 'Rami', 'Nasser', 'رامي', 'ناصر');

    $request = requestLocationChange($this->proposal, locationChange($this->row, ['responsible_employee_id' => $other]));
    app(ApproveProposalChange::class)->handle($request);

    expect($this->row->fresh()->responsible_employee_id)->toBe($other);
});
