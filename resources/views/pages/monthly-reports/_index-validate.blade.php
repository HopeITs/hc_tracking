{{-- "To validate" on ⚡index.blade.php: the MEAL queue (MonthlyReport::validatableBy()), filtered in memory and grouped by report template (D-76), named by its place and proposals, so a validator reads one template's months together and two reports of one place are told apart. The filters' one way back is the register bar's Clear; this panel used to repeat it as "Reset filters". --}}
@use('App\Enums\MonthlyReportStatus')
<x-ui.panel>
    @if ($this->filteredQueue->isEmpty())
        <x-ui.empty-state
            icon="check-badge"
            :heading="$filtersAreActive ? __('monthly_reports.empty_filtered') : __('monthly_reports.empty_validate')"
            :body="$filtersAreActive ? __('common.change_the_filters_and_try_again') : __('monthly_reports.empty_validate_body')"
        />
    @else
        <div class="divide-y divide-secondary-soft dark:divide-line">
            @foreach ($this->filteredQueue->groupBy('report_template_id')->sortBy(fn ($templateRows) => mb_strtolower(($this->queueNames[$templateRows->first()->report_template_id]['place'] ?? '').' '.($this->queueNames[$templateRows->first()->report_template_id]['proposalsText'] ?? ''))) as $templateId => $projectRows)
                @php
                    $queuedNames = $this->queueNames[$templateId] ?? ['place' => '', 'proposals' => [], 'proposalsText' => ''];
                    $queuedName = ($queuedNames['proposalsText'] !== '' ? $queuedNames['proposalsText'] : __('monthly_reports.no_proposal')).' · '.$queuedNames['place'];
                @endphp
                <section aria-labelledby="queue-template-{{ $templateId }}" data-queue-template="{{ $templateId }}" wire:key="queue-template-{{ $templateId }}">
                    <div class="px-4 pt-4 pb-1">
                        <h3 id="queue-template-{{ $templateId }}" class="text-base font-bold" dir="auto">{{ $queuedNames['place'] }}</h3>
                        @include('pages.monthly-reports._covers-proposals', ['proposals' => $queuedNames['proposals']])
                    </div>

                    @foreach ($projectRows as $queued)
                        @php
                            $queuedState = match ($queued->status) {
                                MonthlyReportStatus::Draft => 'draft',
                                MonthlyReportStatus::Submitted => 'submitted',
                                MonthlyReportStatus::Validated => 'validated',
                            };
                        @endphp
                        <article class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3 transition-colors duration-150 hover:bg-board-head" data-validate-row="{{ $queued->id }}" wire:key="queued-{{ $queued->id }}">
                            <div class="min-w-0 flex-1">
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="text-base font-semibold">{{ $queued->period->translatedFormat('F Y') }}</span>
                                    <x-monthly-reports.state-chip :state="$queuedState" />
                                    @if ($queued->seen_by_meal_at === null && $queued->status === MonthlyReportStatus::Submitted)
                                        <span class="inline-flex items-center gap-1 text-xs font-semibold text-secondary-medium dark:text-ink-muted">
                                            <span class="size-1.5 rounded-full bg-primary dark:bg-ink" aria-hidden="true"></span>
                                            {{ __('monthly_reports.not_seen_yet') }}
                                        </span>
                                    @endif
                                    {{-- How long it has waited, in days. A plain fact, not a "late" flag: no validation deadline is set anywhere, so none is invented here. --}}
                                    @if ($queued->status === MonthlyReportStatus::Submitted && $queued->submitted_at !== null)
                                        @php
                                            $waitingDays = (int) $queued->submitted_at->startOfDay()->diffInDays(\Carbon\CarbonImmutable::today());
                                        @endphp
                                        <span class="inline-flex items-center gap-1 rounded-md border border-secondary-soft px-1.5 py-0.5 text-xs font-semibold tabular-nums text-secondary-dark dark:border-line dark:text-ink" data-waiting="{{ $queued->id }}">
                                            <flux:icon.clock variant="micro" class="size-3.5" aria-hidden="true" />
                                            {{ trans_choice('monthly_reports.waiting_days', $waitingDays, ['count' => $waitingDays]) }}
                                        </span>
                                    @endif
                                </p>
                                @if ($queued->submitted_at !== null)
                                    <p class="mt-0.5 type-meta tabular-nums text-secondary-medium dark:text-ink-muted">{{ __('monthly_reports.submitted_on', ['date' => $queued->submitted_at->translatedFormat(__('formats.date'))]) }}</p>
                                @endif
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <x-ui.button tone="ghost" size="sm" icon="eye" class="max-sm:size-11" :href="route('monthly-reports.show', $queued)" wire:navigate :aria-label="__('common.view_name', ['name' => $queuedName.' · '.$queued->period->translatedFormat('F Y')])" />
                                {{-- Back to the monthly matrix for this template's place, with the list's proposal and window (Y-05). --}}
                                <x-ui.button tone="ghost" size="sm" icon="table-cells" class="max-sm:size-11" :href="$this->matrixUrlFor($queued->template)" wire:navigate :aria-label="__('monthly_reports.open_in_matrix', ['name' => $queuedNames['place']])" data-matrix-link="{{ $queued->id }}" />
                            </div>
                        </article>
                    @endforeach
                </section>
            @endforeach
        </div>
    @endif
</x-ui.panel>
