<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\DenominatorBasis;
use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Enums\ProposalStatus;
use App\Enums\ReportTemplateLineStatus;
use App\Enums\TemplatePlacementStatus;
use App\Models\AnnualPlan;
use App\Models\LibraryIndicator;
use App\Models\NarrativeQuestion;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\ReportTemplateLine;
use App\Models\ReportTemplatePlacement;
use App\Models\User;
use App\Notifications\ActivityRecorder;
use App\Notifications\AppNotification;
use App\Notifications\EmployeeRecipients;
use App\Notifications\EventCatalog;
use App\Notifications\NotificationPayload;
use App\Notifications\RecipientFilter;
use App\Support\Reporting\PlaceBridge;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Pre-fills monthly report templates as `pending` (contracts.md §12.4, T33,
 * Y-02, D-41, D-44, D-45, D-75, D-76, D-87, D-90): one template per place of
 * the proposal (PlaceBridge::placesOfProposal), linked to the proposal, the
 * active bank questions copied when it is created, and one pending line per
 * library-linked target that covers the place and has none yet. A place with
 * no line to plan gets no template, so the MEAL queue never holds one it
 * cannot confirm. Lines already there and the template's status are not
 * touched, so a confirmed template stays confirmed and MEAL confirms the new
 * lines; the one exception is a pending `fixed`-basis line created without a
 * `fixed_denominator`, which takes the `target_beneficiaries` filled since
 * (T27 step 8a).
 *
 * Y-04: an approval at an occupied place waits for MEAL to choose an
 * existing template or a separate one. A free place gets its first template.
 * A decided Join is never re-filled with targets that MEAL left out. A self-funded proposal (internal donor) is pre-filled like any
 * other (D-87).
 *
 * A shared target (D-90: one target of a row with several centers) gets one
 * line in each center's template. A shared target on a `fixed` basis would
 * pool one denominator twice, so it gets no line and is listed in the result
 * for Dev 1.
 *
 * Targets are the proposal's own and the annual-plan targets of institutional
 * `HC.*` library entries of the projects its rows carry. Line defaults:
 * `library_default` schedule, `entered_by` = the library `collected_by`,
 * `fixed_denominator` = `project_proposal.target_beneficiaries` when the
 * library basis is `fixed`, no evidence types, and the window = the months of
 * the proposal's dates (actual once both are set, else planned) or, for an
 * `HC.*` target, the plan year cut to those dates.
 *
 * A system writer, so there is no Gate: it runs from the queue after a donor
 * approval (PrefillReportTemplates) and from the launch command
 * (`report-templates:prefill-active`), where no user acts. It never writes a
 * project or proposal status. Idempotent: the proposal row is locked before
 * the proposal's templates are compared, so concurrent runs serialise, and
 * the unique (template, target) index backs the line check.
 */
final class ReportTemplatePrefiller
{
    public const PendingEvent = 'report_template.pending';

    public function __construct(
        private EmployeeRecipients $employees,
        private RecipientFilter $filter,
        private EventCatalog $catalog,
        private ActivityRecorder $activities,
        private PlaceBridge $places,
    ) {}

    /**
     * Pre-fill every place of the proposal. A proposal the donor has not
     * approved pre-fills nothing.
     */
    public function prefillForProposal(Proposal $proposal): PrefillResult
    {
        if (! $proposal->status->isApprovedByDonor()) {
            return new PrefillResult;
        }

        return $this->prefillPlaces($proposal, $this->places->placesOfProposal($proposal));
    }

    /**
     * Pre-fill the places of the project's rows, for each of its
     * donor-approved proposals (the launch command, one project at a time).
     */
    public function prefillProject(int $projectId): PrefillResult
    {
        $result = new PrefillResult;

        foreach ($this->approvedProposals($projectId) as $proposal) {
            $result = $result->merge($this->prefillPlaces($proposal, $this->places->placesOfProject($proposal, $projectId)));
        }

        return $result;
    }

