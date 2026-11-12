<?php

declare(strict_types=1);

namespace App\Actions\Proposals;

use App\Enums\ProposalKind;
use App\Enums\ProposalStatus;
use App\Models\HrCenter;
use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\Indicator;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\Task;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Start a renewal: a new `concept note` proposal on the same project(s)
 * as an earlier one (D-30), linked through `previous_proposal_id` (T10).
 *
 * A renewal never creates a project. It copies what is worth keeping: the
 * donor, the notes, the linked projects with their beneficiaries, the
 * location rows that still stand in HR (W-34a slice 3), the planned
 * duration (as editable planned dates), the framework (through
 * CopyResultTree, the one tree-copy routine) and the plan (milestones and
 * department tasks with planned dates only). It never copies a status,
 * actual dates, files, the agreement, reports, reviewers, deliveries or
 * targets. Last year's achievement is read beside a new target by
 * PlanIndicator::previousYearReference(), not copied.
 *
 * The proposal itself is written by SaveProposal, so the first note and
 * `proposal.created` (carrying `renewed_from`) are recorded exactly once. The
 * whole clone runs in one transaction. A double click returns the draft the
 * same user just made.
 *
 * @phpstan-type CloneResult array{proposal: Proposal, skippedProjects: list<string>, replacedProjects: list<string>, skippedIndicators: list<string>, skippedParts: list<string>, copied: array{results: int, indicators: int, milestones: int, tasks: int}}
 */
final class CloneProposalForRenewal
{
    /** The framework copier of T32, resolved by name so T10 can merge before it. */
    private const TreeCopier = 'App\Actions\Results\CopyResultTree';

    /** The statuses a proposal can be renewed from. */
    public const RenewableStatuses = [ProposalStatus::Approved, ProposalStatus::Active, ProposalStatus::Completed, ProposalStatus::Rejected];

    /** How long a user's renewal draft answers a repeated click. */
    private const DoubleClickMinutes = 2;

    /**
     * `plannedDateBegin`, `plannedDateEnd`, `name` and `notes` default to the
     * previous proposal's, the dates moved one year on.
     *
     * @param  array{name?: ?string, notes?: ?string, plannedDateBegin?: ?string, plannedDateEnd?: ?string}  $data
     * @return CloneResult
     */
    public function handle(Proposal $previous, array $data, User $user): array
    {
        Gate::forUser($user)->authorize('create', Proposal::class);

        $source = Proposal::query()->whereKey($previous->getKey())->firstOrFail();
        Gate::forUser($user)->authorize('view', $source);

        if (! in_array($source->status, self::RenewableStatuses, true)) {
            throw ValidationException::withMessages(['previousProposalId' => __('proposals.choose_previous')]);
        }

        $existing = self::existingDraftFor($source, $user);

        if ($existing !== null) {
            return $this->result($existing);
        }

        $lock = Cache::lock('renewal-clone:'.$user->id.':'.$source->id, 30);

        try {
            $lock->block(5);
        } catch (LockTimeoutException) {
            $existing = self::existingDraftFor($source, $user);

            if ($existing !== null) {
                return $this->result($existing);
            }

            throw ValidationException::withMessages(['previousProposalId' => __('proposals.renewal_in_progress')]);
        }

        try {
            return DB::transaction(function () use ($source, $data, $user): array {
                $existing = self::existingDraftFor($source, $user);

                return $existing !== null ? $this->result($existing) : $this->clone($source, $data, $user);
            });
        } finally {
            $lock->release();
        }
    }

    /**
     * The newest unstarted renewal of a proposal (a concept note, or a
     * proposal draft for payroll, which starts there): the one `$user` made
     * in the last two minutes when a user is given, otherwise anyone's.
     */
    public static function existingDraftFor(Proposal $previous, ?User $user = null): ?Proposal
    {
        $unstarted = $previous->isPayroll() ? ProposalStatus::ProposalDraft : ProposalStatus::ConceptNote;

        return Proposal::query()
            ->where('previous_proposal_id', $previous->getKey())
            ->where('status', $unstarted)
            ->when($user !== null, fn ($query) => $query
                ->where('created_at', '>=', now()->subMinutes(self::DoubleClickMinutes))
                ->whereHas('statusNotes', fn ($notes) => $notes->where('changed_by', $user?->id)))
            ->latest('id')
            ->first();
    }

