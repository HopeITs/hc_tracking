{{--
    The strategies register table: each strategy's period and status, then what
    its programs add up to (StrategyRollup). Every figure opens its register
    already narrowed to that strategy. A figure the viewer may not see is a
    column they never get.
--}}
@php
    use App\Support\Desk\Money;

    $rollups = $this->rollups;
    $firstRollup = $rollups === [] ? null : reset($rollups);
    $showResults = $firstRollup !== null && $firstRollup['results'] !== null;
    $showFunding = $firstRollup !== null && $firstRollup['funding'] !== null;
    $showRisks = $firstRollup !== null && $firstRollup['risks'] !== null;
    $showLate = $firstRollup !== null && $firstRollup['late'] !== null;

    $dotClasses = fn (?string $tone): string => match ($tone) {
        'good' => 'bg-status-good',
        'warn' => 'bg-status-warn',
        'critical' => 'bg-primary dark:bg-status-critical',
        default => 'bg-secondary-light dark:bg-ink-faint',
    };
    $toneWord = fn (?string $tone): string => match ($tone) {
        'good' => __('strategies.tone_good'),
        'warn' => __('strategies.tone_warn'),
        'critical' => __('strategies.tone_critical'),
        default => '',
    };
    $figureColumns = 1 + (int) $showResults + (int) $showFunding + (int) $showRisks + (int) $showLate;
    $figureLink ='font-semibold tabular-nums text-secondary-dark underline-offset-2 hover:underline dark:text-ink';
    $muted = 'text-secondary-medium dark:text-ink-muted';
@endphp