    /**
     * What prefillProject() would do, read-only: whether the project's
     * places have or would get a template and whether any is new, the lines
     * already on them, the targets that would get a new line, the lines
     * whose `fixed_denominator` would be filled, the unlinked targets and the
     * would-be-new `fixed`-basis lines with no `fixed_denominator` (as target
     * ids, since no line exists yet). Writes nothing
     * (`report-templates:prefill-active --dry-run`).
     *
     * @return array{hasTemplate: bool, createsTemplate: bool, existingLineCount: int, newLinePlanIndicatorIds: list<int>, refreshedLineIds: list<int>, unlinkedPlanIndicatorIds: list<int>, missingFixedDenominatorPlanIndicatorIds: list<int>, sharedFixedPlanIndicatorIds: list<int>}
     */
    public function previewProject(int $projectId): array
    {
        $preview = [
            'hasTemplate' => false,
            'createsTemplate' => false,
            'existingLineCount' => 0,
            'newLinePlanIndicatorIds' => [],
            'refreshedLineIds' => [],
            'unlinkedPlanIndicatorIds' => [],
            'missingFixedDenominatorPlanIndicatorIds' => [],
            'sharedFixedPlanIndicatorIds' => [],
        ];

        $countedTemplateIds = [];
        foreach ($this->approvedProposals($projectId) as $proposal) {
            foreach ($this->places->placesOfProject($proposal, $projectId) as $place) {
                ['lines' => $lines, 'unlinked' => $unlinked, 'sharedFixed' => $sharedFixed] = $this->plannedLines($proposal, $place);
                $preview['sharedFixedPlanIndicatorIds'] = [...$preview['sharedFixedPlanIndicatorIds'], ...$sharedFixed];

                $template = $this->existingTemplate($proposal, $place);
                $existing = $template === null ? new Collection : $template->lines()->get()->keyBy(fn (ReportTemplateLine $line): int => (int) $line->plan_indicator_id);
                if ($template !== null && ! in_array($template->id, $countedTemplateIds, true)) {
                    $preview['existingLineCount'] += $existing->count();
                    $countedTemplateIds[] = $template->id;
                }
                $preview['hasTemplate'] = $preview['hasTemplate'] || $template !== null;
                $placement = $place->isUnset() ? null : ReportTemplatePlacement::query()
                    ->where('proposal_id', $proposal->id)->atPlace($place)->first();

                if ($placement?->status === TemplatePlacementStatus::Pending
                    || ($placement === null && $this->needsPlacementChoice($proposal, $place, $template))) {
                    continue;
                }
                if ($placement?->status === TemplatePlacementStatus::Joined) {
                    $lines = array_intersect_key($lines, $existing->all());
                }
                $new = [];

                foreach ($lines as $planIndicatorId => $attributes) {
                    $line = $existing->get($planIndicatorId);

                    if ($line !== null) {
                        if ($this->refreshesDenominator($line, $attributes)) {
                            $preview['refreshedLineIds'][] = $line->id;
                        }

                        continue;
                    }

                    $new[] = $planIndicatorId;

                    if ($attributes['fixed'] && $attributes['values']['fixed_denominator'] === null) {
                        $preview['missingFixedDenominatorPlanIndicatorIds'][] = $planIndicatorId;
                    }
                }

                $preview['hasTemplate'] = $preview['hasTemplate'] || $template !== null || $new !== [];
                $preview['createsTemplate'] = $preview['createsTemplate'] || ($template === null && $new !== []);
                $preview['newLinePlanIndicatorIds'] = [...$preview['newLinePlanIndicatorIds'], ...$new];
                $preview['unlinkedPlanIndicatorIds'] = [...$preview['unlinkedPlanIndicatorIds'], ...$unlinked];
            }
        }

        $preview['newLinePlanIndicatorIds'] = array_values(array_unique($preview['newLinePlanIndicatorIds']));
        $preview['sharedFixedPlanIndicatorIds'] = array_values(array_unique($preview['sharedFixedPlanIndicatorIds']));
        $preview['unlinkedPlanIndicatorIds'] = array_values(array_unique($preview['unlinkedPlanIndicatorIds']));

        return $preview;
    }

    /**
     * The project's donor-approved proposals.
     *
     * @return Collection<int, Proposal>
     */
    private function approvedProposals(int $projectId): Collection
    {
        return new Collection($this->places->proposalsOfProject($projectId, array_map(
            fn (ProposalStatus $status): string => $status->value,
            ProposalStatus::approvedByDonor(),
        ))->all());
    }

