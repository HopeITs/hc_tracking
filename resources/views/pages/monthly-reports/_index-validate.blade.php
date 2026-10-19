{{-- "To validate" on ⚡index.blade.php: the MEAL queue (MonthlyReport::validatableBy()), filtered in memory. --}}
@use('App\Enums\MonthlyReportStatus')
<x-ui.panel>
    @if ($filtersAreActive)
        <x-slot:toolbar>
            <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-ink-muted!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
        </x-slot:toolbar>
    @endif

    @if ($this->filteredQueue->isEmpty())
        <x-ui.empty-state
            icon="check-badge"
            :heading="$filtersAreActive ? __('monthly_reports.empty_filtered') : __('monthly_reports.empty_validate')"
            :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('monthly_reports.empty_validate_body')"
        />
    @else
        <div class="divide-y divide-secondary-soft dark:divide-line">
            @foreach ($this->filteredQueue as $queued)
                @php
                    $queuedCity = $queued->project->city_id === null ? null : ($this->cityNames[(int) $queued->project->city_id] ?? null);
                    $queuedState = match ($queued->status) {
                        MonthlyReportStatus::Draft => 'draft',
                        MonthlyReportStatus::Submitted => 'submitted',
                        MonthlyReportStatus::Validated => 'validated',
                    };
                @endphp
                <article class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-4 transition-colors duration-150 hover:bg-board-head" data-validate-row="{{ $queued->id }}" wire:key="queued-{{ $queued->id }}">
                    <div class="min-w-0 flex-1">
                        <p class="flex flex-wrap items-center gap-2">
                            <span class="text-base font-bold" dir="auto">{{ $queued->project->name }}</span>
                            <x-monthly-reports.state-chip :state="$queuedState" />
                            @if ($queued->seen_by_meal_at === null)
                                <span class="inline-flex items-center gap-1 text-xs font-semibold text-secondary-medium dark:text-ink-muted">
                                    <span class="size-1.5 rounded-full bg-primary dark:bg-ink" aria-hidden="true"></span>
                                    {{ __('monthly_reports.not_seen_yet') }}
                                </span>
                            @endif
                        </p>
                        <p class="mt-0.5 flex flex-wrap gap-x-3 type-meta text-secondary-medium dark:text-ink-muted">
                            <span>{{ $queued->period->translatedFormat('F Y') }}</span>
                            @if ($queuedCity !== null)
                                <span dir="auto">{{ $queuedCity }}</span>
                            @endif
                            @if ($queued->submitted_at !== null)
                                <span class="tabular-nums">{{ __('monthly_reports.submitted_on', ['date' => $queued->submitted_at->translatedFormat(__('formats.date'))]) }}</span>
                            @endif
                        </p>
                    </div>

                    <x-ui.button tone="ghost" size="sm" icon="eye" :href="route('monthly-reports.show', $queued)" wire:navigate :aria-label="__('common.view_name', ['name' => $queued->project->name.' · '.$queued->period->translatedFormat('F Y')])" />
                </article>
            @endforeach
        </div>
    @endif
</x-ui.panel>
