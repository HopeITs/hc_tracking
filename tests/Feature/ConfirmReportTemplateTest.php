<?php

use App\Actions\ReportTemplates\ConfirmReportTemplate;
use App\Actions\ReportTemplates\RetireReportTemplateLine;
use App\Actions\ReportTemplates\SaveReportTemplateLine;
use App\Enums\AssignmentStatus;
use App\Enums\CollectionFrequency;
use App\Enums\IndicatorCollector;
use App\Enums\LineSchedule;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\ReportTemplateStatus;
use App\Events\ProjectActivated;
use App\Models\Activity;
use App\Models\EvidenceType;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\MealCoverageAssignment;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| ConfirmReportTemplate, SaveReportTemplateLine and RetireReportTemplateLine
| (contracts.md §12.4, T33). Confirmation activates an approved project only
| through ActivateProject, in the same transaction; on an active project it
| records report_template.lines_added for the owner instead.
*/

beforeEach(fn () => $this->travelTo('2026-10-14 10:00:00'));

/**
 * A pending template with one pending, library-linked monthly line on a
 * dated project with an acknowledged owner and one proposal.
 *
 * @return array{project: Project, proposal: Proposal, template: ReportTemplate, line: ReportTemplateLine}
 */
function rtFixture(ProjectStatus $status = ProjectStatus::Approved, CollectionFrequency $frequency = CollectionFrequency::Monthly): array
{
    // Created while recording: the owner assignment comes from the project's own hooks.
    $project = Project::factory()->approved()->create(['status' => $status, 'start_date' => '2026-11-01', 'end_date' => '2027-10-31']);
    $project->currentAssignment('user_id')?->forceFill(['status' => AssignmentStatus::Acknowledged])->save();

    return Activity::withoutRecording(function () use ($project, $status, $frequency): array {

        $proposal = Proposal::factory()->create([
            'name' => 'Winter WASH',
            'status' => $status === ProjectStatus::Active ? ProposalStatus::Active : ProposalStatus::Approved,
            'actual_date_begin' => '2026-11-01',
            'actual_date_end' => '2027-10-31',
        ]);
        $proposal->projects()->attach($project->id, ['target_beneficiaries' => 100]);

        $template = ReportTemplate::factory()->create(['project_id' => $project->id]);
        $line = rtLine($template, $proposal, $frequency);

        return ['project' => $project, 'proposal' => $proposal, 'template' => $template, 'line' => $line];
    });
}

/**
 * A pending line on the template for a new target of the proposal, linked to
 * a library entry of the given frequency unless $linked is false.
 *
 * @param  array<string, mixed>  $attributes
 */
function rtLine(ReportTemplate $template, Proposal $proposal, CollectionFrequency $frequency = CollectionFrequency::Monthly, bool $linked = true, array $attributes = []): ReportTemplateLine
{
    return Activity::withoutRecording(function () use ($template, $proposal, $frequency, $linked, $attributes): ReportTemplateLine {
        $library = $linked ? LibraryIndicator::factory()->create(['frequency' => $frequency]) : null;
        $indicator = Indicator::factory()->create(['library_indicator_id' => $library?->id]);
        $target = PlanIndicator::factory()->forProposal($proposal)->create(['indicator_id' => $indicator->id, 'project_id' => $template->project_id]);

        return ReportTemplateLine::factory()->create([
            'report_template_id' => $template->id,
            'plan_indicator_id' => $target->id,
            'effective_from' => '2026-11-01',
            'effective_to' => '2027-10-01',
            ...$attributes,
        ]);
    });
}

/**
 * @param  list<int>  $lineIds
 */
function rtConfirm(User $actor, ReportTemplate $template, array $lineIds): ReportTemplate
{
    test()->actingAs($actor);

    return app(ConfirmReportTemplate::class)->handle($template, $lineIds);
}

/**
 * The first validation message, or null when the call went through.
 */
function rtRefusal(callable $call): ?string
{
    try {
        $call();
    } catch (ValidationException $exception) {
        return collect($exception->errors())->flatten()->first();
    }

    return null;
}

