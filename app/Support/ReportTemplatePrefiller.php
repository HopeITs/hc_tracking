<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\DenominatorBasis;
use App\Enums\NotificationKind;
use App\Enums\NotificationPriority;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Models\AnnualPlan;
use App\Models\LibraryIndicator;
use App\Models\NarrativeQuestion;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ReportTemplate;
use App\Models\User;
use App\Notifications\ActivityRecorder;
use App\Notifications\AppNotification;
use App\Notifications\EmployeeRecipients;
use App\Notifications\EventCatalog;
use App\Notifications\NotificationPayload;
use App\Notifications\RecipientFilter;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Pre-fills projects' monthly report templates as `pending` (contracts.md
 * §12.4, T33, D-41, D-44, D-45): one template per project, the active bank
 * questions copied when it is created, and one pending line per
 * library-linked target that has none yet. Lines already there (pending,
 * confirmed or retired) and the template's status are never touched, so a
 * confirmed template stays confirmed and MEAL confirms the new lines.
 *
 * Targets are the project's proposal targets (one proposal on approval, every
 * donor-approved proposal for prefillProject()) and its annual-plan targets
 * of institutional `HC.*` library entries. Line defaults: `library_default`
 * schedule, `entered_by` = the library `collected_by`, `fixed_denominator` =
 * `project_proposal.target_beneficiaries` when the library basis is `fixed`,
 * no evidence types, and the window = the months of the proposal's dates
 * (actual once both are set, else planned) or, for an `HC.*` target, the
 * plan year cut to the project's dates.
 *
 * A system writer, so there is no Gate: it runs from the queue after a donor
 * approval (PrefillReportTemplates) and from the launch command
 * (`report-templates:prefill-active`, slice 4), where no user acts. It never
 * writes a project or proposal status. Idempotent: the template row is locked
 * (or created against its unique index) before the lines are compared, so
 * concurrent runs on one project serialise and the unique (template, target)
 * index backs the check.
 */
final class ReportTemplatePrefiller
{
    public const PendingEvent = 'report_template.pending';

    public function __construct(
        private EmployeeRecipients $employees,
        private RecipientFilter $filter,
        private EventCatalog $catalog,
        private ActivityRecorder $activities,
    ) {}

    /**
     * Pre-fill every open project the proposal funds with the proposal's
     * targets (and the project's `HC.*` annual-plan targets). A proposal the
     * donor has not approved pre-fills nothing.
     */
    public function prefillForProposal(Proposal $proposal): PrefillResult
    {
        $result = new PrefillResult;

        if (! $proposal->status->isApprovedByDonor()) {
            return $result;
        }

        $projects = $proposal->projects()
            ->whereNotIn('projects.status', [ProjectStatus::Closed, ProjectStatus::Archived])
            ->orderBy('projects.id')
            ->get();

        foreach ($projects as $project) {
            /** @var int|null $beneficiaries */
            $beneficiaries = $project->getRelation('pivot')->getAttribute('target_beneficiaries');

            $result = $result->merge($this->prefill(
                $project,
                new Collection([$proposal]),
                [$proposal->id => $beneficiaries === null ? null : (int) $beneficiaries],
            ));
        }

        return $result;
    }

    /**
     * Pre-fill one project with the targets of all its donor-approved
     * proposals and its `HC.*` annual-plan targets.
     */
    public function prefillProject(Project $project): PrefillResult
    {
        $proposals = $project->proposals()
            ->whereIn('proposals.status', ProposalStatus::approvedByDonor())
            ->orderBy('proposals.id')
            ->get();

        $beneficiaries = [];

        foreach ($proposals as $proposal) {
            $value = $proposal->getRelation('pivot')->getAttribute('target_beneficiaries');
            $beneficiaries[$proposal->id] = $value === null ? null : (int) $value;
        }

        return $this->prefill($project, $proposals, $beneficiaries);
    }

    /**
     * @param  Collection<int, Proposal>  $proposals
     * @param  array<int, int|null>  $beneficiaries  proposal id => project_proposal.target_beneficiaries
     */
    private function prefill(Project $project, Collection $proposals, array $beneficiaries): PrefillResult
    {
        ['lines' => $lines, 'unlinked' => $unlinked] = $this->plannedLines($project, $proposals, $beneficiaries);

        $result = DB::transaction(function () use ($project, $lines, $unlinked): PrefillResult {
            [$template, $created] = $this->lockedTemplate($project);

            if ($created) {
                $this->copyQuestions($template);
            }

            $existing = $template->lines()->pluck('plan_indicator_id')->map(fn (int|string $id): int => (int) $id)->all();
            $createdLineIds = [];
            $missing = [];

            foreach ($lines as $planIndicatorId => $attributes) {
                if (in_array($planIndicatorId, $existing, true)) {
                    continue;
                }

                $line = $template->lines()->create(['plan_indicator_id' => $planIndicatorId, ...$attributes['values']]);
                $createdLineIds[] = $line->id;

                if ($attributes['fixed'] && $line->fixed_denominator === null) {
                    $missing[] = $line->id;
                }
            }

            return new PrefillResult($created ? [$template->id] : [], $createdLineIds, $unlinked, $missing);
        });

        if ($result->missingFixedDenominatorLineIds !== []) {
            Log::warning('Report template lines with a fixed denominator basis have no fixed_denominator.', [
                'project_id' => $project->id,
                'line_ids' => $result->missingFixedDenominatorLineIds,
            ]);
        }

        if ($result->createdLineIds !== []) {
            DB::afterCommit(fn () => $this->notifyPending($project));
        }

        return $result;
    }

