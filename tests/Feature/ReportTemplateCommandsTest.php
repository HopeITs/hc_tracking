<?php

use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\ReportTemplateStatus;
use App\Enums\ResultType;
use App\Events\ProjectActivated;
use App\Models\Activity;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\NarrativeQuestion;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\Result;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

describe('annual-plans:ensure-2026', function () {
    test('creates the missing 2026 plan of every managed program once, copying the program manager', function () {
        $first = Program::factory()->create();
        $second = Program::factory()->create();
        $older = AnnualPlan::factory()->create(['program_id' => $first->id, 'year' => 2025]);
        $olderBefore = DB::table('annual_plans')->find($older->id);

        $this->artisan('annual-plans:ensure-2026')
            ->expectsOutputToContain('Program '.$first->id.': 2026 plan created, manager '.$first->manager_id.'.')
            ->expectsOutputToContain('Program '.$second->id.': 2026 plan created, manager '.$second->manager_id.'.')
            ->expectsOutputToContain('2 created, 0 already there, 0 skipped (no manager).')
            ->doesntExpectOutputToContain('Dry run')
            ->assertSuccessful();

        expect(DB::table('annual_plans')->where('year', 2026)->orderBy('program_id')->get(['program_id', 'manager_id'])->map(fn (object $row): array => (array) $row)->all())
            ->toBe([
                ['program_id' => $first->id, 'manager_id' => $first->manager_id],
                ['program_id' => $second->id, 'manager_id' => $second->manager_id],
            ])
            ->and(DB::table('annual_plans')->find($older->id))->toEqual($olderBefore);
    });

    test('a second run creates nothing', function () {
        Program::factory()->count(2)->create();

        $this->artisan('annual-plans:ensure-2026')->assertSuccessful();
        $before = DB::table('annual_plans')->orderBy('id')->get()->toArray();

        $this->artisan('annual-plans:ensure-2026')
            ->expectsOutputToContain('0 created, 2 already there, 0 skipped (no manager).')
            ->assertSuccessful();

        expect(DB::table('annual_plans')->orderBy('id')->get()->toArray())->toEqual($before);
    });

    test('a dry run lists what would be created and writes nothing', function () {
        $program = Program::factory()->create();
        $activitiesBefore = DB::table('activities')->count();

        $this->artisan('annual-plans:ensure-2026 --dry-run')
            ->expectsOutputToContain('Program '.$program->id.': 2026 plan created, manager '.$program->manager_id.'.')
            ->expectsOutputToContain('1 created, 0 already there, 0 skipped (no manager).')
            ->expectsOutputToContain('Dry run: nothing was written.')
            ->assertSuccessful();

        expect(DB::table('annual_plans')->count())->toBe(0)
            ->and(DB::table('activities')->count())->toBe($activitiesBefore);
    });

    test('a program without a manager is skipped and listed by id', function () {
        $managed = Program::factory()->create();
        $unmanaged = Program::factory()->unassigned()->create();

        $this->artisan('annual-plans:ensure-2026')
            ->expectsOutputToContain('Skipped, no program manager: programs '.$unmanaged->id.'.')
            ->expectsOutputToContain('1 created, 0 already there, 1 skipped (no manager).')
            ->assertExitCode(0);

        expect(DB::table('annual_plans')->where('year', 2026)->pluck('program_id')->all())->toBe([$managed->id]);
    });

    test('an existing 2026 plan is left as it is', function () {
        $program = Program::factory()->create();
        $existing = AnnualPlan::factory()->create(['program_id' => $program->id, 'year' => 2026]);
        $before = DB::table('annual_plans')->find($existing->id);

        $this->artisan('annual-plans:ensure-2026')
            ->doesntExpectOutputToContain('Program '.$program->id.':')
            ->expectsOutputToContain('0 created, 1 already there, 0 skipped (no manager).')
            ->assertSuccessful();

        expect(DB::table('annual_plans')->count())->toBe(1)
            ->and(DB::table('annual_plans')->find($existing->id))->toEqual($before);
    });

    test('a real run writes no activity rows', function () {
        Program::factory()->count(2)->create();
        $activities = Activity::query()->count();

        $this->artisan('annual-plans:ensure-2026')->assertSuccessful();

        expect(DB::table('annual_plans')->where('year', 2026)->count())->toBe(2)
            ->and(Activity::query()->count())->toBe($activities);
    });
});