    /**
     * @param  list<ReportPlace>  $places
     */
    private function prefillPlaces(Proposal $proposal, array $places): PrefillResult
    {
        $result = new PrefillResult;

        foreach ($places as $place) {
            $result = $result->merge($this->prefill($proposal, $place));
        }

        return $result;
    }

    private function prefill(Proposal $proposal, ReportPlace $place): PrefillResult
    {
        ['lines' => $lines, 'unlinked' => $unlinked, 'sharedFixed' => $sharedFixed] = $this->plannedLines($proposal, $place);

        if ($lines === []) {
            return new PrefillResult(unlinkedPlanIndicatorIds: $unlinked, sharedFixedPlanIndicatorIds: $sharedFixed);
        }

        [$result, $templateId] = DB::transaction(function () use ($proposal, $place, $lines, $unlinked, $sharedFixed): array {
            Program::query()->whereKey($place->programId)->lockForUpdate()->firstOrFail();
            Proposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

            $placement = $place->isUnset() ? null : ReportTemplatePlacement::query()
                ->where('proposal_id', $proposal->id)->atPlace($place)->lockForUpdate()->first();

            if ($placement?->status === TemplatePlacementStatus::Pending) {
                return [new PrefillResult, null];
            }

            if ($placement?->status === TemplatePlacementStatus::Joined) {
                $selectedIds = ReportTemplateLine::query()->where('report_template_id', $placement->report_template_id)
                    ->pluck('plan_indicator_id')->all();
                $lines = array_intersect_key($lines, array_flip($selectedIds));
            }

            $existing = $this->existingTemplate($proposal, $place, lock: true);
            if ($placement === null && $this->needsPlacementChoice($proposal, $place, $existing)) {
                $placement = $this->recordPlacement($proposal, $place, TemplatePlacementStatus::Pending);
                DB::afterCommit(fn () => $this->notifyPlacementPending($placement));

                return [new PrefillResult, null];
            }

            [$template, $created] = $this->lockedTemplate($proposal, $place);
            if ($placement === null && ! $place->isUnset()) {
                $this->recordPlacement($proposal, $place, TemplatePlacementStatus::Created, $template);
            }

            if ($created) {
                $this->copyQuestions($template);
            }

            $existing = $template->lines()->get()->keyBy(fn (ReportTemplateLine $line): int => (int) $line->plan_indicator_id);
            $createdLineIds = [];
            $refreshedLineIds = [];
            $missing = [];

            foreach ($lines as $planIndicatorId => $attributes) {
                $line = $existing->get($planIndicatorId);

                if ($line !== null) {
                    if ($this->refreshesDenominator($line, $attributes)) {
                        $line->update(['fixed_denominator' => $attributes['values']['fixed_denominator']]);
                        $refreshedLineIds[] = $line->id;
                    }

                    continue;
                }

                $line = $template->lines()->create(['plan_indicator_id' => $planIndicatorId, ...$attributes['values']]);
                $createdLineIds[] = $line->id;

                if ($attributes['fixed'] && $line->fixed_denominator === null) {
                    $missing[] = $line->id;
                }
            }

            return [new PrefillResult($created ? [$template->id] : [], $createdLineIds, $unlinked, $missing, $refreshedLineIds, $sharedFixed), $template->id];
        });

        if ($result->missingFixedDenominatorLineIds !== []) {
            Log::warning('Report template lines with a fixed denominator basis have no fixed_denominator.', [
                'report_template_id' => $templateId,
                'line_ids' => $result->missingFixedDenominatorLineIds,
            ]);
        }

        if ($result->sharedFixedPlanIndicatorIds !== []) {
            Log::warning('Shared fixed-basis targets have a line in each center: count their denominator once per target when pooling (D-90).', [
                'report_template_id' => $templateId,
                'plan_indicator_ids' => $result->sharedFixedPlanIndicatorIds,
            ]);
        }

        if ($result->createdLineIds !== []) {
            DB::afterCommit(fn () => $this->notifyPending(ReportTemplate::query()->findOrFail($templateId)));
        }

        return $result;
    }