    /**
     * @param  array<string, mixed>  $data
     * @return CloneResult
     */
    private function clone(Proposal $previous, array $data, User $user): array
    {
        if ($previous->isPayroll()) {
            return $this->result($this->clonePayroll($previous, $data, $user));
        }

        $projects = $this->projects($previous);
        $locations = $this->locations($previous);

        if ($projects['ids'] === [] && $locations === []) {
            throw ValidationException::withMessages(['projectIds' => __('proposals.link_at_least_one_project')]);
        }

        ['begin' => $begin, 'end' => $end] = $this->plannedDates($previous, $data);

        $renewal = app(SaveProposal::class)->handle(null, [
            'name' => isset($data['name']) && $data['name'] !== '' ? (string) $data['name'] : $previous->name,
            'donorId' => $previous->donor_id,
            'projectIds' => $projects['ids'],
            ...($locations === [] ? [] : ['locations' => $locations]),
            'beneficiaries' => $projects['beneficiaries'],
            'plannedDateBegin' => $begin->toDateString(),
            'plannedDateEnd' => $end->toDateString(),
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $previous->notes,
            'startingStage' => 'concept note',
            'previousProposalId' => $previous->id,
        ], $user);

        $skippedParts = [];
        $skippedIndicators = [];
        $plan = ['milestones' => 0, 'tasks' => 0];

        if ($user->can('results.create')) {
            $skippedIndicators = $this->copyFramework($previous, $renewal);
        } else {
            $skippedParts[] = 'framework';
        }

        if (($user->can('milestones.create') && $user->can('tasks.create')) || $user->can('proposals.update')) {
            $deltaDays = (int) $previous->planned_date_begin->diffInDays($begin, false);
            $plan = [
                'milestones' => $this->copyMilestones($previous, $renewal, $projects['map'], $deltaDays),
                'tasks' => $this->copyTasks($previous, $renewal, $user, $deltaDays),
            ];
        } else {
            $skippedParts[] = 'plan';
        }

        return $this->result($renewal, $projects['skipped'], $projects['replaced'], $skippedIndicators, $skippedParts, $plan);
    }

    /**
     * A payroll renewal (W-32) stays payroll: it carries the narrative, the
     * donor, the notes and the planned duration, and never a project, a
     * framework or a plan. SaveProposal starts it in `proposal draft`.
     *
     * @param  array<string, mixed>  $data
     */
    private function clonePayroll(Proposal $previous, array $data, User $user): Proposal
    {
        ['begin' => $begin, 'end' => $end] = $this->plannedDates($previous, $data);

        return app(SaveProposal::class)->handle(null, [
            'kind' => ProposalKind::Payroll->value,
            'name' => isset($data['name']) && $data['name'] !== '' ? (string) $data['name'] : $previous->name,
            'donorId' => $previous->donor_id,
            'narrative' => $previous->narrative,
            'plannedDateBegin' => $begin->toDateString(),
            'plannedDateEnd' => $end->toDateString(),
            'notes' => array_key_exists('notes', $data) ? $data['notes'] : $previous->notes,
            'previousProposalId' => $previous->id,
        ], $user);
    }

    /**
     * The previous proposal's location rows for the renewal: city, program,
     * responsible and centers, merged by SaveProposal onto the rows its
     * linked projects bring. Only rows that still stand are copied: a row
     * without a program (its request belongs to the old proposal) or in a
     * city HR has closed is left out, and a responsible who has left is
     * dropped, so the renewal is never refused for last year's place.
     *
     * @return list<array{city_id: int, program_id: int, responsible_employee_id: int|null, center_ids: list<int>}>
     */
    private function locations(Proposal $previous): array
    {
        $rows = $previous->locations()->whereNotNull('program_id')->with('centers')->get();

        if ($rows->isEmpty()) {
            return [];
        }

        $activeCities = HrCity::query()->whereKey($rows->pluck('city_id')->unique()->all())->where('is_active', true)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $activeEmployees = HrEmployee::query()->whereKey($rows->pluck('responsible_employee_id')->filter()->unique()->all())->where('is_active', true)->pluck('id')->map(fn (mixed $id): int => (int) $id)->all();
        $copied = [];

        foreach ($rows as $row) {
            if (! in_array((int) $row->city_id, $activeCities, true)) {
                continue;
            }

            $copied[] = [
                'city_id' => (int) $row->city_id,
                'program_id' => (int) $row->program_id,
                'responsible_employee_id' => in_array((int) $row->responsible_employee_id, $activeEmployees, true) ? (int) $row->responsible_employee_id : null,
                'center_ids' => $row->centerIds(),
            ];
        }

        return $copied;
    }