describe('report-templates:prefill-active', function () {
    beforeEach(function () {
        $this->travelTo('2026-10-16 10:00:00');

        $this->program = Program::factory()->create();
        $this->active = Project::factory()->active()->create(['program_id' => $this->program->id]);
        $this->approved = Project::factory()->approved()->create(['program_id' => $this->program->id]);
        $this->closed = Project::factory()->closed()->create(['program_id' => $this->program->id]);

        $this->proposal = Proposal::factory()->create(['status' => ProposalStatus::Approved, 'actual_date_begin' => '2026-01-10', 'actual_date_end' => '2027-06-20']);
        $this->proposal->projects()->attach([
            $this->active->id => ['target_beneficiaries' => null],
            $this->approved->id => ['target_beneficiaries' => 40],
            $this->closed->id => ['target_beneficiaries' => 40],
        ]);
        $this->output = Result::factory()->create(['type' => ResultType::Output, 'program_id' => $this->program->id, 'proposal_id' => $this->proposal->id]);

        $this->target = function (Project $project, ?array $library = []): PlanIndicator {
            $entry = $library === null ? null : LibraryIndicator::factory()->create($library);
            $indicator = Indicator::factory()->create(['result_id' => $this->output->id, 'library_indicator_id' => $entry?->id]);

            return PlanIndicator::factory()->forProposal($this->proposal)->create(['indicator_id' => $indicator->id, 'project_id' => $project->id]);
        };

        $this->fixedBasis = ['denominator_basis' => 'fixed', 'measure' => 'percentage', 'unit' => null];
        $this->fixed = ($this->target)($this->active, $this->fixedBasis);
        $this->count = ($this->target)($this->active);
        $this->unlinked = ($this->target)($this->active, null);
        ($this->target)($this->approved);
        ($this->target)($this->closed);

        $this->row = fn (Project $project, string $template, int $created, int $skipped, string $unlinked): string => __('report_templates.console.prefill_row', [
            'project' => $project->id,
            'template' => __('report_templates.console.template_'.$template),
            'created' => $created,
            'skipped' => $skipped,
            'unlinked' => $unlinked,
        ]);
    });

    test('creates a pending template with the bank questions and pending lines for active projects only, without activating anything', function () {
        Event::fake([ProjectActivated::class]);
        NarrativeQuestion::factory()->monthly()->create();
        $questions = NarrativeQuestion::query()->active()->count();
        $proposalStatus = $this->proposal->status;

        $this->artisan('report-templates:prefill-active')
            ->expectsOutputToContain(($this->row)($this->active, 'new', 2, 0, (string) $this->unlinked->id))
            ->assertSuccessful();

        $template = ReportTemplate::query()->sole();
        expect($template->project_id)->toBe($this->active->id)
            ->and($template->status)->toBe(ReportTemplateStatus::Pending)
            ->and(DB::table('report_template_questions')->where('report_template_id', $template->id)->count())->toBe($questions)
            ->and(ReportTemplateLine::query()->orderBy('plan_indicator_id')->pluck('plan_indicator_id')->all())->toBe([$this->fixed->id, $this->count->id])
            ->and(ReportTemplateLine::query()->pluck('status')->unique()->all())->toBe([ReportTemplateLineStatus::Pending])
            ->and($this->active->fresh()->status)->toBe(ProjectStatus::Active)
            ->and($this->approved->fresh()->status)->toBe(ProjectStatus::Approved)
            ->and($this->closed->fresh()->status)->toBe(ProjectStatus::Closed)
            ->and($this->proposal->fresh()->status)->toBe($proposalStatus);

        Event::assertNotDispatched(ProjectActivated::class);
    });

    test('a second run creates nothing and counts the lines already there', function () {
        $this->artisan('report-templates:prefill-active')->assertSuccessful();
        $counts = [ReportTemplate::query()->count(), ReportTemplateLine::query()->count(), DB::table('report_template_questions')->count()];

        $this->artisan('report-templates:prefill-active')
            ->expectsOutputToContain(($this->row)($this->active, 'existing', 0, 2, (string) $this->unlinked->id))
            ->assertSuccessful();

        expect([ReportTemplate::query()->count(), ReportTemplateLine::query()->count(), DB::table('report_template_questions')->count()])->toBe($counts);
    });

    test('a real run writes no activity rows', function () {
        $activities = Activity::query()->count();

        $this->artisan('report-templates:prefill-active')->assertSuccessful();

        expect(ReportTemplate::query()->count())->toBe(1)
            ->and(Activity::query()->count())->toBe($activities);
    });

    test('--dry-run writes nothing yet prints what would be created and the fixed-basis targets missing a denominator', function () {
        $counts = fn (): array => [ReportTemplate::query()->count(), ReportTemplateLine::query()->count(), Activity::query()->count(), DB::table('notifications')->count()];
        $before = $counts();

        $this->artisan('report-templates:prefill-active', ['--dry-run' => true])
            ->expectsOutputToContain(($this->row)($this->active, 'new', 2, 0, (string) $this->unlinked->id))
            ->expectsOutputToContain(__('report_templates.console.missing_targets', ['ids' => $this->fixed->id]))
            ->expectsOutputToContain(__('report_templates.console.dry_run'))
            ->assertSuccessful();

        expect($counts())->toBe($before);
    });

    test('a fixed-basis line with no target beneficiaries is listed by line id, also on a later dry run', function () {
        $this->artisan('report-templates:prefill-active')->assertSuccessful();
        $line = ReportTemplateLine::query()->where('plan_indicator_id', $this->fixed->id)->sole();

        $this->artisan('report-templates:prefill-active')
            ->expectsOutputToContain(__('report_templates.console.missing_lines', ['ids' => $line->id]))
            ->assertSuccessful();

        $this->artisan('report-templates:prefill-active', ['--dry-run' => true])
            ->expectsOutputToContain(__('report_templates.console.missing_lines', ['ids' => $line->id]))
            ->doesntExpectOutputToContain(__('report_templates.console.missing_none'))
            ->assertSuccessful();
    });

    test('with target beneficiaries set, no line is listed and "none" shows in both modes', function () {
        $this->proposal->projects()->updateExistingPivot($this->active->id, ['target_beneficiaries' => 120]);

        $this->artisan('report-templates:prefill-active', ['--dry-run' => true])
            ->expectsOutputToContain(__('report_templates.console.missing_none'))
            ->assertSuccessful();

        $this->artisan('report-templates:prefill-active')
            ->expectsOutputToContain(__('report_templates.console.missing_none'))
            ->assertSuccessful();

        expect(ReportTemplateLine::query()->where('plan_indicator_id', $this->fixed->id)->sole()->fixed_denominator)->toBe(120);
    });
});