    /**
     * Whether an existing line takes the `fixed_denominator` it was created
     * without: still pending, `fixed` basis, and the
     * `project_proposal.target_beneficiaries` filled since (D-25, T27 step
     * 8a). A confirmed or retired line is MEAL's to edit.
     *
     * @param  array{values: array<string, mixed>, fixed: bool}  $attributes
     */
    private function refreshesDenominator(ReportTemplateLine $line, array $attributes): bool
    {
        return $attributes['fixed']
            && $line->status === ReportTemplateLineStatus::Pending
            && $line->fixed_denominator === null
            && $attributes['values']['fixed_denominator'] !== null;
    }

    /**
     * The pending line wanted per target (plan_indicator id => attributes)
     * at the place, the proposal targets whose indicator has no library link,
     * and the shared `fixed`-basis targets (D-90): they get a line in each
     * center's template with the row's one denominator, which aggregation
     * must count once per target, never once per center (Dev 1, 17 Nov).
     *
     * @return array{lines: array<int, array{values: array<string, mixed>, fixed: bool}>, unlinked: list<int>, sharedFixed: list<int>}
     */
    public function plannedLines(Proposal $proposal, ReportPlace $place): array
    {
        $lines = [];
        $unlinked = [];
        $sharedFixed = [];

        $useActual = $proposal->actual_date_begin !== null && $proposal->actual_date_end !== null;
        $begin = $useActual ? $proposal->actual_date_begin : $proposal->planned_date_begin;
        $end = $useActual ? $proposal->effectiveDateEnd() : $proposal->planned_date_end;

        $proposalTargets = PlanIndicator::query()
            ->with('indicator.libraryIndicator')
            ->whereNotNull('project_id')
            ->where('source_type', 'proposal')
            ->where('source_id', $proposal->id)
            ->orderBy('id')
            ->get();

        foreach ($proposalTargets as $target) {
            if (! $this->places->targetCoversPlace($target, $place)) {
                continue;
            }

            $library = $target->indicator->libraryIndicator;

            if ($library === null) {
                $unlinked[] = $target->id;

                continue;
            }

            $fixed = $library->denominator_basis === DenominatorBasis::Fixed;

            if ($fixed && $this->places->placeOfTarget($target) === null) {
                $sharedFixed[] = $target->id;
            }

            $lines[$target->id] = [
                'values' => $this->lineValues($library, $begin->startOfMonth(), $end->startOfMonth(), $fixed ? $this->places->targetBeneficiariesOf($target) : null),
                'fixed' => $fixed,
            ];
        }

        foreach ($this->institutionalPlanTargets($proposal) as $target) {
            if (! $this->places->targetCoversPlace($target, $place) || $this->placeAlreadyHasLine($place, $target->id)) {
                continue;
            }

            /** @var LibraryIndicator $library */
            $library = $target->indicator->libraryIndicator;
            /** @var AnnualPlan $plan */
            $plan = $target->source;
            $window = $this->planWindow($begin, $end, $plan->year);

            if ($window === null) {
                continue;
            }

            if ($library->denominator_basis === DenominatorBasis::Fixed && $this->places->placeOfTarget($target) === null) {
                $sharedFixed[] = $target->id;
            }

            $lines[$target->id] = ['values' => $this->lineValues($library, $window[0], $window[1], null), 'fixed' => $library->denominator_basis === DenominatorBasis::Fixed];
        }

        return ['lines' => $lines, 'unlinked' => $unlinked, 'sharedFixed' => $sharedFixed];
    }

    /**
     * The annual-plan targets of institutional `HC.*` library entries
     * (D-44) of the projects the proposal's rows carry.
     *
     * @return Collection<int, PlanIndicator>
     */
    private function institutionalPlanTargets(Proposal $proposal): Collection
    {
        $projectIds = $proposal->locations()->whereNotNull('project_id')->pluck('project_id')->all();

        if ($projectIds === []) {
            return new Collection;
        }

        return PlanIndicator::query()
            ->with(['indicator.libraryIndicator', 'source'])
            ->whereIn('project_id', $projectIds)
            ->where('source_type', 'annual_plan')
            ->whereHas('indicator.libraryIndicator', fn ($library) => $library->where('code', 'like', 'HC.%'))
            ->orderBy('id')
            ->get();
    }

