<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Tasks;

use App\Models\HrDepartment;
use App\Models\HrEmployee;
use App\Models\Milestone;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * The option lists behind the tasks page's filters, drawer fields and
 * quick-set popovers: departments, employees, source types and the records
 * of a chosen source type (for the drawer and for the filter bar).
 *
 * Used by tasks/index only.
 *
 * @property string $sourceType
 * @property string $taskSourceType
 */
trait ProvidesTaskOptions
{
    /**
     * The department filter's options — only ever queried/rendered for a
     * bypass role (see ManagesTaskVisibility); a department-scoped role's
     * view never needs this list.
     *
     * @return array<int, array{id: int, name: string}>
     */
    #[Computed(persist: true, seconds: 300)]
    public function departmentOptions(): array
    {
        if (! auth()->user()->canViewAllTaskDepartments()) {
            return [];
        }

        return HrDepartment::options()
            ->map(fn (HrDepartment $department): array => ['id' => $department->id, 'name' => $department->name])
            ->all();
    }

    /**
     * These three option lists back every filter, drawer field, and quick-set
     * popover, so without caching they were re-queried in full on every
     * single quick action (toggle complete, quick status, reschedule, …)
     * even though nothing about the employees/proposals/milestones changed.
     * `persist` keys the cache to this component instance, so it never leaks
     * across users and expires with the page (5 minutes is just a safety
     * bound against an unusually long-lived tab, not a real staleness risk).
     *
     * Returned as plain arrays, not Eloquent collections/models: the cache
     * store's `serializable_classes` is `false` (intentionally, to block
     * gadget-chain attacks — see config/cache.php), so any object graph
     * silently fails to unserialize on every read. `persist` would then
     * re-run the query AND re-write the cache on every single request,
     * which is slower than no caching at all — this is what actually made
     * every quick action take seconds.
     *
     * @return array<int, array{id: int, name: string, secondary_name: string}>
     */
    #[Computed(persist: true, seconds: 300)]
    public function userOptions(): array
    {
        return HrEmployee::pickerOptions()
            ->map(fn (HrEmployee $employee): array => [
                'id' => $employee->id,
                'name' => $employee->name,
                'secondary_name' => $employee->secondary_name,
            ])
            ->all();
    }

    /**
     * The five source types a task can be raised against — static, so it
     * needs no query. Mirrors the morph keys registered in
     * AppServiceProvider::configureMorphMap() for the `tasks.source_type`
     * column.
     *
     * @return array<int, array{value: string, label: string}>
     */
    #[Computed]
    public function sourceTypeOptions(): array
    {
        return [
            ['value' => 'milestone', 'label' => __('tasks.source_type_milestone')],
            ['value' => 'proposal', 'label' => __('tasks.source_type_proposal')],
            ['value' => 'project', 'label' => __('tasks.source_type_project')],
            ['value' => 'program', 'label' => __('tasks.source_type_program')],
            ['value' => 'department', 'label' => __('tasks.source_type_department')],
        ];
    }

    /**
     * The records the drawer's own Source select offers, for whichever
     * source type is currently chosen there. Deliberately NOT
     * `persist`-cached like userOptions() above: this depends on
     * $this->taskSourceType, so a per-instance cache keyed only by method
     * name would go stale (or serve the wrong type's records) the moment
     * the type select changes. It costs one cheap query either way.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    #[Computed]
    public function sourceOptions(): Collection
    {
        return $this->optionsForSourceType($this->taskSourceType);
    }

    /**
     * Same shape as sourceOptions(), but scoped by the filter bar's own
     * source type instead of the drawer's — a separate computed method so
     * switching one never invalidates the other's Livewire request-scoped
     * cache.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    #[Computed]
    public function filterSourceOptions(): Collection
    {
        return $this->optionsForSourceType($this->sourceType);
    }

    /**
     * @return Collection<int, array{id: int, name: string}>
     */
    protected function optionsForSourceType(string $type): Collection
    {
        return match ($type) {
            'milestone' => Milestone::query()
                ->select(['id', 'name', 'project_id', 'proposal_id'])
                ->with(['project:id,name', 'proposal:id,name'])
                ->orderBy('name')
                ->orderBy('id')
                ->get()
                ->map(fn (Milestone $milestone): array => [
                    'id' => $milestone->id,
                    'name' => $milestone->name.' · '.$milestone->ownerName(),
                ]),
            'proposal' => Proposal::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get()
                ->map(fn (Proposal $proposal): array => ['id' => $proposal->id, 'name' => $proposal->name]),
            'project' => Project::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get()
                ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name]),
            'program' => Program::options()
                ->map(fn (Program $program): array => ['id' => $program->id, 'name' => $program->name]),
            'department' => HrDepartment::query()->select(['id', 'name'])->orderBy('name')->get()
                ->map(fn (HrDepartment $department): array => ['id' => $department->id, 'name' => $department->name]),
            default => collect(),
        };
    }
}
