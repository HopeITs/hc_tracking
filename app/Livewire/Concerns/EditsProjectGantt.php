<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Enums\DependencyType;
use App\Exceptions\InvalidParentException;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\ScheduleBaseline;
use App\Models\ScheduleDependency;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

/**
 * The write side of the project Gantt: every edit the chart makes lands here —
 * drag, resize, progress, rename, indent/outdent, links, the working week,
 * baselines, and the restores undo sends.
 *
 * Only milestones and tasks of the page's own project are editable — a row id
 * from another project resolves to nothing and the edit is refused, so a
 * tampered payload cannot reach foreign records. Proposals, transfers and
 * donor reports are shown on the chart but always edited on their own pages.
 *
 * Date edits and link edits run the auto-scheduler afterwards, which may push
 * successors later. Every answer carries:
 * - payload: the freshly built chart, so it redraws from the database;
 * - changes: each row whose dates moved, as it stood BEFORE, so the chart can
 *   undo the whole ripple with one ganttRestore();
 * - previous: whatever a link or weekend edit replaced, for the same reason.
 *
 * The using component provides $projectId, a `ganttPayload` computed property
 * and the BuildsProjectGantt trait (which brings SchedulesProjectGantt).
 *
 * @phpstan-type GanttAnswer array{ok: bool, payload: array<string, mixed>, changes: list<array{id: string, start: string, end: string}>, previous: mixed}
 */
trait EditsProjectGantt
{
    public ?int $ganttBaselineId = null;

    /**
     * @return GanttAnswer
     */
    public function ganttReschedule(string $rowId, string $start, string $end): array
    {
        $record = $this->ganttRecord($rowId);

        if ($record === null || ! $this->validDateRange($start, $end)) {
            return $this->ganttRefused(__('gantt.edit_refused'));
        }

        $before = $this->currentDates($record, $rowId);

        return DB::transaction(function () use ($record, $start, $end, $before, $rowId): array {
            $this->writeDates($record, $start, $end);
            $moved = array_values(array_filter($this->autoSchedule($this->ganttProject()), fn (array $row): bool => $row['id'] !== $rowId));

            return $this->ganttSaved([$before, ...$moved]);
        });
    }

    /**
     * Put rows back to earlier dates exactly, without auto-scheduling — the
     * undo of a reschedule and of whatever it pushed.
     *
     * @param  list<array{id?: mixed, start?: mixed, end?: mixed}>  $rows
     * @return GanttAnswer
     */
    public function ganttRestore(array $rows): array
    {
        $targets = [];

        foreach (array_slice($rows, 0, 500) as $row) {
            $record = is_string($row['id'] ?? null) ? $this->ganttRecord($row['id']) : null;
            $start = is_string($row['start'] ?? null) ? $row['start'] : '';
            $end = is_string($row['end'] ?? null) ? $row['end'] : '';

            if ($record === null || ! $this->validDateRange($start, $end)) {
                return $this->ganttRefused(__('gantt.edit_refused'));
            }

            $targets[] = [$record, $row['id'], $start, $end];
        }

        return DB::transaction(function () use ($targets): array {
            $changes = [];

            foreach ($targets as [$record, $rowId, $start, $end]) {
                $changes[] = $this->currentDates($record, $rowId);
                $this->writeDates($record, $start, $end);
            }

            return $this->ganttSaved($changes);
        });
    }

    /**
     * @return GanttAnswer
     */
    public function ganttSetProgress(string $rowId, int $progress): array
    {
        $record = $this->ganttRecord($rowId);

        if (! $record instanceof Task || $progress < 0 || $progress > 100 || $record->subtasks()->exists()) {
            return $this->ganttRefused(__('gantt.edit_refused'));
        }

        $record->update(['progress_percentage' => $progress]);

        return $this->ganttSaved();
    }

    /**
     * @return GanttAnswer
     */
    public function ganttRename(string $rowId, string $name): array
    {
        $name = trim($name);
        $record = $this->ganttRecord($rowId);

        if ($record === null || $name === '' || mb_strlen($name) > 255) {
            return $this->ganttRefused(__('gantt.edit_refused'));
        }

        $record->update([$record instanceof Milestone ? 'name' : 'title' => $name]);

        return $this->ganttSaved();
    }