    /**
     * An `HC.*` target belongs to the place once, not once per proposal
     * template: another template there may already carry it.
     */
    private function placeAlreadyHasLine(ReportPlace $place, int $planIndicatorId): bool
    {
        return ReportTemplateLine::query()
            ->where('plan_indicator_id', $planIndicatorId)
            ->whereIn('report_template_id', $this->templatesAt($place)->select('id'))
            ->exists();
    }

    /**
     * @return Builder<ReportTemplate>
     */
    private function templatesAt(ReportPlace $place): Builder
    {
        return ReportTemplate::query()
            ->where('city_id', $place->cityId)
            ->where('program_id', $place->programId)
            ->where('city_wide', $place->cityWide)
            ->where('center_id', $place->centerId);
    }

    /**
     * The plan year's months cut to the proposal's dates, null when they do
     * not overlap.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function planWindow(CarbonImmutable $begin, CarbonImmutable $end, int $year): ?array
    {
        $from = CarbonImmutable::create($year, 1, 1);
        $to = CarbonImmutable::create($year, 12, 1);

        if ($begin->startOfMonth()->greaterThan($from)) {
            $from = $begin->startOfMonth();
        }

        if ($end->startOfMonth()->lessThan($to)) {
            $to = $end->startOfMonth();
        }

        return $from->greaterThan($to) ? null : [$from, $to];
    }

    /**
     * @return array<string, mixed>
     */
    private function lineValues(LibraryIndicator $library, CarbonImmutable $from, CarbonImmutable $to, ?int $fixedDenominator): array
    {
        return [
            'entered_by' => $library->collected_by,
            'fixed_denominator' => $fixedDenominator,
            'effective_from' => $from->toDateString(),
            'effective_to' => $to->toDateString(),
        ];
    }

    private function needsPlacementChoice(Proposal $proposal, ReportPlace $place, ?ReportTemplate $existing): bool
    {
        return ! $place->isUnset() && $existing === null
            && ReportTemplate::query()->atPlace($place)
                ->whereHas('proposals', fn ($query) => $query->whereKeyNot($proposal->id))->exists();
    }

    /**
     * The proposal's template at the place, if it is linked to one.
     */
    private function existingTemplate(Proposal $proposal, ReportPlace $place, bool $lock = false): ?ReportTemplate
    {
        return $this->templatesAt($place)
            ->whereHas('proposals', fn ($query) => $query->whereKey($proposal->id))
            ->orderBy('id')
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->first();
    }

    /**
     * Create the explicitly requested template, reusing all prefill defaults.
     * The caller holds the placement and program locks and authorizes MEAL.
     */
    public function createForPlacement(Proposal $proposal, ReportPlace $place): ReportTemplate
    {
        [$template, $created] = $this->lockedTemplate($proposal, $place);
        if ($created) {
            $this->copyQuestions($template);
        }

        $planned = $this->plannedLines($proposal, $place)['lines'];
        if ($planned === []) {
            throw ValidationException::withMessages(['placement' => __('report_templates.join_needs_one_line')]);
        }
        $this->addPlacementLines($template, $planned);

        return $template;
    }

    /**
     * @param  array<int, array{values: array<string, mixed>, fixed: bool}>  $planned
     */
    public function addPlacementLines(ReportTemplate $template, array $planned): void
    {
        foreach ($planned as $targetId => $attributes) {
            $template->lines()->firstOrCreate(['plan_indicator_id' => $targetId], $attributes['values']);
        }

        DB::afterCommit(fn () => $this->notifyPending($template));
    }

    private function recordPlacement(Proposal $proposal, ReportPlace $place, TemplatePlacementStatus $status, ?ReportTemplate $template = null): ReportTemplatePlacement
    {
        $placement = new ReportTemplatePlacement;
        $placement->forceFill([
            'proposal_id' => $proposal->id, 'city_id' => $place->cityId, 'program_id' => $place->programId,
            'center_id' => $place->centerId, 'city_wide' => $place->cityWide, 'status' => $status,
            'report_template_id' => $template?->id, 'decided_at' => $template === null ? null : now(),
        ])->save();

        return $placement;
    }