    /**
     * The renewal's planned dates: as given, else the day after the previous
     * end and the previous duration.
     *
     * @param  array<string, mixed>  $data
     * @return array{begin: CarbonImmutable, end: CarbonImmutable}
     */
    private function plannedDates(Proposal $previous, array $data): array
    {
        $begin = isset($data['plannedDateBegin']) && $data['plannedDateBegin'] !== ''
            ? CarbonImmutable::parse((string) $data['plannedDateBegin'])
            : $previous->planned_date_end->addDay();
        $span = (int) $previous->planned_date_begin->diffInDays($previous->planned_date_end);
        $end = isset($data['plannedDateEnd']) && $data['plannedDateEnd'] !== ''
            ? CarbonImmutable::parse((string) $data['plannedDateEnd'])
            : $begin->addDays($span);

        return ['begin' => $begin, 'end' => $end];
    }

    /**
     * The projects the renewal links: the previous proposal's, minus those
     * that cannot take a proposal or whose HR center is inactive (named), with
     * a closed project replaced by the open one of its center and program.
     *
     * @return array{ids: list<int>, beneficiaries: array<int, int|null>, map: array<int, int>, skipped: list<string>, replaced: list<string>}
     */
    private function projects(Proposal $previous): array
    {
        $linked = $previous->projects()
            ->select(['projects.id', 'projects.name', 'projects.status', 'projects.city_id', 'projects.center_id', 'projects.host_center_id', 'projects.program_id'])
            ->orderBy('projects.id')
            ->get();

        $linkable = Project::query()->linkableToProposals()->whereKey($linked->modelKeys())->pluck('id')->all();
        $centers = HrCenter::query()->whereIn('id', $linked->pluck('center_id')->filter()->unique()->all())->pluck('is_active', 'id');

        $ids = [];
        $beneficiaries = [];
        $map = [];
        $skipped = [];
        $replaced = [];

        foreach ($linked as $project) {
            /** @var int|string|null $stored */
            $stored = $project->getRelation('pivot')->getAttribute('target_beneficiaries');
            $isInactive = $project->center_id !== null && $centers->has($project->center_id) && ! $centers->get($project->center_id);

            if (! in_array($project->id, $linkable, true) || $isInactive) {
                $skipped[] = $project->name;

                continue;
            }

            $target = $project;

            if ($project->status->value === 'closed' && ($project->center_id !== null || $project->host_center_id !== null)) {
                $open = Project::openProjectFor($project->center_id, (int) $project->city_id, $project->program_id, $project->id);

                if ($open !== null) {
                    $target = $open;
                    $replaced[] = $project->name;
                }
            }

            $map[$project->id] = $target->id;

            if (! in_array($target->id, $ids, true)) {
                $ids[] = $target->id;
                $beneficiaries[$target->id] = $stored === null ? null : (int) $stored;
            }
        }

        return ['ids' => $ids, 'beneficiaries' => $beneficiaries, 'map' => $map, 'skipped' => $skipped, 'replaced' => $replaced];
    }

    /**
     * Copy each root of the previous framework through CopyResultTree, then
     * drop the copied indicators whose library entry is no longer active.
     *
     * @return list<string> the names of the indicators left out
     */
    private function copyFramework(Proposal $previous, Proposal $renewal): array
    {
        $roots = Result::query()->where('proposal_id', $previous->id)->whereNull('parent_id')->orderBy('id')->get();

        if ($roots->isEmpty() || (! class_exists(self::TreeCopier) && ! app()->bound(self::TreeCopier))) {
            return [];
        }

        $copier = app(self::TreeCopier);

        foreach ($roots as $root) {
            $copier->handle($root, $renewal);
        }

        $inactive = Indicator::query()
            ->whereIn('result_id', Result::query()->where('proposal_id', $previous->id)->select('id'))
            ->whereHas('libraryIndicator', fn ($library) => $library->where('is_active', false))
            ->get(['id', 'name', 'library_indicator_id']);

        if ($inactive->isNotEmpty()) {
            Indicator::query()
                ->whereIn('result_id', Result::query()->where('proposal_id', $renewal->id)->select('id'))
                ->whereIn('library_indicator_id', $inactive->pluck('library_indicator_id')->all())
                ->get()
                ->each->delete();
        }

        return array_values($inactive->map(fn (Indicator $indicator): string => (string) $indicator->name)->all());
    }

