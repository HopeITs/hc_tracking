<?php

declare(strict_types=1);

namespace App\Actions\Milestones;

use App\Enums\TaskKind;
use App\Models\HrDepartment;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Proposal;
use App\Models\Task;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\Validation\Validator as ValidatorInstance;

/**
 * Add or edit a milestone on a proposal's plan (T31, contracts.md §12.2).
 *
 * One transaction: the proposal row is locked, an existing milestone is only
 * ever loaded through that proposal (a foreign id is a 404 with no write),
 * and the plan window is checked against the locked row
 * (MilestonePolicy::planForProposal). A milestone is shared at proposal
 * level, or center-specific when it also names one of the proposal's linked
 * projects, and it sits inside the proposal period: the grant dates once
 * approved, the planned dates before.
 */
final class SaveProposalMilestone
{
    /**
     * @param  array{name?: string, description?: ?string, planned_start_date?: string, planned_end_date?: ?string, responsible_employee_id?: int|string, project_id?: int|string|null, parent_milestone_id?: int|string|null}  $input
     */
    public function handle(Proposal $proposal, ?int $milestoneId, array $input): Milestone
    {
        return DB::transaction(function () use ($proposal, $milestoneId, $input): Milestone {
            $locked = $this->lockProposal($proposal);
            $existing = $milestoneId === null ? null : $locked->milestones()->whereKey($milestoneId)->firstOrFail();

            Gate::authorize('planForProposal', [Milestone::class, $locked, $existing]);

            $validated = $this->validate($locked, $existing, $input);

            $milestone = $existing ?? new Milestone;
            $milestone->fill([
                'proposal_id' => $locked->id,
                'project_id' => $this->nullableId($validated['project_id'] ?? null),
                'name' => $validated['name'],
                'description' => $this->nullableText($validated['description'] ?? null),
                'planned_start_date' => $validated['planned_start_date'],
                'planned_end_date' => $this->nullableText($validated['planned_end_date'] ?? null),
                'responsible_user_id' => (int) $validated['responsible_employee_id'],
                'parent_milestone_id' => $this->nullableId($validated['parent_milestone_id'] ?? null),
            ])->save();

            return $milestone;
        });
    }

    /**
     * The Final Report milestone template (D-02): the proposal's one
     * is_final_report milestone, with the department task that prepares the
     * report. The only way such a milestone is created.
     *
     * @param  array{planned_start_date?: string, planned_end_date?: ?string, responsible_employee_id?: int|string, department_id?: int|string, due_date?: ?string}  $input
     */
    public function addFinalReport(Proposal $proposal, array $input): Milestone
    {
        return DB::transaction(function () use ($proposal, $input): Milestone {
            $locked = $this->lockProposal($proposal);

            Gate::authorize('planForProposal', [Milestone::class, $locked]);
            Gate::authorize('planDepartmentTask', [Task::class, $locked]);

            if ($locked->milestones()->where('is_final_report', true)->exists()) {
                throw ValidationException::withMessages(['final_report' => __('milestones.final_report_exists')]);
            }

            $validated = $this->validateFinalReport($locked, $input);

            $milestone = new Milestone;
            $milestone->fill([
                'proposal_id' => $locked->id,
                'project_id' => null,
                'name' => __('milestones.final_report_milestone_name'),
                'planned_start_date' => $validated['planned_start_date'],
                'planned_end_date' => $this->nullableText($validated['planned_end_date'] ?? null),
                'responsible_user_id' => (int) $validated['responsible_employee_id'],
            ])->forceFill(['is_final_report' => true])->save();

            Task::query()->create([
                'source_type' => 'milestone',
                'source_id' => $milestone->id,
                'department_id' => (int) $validated['department_id'],
                'kind' => TaskKind::General,
                'title' => __('department_tasks.final_report_task_title'),
                'requested_on' => CarbonImmutable::today()->toDateString(),
                'due_date' => $this->finalReportDueDate($validated),
                'created_by' => Auth::id(),
            ]);

            return $milestone;
        });
    }

