{{-- The optional "Update risks" line on ⚡show.blade.php: while filling, a checkbox; when ticked, every logframe risk of the report's proposals with a "Happened" tick (pre-ticked from its last answer). Read only: the saved answers, only when the report carries the line. --}}
@php
    $fillsNarrative = $this->fillsNarrative();
    $showsRisks = $fillsNarrative || $this->report->updates_risks;
    $risks = $showsRisks && ($updatesRisks || ! $fillsNarrative) ? $this->risks : [];
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $tick = 'size-4 rounded border-secondary-light text-[#be1f24] focus:ring-secondary-light dark:border-line-strong';
    $groups = collect($risks)->groupBy(fn (array $risk): string => (string) $risk['proposal']);
@endphp

@if ($showsRisks)
    <section aria-labelledby="monthly-report-risks-heading" class="flex flex-col gap-3" data-monthly-report-risks>
        <div>
            <h2 id="monthly-report-risks-heading" class="mrp-h2">{{ __('monthly_reports.risks_heading') }}@if ($risks !== []) <span class="mrp-count">{{ count($risks) }}</span>@endif</h2>
            @if ($fillsNarrative)
                <label class="mt-1 inline-flex cursor-pointer items-center gap-2 text-sm font-semibold text-secondary-dark dark:text-ink">
                    <input type="checkbox" wire:model.live="updatesRisks" class="{{ $tick }}" data-update-risks>
                    {{ __('monthly_reports.update_risks') }}
                </label>
                @if ($updatesRisks && $risks !== [])
                    <p class="type-meta {{ $mutedText }}">{{ __('monthly_reports.update_risks_help') }}</p>
                @endif
            @endif
        </div>

        @if ($updatesRisks || ! $fillsNarrative)
            @if ($risks === [])
                @include('pages.monthly-reports._show-empty-line', ['key' => 'risks', 'icon' => 'shield-exclamation', 'heading' => __('monthly_reports.no_risks'), 'body' => null])
            @else
                @foreach ($groups as $proposalName => $groupRisks)
                    @if ($proposalName !== '')
                        <p class="type-meta font-semibold {{ $mutedText }}" dir="auto">{{ $proposalName }}</p>
                    @endif
                    <ol class="mrp-stack" role="list">
                        @foreach ($groupRisks as $risk)
                            @php($happened = $fillsNarrative ? (bool) ($riskHappened[$risk['id']] ?? false) : (bool) $risk['answer'])
                            <li @class(['mrp-act', 'mrp-act-stopped' => $happened]) wire:key="risk-{{ $risk['id'] }}" data-risk="{{ $risk['id'] }}">
                                <div class="mrp-act-body">
                                    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-2">
                                        <div class="min-w-0 flex-1 basis-64">
                                            <p class="mrp-act-title" dir="auto">{{ $risk['name'] }}</p>
                                            @if ($risk['score'] !== null)
                                                <p class="mt-0.5 text-xs tabular-nums {{ $mutedText }}">{{ __('monthly_reports.risk_rating', ['likelihood' => $risk['likelihood'], 'severity' => $risk['severity']]) }}</p>
                                            @endif
                                        </div>
                                        @if ($fillsNarrative)
                                            <label class="inline-flex cursor-pointer items-center gap-2 text-sm font-semibold text-secondary-dark dark:text-ink">
                                                <input type="checkbox" wire:model.live="riskHappened.{{ $risk['id'] }}" class="{{ $tick }}" data-risk-happened="{{ $risk['id'] }}">
                                                {{ __('monthly_reports.risk_happened') }}
                                            </label>
                                        @elseif ($happened)
                                            <span class="mrp-pill mrp-pill-stopped" data-risk-answer="{{ $risk['id'] }}">{{ __('monthly_reports.risk_happened') }}</span>
                                        @endif
                                    </div>
                                </div>
                            </li>
                        @endforeach
                    </ol>
                @endforeach
            @endif
        @endif
    </section>
@endif