    /**
     * The pending line wanted per target (plan_indicator id => attributes),
     * and the proposal targets whose indicator has no library link.
     *
     * @param  Collection<int, Proposal>  $proposals
     * @param  array<int, int|null>  $beneficiaries
     * @return array{lines: array<int, array{values: array<string, mixed>, fixed: bool}>, unlinked: list<int>}
     */
    private function plannedLines(Project $project, Collection $proposals, array $beneficiaries): array
    {
        $lines = [];
        $unlinked = [];
        $proposalsById = $proposals->keyBy('id');

        $proposalTargets = $proposals->isEmpty() ? new Collection : PlanIndicator::query()
            ->with('indicator.libraryIndicator')
            ->where('project_id', $project->id)
            ->where('source_type', 'proposal')
            ->whereIn('source_id', $proposalsById->keys())
            ->orderBy('id')
            ->get();

        foreach ($proposalTargets as $target) {
            $library = $target->indicator->libraryIndicator;

            if ($library === null) {
                $unlinked[] = $target->id;

                continue;
            }

            /** @var Proposal $proposal */
            $proposal = $proposalsById->get($target->source_id);
            $useActual = $proposal->actual_date_begin !== null && $proposal->actual_date_end !== null;
            $begin = $useActual ? $proposal->actual_date_begin : $proposal->planned_date_begin;
            $end = $useActual ? $proposal->actual_date_end : $proposal->planned_date_end;
            $fixed = $library->denominator_basis === DenominatorBasis::Fixed;

            $lines[$target->id] = [
                'values' => $this->lineValues($library, $begin->startOfMonth(), $end->startOfMonth(), $fixed ? ($beneficiaries[$proposal->id] ?? null) : null),
                'fixed' => $fixed,
            ];
        }

        foreach ($this->institutionalPlanTargets($project) as $target) {
            /** @var LibraryIndicator $library */
            $library = $target->indicator->libraryIndicator;
            /** @var AnnualPlan $plan */
            $plan = $target->source;
            $window = $this->planWindow($project, $plan->year);

            if ($window === null) {
                continue;
            }

            $fixed = $library->denominator_basis === DenominatorBasis::Fixed;
            $lines[$target->id] = ['values' => $this->lineValues($library, $window[0], $window[1], null), 'fixed' => $fixed];
        }

        return ['lines' => $lines, 'unlinked' => $unlinked];
    }

    /**
     * The project's annual-plan targets of institutional `HC.*` library
     * entries (D-44).
     *
     * @return Collection<int, PlanIndicator>
     */
    private function institutionalPlanTargets(Project $project): Collection
    {
        return PlanIndicator::query()
            ->with(['indicator.libraryIndicator', 'source'])
            ->where('project_id', $project->id)
            ->where('source_type', 'annual_plan')
            ->whereHas('indicator.libraryIndicator', fn (Builder $library) => $library->where('code', 'like', 'HC.%'))
            ->orderBy('id')
            ->get();
    }

    /**
     * The plan year's months cut to the project's dates, null when they do
     * not overlap.
     *
     * @return array{0: CarbonImmutable, 1: CarbonImmutable}|null
     */
    private function planWindow(Project $project, int $year): ?array
    {
        $from = CarbonImmutable::create($year, 1, 1);
        $to = CarbonImmutable::create($year, 12, 1);

        if ($project->start_date !== null && $project->start_date->startOfMonth()->greaterThan($from)) {
            $from = $project->start_date->startOfMonth();
        }

        if ($project->end_date !== null && $project->end_date->startOfMonth()->lessThan($to)) {
            $to = $project->end_date->startOfMonth();
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

    /**
     * The project's template, locked, and whether this call created it. A
     * concurrent creator is absorbed by the unique `project_id` index.
     *
     * @return array{0: ReportTemplate, 1: bool}
     */
    private function lockedTemplate(Project $project): array
    {
        $template = ReportTemplate::query()->where('project_id', $project->id)->lockForUpdate()->first();

        if ($template !== null) {
            return [$template, false];
        }

        $now = now();
        $created = DB::table('report_templates')->insertOrIgnore([
            'project_id' => $project->id,
            'status' => 'pending',
            'created_at' => $now,
            'updated_at' => $now,
        ]) === 1;

        $template = ReportTemplate::query()->where('project_id', $project->id)->lockForUpdate()->firstOrFail();

        if ($created) {
            $this->activities->record($template, 'report_template.created', notify: false);
        }

        return [$template, $created];
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
     * "Report template to confirm" for the project's responsible MEAL officer
     * and the MEAL Manager slot holder, once each. Both are named directly,
     * so no module permission or mute removes them.
     */
    private function notifyPending(Project $project): void
    {
        $event = $this->catalog->effective(self::PendingEvent);

        if ($event === null || ! $event['enabled']) {
            return;
        }

        $employeeIds = array_values(array_unique(array_filter([
            MealCoverage::responsibleFor($project),
            Slots::holderOf('meal_manager'),
        ])));
        $userIds = array_values($this->employees->usersFor($employeeIds));
        $recipients = $this->filter->apply($userIds, $event['module'], null, $userIds, $userIds);

        if ($recipients === []) {
            return;
        }

        $payload = new NotificationPayload(
            kind: NotificationKind::Action,
            module: $event['module'],
            priority: NotificationPriority::Important,
            key: 'notifications.events.'.self::PendingEvent,
            params: ['subject' => $project->name],
            subjectType: $project->getMorphClass(),
            subjectId: $project->id,
        );

        foreach (User::query()->whereIn('id', $recipients)->get() as $user) {
            rescue(fn () => $user->notify(new AppNotification($payload)));
        }
    }
}