/**
 * A complete SaveReportTemplateLine submission for the line, unless overridden.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rtLineInput(ReportTemplateLine $line, array $overrides = []): array
{
    return [
        'schedule' => 'monthly',
        'schedule_months' => [],
        'once_after_months' => null,
        'entered_by' => 'project_team',
        'evidence_type_ids' => [],
        'fixed_denominator' => null,
        'expected_updated_at' => $line->updated_at?->toDateTimeString(),
        ...$overrides,
    ];
}

function rtCoverProject(int $projectId, int $employeeId): void
{
    Activity::withoutRecording(fn () => MealCoverageAssignment::factory()->forProject($projectId)->create(['employee_id' => $employeeId]));
}

test('confirming a line on an approved project activates it through ActivateProject once, after the commit', function () {
    $actor = mealManager();
    ['project' => $project, 'proposal' => $proposal, 'template' => $template, 'line' => $line] = rtFixture();
    $owner = userWithRole('Team Member', $project->user_id);
    Event::fake([ProjectActivated::class]);

    $confirmed = rtConfirm($actor, $template, [$line->id]);

    expect($confirmed->status)->toBe(ReportTemplateStatus::Confirmed)
        ->and($template->fresh()->status)->toBe(ReportTemplateStatus::Confirmed)
        ->and($template->fresh()->confirmed_by)->toBe($actor->id)
        ->and($line->fresh()->status)->toBe(ReportTemplateLineStatus::Confirmed)
        ->and($line->fresh()->confirmed_by)->toBe($actor->id)
        ->and($line->fresh()->confirmed_at)->not->toBeNull()
        ->and($project->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Active)
        ->and(Activity::query()->where('event', 'report_template.confirmed')->count())->toBe(1)
        ->and(Activity::query()->where('event', 'report_template.lines_added')->exists())->toBeFalse()
        ->and(sentencesFor($owner))->toContain($actor->name.' confirmed the report template of “'.$project->name.'”');

    Event::assertDispatchedTimes(ProjectActivated::class, 1);
    Event::assertDispatched(ProjectActivated::class, fn (ProjectActivated $event): bool => $event->projectId === $project->id
        && $event->via === 'template_confirmation');
});

test('a confirmation rolled back by its caller activates nothing and dispatches nothing', function () {
    $actor = mealManager();
    ['project' => $project, 'template' => $template, 'line' => $line] = rtFixture();
    Event::fake([ProjectActivated::class]);

    expect(fn () => DB::transaction(function () use ($actor, $template, $line): void {
        rtConfirm($actor, $template, [$line->id]);

        throw new RuntimeException('caller failed');
    }))->toThrow(RuntimeException::class);

    expect($project->fresh()->status)->toBe(ProjectStatus::Approved)
        ->and($template->fresh()->status)->toBe(ReportTemplateStatus::Pending)
        ->and($line->fresh()->status)->toBe(ReportTemplateLineStatus::Pending);

    Event::assertNotDispatched(ProjectActivated::class);
});

test('confirming no line is refused and writes nothing', function () {
    $actor = mealManager();
    ['project' => $project, 'template' => $template, 'line' => $line] = rtFixture();
    $activities = Activity::query()->count();
    Event::fake([ProjectActivated::class]);

    expect(rtRefusal(fn () => rtConfirm($actor, $template, [])))->toBe(__('report_templates.confirm_at_least_one'))
        ->and($template->fresh()->status)->toBe(ReportTemplateStatus::Pending)
        ->and($line->fresh()->status)->toBe(ReportTemplateLineStatus::Pending)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Approved)
        ->and(Activity::query()->count())->toBe($activities);

    Event::assertNotDispatched(ProjectActivated::class);
});

test('a line whose library frequency has no automatic months is refused on library_default, with the whole batch', function (CollectionFrequency $frequency) {
    $actor = mealManager();
    ['project' => $project, 'proposal' => $proposal, 'template' => $template, 'line' => $monthly] = rtFixture();
    $seasonal = rtLine($template, $proposal, $frequency);

    expect(rtRefusal(fn () => rtConfirm($actor, $template, [$monthly->id, $seasonal->id])))->toBe(__('report_templates.choose_measurement_months'))
        ->and($monthly->fresh()->status)->toBe(ReportTemplateLineStatus::Pending)
        ->and($seasonal->fresh()->status)->toBe(ReportTemplateLineStatus::Pending)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Approved);
})->with([
    'per season' => CollectionFrequency::PerSeason,
    'baseline-endline' => CollectionFrequency::BaselineEndline,
    'one time' => CollectionFrequency::OneTime,
]);

test('a per-season line with chosen months confirms', function () {
    $actor = mealManager();
    ['template' => $template] = rtFixture();
    $proposal = Proposal::query()->firstOrFail();
    $seasonal = rtLine($template, $proposal, CollectionFrequency::PerSeason, attributes: ['schedule' => LineSchedule::SelectedMonths, 'schedule_months' => [3, 9]]);
    Event::fake([ProjectActivated::class]);

    rtConfirm($actor, $template, [$seasonal->id]);

    expect($seasonal->fresh()->status)->toBe(ReportTemplateLineStatus::Confirmed);
});

test('a target with no library link cannot be confirmed', function () {
    $actor = mealManager();
    ['project' => $project, 'proposal' => $proposal, 'template' => $template] = rtFixture();
    $unlinked = rtLine($template, $proposal, linked: false);

    expect(rtRefusal(fn () => rtConfirm($actor, $template, [$unlinked->id])))->toBe(__('report_templates.link_library_first'))
        ->and($unlinked->fresh()->status)->toBe(ReportTemplateLineStatus::Pending)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Approved);
});

test('new lines on an active project tell the owner the proposal and month range, with no activation', function () {
    $actor = mealManager();
    ['project' => $project, 'template' => $template, 'line' => $first] = rtFixture(ProjectStatus::Active);
    Activity::withoutRecording(function () use ($template, $first): void {
        $template->forceFill(['status' => ReportTemplateStatus::Confirmed, 'confirmed_at' => '2026-10-01 09:00:00'])->save();
        $first->forceFill(['status' => ReportTemplateLineStatus::Confirmed, 'confirmed_at' => '2026-10-01 09:00:00'])->save();
    });
    $spring = Activity::withoutRecording(fn () => Proposal::factory()->create(['name' => 'Spring Hygiene', 'status' => ProposalStatus::Approved]));
    $newLine = rtLine($template, $spring, attributes: ['effective_from' => '2027-01-01', 'effective_to' => '2027-06-01']);
    $owner = userWithRole('Team Member', $project->user_id);
    Event::fake([ProjectActivated::class]);

    rtConfirm($actor, $template, [$newLine->id]);

    $activity = Activity::query()->where('event', 'report_template.lines_added')->sole();

    expect($newLine->fresh()->status)->toBe(ReportTemplateLineStatus::Confirmed)
        ->and($template->fresh()->confirmed_at->toDateTimeString())->toBe('2026-10-01 09:00:00')
        ->and($project->fresh()->status)->toBe(ProjectStatus::Active)
        ->and($activity->properties['proposal_ids'])->toBe([$spring->id])
        ->and($activity->properties['from_month'])->toBe('2027-01')
        ->and($activity->properties['to_month'])->toBe('2027-06')
        ->and(sentencesFor($owner))->toContain($actor->name.' added indicators for “Spring Hygiene” to the report template of “'.$project->name.'”, from January 2027 to June 2027');

    Event::assertNotDispatched(ProjectActivated::class);
});

test('a MEAL user who is not responsible for the project is refused', function () {
    holdMealManagerSlot();
    $outsider = userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Rami'));
    ['project' => $project, 'template' => $template, 'line' => $line] = rtFixture();
    $this->actingAs($outsider);

    expect(fn () => app(ConfirmReportTemplate::class)->handle($template, [$line->id]))->toThrow(AuthorizationException::class);

    expect($line->fresh()->status)->toBe(ReportTemplateLineStatus::Pending)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Approved);
});

test('the officer assigned to the project is allowed though based in another governorate', function () {
    holdMealManagerSlot();
    $officer = userWithRole('MEAL', insertHrEmployee(insertHrCity('Homs', 'حمص'), 'Nour', 'Ali'));
    ['project' => $project, 'template' => $template, 'line' => $line] = rtFixture();
    rtCoverProject($project->id, $officer->employee_id);
    Event::fake([ProjectActivated::class]);

    rtConfirm($officer, $template, [$line->id]);

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('the MEAL Manager slot holder is allowed on a project another officer covers', function () {
    $manager = mealManager();
    ['project' => $project, 'template' => $template, 'line' => $line] = rtFixture();
    rtCoverProject($project->id, insertHrEmployee(firstNameEn: 'Nour'));
    Event::fake([ProjectActivated::class]);

    rtConfirm($manager, $template, [$line->id]);

    expect($project->fresh()->status)->toBe(ProjectStatus::Active);
});

test('confirming the same lines twice activates once; the second call is stale', function () {
    $actor = mealManager();
    ['template' => $template, 'line' => $line] = rtFixture();
    Event::fake([ProjectActivated::class]);

    rtConfirm($actor, $template, [$line->id]);

    expect(rtRefusal(fn () => rtConfirm($actor, $template, [$line->id])))->toBe(__('report_templates.stale'));

    Event::assertDispatchedTimes(ProjectActivated::class, 1);
});

test('a line id from another template rejects the whole batch', function () {
    $actor = mealManager();
    ['project' => $project, 'template' => $template, 'line' => $line] = rtFixture();
    ['line' => $foreign] = rtFixture();

    expect(fn () => rtConfirm($actor, $template, [$line->id, $foreign->id]))->toThrow(ModelNotFoundException::class);

    expect($line->fresh()->status)->toBe(ReportTemplateLineStatus::Pending)
        ->and($foreign->fresh()->status)->toBe(ReportTemplateLineStatus::Pending)
        ->and($project->fresh()->status)->toBe(ProjectStatus::Approved);
});

test('saving a line stores its schedule, collector, evidence and denominator', function () {
    $actor = mealManager();
    ['template' => $template, 'line' => $line] = rtFixture();
    $evidence = EvidenceType::factory()->create();
    $this->actingAs($actor);

    $saved = app(SaveReportTemplateLine::class)->handle($template, $line->id, rtLineInput($line, [
        'schedule' => 'selected_months',
        'schedule_months' => [9, 3, 9],
        'entered_by' => 'meal',
        'evidence_type_ids' => [$evidence->id],
        'fixed_denominator' => 250,
    ]));

    $fresh = $line->fresh();

    expect($saved->id)->toBe($line->id)
        ->and($fresh->schedule)->toBe(LineSchedule::SelectedMonths)
        ->and($fresh->schedule_months)->toBe([3, 9])
        ->and($fresh->once_after_months)->toBeNull()
        ->and($fresh->entered_by)->toBe(IndicatorCollector::Meal)
        ->and($fresh->fixed_denominator)->toBe(250)
        ->and($fresh->status)->toBe(ReportTemplateLineStatus::Pending)
        ->and($fresh->evidenceTypes()->pluck('evidence_types.id')->all())->toBe([$evidence->id]);
});

test('an invalid line is refused with a translated message', function (array $overrides, string $message) {
    $actor = mealManager();
    ['template' => $template, 'line' => $line] = rtFixture();
    $inactive = EvidenceType::factory()->create(['name_ar' => 'قديم']);
    $inactive->forceFill(['is_active' => false])->save();
    $this->actingAs($actor);

    $overrides = array_map(fn (mixed $value): mixed => $value === 'INACTIVE' ? [$inactive->id] : $value, $overrides);

    expect(rtRefusal(fn () => app(SaveReportTemplateLine::class)->handle($template, $line->id, rtLineInput($line, $overrides))))->toBe(__($message))
        ->and($line->fresh()->schedule)->toBe(LineSchedule::LibraryDefault);
})->with([
    'unknown schedule' => [['schedule' => 'weekly'], 'report_templates.schedule_invalid'],
    'selected months without months' => [['schedule' => 'selected_months', 'schedule_months' => []], 'report_templates.schedule_months_invalid'],
    'month 13' => [['schedule' => 'selected_months', 'schedule_months' => [13]], 'report_templates.schedule_months_invalid'],
    'once after zero months' => [['schedule' => 'once_after_months', 'once_after_months' => 0], 'report_templates.once_after_months_invalid'],
    'once after with no number' => [['schedule' => 'once_after_months', 'once_after_months' => null], 'report_templates.once_after_months_invalid'],
    'unknown collector' => [['entered_by' => 'donor'], 'report_templates.entered_by_invalid'],
    'inactive evidence type' => [['evidence_type_ids' => 'INACTIVE'], 'report_templates.evidence_types_invalid'],
    'zero denominator' => [['fixed_denominator' => 0], 'report_templates.fixed_denominator_invalid'],
]);

test('a confirmed seasonal line cannot go back to library_default', function () {
    $actor = mealManager();
    ['template' => $template, 'proposal' => $proposal] = rtFixture();
    $seasonal = rtLine($template, $proposal, CollectionFrequency::PerSeason, attributes: ['schedule' => LineSchedule::SelectedMonths, 'schedule_months' => [3]]);
    Activity::withoutRecording(fn () => $seasonal->forceFill(['status' => ReportTemplateLineStatus::Confirmed])->save());
    $this->actingAs($actor);

    expect(rtRefusal(fn () => app(SaveReportTemplateLine::class)->handle($template, $seasonal->id, rtLineInput($seasonal->fresh(), ['schedule' => 'library_default']))))
        ->toBe(__('report_templates.choose_measurement_months'));
});

test('saving a line changed since it was read, or a retired line, is stale', function (string $case) {
    $actor = mealManager();
    ['template' => $template, 'line' => $line] = rtFixture();
    $input = rtLineInput($line);

    if ($case === 'changed') {
        $input['expected_updated_at'] = '2026-10-14 09:00:00';
    } else {
        Activity::withoutRecording(fn () => $line->forceFill(['status' => ReportTemplateLineStatus::Retired])->save());
    }

    $this->actingAs($actor);

    expect(rtRefusal(fn () => app(SaveReportTemplateLine::class)->handle($template, $line->id, $input)))->toBe(__('report_templates.stale'))
        ->and($line->fresh()->schedule)->toBe(LineSchedule::LibraryDefault);
})->with(['changed', 'retired']);

test('saving a line of another template is not found, and a non-responsible user is refused', function () {
    $actor = mealManager();
    ['template' => $template] = rtFixture();
    ['line' => $foreign] = rtFixture();
    $this->actingAs($actor);

    expect(fn () => app(SaveReportTemplateLine::class)->handle($template, $foreign->id, rtLineInput($foreign)))->toThrow(ModelNotFoundException::class);

    $this->actingAs(userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Rami')));

    expect(fn () => app(SaveReportTemplateLine::class)->handle($template, $template->lines()->value('id'), rtLineInput($foreign)))->toThrow(AuthorizationException::class);
});

test('retiring a confirmed line stops it from the current month and keeps the row', function () {
    $actor = mealManager();
    ['template' => $template, 'line' => $line] = rtFixture();
    Activity::withoutRecording(fn () => $line->forceFill(['status' => ReportTemplateLineStatus::Confirmed])->save());
    $this->actingAs($actor);

    app(RetireReportTemplateLine::class)->handle($template, $line->id);

    $row = DB::table('report_template_lines')->find($line->id);

    expect($row)->not->toBeNull()
        ->and($row->status)->toBe('retired')
        ->and($row->effective_to)->toBe('2026-10-01');
});

test('only a confirmed line can be retired', function (ReportTemplateLineStatus $status) {
    $actor = mealManager();
    ['template' => $template, 'line' => $line] = rtFixture();
    Activity::withoutRecording(fn () => $line->forceFill(['status' => $status])->save());
    $this->actingAs($actor);

    expect(rtRefusal(fn () => app(RetireReportTemplateLine::class)->handle($template, $line->id)))->toBe(__('report_templates.stale'))
        ->and($line->fresh()->effective_to->toDateString())->toBe('2027-10-01');
})->with([
    'pending' => ReportTemplateLineStatus::Pending,
    'retired' => ReportTemplateLineStatus::Retired,
]);

test('retiring is refused to a non-responsible user and for another template\'s line', function () {
    $manager = mealManager();
    ['template' => $template, 'line' => $line] = rtFixture();
    ['line' => $foreign] = rtFixture();
    Activity::withoutRecording(fn () => $line->forceFill(['status' => ReportTemplateLineStatus::Confirmed])->save());

    $this->actingAs($manager);
    expect(fn () => app(RetireReportTemplateLine::class)->handle($template, $foreign->id))->toThrow(ModelNotFoundException::class);

    $this->actingAs(userWithRole('MEAL', insertHrEmployee(firstNameEn: 'Rami')));
    expect(fn () => app(RetireReportTemplateLine::class)->handle($template, $line->id))->toThrow(AuthorizationException::class);

    expect($line->fresh()->status)->toBe(ReportTemplateLineStatus::Confirmed);
});
