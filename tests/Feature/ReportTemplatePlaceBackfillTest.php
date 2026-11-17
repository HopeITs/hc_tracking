<?php

declare(strict_types=1);

use App\Console\Commands\PlaceBackfillReportTemplates;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Models\ReportTemplate;
use Illuminate\Support\Facades\DB;

beforeEach(function (): void {
    $this->cityId = insertHrCity('Idlib '.Str::random(4), 'إدلب');
    $this->centerA = insertHrCenter($this->cityId, 'A '.Str::random(4));
    $this->centerB = insertHrCenter($this->cityId, 'B '.Str::random(4));
    $this->backfill = new PlaceBackfillReportTemplates;
});

/**
 * A legacy template: a project with a template and one linked proposal.
 *
 * @param  array<string, mixed>  $project
 * @return array{0: ReportTemplate, 1: Proposal, 2: Project}
 */
function legacyTemplate(int $cityId, array $project = []): array
{
    $project = Project::factory()->approved()->create(['city_id' => $cityId, 'center_id' => null, ...$project]);
    $proposal = Proposal::factory()->create();
    $proposal->projects()->attach($project->id);
    $template = ReportTemplate::factory()->create(['project_id' => $project->id]);

    return [$template, $proposal, $project];
}

/**
 * @param  list<int>  $centers
 */
function legacyRow(Proposal $proposal, Project $project, array $centers = [], bool $cityWide = false): void
{
    $row = ProposalLocation::factory()->create([
        'proposal_id' => $proposal->id,
        'city_id' => $project->city_id,
        'program_id' => $project->program_id,
        'project_id' => $project->id,
        'city_wide' => $cityWide,
    ]);

    foreach ($centers as $center) {
        $row->centers()->create(['center_id' => $center]);
    }
}

it('places a template at its project center and links the proposals', function (): void {
    [$template, $proposal] = legacyTemplate($this->cityId, ['center_id' => $this->centerA]);

    $this->backfill->apply($this->backfill->plan());

    $template->refresh();
    expect($template->center_id)->toBe($this->centerA)
        ->and($template->city_wide)->toBeFalse()
        ->and($template->proposals()->pluck('proposals.id')->all())->toBe([$proposal->id])
        ->and($template->proposals->first()->pivot->joined_at)->not->toBeNull();
});

it('takes the single center of the row when the project has none', function (): void {
    [$template, $proposal, $project] = legacyTemplate($this->cityId);
    legacyRow($proposal, $project, [$this->centerB]);

    $this->backfill->apply($this->backfill->plan());

    expect($template->refresh()->center_id)->toBe($this->centerB);
});

it('marks a template city-wide when its row is city-wide', function (): void {
    [$template, $proposal, $project] = legacyTemplate($this->cityId);
    legacyRow($proposal, $project, [], true);

    $this->backfill->apply($this->backfill->plan());

    $template->refresh();
    expect($template->city_wide)->toBeTrue()->and($template->center_id)->toBeNull();
});

it('leaves a template unset when its rows disagree and lists it', function (): void {
    [$template, $proposal, $project] = legacyTemplate($this->cityId);
    legacyRow($proposal, $project, [$this->centerA]);
    legacyRow(Proposal::factory()->create(), $project, [$this->centerB]);

    $plan = $this->backfill->plan();
    $this->backfill->apply($plan);

    $template->refresh();
    expect($plan[$template->id]['disagree'])->toBeTrue()
        ->and($template->center_id)->toBeNull()
        ->and($template->city_wide)->toBeFalse()
        ->and($template->place()->isUnset())->toBeTrue();
});

it('leaves a template with no row as center not set', function (): void {
    [$template] = legacyTemplate($this->cityId);

    $this->backfill->apply($this->backfill->plan());

    expect($template->refresh()->place()->isUnset())->toBeTrue();
});

it('prints the same counts on a dry run and writes nothing', function (): void {
    [, $proposalA, $projectA] = legacyTemplate($this->cityId, ['center_id' => $this->centerA]);
    [, $proposalB, $projectB] = legacyTemplate($this->cityId);
    legacyRow($proposalB, $projectB, [], true);
    legacyTemplate($this->cityId);

    $this->artisan('report-templates:place-backfill', ['--dry-run' => true])
        ->expectsOutput(__('report_templates.console.backfill_templates', ['count' => 3]))
        ->expectsOutput(__('report_templates.console.backfill_with_center', ['count' => 1]))
        ->expectsOutput(__('report_templates.console.backfill_city_wide', ['count' => 1]))
        ->expectsOutput(__('report_templates.console.backfill_center_not_set', ['count' => 1]))
        ->expectsOutput(__('report_templates.console.backfill_proposals_linked', ['count' => 3]))
        ->expectsOutput(__('report_templates.console.backfill_without_proposal', ['count' => 0]))
        ->assertSuccessful();

    expect(DB::table('proposal_report_template')->count())->toBe(0)
        ->and(DB::table('report_templates')->whereNotNull('center_id')->count())->toBe(0);

    $this->artisan('report-templates:place-backfill')->assertSuccessful();

    expect(DB::table('proposal_report_template')->count())->toBe(3);
});

it('stops before writing when a template has no proposal', function (): void {
    $project = Project::factory()->approved()->create(['city_id' => $this->cityId]);
    ReportTemplate::factory()->create(['project_id' => $project->id]);

    $this->artisan('report-templates:place-backfill')->assertFailed();

    expect(DB::table('proposal_report_template')->count())->toBe(0);
});

it('restores the unique project_id on backfilled data and applies again', function (): void {
    [$template] = legacyTemplate($this->cityId, ['center_id' => $this->centerA]);
    $this->backfill->apply($this->backfill->plan());

    $migration = require database_path('migrations/2026_11_17_093015_require_place_on_report_templates_table.php');

    try {
        $migration->down();

        expect(collect(Schema::getIndexes('report_templates'))->firstWhere('name', 'report_templates_project_id_unique'))->not->toBeNull()
            ->and(DB::table('report_templates')->where('id', $template->id)->value('center_id'))->toBe($this->centerA);
    } finally {
        if (collect(Schema::getIndexes('report_templates'))->firstWhere('name', 'report_templates_project_id_unique') !== null) {
            $migration->up();
        }
    }

    expect(collect(Schema::getIndexes('report_templates'))->firstWhere('name', 'report_templates_place_index'))->not->toBeNull();
});