    /**
     * Re-nest a row under another row of the same kind, or lift it to the top
     * of its branch when $parentRowId is null. Indent, outdent and their undo
     * all come through here.
     *
     * Tasks stay one level deep (a subtask never gets subtasks of its own) and
     * move into their new parent's milestone; milestones only nest inside their
     * own project — MilestoneObserver refuses cycles and cross-project parents.
     *
     * @return GanttAnswer
     */
    public function ganttSetParent(string $rowId, ?string $parentRowId): array
    {
        $record = $this->ganttRecord($rowId);
        $parent = $parentRowId === null ? null : $this->ganttRecord($parentRowId);

        if ($record === null || ($parentRowId !== null && ($parent === null || $parent::class !== $record::class))) {
            return $this->ganttRefused(__('gantt.edit_refused'));
        }

        if ($record instanceof Task) {
            /** @var Task|null $parent */
            if ($parent !== null && ($parent->parent_task_id !== null || $parent->id === $record->id || $record->subtasks()->exists())) {
                return $this->ganttRefused(__('gantt.subtasks_one_level'));
            }

            $record->update([
                'parent_task_id' => $parent?->id,
                'source_id' => $parent?->source_id ?? $record->source_id,
            ]);

            return $this->ganttSaved();
        }

        try {
            DB::transaction(fn () => $record->update(['parent_milestone_id' => $parent?->id]));
        } catch (InvalidParentException) {
            return $this->ganttRefused(__('gantt.cannot_nest_there'));
        }

        return $this->ganttSaved();
    }

    /**
     * Create a link, or change the type and lag of an existing one, then let
     * the auto-scheduler push the successor chain. previous holds the link as
     * it was (null when it is new).
     *
     * @return GanttAnswer
     */
    public function ganttLink(string $fromRowId, string $toRowId, string $type = 'fs', int $lag = 0): array
    {
        $from = $this->ganttRecord($fromRowId);
        $to = $this->ganttRecord($toRowId);

        if ($from === null || $to === null || DependencyType::tryFrom($type) === null || $lag < -365 || $lag > 365) {
            return $this->ganttRefused(__('gantt.edit_refused'));
        }

        $existing = $this->findLink($fromRowId, $toRowId);

        if ($existing === null && $this->linkWouldCycle($this->projectId, $fromRowId, $toRowId)) {
            return $this->ganttRefused(__('gantt.link_would_loop'));
        }

        // As in MS Project, a summary row cannot be linked to its own sub-items.
        if ($this->isAncestorRow($fromRowId, $toRowId) || $this->isAncestorRow($toRowId, $fromRowId)) {
            return $this->ganttRefused(__('gantt.link_to_own_summary'));
        }

        $previous = $existing === null ? null : ['type' => $existing->type->value, 'lag' => $existing->lag_days];

        return DB::transaction(function () use ($fromRowId, $toRowId, $type, $lag, $previous): array {
            [$fromType, $fromId] = explode('-', $fromRowId);
            [$toType, $toId] = explode('-', $toRowId);

            ScheduleDependency::query()->updateOrCreate(
                ['predecessor_type' => $fromType, 'predecessor_id' => (int) $fromId, 'successor_type' => $toType, 'successor_id' => (int) $toId],
                ['project_id' => $this->projectId, 'type' => $type, 'lag_days' => $lag],
            );

            return $this->ganttSaved($this->autoSchedule($this->ganttProject()), $previous);
        });
    }

    /**
     * Remove a link. previous holds it, so undo can put it back.
     *
     * @return GanttAnswer
     */
    public function ganttUnlink(string $fromRowId, string $toRowId): array
    {
        $existing = $this->findLink($fromRowId, $toRowId);

        if ($existing === null) {
            return $this->ganttRefused(__('gantt.edit_refused'));
        }

        $previous = ['type' => $existing->type->value, 'lag' => $existing->lag_days];
        $existing->delete();

        return $this->ganttSaved([], $previous);
    }

    /**
     * Change the project's working week; linked items are re-checked against
     * the new calendar. previous holds the old preset.
     *
     * @return GanttAnswer
     */
    public function ganttSetWeekend(string $preset): array
    {
        if (! array_key_exists($preset, self::WEEKEND_PRESETS)) {
            return $this->ganttRefused(__('gantt.edit_refused'));
        }

        $project = $this->ganttProject();
        $previous = $project->schedule_weekend;

        return DB::transaction(function () use ($project, $preset, $previous): array {
            $project->update(['schedule_weekend' => $preset]);

            return $this->ganttSaved($this->autoSchedule($project), $previous);
        });
    }

    /**
     * Freeze the current schedule as a named baseline and show it.
     *
     * @return GanttAnswer
     */
    public function ganttSaveBaseline(string $name = ''): array
    {
        $name = trim($name) === ''
            ? __('gantt.baseline_default_name', ['date' => now()->translatedFormat(__('formats.date'))])
            : mb_substr(trim($name), 0, 255);

        $baseline = DB::transaction(function () use ($name): ScheduleBaseline {
            $baseline = ScheduleBaseline::query()->create(['project_id' => $this->projectId, 'name' => $name, 'created_by' => auth()->id()]);

            foreach ($this->scheduleItems($this->projectId) as $rowId => $item) {
                [$type, $id] = explode('-', $rowId);
                $baseline->items()->create([
                    'item_type' => $type,
                    'item_id' => (int) $id,
                    'start_date' => $this->isoDate($item['start']),
                    'end_date' => $this->isoDate($item['end']),
                    'progress' => $item['model'] instanceof Task ? (int) $item['model']->progress_percentage : 0,
                ]);
            }

            return $baseline;
        });

        $this->ganttBaselineId = $baseline->id;
        Flux::toast(variant: 'success', text: __('gantt.baseline_saved'));

        return $this->ganttSaved();
    }