    /**
     * @param  array<int, int>  $projectMap  previous project id => renewal project id
     */
    private function copyMilestones(Proposal $previous, Proposal $renewal, array $projectMap, int $deltaDays): int
    {
        $pending = Milestone::query()->where('proposal_id', $previous->id)->orderBy('id')->get()
            ->filter(fn (Milestone $milestone): bool => isset($projectMap[$milestone->project_id]));

        $created = [];

        while ($pending->isNotEmpty()) {
            $ready = $pending->filter(fn (Milestone $milestone): bool => $milestone->parent_milestone_id === null || isset($created[$milestone->parent_milestone_id]));

            if ($ready->isEmpty()) {
                break;
            }

            foreach ($ready as $milestone) {
                $copy = Milestone::query()->create([
                    'project_id' => $projectMap[$milestone->project_id],
                    'name' => $milestone->name,
                    'description' => $milestone->description,
                    'planned_start_date' => $milestone->planned_start_date->addDays($deltaDays)->toDateString(),
                    'planned_end_date' => $milestone->planned_end_date?->addDays($deltaDays)->toDateString(),
                    'sequence' => $milestone->sequence,
                    'weight_percentage' => $milestone->weight_percentage,
                    'responsible_user_id' => $milestone->responsible_user_id,
                    'parent_milestone_id' => $milestone->parent_milestone_id === null ? null : $created[$milestone->parent_milestone_id],
                    'proposal_id' => $renewal->id,
                    'copied_from_id' => $milestone->id,
                ]);

                $created[$milestone->id] = $copy->id;
            }

            $pending = $pending->reject(fn (Milestone $milestone): bool => isset($created[$milestone->id]));
        }

        return count($created);
    }

    private function copyTasks(Proposal $previous, Proposal $renewal, User $user, int $deltaDays): int
    {
        /** @var Collection<int, Task> $pending */
        $pending = Task::query()->withoutGlobalScopes()->with('taskAssignees')
            ->where('source_type', 'proposal')->where('source_id', $previous->id)
            ->orderBy('id')->get();

        $created = [];

        while ($pending->isNotEmpty()) {
            $ready = $pending->filter(fn (Task $task): bool => $task->parent_task_id === null || isset($created[$task->parent_task_id]));

            if ($ready->isEmpty()) {
                break;
            }

            foreach ($ready as $task) {
                $copy = Task::query()->create([
                    'parent_task_id' => $task->parent_task_id === null ? null : $created[$task->parent_task_id],
                    'source_type' => 'proposal',
                    'source_id' => $renewal->id,
                    'title' => $task->title,
                    'description' => $task->description,
                    'priority' => $task->priority,
                    'start_date' => $task->start_date?->addDays($deltaDays)->toDateString(),
                    'due_date' => $task->due_date?->addDays($deltaDays)->toDateString(),
                    'created_by' => $user->id,
                    'copied_from_id' => $task->id,
                ]);

                foreach ($task->taskAssignees as $assignee) {
                    $copy->taskAssignees()->create(['employee_id' => $assignee->employee_id]);
                }

                $created[$task->id] = $copy->id;
            }

            $pending = $pending->reject(fn (Task $task): bool => isset($created[$task->id]));
        }

        return count($created);
    }

    /**
     * @param  list<string>  $skippedProjects
     * @param  list<string>  $replacedProjects
     * @param  list<string>  $skippedIndicators
     * @param  list<string>  $skippedParts
     * @param  array{milestones: int, tasks: int}|null  $plan
     * @return CloneResult
     */
    private function result(Proposal $proposal, array $skippedProjects = [], array $replacedProjects = [], array $skippedIndicators = [], array $skippedParts = [], ?array $plan = null): array
    {
        $resultIds = Result::query()->where('proposal_id', $proposal->id)->pluck('id');

        return [
            'proposal' => $proposal,
            'skippedProjects' => $skippedProjects,
            'replacedProjects' => $replacedProjects,
            'skippedIndicators' => $skippedIndicators,
            'skippedParts' => $skippedParts,
            'copied' => [
                'results' => $resultIds->count(),
                'indicators' => Indicator::query()->whereIn('result_id', $resultIds)->count(),
                'milestones' => $plan['milestones'] ?? 0,
                'tasks' => $plan['tasks'] ?? 0,
            ],
        ];
    }
}