    private function notifyPlacementPending(ReportTemplatePlacement $placement): void
    {
        $event = $this->catalog->effective('report_template.placement_pending');
        if ($event === null || ! $event['enabled']) {
            return;
        }

        $employeeId = $this->places->mealOfficerFor($placement->proposal, $placement->place()) ?? Slots::holderOf('meal_manager');
        $userIds = array_values($this->employees->usersFor(array_filter([$employeeId])));
        $payload = new NotificationPayload(
            kind: NotificationKind::Action,
            module: 'report-templates',
            priority: NotificationPriority::Important,
            key: 'notifications.events.report_template.placement_pending',
            params: ['subject' => $placement->proposal->name],
            subjectType: null,
            subjectId: null,
            link: ['route' => 'report-templates.index', 'parameters' => ['focus' => 'placement']],
        );
        foreach (User::query()->whereIn('id', $this->filter->apply($userIds, 'report-templates', null, $userIds, $userIds))->get() as $user) {
            rescue(fn () => $user->notify(new AppNotification($payload)));
        }
    }

    /**
     * The proposal's template at the place, locked, and whether this call
     * created it. The proposal row is locked first, so two runs for one
     * proposal cannot both create one (a place is not unique, D-76).
     *
     * @return array{0: ReportTemplate, 1: bool}
     */
    private function lockedTemplate(Proposal $proposal, ReportPlace $place): array
    {
        Proposal::query()->whereKey($proposal->id)->lockForUpdate()->firstOrFail();

        $template = $this->existingTemplate($proposal, $place, lock: true);

        if ($template !== null) {
            return [$template, false];
        }

        $now = now();
        $id = DB::table('report_templates')->insertGetId([
            'city_id' => $place->cityId,
            'program_id' => $place->programId,
            'center_id' => $place->centerId,
            'city_wide' => $place->cityWide,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        DB::table('proposal_report_template')->insert([
            'proposal_id' => $proposal->id,
            'report_template_id' => $id,
            'joined_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $template = ReportTemplate::query()->whereKey($id)->lockForUpdate()->firstOrFail();
        $this->activities->record($template, 'report_template.created', notify: false);

        return [$template, true];
    }

    /**
     * Copy the active bank questions into a new template, in bank order.
     */
    private function copyQuestions(ReportTemplate $template): void
    {
        $questions = NarrativeQuestion::query()->active()->ordered()->get(['id', 'text_ar', 'text_en', 'frequency', 'sort_order']);

        $template->questions()->createMany($questions->map(fn (NarrativeQuestion $question): array => [
            'narrative_question_id' => $question->id,
            'text_ar' => $question->text_ar,
            'text_en' => $question->text_en,
            'frequency' => $question->frequency,
            'sort_order' => $question->sort_order,
        ])->all());
    }

    /**
     * "Report template to confirm" for the template's responsible MEAL
     * officer and the MEAL Manager slot holder, once each. Both are named
     * directly, so no module permission or mute removes them.
     */
    private function notifyPending(ReportTemplate $template): void
    {
        $event = $this->catalog->effective(self::PendingEvent);

        if ($event === null || ! $event['enabled']) {
            return;
        }

        $project = $this->places->legacyProjectFor($template);
        $employeeIds = array_values(array_unique(array_filter([
            $project === null ? null : MealCoverage::responsibleFor($project),
            Slots::holderOf('meal_manager'),
        ])));
        $userIds = array_values($this->employees->usersFor($employeeIds));
        $recipients = $this->filter->apply($userIds, $event['module'], null, $userIds, $userIds);

        if ($recipients === []) {
            return;
        }

        $subject = $project ?? $template;
        $payload = new NotificationPayload(
            kind: NotificationKind::Action,
            module: $event['module'],
            priority: NotificationPriority::Important,
            key: 'notifications.events.'.self::PendingEvent,
            params: ['subject' => $project->name ?? $template->activityLabel()],
            subjectType: $subject->getMorphClass(),
            subjectId: $subject->getKey(),
        );

        foreach (User::query()->whereIn('id', $recipients)->get() as $user) {
            rescue(fn () => $user->notify(new AppNotification($payload)));
        }
    }
}