    private function lockProposal(Proposal $proposal): Proposal
    {
        return Proposal::query()->whereKey($proposal->getKey())->lockForUpdate()->firstOrFail();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name: string, description?: ?string, planned_start_date: string, planned_end_date?: ?string, responsible_employee_id: int|string, project_id?: int|string|null, parent_milestone_id?: int|string|null}
     */
    private function validate(Proposal $proposal, ?Milestone $existing, array $input): array
    {
        $linkedProjectIds = $proposal->projects()->pluck('projects.id')->all();
        $proposalMilestoneIds = $proposal->milestones()->pluck('id')->all();

        $validator = Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            ...$this->dateRules(),
            'responsible_employee_id' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'project_id' => ['nullable', 'integer', Rule::in($linkedProjectIds)],
            'parent_milestone_id' => ['nullable', 'integer', Rule::in($proposalMilestoneIds)],
        ], [
            'name.required' => __('milestones.enter_a_milestone_name'),
            ...$this->dateMessages(),
            'responsible_employee_id.required' => __('milestones.select_the_responsible_staff_member'),
            'responsible_employee_id.integer' => __('milestones.select_the_responsible_staff_member'),
            'responsible_employee_id.exists' => __('milestones.select_the_responsible_staff_member'),
            'project_id.integer' => __('milestones.project_not_linked_to_proposal'),
            'project_id.in' => __('milestones.project_not_linked_to_proposal'),
            'parent_milestone_id.integer' => __('milestones.select_a_milestone_from_this_proposal'),
            'parent_milestone_id.in' => __('milestones.select_a_milestone_from_this_proposal'),
        ]);

        $validator->after(function (ValidatorInstance $validator) use ($proposal, $existing, $input): void {
            $this->checkPeriod($validator, $proposal, $input);
            $this->checkTree($validator, $existing, $input);
        });

        /** @var array{name: string, description?: ?string, planned_start_date: string, planned_end_date?: ?string, responsible_employee_id: int|string, project_id?: int|string|null, parent_milestone_id?: int|string|null} */
        return $validator->validate();
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{planned_start_date: string, planned_end_date?: ?string, responsible_employee_id: int|string, department_id: int|string, due_date?: ?string}
     */
    private function validateFinalReport(Proposal $proposal, array $input): array
    {
        $validator = Validator::make($input, [
            ...$this->dateRules(),
            'responsible_employee_id' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
            'department_id' => ['required', 'integer', Rule::exists(HrDepartment::class, 'id')],
            'due_date' => ['nullable', 'date_format:Y-m-d'],
        ], [
            ...$this->dateMessages(),
            'responsible_employee_id.required' => __('milestones.select_the_responsible_staff_member'),
            'responsible_employee_id.integer' => __('milestones.select_the_responsible_staff_member'),
            'responsible_employee_id.exists' => __('milestones.select_the_responsible_staff_member'),
            'department_id.required' => __('department_tasks.choose_the_department'),
            'department_id.integer' => __('department_tasks.choose_the_department'),
            'department_id.exists' => __('department_tasks.department_not_found'),
            'due_date.date_format' => __('tasks.set_the_due_date'),
        ]);

        $validator->after(function (ValidatorInstance $validator) use ($proposal, $input): void {
            $this->checkPeriod($validator, $proposal, $input);

            if ($validator->errors()->isEmpty() && $this->finalReportDueDate($input) < CarbonImmutable::today()->toDateString()) {
                $validator->errors()->add('due_date', __('tasks.due_before_requested'));
            }
        });

        /** @var array{planned_start_date: string, planned_end_date?: ?string, responsible_employee_id: int|string, department_id: int|string, due_date?: ?string} */
        return $validator->validate();
    }

    /**
     * @return array<string, list<string>>
     */
    private function dateRules(): array
    {
        return [
            'planned_start_date' => ['required', 'date_format:Y-m-d'],
            'planned_end_date' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:planned_start_date'],
        ];
    }

    /**
     * @return array<string, string>
     */
    private function dateMessages(): array
    {
        return [
            'planned_start_date.required' => __('milestones.set_the_planned_start_date'),
            'planned_start_date.date_format' => __('milestones.set_the_planned_start_date'),
            'planned_end_date.date_format' => __('milestones.planned_end_date_must_be_on_or_after_the_planned_start_date'),
            'planned_end_date.after_or_equal' => __('milestones.planned_end_date_must_be_on_or_after_the_planned_start_date'),
        ];
    }

    /**
     * The planned dates sit inside the proposal period: the grant dates once
     * the donor approved them, the planned dates before that.
     *
     * @param  array<string, mixed>  $input
     */
    private function checkPeriod(ValidatorInstance $validator, Proposal $proposal, array $input): void
    {
        $errors = $validator->errors();

        if ($errors->has('planned_start_date') || $errors->has('planned_end_date')) {
            return;
        }

        $period = $this->proposalPeriod($proposal);

        if ($period === null) {
            $errors->add('planned_start_date', __('milestones.set_proposal_period'));

            return;
        }

        [$begin, $end] = $period;
        $start = (string) $input['planned_start_date'];
        $finish = trim((string) ($input['planned_end_date'] ?? ''));

        if ($start < $begin || $start > $end) {
            $errors->add('planned_start_date', __('milestones.milestone_outside_period'));
        } elseif ($finish !== '' && $finish > $end) {
            $errors->add('planned_end_date', __('milestones.milestone_outside_period'));
        }
    }

    /**
     * The proposal period as Y-m-d strings: the actual (grant) dates when
     * both are set, else the planned dates; null when neither pair is whole.
     *
     * @return array{0: string, 1: string}|null
     */
    private function proposalPeriod(Proposal $proposal): ?array
    {
        foreach ([['actual_date_begin', 'actual_date_end'], ['planned_date_begin', 'planned_date_end']] as [$beginColumn, $endColumn]) {
            $begin = $proposal->getAttribute($beginColumn);
            $end = $proposal->getAttribute($endColumn);

            if ($begin instanceof CarbonImmutable && $end instanceof CarbonImmutable) {
                return [$begin->toDateString(), $end->toDateString()];
            }
        }

        return null;
    }

    /**
     * The parent is a milestone of this proposal (already checked by the
     * rules), never the milestone itself or one of its descendants, and a
     * parent in a project only takes children in that same project. Nested
     * milestones in other centers keep an edited milestone in its project.
     *
     * @param  array<string, mixed>  $input
     */
    private function checkTree(ValidatorInstance $validator, ?Milestone $existing, array $input): void
    {
        $errors = $validator->errors();

        if ($errors->has('parent_milestone_id') || $errors->has('project_id')) {
            return;
        }

        $parentId = $this->nullableId($input['parent_milestone_id'] ?? null);
        $projectId = $this->nullableId($input['project_id'] ?? null);

        if ($parentId !== null) {
            if ($existing !== null && $existing->wouldCycleUnder($parentId)) {
                $errors->add('parent_milestone_id', __('milestones.a_milestone_cannot_be_nested_under_itself_or_its_descendants'));

                return;
            }

            $parentProjectId = Milestone::query()->whereKey($parentId)->value('project_id');

            if ($parentProjectId !== null && (int) $parentProjectId !== $projectId) {
                $errors->add('project_id', __('milestones.parent_milestone_in_another_center'));

                return;
            }
        }

        if ($existing !== null && $projectId !== null
            && $existing->children()->where(fn ($query) => $query->whereNull('project_id')->orWhere('project_id', '!=', $projectId))->exists()) {
            $errors->add('project_id', __('milestones.nested_milestones_in_other_centers'));
        }
    }

    /**
     * The final report task is due when asked, else when the milestone ends,
     * else when it starts.
     *
     * @param  array<string, mixed>  $input
     */
    private function finalReportDueDate(array $input): string
    {
        return $this->nullableText($input['due_date'] ?? null)
            ?? $this->nullableText($input['planned_end_date'] ?? null)
            ?? (string) $input['planned_start_date'];
    }

    private function nullableId(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function nullableText(mixed $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
