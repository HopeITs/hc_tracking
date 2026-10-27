{{--
    The lifecycle rail and the portfolio/triage figures of projects/index: the
    status filter, the committed funding and beneficiary tiles, and the four
    triage lenses. Included by ⚡index.blade.php, whose top PHP block defines
    $statusCounts, $triageCounts, $canViewMoney and $lensTiles.
--}}
@use('App\Enums\ProjectStatus')
    {{-- The pipeline, which is also the status filter. Six equal segments on
         one baseline; the selected one is the only large red area here. --}}
    <div role="group" aria-label="{{ __('projects.lifecycle') }}" class="grid grid-flow-col auto-cols-[minmax(7.5rem,1fr)] snap-x gap-px overflow-x-auto rounded-xl border border-secondary-soft bg-secondary-soft dark:border-line dark:bg-line">
        @foreach (ProjectStatus::live() as $case)
            @php
                $segmentCount = $statusCounts[$case->value] ?? 0;
                $isSelected = $status === $case->value;
            @endphp

            <button
                type="button"
                wire:click="toggleStatus('{{ $case->value }}')"
                aria-pressed="{{ $isSelected ? 'true' : 'false' }}"
                data-test="lifecycle-{{ $case->value }}"
                @class([
                    'flex min-h-16 snap-start cursor-pointer flex-col justify-center gap-1 px-4 py-3 text-start transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-secondary-medium',
                    'bg-primary text-white dark:bg-white/[0.06] dark:text-ink' => $isSelected,
                    'bg-white text-secondary-dark hover:bg-secondary-soft/50 dark:bg-surface-1 dark:text-ink dark:hover:bg-surface-2' => ! $isSelected,
                ])
            >
                <span class="sr-only">{{ trans_choice('projects.lifecycle_segment', $segmentCount, ['status' => $case->label(), 'count' => $segmentCount]) }}</span>
                <span aria-hidden="true" class="type-label truncate {{ $isSelected ? 'text-white/80' : 'text-secondary-medium dark:text-ink-muted' }}">{{ $case->label() }}</span>
                <span aria-hidden="true" class="text-2xl leading-none font-bold tabular-nums">{{ $segmentCount }}</span>
            </button>
        @endforeach
    </div>

    {{-- Two readings of the same filtered portfolio, then the four decisions
         waiting on it. Kept apart because they answer different questions. --}}
    <div class="grid gap-4 @4xl/directory:grid-cols-[minmax(0,2fr)_minmax(0,3fr)]">
        <section aria-labelledby="portfolio-figures-heading">
            <h2 id="portfolio-figures-heading" class="type-label mb-2 text-secondary-medium dark:text-ink-muted">{{ __('projects.group_portfolio') }}</h2>

            <div @class([
                'grid min-h-[calc(100%-1.75rem)] gap-px overflow-hidden rounded-xl border border-secondary-soft bg-secondary-soft dark:border-line dark:bg-line',
                'grid-cols-2' => $canViewMoney,
                'grid-cols-1' => ! $canViewMoney,
            ])>
                @if ($canViewMoney)
                <div class="flex flex-col justify-between gap-2 bg-white p-4 dark:bg-surface-1">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('projects.tile_committed_funding') }}</p>
                    <div>
                        @forelse ($this->committedFunding as $fundingCurrency => $fundingTotal)
                            <p class="type-figure" wire:key="committed-{{ $fundingCurrency }}">
                                <x-ui.money :amount="$fundingTotal" :currency="$fundingCurrency" />
                            </p>
                        @empty
                            <p class="type-figure text-secondary-light">—</p>
                        @endforelse
                    </div>
                    <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('projects.tile_committed_funding_hint') }}</p>
                </div>
                @endif

                <div class="flex flex-col justify-between gap-2 bg-white p-4 dark:bg-surface-1">
                    <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('projects.tile_beneficiaries') }}</p>
                    <p class="type-figure">{{ number_format($this->committedBeneficiaries) }}</p>
                    <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('projects.tile_beneficiaries_hint') }}</p>
                </div>
            </div>
        </section>

        <section aria-labelledby="portfolio-triage-heading">
            <h2 id="portfolio-triage-heading" class="type-label mb-2 text-secondary-medium dark:text-ink-muted">{{ __('projects.group_attention') }}</h2>

            <div class="grid min-h-[calc(100%-1.75rem)] grid-cols-2 gap-px overflow-hidden rounded-xl border border-secondary-soft bg-secondary-soft @2xl/directory:grid-cols-4 dark:border-line dark:bg-line">
                @foreach ($lensTiles as $lensValue => $lensTile)
                    @php
                        $lensCount = $triageCounts[$lensValue] ?? 0;
                        $lensIsActive = $lens === $lensValue;
                        $lensIsQuiet = $lensCount === 0;

                        // The grid is 2x2 below @2xl/directory and a single row of 4 from
                        // it up, so which corners each tile owns changes at that breakpoint.
                        // Match the container's rounded-xl so an active tile's own border
                        // isn't squared off where the container clips it round.
                        $lensCornerClasses = match ($loop->index) {
                            0 => 'rounded-ss-xl @2xl/directory:rounded-es-xl',
                            1 => 'rounded-se-xl @2xl/directory:rounded-se-none',
                            2 => 'rounded-es-xl @2xl/directory:rounded-es-none',
                            3 => 'rounded-ee-xl @2xl/directory:rounded-se-xl',
                        };
                    @endphp

                    <button
                        type="button"
                        wire:click="toggleLens('{{ $lensValue }}')"
                        @disabled($lensIsQuiet)
                        aria-pressed="{{ $lensIsActive ? 'true' : 'false' }}"
                        data-test="lens-{{ $lensValue }}"
                        wire:key="tile-lens-{{ $lensValue }}"
                        @class([
                            'box-border flex flex-col justify-between gap-2 border-2 p-4 text-start transition-colors focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-secondary-medium',
                            $lensCornerClasses,
                            'cursor-pointer border-white bg-white hover:border-secondary-soft/50 hover:bg-secondary-soft/50 dark:border-surface-1 dark:bg-surface-1 dark:hover:border-surface-2 dark:hover:bg-surface-2' => ! $lensIsQuiet && ! $lensIsActive,
                            'cursor-pointer border-primary bg-white dark:bg-surface-1' => $lensIsActive,
                            'cursor-default border-white bg-white dark:border-surface-1 dark:bg-surface-1' => $lensIsQuiet,
                        ])
                    >
                        <span class="type-label flex items-center gap-1.5 text-secondary-medium dark:text-ink-muted">
                            <flux:icon :name="$lensTile['icon']" variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                            <span class="truncate">{{ $lensTile['label'] }}</span>
                        </span>
                        <span class="type-figure {{ $lensIsQuiet ? 'text-secondary-light' : 'text-primary dark:text-brand-text' }}">{{ $lensCount }}</span>
                        <span class="type-meta text-secondary-medium dark:text-ink-muted">{{ $lensTile['hint'] }}</span>
                    </button>
                @endforeach
            </div>
        </section>
    </div>