<x-ui.data-table :caption="__('strategies.strategic_planning_cycles')">
    <x-slot:head>
        <th scope="col">{{ __('common.strategy') }}</th>
        <th scope="col" class="text-end!">{{ __('common.programs') }}</th>
        @if ($showResults)
            <th scope="col" class="text-end!">{{ __('strategies.col_targets') }}</th>
        @endif
        @if ($showFunding)
            <th scope="col" class="text-end!">{{ __('strategies.col_funding') }}</th>
        @endif
        @if ($showRisks)
            <th scope="col" class="text-end!">{{ __('strategies.col_high_risks') }}</th>
        @endif
        @if ($showLate)
            <th scope="col" class="text-end!">{{ __('strategies.col_late') }}</th>
        @endif
        <th scope="col"><span class="sr-only">{{ __('common.actions') }}</span></th>
    </x-slot:head>

    @foreach ($portfolio['paginated_strategies'] as $strategy)
        @php
            $rollup = $rollups[$strategy['id']] ?? null;
            $filtered = fn (string $route, array $extra = []): string => route($route, ['strategy' => $strategy['id'], ...$extra]);
        @endphp
        <tr class="group" wire:key="strategy-row-{{ $strategy['id'] }}">
            <x-ui.data-table.cell :label="__('common.strategy')">
                <span class="flex min-w-0 flex-col gap-1 @max-3xl:items-end">
                    <span class="font-semibold text-secondary-dark dark:text-ink">{{ $strategy['name'] }}</span>
                    <span class="type-meta flex flex-wrap items-center gap-x-2 gap-y-1 whitespace-nowrap {{ $muted }}">
                        <span class="{{ $statusChipClasses($strategy['status']) }} inline-flex shrink-0 items-center rounded-md px-2 py-0.5 text-xs font-semibold">{{ $statusLabel($strategy['status']) }}</span>
                        <span><bdi class="tabular-nums">{{ $strategy['start_year'] }}–{{ $strategy['end_year'] }}</bdi> · {{ trans_choice('strategies.count_years', $strategy['duration_years'], ['count' => $strategy['duration_years']]) }}</span>
                    </span>
                </span>
            </x-ui.data-table.cell>

            @if ($strategy['programs_count'] === 0)
                {{-- Nothing rolls up without programs: one quiet line instead of a row of dashes. --}}
                <x-ui.data-table.cell colspan="{{ $figureColumns }}" data-test="strategy-no-programs">
                    <span class="flex flex-wrap items-center gap-x-2 gap-y-1 @3xl:justify-end">
                        <span class="type-meta {{ $muted }}">{{ __('strategies.no_programs_yet_short') }}</span>
                        @if ($this->canLinkPrograms)
                            <span class="type-meta {{ $muted }}" aria-hidden="true">·</span>
                            <button type="button" wire:click="openProgramsModal({{ $strategy['id'] }})" class="text-xs font-semibold text-primary underline-offset-2 hover:underline dark:text-brand-text" data-test="strategy-link-programs">{{ __('strategies.link_programs') }}</button>
                        @endif
                    </span>
                </x-ui.data-table.cell>
            @else
            <x-ui.data-table.cell :label="__('common.programs')" numeric>
                <a href="{{ $filtered('programs.index') }}" wire:navigate class="{{ $figureLink }}" data-test="strategy-programs-link">{{ $strategy['programs_count'] }}</a>
            </x-ui.data-table.cell>

            @if ($showResults)
                @php $results = $rollup['results']; @endphp
                <x-ui.data-table.cell :label="__('strategies.col_targets')" numeric>
                    @if ($results['total'] === 0)
                        <span class="{{ $muted }}" title="{{ __('strategies.no_rated_targets') }}">—<span class="sr-only">{{ __('strategies.no_rated_targets') }}</span></span>
                    @else
                        <a href="{{ $filtered('indicators.index') }}" wire:navigate class="{{ $figureLink }} inline-flex items-center gap-1.5" title="{{ __('strategies.targets_on_pace_hint', ['on' => $results['on'], 'total' => $results['total']]) }}" data-test="strategy-targets-link">
                            <span class="size-2 shrink-0 rounded-full {{ $dotClasses($results['tone']) }}" aria-hidden="true"></span>
                            <bdi>{{ $results['on'] }}/{{ $results['total'] }}</bdi>
                            <span class="sr-only">{{ __('strategies.targets_on_pace_hint', ['on' => $results['on'], 'total' => $results['total']]) }} {{ $toneWord($results['tone']) }}</span>
                        </a>
                    @endif
                </x-ui.data-table.cell>
            @endif

            @if ($showFunding)
                @php
                    $funding = $rollup['funding'];
                    $lines = collect($funding['lines']);
                    $fundingHint = $lines->map(fn (array $line): string => $line['requested'] > 0
                        ? __('strategies.funding_line_requested', ['agreed' => Money::format($line['agreed'], $line['currency']), 'requested' => Money::format($line['requested'], $line['currency']), 'percent' => $line['percent']])
                        : __('strategies.funding_line_agreed', ['agreed' => Money::format($line['agreed'], $line['currency'])]))->implode(' · ');
                @endphp
                <x-ui.data-table.cell :label="__('strategies.col_funding')" numeric>
                    @if ($lines->isEmpty())
                        <span class="{{ $muted }}" title="{{ __('strategies.no_funding_linked') }}">—<span class="sr-only">{{ __('strategies.no_funding_linked') }}</span></span>
                    @else
                        <a href="{{ $filtered('funding-agreements.index') }}" wire:navigate class="{{ $figureLink }} inline-flex items-center gap-1.5" title="{{ $fundingHint }}" data-test="strategy-funding-link">
                            @if ($funding['percent'] !== null)
                                <span class="size-2 shrink-0 rounded-full {{ $dotClasses($funding['tone']) }}" aria-hidden="true"></span>
                                <bdi>{{ __('strategies.percent_funded', ['percent' => $funding['percent']]) }}</bdi>
                            @else
                                <bdi>{{ Money::format($lines[0]['agreed'], $lines[0]['currency']) }}</bdi>
                                @if ($lines->count() > 1)
                                    <bdi class="type-meta {{ $muted }}" dir="ltr">+{{ $lines->count() - 1 }}</bdi>
                                @endif
                            @endif
                            <span class="sr-only">{{ $fundingHint }}</span>
                        </a>
                    @endif
                </x-ui.data-table.cell>
            @endif

            @if ($showRisks)
                <x-ui.data-table.cell :label="__('strategies.col_high_risks')" numeric>
                    @if ($rollup['risks'] > 0)
                        <a href="{{ $filtered('risks.index', ['high' => 1]) }}" wire:navigate class="{{ $figureLink }} text-status-critical!" data-test="strategy-risks-link">{{ $rollup['risks'] }}</a>
                    @else
                        <span class="tabular-nums {{ $muted }}">0</span>
                    @endif
                </x-ui.data-table.cell>
            @endif

            @if ($showLate)
                @php $late = $rollup['late']; @endphp
                <x-ui.data-table.cell :label="__('strategies.col_late')" numeric>
                    @if ($late['total'] === 0)
                        <span class="tabular-nums {{ $muted }}">0</span>
                    @else
                        <span class="flex flex-col gap-0.5 whitespace-nowrap font-semibold text-status-critical @3xl:items-end">
                            @if (($late['milestones'] ?? 0) > 0)
                                <a href="{{ $filtered('milestones.index', ['status' => 'missed', 'view' => 'list']) }}" wire:navigate class="underline-offset-2 hover:underline" data-test="strategy-late-milestones-link">{{ trans_choice('strategies.count_late_milestones', $late['milestones'], ['count' => $late['milestones']]) }}</a>
                            @endif
                            @if (($late['reports'] ?? 0) > 0)
                                <a href="{{ $filtered('reports.index', ['overdue' => 1]) }}" wire:navigate class="underline-offset-2 hover:underline" data-test="strategy-late-reports-link">{{ trans_choice('strategies.count_late_reports', $late['reports'], ['count' => $late['reports']]) }}</a>
                            @endif
                        </span>
                    @endif
                </x-ui.data-table.cell>
            @endif
            @endif

            <x-ui.data-table.cell :label="__('common.actions')">
                <div class="flex items-center justify-end gap-1 @3xl:justify-start">
                    <x-ui.button tone="ghost" size="sm" icon="eye" class="size-9!" :href="route('strategies.show', tap(new App\Models\Strategy, fn ($s) => $s->id = $strategy['id']))" wire:navigate :aria-label="__('common.view_name', ['name' => $strategy['name']])" />

                    @if ($this->canLinkPrograms)
                        <flux:tooltip :content="__('strategies.manage_programs')">
                            <x-ui.button tone="ghost" size="sm" icon="link" class="size-9!" wire:click="openProgramsModal({{ $strategy['id'] }})" :aria-label="__('strategies.manage_programs_of', ['name' => $strategy['name']])" data-test="manage-strategy-programs-button" />
                        </flux:tooltip>
                    @endif

                    @if ($this->canEditStrategies)
                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $strategy['id'] }})" :aria-label="__('common.edit_name', ['name' => $strategy['name']])" data-test="edit-strategy-button" />
                    @endif

                    @if ($this->canDeleteStrategies)
                        @if ($strategy['programs_count'] === 0 && $strategy['pillars_count'] === 0)
                            <x-ui.button tone="ghost" size="sm" icon="trash" class="size-9!" wire:click="confirmDelete({{ $strategy['id'] }})" :aria-label="__('common.delete_name', ['name' => $strategy['name']])" data-test="delete-strategy-button" />
                        @else
                            @php
                                $lockReason = $strategy['programs_count'] > 0 ? __('strategies.strategies_with_linked_programs_cannot_be_deleted') : __('strategies.strategy_has_pillars');
                            @endphp
                            <flux:tooltip :content="$lockReason">
                                <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light opacity-0 transition-opacity group-hover:opacity-100 focus:opacity-100 @max-3xl:opacity-100 dark:text-ink-faint" tabindex="0" role="img" aria-label="{{ $lockReason }}" data-test="delete-strategy-locked">
                                    <flux:icon.lock-closed class="size-4" />
                                </span>
                            </flux:tooltip>
                        @endif
                    @endif
                </div>
            </x-ui.data-table.cell>
        </tr>
    @endforeach
</x-ui.data-table>
