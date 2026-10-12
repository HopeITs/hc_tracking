@use('Illuminate\Support\Number')

    {{-- Charts --}}
    <div class="grid grid-cols-1 gap-4 @3xl/dashboard:grid-cols-2 @5xl/dashboard:grid-cols-12">
        <x-ui.panel :title="__('dashboard.projects_by_status')" :subtitle="__('dashboard.projects_by_status_hint')" class="min-w-0 {{ $chartSpan }}">
            @php $projectChart = $this->projectStatusChart; @endphp
            <div class="px-4 pt-4 pb-3">
                <x-ui.chart
                    type="donut"
                    :label="__('dashboard.projects_by_status')"
                    :series="$projectChart['series']"
                    :categories="$projectChart['categories']"
                    :tones="$projectChart['tones']"
                    :total-label="__('dashboard.projects')"
                    :height="210"
                    :empty="__('dashboard.no_projects_yet')"
                />
                <ul class="mt-3 grid grid-cols-2 gap-x-4 gap-y-1.5 @md/dashboard:grid-cols-3" role="list">
                    @foreach ($projectChart['categories'] as $index => $category)
                        @php
                            $legendTone = match ($projectChart['tones'][$index]) {
                                'good' => 'bg-status-good',
                                'funding' => 'bg-funding',
                                'warn' => 'bg-status-warn',
                                'strong' => 'bg-secondary-dark dark:bg-ink-muted',
                                'muted' => 'bg-secondary-soft dark:bg-line-strong',
                                default => 'bg-secondary-light',
                            };
                        @endphp
                        <li class="flex items-center gap-1.5 text-xs text-secondary-medium dark:text-ink-muted" wire:key="project-legend-{{ $index }}">
                            <span class="{{ $legendTone }} size-2 shrink-0 rounded-full" aria-hidden="true"></span>
                            <span class="truncate">{{ $category }}</span>
                            <span class="ms-auto font-semibold tabular-nums text-secondary-dark dark:text-ink">{{ $projectChart['series'][$index] }}</span>
                        </li>
                    @endforeach
                </ul>
            </div>
        </x-ui.panel>

        @if ($canViewProposals)
        <x-ui.panel :title="__('dashboard.proposal_pipeline')" :subtitle="__('dashboard.proposal_pipeline_hint')" class="min-w-0 {{ $chartSpan }}">
            <x-slot:toolbar>
                <a href="{{ route('proposals.index') }}" wire:navigate class="rounded-md text-xs font-semibold text-secondary-medium underline-offset-4 hover:text-secondary-dark hover:underline focus-visible:outline-2 focus-visible:outline-primary dark:text-ink-muted dark:hover:text-ink">{{ __('dashboard.view_proposals') }}</a>
            </x-slot:toolbar>
            @php $pipeline = $this->pipelineChart; @endphp
            <div class="px-2 pt-3 pb-2">
                <x-ui.chart
                    type="bar"
                    horizontal
                    distributed
                    :label="__('dashboard.proposal_pipeline')"
                    :series="$pipeline['series']"
                    :categories="$pipeline['categories']"
                    :tones="['neutral', 'funding', 'good', 'warn']"
                    :height="268"
                    :empty="__('dashboard.no_proposals_yet')"
                />
            </div>
        </x-ui.panel>
        @endif

        @if ($canViewTransfers)
        <x-ui.panel :title="__('dashboard.received_per_month')" :subtitle="__('dashboard.received_per_month_hint')" class="min-w-0 {{ $receiptsSpan }}">
            <div class="px-2 pt-3 pb-2" x-data="{ currency: @js($receipts['currencies'][0]['currency'] ?? null) }">
                @if (count($receipts['currencies']) === 0)
                    <x-ui.chart type="bar" :label="__('dashboard.received_per_month')" :series="[]" :categories="$receipts['categories']" :height="268" :empty="__('dashboard.no_receipts_yet')" />
                @else
                    @if (count($receipts['currencies']) > 1)
                        <div class="mb-2 flex gap-1 px-2" role="group" aria-label="{{ __('dashboard.currency') }}">
                            @foreach ($receipts['currencies'] as $receipt)
                                <button
                                    type="button"
                                    x-on:click="currency = @js($receipt['currency'])"
                                    x-bind:aria-pressed="currency === @js($receipt['currency']) ? 'true' : 'false'"
                                    x-bind:class="currency === @js($receipt['currency']) ? 'bg-funding-soft text-funding' : 'text-secondary-medium hover:text-secondary-dark dark:text-ink-muted'"
                                    class="min-h-8 cursor-pointer rounded-md px-2.5 text-xs font-bold transition-colors duration-150 focus-visible:outline-2 focus-visible:outline-primary"
                                    wire:key="receipt-tab-{{ $receipt['currency'] }}"
                                >{{ $receipt['currency'] }}</button>
                            @endforeach
                        </div>
                    @endif
                    @foreach ($receipts['currencies'] as $receipt)
                        <div x-show="currency === @js($receipt['currency'])" @unless ($loop->first) style="display: none" @endunless wire:key="receipt-chart-{{ $receipt['currency'] }}">
                            <p class="px-2 text-xs text-secondary-medium dark:text-ink-muted">
                                {{ __('dashboard.received_last_12_months') }}
                                <span class="ms-1 text-base font-bold tabular-nums text-secondary-dark dark:text-ink">{{ Number::format($receipt['total'], maxPrecision: 0) }}</span>
                                <span class="text-[11px] font-semibold">{{ $receipt['currency'] }}</span>
                            </p>
                            <x-ui.chart
                                type="bar"
                                :label="__('dashboard.received_per_month').' · '.$receipt['currency']"
                                :series="[['name' => $receipt['currency'], 'data' => $receipt['data']]]"
                                :categories="$receipts['categories']"
                                :tones="['funding']"
                                :currency="$receipt['currency']"
                                :height="count($receipts['currencies']) > 1 ? 206 : 244"
                                :empty="__('dashboard.no_receipts_yet')"
                            />
                        </div>
                    @endforeach
                @endif
            </div>
        </x-ui.panel>
        @endif
    </div>
