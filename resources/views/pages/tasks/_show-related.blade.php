{{-- The "Related to" card on ⚡show.blade.php, under Details: what the task belongs to and why it exists. Each source shows its own facts (a proposal its donor and dates, a project its program and dates, a program its manager); the source loads its relations in task(). The name links to the record for whoever may open it. --}}
@use('App\Enums\TaskKind')
@php
    $relatedSource = $this->task->source;
    $relatedUrl = match (true) {
        $relatedSource instanceof \App\Models\Proposal => route('proposals.show', $relatedSource),
        $relatedSource instanceof \App\Models\Project => route('projects.show', $relatedSource),
        $relatedSource instanceof \App\Models\Program => route('programs.show', $relatedSource),
        default => null,
    };
    $relatedUrl = $relatedUrl !== null && auth()->user()->can('view', $relatedSource) ? $relatedUrl : null;
    $relatedType = match (true) {
        $relatedSource instanceof \App\Models\Proposal => __('tasks.related.proposal'),
        $relatedSource instanceof \App\Models\Project => __('tasks.related.project'),
        $relatedSource instanceof \App\Models\Program => __('tasks.related.program'),
        default => null,
    };
    $formatDate = fn ($date): string => $date?->translatedFormat(__('formats.date')) ?? '—';
    $relatedRows = [];

    if ($relatedSource instanceof \App\Models\Proposal) {
        $relatedRows = [
            [__('tasks.related.donor'), $relatedSource->donor?->name ?? '—'],
            [__('tasks.related.kind'), $relatedSource->kind?->label() ?? '—'],
            [__('tasks.related.number'), $relatedSource->internal_project_number ?: '—'],
            [__('tasks.related.planned_start'), $formatDate($relatedSource->planned_date_begin)],
            [__('tasks.related.planned_end'), $formatDate($relatedSource->planned_date_end)],
        ];

        if ($relatedSource->actual_date_begin !== null || $relatedSource->effectiveDateEnd() !== null) {
            $relatedRows[] = [__('tasks.related.actual_start'), $formatDate($relatedSource->actual_date_begin)];
            $relatedRows[] = [__('tasks.related.actual_end'), $formatDate($relatedSource->effectiveDateEnd())];
        }

        if ($relatedSource->projects->isNotEmpty()) {
            $relatedRows[] = [__('tasks.related.projects'), $relatedSource->projects->pluck('name')->join(', ')];
        }
    } elseif ($relatedSource instanceof \App\Models\Project) {
        $relatedRows = [
            [__('tasks.related.program'), $relatedSource->program?->name ?? '—'],
            [__('tasks.related.manager'), $relatedSource->user?->name ?? '—'],
            [__('tasks.related.city'), $relatedSource->city?->name ?? '—'],
            [__('tasks.related.start'), $formatDate($relatedSource->start_date)],
            [__('tasks.related.end'), $formatDate($relatedSource->end_date)],
        ];

        if ($relatedSource->target_beneficiaries) {
            $relatedRows[] = [__('tasks.related.beneficiaries'), number_format((int) $relatedSource->target_beneficiaries)];
        }

        if ($relatedSource->proposals->isNotEmpty()) {
            $relatedRows[] = [__('tasks.related.proposals'), $relatedSource->proposals->pluck('name')->join(', ')];
            $donorNames = $relatedSource->proposals->pluck('donor.name')->filter()->unique();

            if ($donorNames->isNotEmpty()) {
                $relatedRows[] = [__('tasks.related.donor'), $donorNames->join(', ')];
            }
        }
    } elseif ($relatedSource instanceof \App\Models\Program) {
        $relatedRows = [
            [__('tasks.related.manager'), $relatedSource->manager?->name ?? '—'],
            [__('tasks.related.strategy'), $relatedSource->strategy?->name ?? '—'],
            [__('tasks.related.projects_count'), (string) $relatedSource->projects_count],
        ];
    }

    $taskKind = $this->task->kind;
@endphp

<x-ui.panel :clip="false" class="mt-4" data-test="task-related-card">
    <h2 class="px-4 pt-3.5 pb-1.5 text-base font-bold text-secondary-dark dark:text-ink">{{ __('tasks.source_type') }}</h2>

    <div class="grid gap-1 px-4 pb-3">
        @if ($relatedType !== null)
            <span class="text-xs font-semibold tracking-wide text-secondary-medium uppercase dark:text-ink-muted">{{ $relatedType }}</span>
        @endif
        @if ($relatedUrl !== null)
            <a href="{{ $relatedUrl }}" wire:navigate class="text-sm font-semibold text-secondary-dark hover:underline dark:text-ink" dir="auto" data-test="task-related-to">{{ $this->task->sourceLabel() }}</a>
        @else
            <span class="text-sm font-semibold text-secondary-dark dark:text-ink" dir="auto" data-test="task-related-to">{{ $this->task->sourceLabel() }}</span>
        @endif
        @if (isset($relatedSource->status) && $relatedSource->status instanceof \App\Enums\Concerns\HasStatusTone)
            <div>
                <x-ui.enum-badge :case="$relatedSource->status" :label="$relatedSource instanceof \App\Models\Proposal ? $relatedSource->statusLabel() : null" data-test="task-related-status" />
            </div>
        @endif
    </div>

    <dl class="divide-y divide-secondary-soft border-t border-secondary-soft dark:divide-line dark:border-line" data-test="task-related-facts">
        @foreach ($relatedRows as [$factLabel, $factValue])
            <div class="flex min-h-10 items-start justify-between gap-3 px-4 py-2">
                <dt class="shrink-0 text-sm text-secondary-medium dark:text-ink-muted">{{ $factLabel }}</dt>
                <dd class="min-w-0 text-end text-sm font-semibold text-secondary-dark dark:text-ink" dir="auto">{{ $factValue }}</dd>
            </div>
        @endforeach

        @if ($taskKind !== null && $taskKind !== TaskKind::General)
            <div class="flex min-h-10 items-start justify-between gap-3 px-4 py-2">
                <dt class="shrink-0 text-sm text-secondary-medium dark:text-ink-muted">{{ __('tasks.related.task_kind') }}</dt>
                <dd class="min-w-0 text-end"><x-ui.enum-badge :case="$taskKind" /></dd>
            </div>
        @endif

        @if ($this->task->planMilestone?->plan !== null)
            <div class="flex min-h-10 items-start justify-between gap-3 px-4 py-2">
                <dt class="shrink-0 text-sm text-secondary-medium dark:text-ink-muted">{{ __('tasks.related.checkpoint') }}</dt>
                <dd class="min-w-0 text-end"><a href="{{ route('activity-plans.show', $this->task->planMilestone->plan) }}" wire:navigate class="text-sm font-semibold hover:underline" dir="auto">{{ $this->task->planMilestone->title }}</a></dd>
            </div>
        @endif

        @if ($this->task->parentTask !== null)
            <div class="flex min-h-10 items-start justify-between gap-3 px-4 py-2">
                <dt class="shrink-0 text-sm text-secondary-medium dark:text-ink-muted">{{ __('tasks.related.parent_task') }}</dt>
                <dd class="min-w-0 text-end"><a href="{{ route('tasks.show', $this->task->parentTask) }}" wire:navigate class="text-sm font-semibold hover:underline" dir="auto">{{ $this->task->parentTask->title }}</a></dd>
            </div>
        @endif
    </dl>
</x-ui.panel>