    /**
     * Show a saved baseline under the bars, or hide baselines with null.
     *
     * @return GanttAnswer
     */
    public function ganttShowBaseline(?int $baselineId): array
    {
        if ($baselineId !== null && ! ScheduleBaseline::query()->where('project_id', $this->projectId)->whereKey($baselineId)->exists()) {
            return $this->ganttRefused(__('gantt.edit_refused'));
        }

        $this->ganttBaselineId = $baselineId;

        return $this->ganttSaved();
    }

    /**
     * @return GanttAnswer
     */
    public function ganttDeleteBaseline(int $baselineId): array
    {
        $deleted = ScheduleBaseline::query()->where('project_id', $this->projectId)->whereKey($baselineId)->delete();

        if ($deleted === 0) {
            return $this->ganttRefused(__('gantt.edit_refused'));
        }

        if ($this->ganttBaselineId === $baselineId) {
            $this->ganttBaselineId = null;
        }

        return $this->ganttSaved();
    }

    /**
     * The milestone or task behind a chart row id, only when it belongs to
     * this page's project.
     */
    private function ganttRecord(string $rowId): Milestone|Task|null
    {
        if (preg_match('/^(milestone|task)-(\d+)$/', $rowId, $matches) !== 1) {
            return null;
        }

        $projectMilestones = Milestone::query()->where('project_id', $this->projectId)->select('id');

        return $matches[1] === 'milestone'
            ? Milestone::query()->where('project_id', $this->projectId)->find((int) $matches[2])
            : Task::query()->where('source_type', 'milestone')->whereIn('source_id', $projectMilestones)->find((int) $matches[2]);
    }

    /**
     * Whether $ancestorRowId sits above $rowId in the chart's WBS tree.
     */
    private function isAncestorRow(string $ancestorRowId, string $rowId): bool
    {
        $parents = collect($this->ganttPayload['rows'])->pluck('parent', 'id');

        for ($current = $parents->get($rowId); $current !== null; $current = $parents->get($current)) {
            if ($current === $ancestorRowId) {
                return true;
            }
        }

        return false;
    }

    private function ganttProject(): Project
    {
        return Project::query()->findOrFail($this->projectId);
    }

    private function findLink(string $fromRowId, string $toRowId): ?ScheduleDependency
    {
        [$fromType, $fromId] = array_pad(explode('-', $fromRowId, 2), 2, '0');
        [$toType, $toId] = array_pad(explode('-', $toRowId, 2), 2, '0');

        return ScheduleDependency::query()
            ->where('project_id', $this->projectId)
            ->where('predecessor_type', $fromType)->where('predecessor_id', (int) $fromId)
            ->where('successor_type', $toType)->where('successor_id', (int) $toId)
            ->first();
    }

    private function validDateRange(string $start, string $end): bool
    {
        return Validator::make(['start' => $start, 'end' => $end], [
            'start' => ['required', 'date_format:Y-m-d'],
            'end' => ['required', 'date_format:Y-m-d', 'after_or_equal:start'],
        ])->passes();
    }

    /**
     * @return array{id: string, start: string, end: string}
     */
    private function currentDates(Milestone|Task $record, string $rowId): array
    {
        $start = $record instanceof Milestone ? $record->planned_start_date : ($record->start_date ?? $record->due_date);
        $end = $record instanceof Milestone ? ($record->planned_end_date ?? $record->planned_start_date) : ($record->due_date ?? $start);

        return ['id' => $rowId, 'start' => (string) $start?->toDateString(), 'end' => (string) $end?->toDateString()];
    }

    /**
     * @param  list<array{id: string, start: string, end: string}>  $changes
     * @return GanttAnswer
     */
    private function ganttSaved(array $changes = [], mixed $previous = null): array
    {
        $this->forgetGanttState();

        return [
            'ok' => true,
            'payload' => $this->ganttPayload,
            'changes' => array_values(array_filter($changes, fn (array $change): bool => $change['start'] !== '' && $change['end'] !== '')),
            'previous' => $previous,
        ];
    }

    /**
     * @return GanttAnswer
     */
    private function ganttRefused(string $message): array
    {
        Flux::toast(variant: 'danger', text: $message);

        return ['ok' => false, 'payload' => $this->ganttPayload, 'changes' => [], 'previous' => null];
    }

    /**
     * Drop every computed value an edit can change, so the rest of the page
     * (progress ring, milestone tab) agrees with the chart after the save.
     */
    private function forgetGanttState(): void
    {
        unset($this->ganttPayload, $this->project, $this->milestonesFlat, $this->milestoneWindow, $this->milestoneProgressPercent);
    }
}
