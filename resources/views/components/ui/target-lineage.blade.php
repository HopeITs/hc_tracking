@props(['groups', 'format'])

@php
    use App\Enums\ResultType;

    $mark = fn (ResultType $type): string => match ($type) {
        ResultType::Impact => 'bg-secondary-dark ring-secondary-dark dark:bg-ink dark:ring-ink',
        ResultType::Outcome => 'bg-primary ring-primary',
        ResultType::Output => 'bg-board ring-secondary-light',
        ResultType::Activity => 'bg-transparent ring-secondary-medium dark:ring-ink-muted',
        ResultType::CrossCutting => 'bg-secondary-light ring-secondary-light dark:bg-ink-muted dark:ring-ink-muted',
    };

    $accent = fn (ResultType $type): string => match ($type) {
        ResultType::Impact => 'h-1 bg-primary',
        ResultType::Outcome => 'h-0.5 bg-primary/60',
        ResultType::Output, ResultType::Activity, ResultType::CrossCutting => 'h-0.5 bg-transparent',
    };
@endphp

<div {{ $attributes->class('flex flex-col gap-4') }}>
    @foreach ($groups as $index => $group)
        <article
            class="logframe-reveal overflow-hidden rounded-xl border border-secondary-soft bg-white shadow-card dark:border-transparent dark:bg-surface-1 dark:shadow-raised"
            style="--logframe-order: {{ $index }}"
            wire:key="target-result-{{ $group['result']->id }}"
        >
            <span aria-hidden="true" class="block {{ $accent($group['result']->type) }}"></span>

            <header class="flex flex-wrap items-start gap-3 border-b border-secondary-soft px-4 py-3 dark:border-line">
                <span class="mt-0.5 inline-flex shrink-0 items-center gap-1.5">
                    <span aria-hidden="true" class="size-2.5 rounded-full ring-2 ring-inset {{ $mark($group['result']->type) }}"></span>
                    <span class="type-label text-secondary-medium dark:text-ink-muted">{{ $group['result']->type->label() }}</span>
                </span>
                <p class="min-w-0 flex-1 text-sm font-semibold text-secondary-dark dark:text-ink">{{ $group['result']->statement }}</p>
                <span class="shrink-0 rounded-full border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-ink-muted">
                    {{ trans_choice('projects.count_indicator', $group['indicators']->count(), ['count' => $group['indicators']->count()]) }}
                </span>
            </header>

            <div class="divide-y divide-secondary-soft dark:divide-line">
                @foreach ($group['indicators'] as $indicatorGroup)
                    <div class="flex flex-col gap-3 p-4" wire:key="target-indicator-{{ $indicatorGroup['indicator']->id }}">
                        <div class="flex items-center gap-2">
                            <flux:icon.chart-bar variant="micro" class="size-4 shrink-0 text-secondary-medium dark:text-ink-muted" aria-hidden="true" />
                            <p class="min-w-0 flex-1 truncate text-sm font-semibold text-secondary-dark dark:text-ink">{{ $indicatorGroup['indicator']->name }}</p>
                            <x-ui.enum-badge :case="$indicatorGroup['indicator']->measure" class="shrink-0" />
                        </div>

                        <div class="flex flex-col gap-2 border-s border-secondary-soft ps-4 @xl/detail:flex-row @xl/detail:flex-wrap dark:border-line">
                            @foreach ($indicatorGroup['targets'] as $target)
                                @php $hasReports = $target->reports_count > 0; @endphp
                                <div
                                    class="flex flex-1 flex-wrap items-center gap-3 rounded-lg border border-secondary-soft p-3 @xl/detail:min-w-56 dark:border-line"
                                    wire:key="project-target-{{ $target->id }}"
                                >
                                    <div class="flex flex-wrap items-center gap-1.5">
                                        <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-ink-muted">
                                            {{ $target->source_type === 'annual_plan' ? __('common.annual_plan') : __('common.proposal') }}
                                        </span>
                                        <x-ui.enum-badge :case="$target->period" />
                                    </div>

                                    <p class="type-figure ms-auto text-secondary-dark dark:text-ink">{{ $format($target) }}</p>

                                    <flux:tooltip :content="$hasReports ? trans_choice('common.count_report', $target->reports_count, ['count' => $target->reports_count]) : __('projects.target_no_reports_yet')">
                                        <span @class([
                                            'inline-flex items-center gap-1 rounded-full px-2 py-0.5 text-[11px] font-semibold',
                                            'bg-status-good-soft text-status-good' => $hasReports,
                                            'bg-status-warn-soft text-status-warn' => ! $hasReports,
                                        ])>
                                            <flux:icon :name="$hasReports ? 'document-check' : 'exclamation-triangle'" variant="micro" class="size-3.5" />
                                            {{ $target->reports_count }}
                                        </span>
                                    </flux:tooltip>
                                </div>
                            @endforeach
                        </div>
                    </div>
                @endforeach
            </div>
        </article>
    @endforeach
</div>
