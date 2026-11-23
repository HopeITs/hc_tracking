@props([
    'row',
    'state' => [],
    'context' => null,
    'month' => null,
])

@use('App\Enums\MonthlyReportEntryKind')

@php
    /*
     * One indicator of a monthly report (T17B): name, code and target on the
     * left, the figure input in the middle, the server's result as the large
     * number on the right. The note, evidence, both names, the frequency note,
     * the male/female breakdown and the donor shares sit under Details, which
     * starts open when the line needs them (split, disaggregated, an error).
     * Rows are prebuilt by ReadsMonthlyReport::rows(); this runs no query.
     */
    $id = $row['id'];
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $inlineEntry = ! $row['split'] && ! $row['disaggregated'];
    $noteModel = 'figures.'.$id.'.note';
    $hasDetailErrors = $errors->has($noteModel) || $errors->has('figures.'.$id);
    $hasExtras = filled($row['note']) || $row['files'] !== [];
    $open = $row['split'] || $row['disaggregated'] || $hasDetailErrors;
    $detailsId = 'line-details-'.$id;
    // A read-only count shows its figure once, as the large number; inputs and ratio parts stay.
    $isCount = $row['kind'] === MonthlyReportEntryKind::Count;
    $showsEntry = $inlineEntry && ($row['editable'] || ! $isCount);
    $entryIsTheFigure = $showsEntry && $row['editable'] && $isCount;
    $monthName = $month?->translatedFormat('F');
    $whole = fn (float $value): string => number_format($value, 0);
    $sparkMax = $context === null ? 1.0 : max(1.0, ...array_column($context['series'], 'value'));
@endphp

<div data-line="{{ $id }}" data-line-kind="{{ $row['kind']->value }}" wire:key="line-{{ $id }}" x-data="{ open: @js($open) }" class="mrp-line">
    <div class="mrp-line-main">
        <div class="mrp-line-ind">
            <p class="mrp-line-name" dir="auto">{{ $row['name'] }}</p>
            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs {{ $mutedText }}">
                <bdi dir="ltr" class="mrp-code">{{ $row['code'] }}</bdi>
                <span aria-hidden="true">·</span>
                <span>{{ __('monthly_reports.grid_target') }} <bdi dir="ltr" class="font-semibold tabular-nums text-secondary-dark dark:text-ink" data-target="{{ $id }}">{{ $row['target'] }}</bdi></span>
                @if (($row['shared'] ?? null) !== null)
                    {{-- D-90: the target belongs to the row and its centers reach it together. Read only: this center's own figure is never set against the whole target. --}}
                    <span class="inline-flex flex-wrap items-center gap-x-1" data-shared-target="{{ $id }}">
                        <flux:icon.users variant="micro" class="size-3" aria-hidden="true" />
                        <span>{{ trans_choice('monthly_reports.shared_with_centers', $row['shared']['centers'], ['count' => $row['shared']['centers']]) }}</span>
                        <span aria-hidden="true">·</span>
                        @if ($row['shared']['reached'] !== null)
                            <span>{{ __('monthly_reports.reached_together', ['value' => '']) }}<bdi dir="ltr" class="font-semibold tabular-nums text-secondary-dark dark:text-ink">{{ $row['shared']['reached'] }}</bdi></span>
                        @else
                            <span>{{ __('monthly_reports.reached_together_none') }}</span>
                        @endif
                    </span>
                @endif
                @if ($row['meal'])
                    <span class="inline-flex items-center gap-1 font-semibold text-secondary-dark dark:text-ink">
                        <flux:icon.lock-closed variant="micro" class="size-3" aria-hidden="true" />
                        {{ __('monthly_reports.filled_by_meal') }}
                    </span>
                @endif
                @if ($row['missing_evidence'] !== [])
                    <span class="inline-flex items-center gap-1 font-semibold text-status-warn">
                        <flux:icon.paper-clip variant="micro" class="size-3" aria-hidden="true" />
                        {{ __('monthly_reports.files_needed', ['types' => implode(app()->getLocale() === 'ar' ? '، ' : ', ', $row['missing_evidence'])]) }}
                    </span>
                @endif
            </p>
        </div>

        <div class="mrp-line-value">
            @if ($monthName !== null)
                <span class="mrp-line-label">{{ $monthName }}</span>
            @endif
            @if ($row['split'])
                <bdi dir="ltr" @class(['mrp-line-figure', 'mrp-line-empty' => $row['computed'] === '—']) data-split-total="{{ $id }}">{{ $row['computed'] }}</bdi>
            @elseif (! $entryIsTheFigure)
                <bdi dir="ltr" @class(['mrp-line-figure', 'mrp-line-empty' => $row['computed'] === '—']) data-computed="{{ $id }}">{{ $row['computed'] }}</bdi>
            @endif
        @if ($showsEntry)
            <div class="shrink-0" data-nav-row>
                <x-monthly-reports.line-entry
                    :row="$row"
                    :prefix="'figures.'.$id"
                    :state="$state"
                    :fixed-denominator="$row['fixed_denominator']"
                    :computed="$row['computed']"
                    :computed-key="(string) $id"
                    :editable="$row['editable']"
                    :show-computed="false"
                />
            </div>
        @endif
            @if ($entryIsTheFigure)
                <bdi dir="ltr" class="sr-only" data-computed="{{ $id }}">{{ $row['computed'] }}</bdi>
            @endif
            @if ($context !== null && $context['vsShare'] !== null)
                <span @class(['mrp-line-compare', 'mrp-line-low' => $context['vsShare'] < 80]) data-month-share>
                    {{ $context['vsShare'] < 80
                        ? __('monthly_reports.below_monthly_share', ['percent' => round(100 - $context['vsShare'])])
                        : __('monthly_reports.of_monthly_share', ['percent' => round($context['vsShare'])]) }}
                </span>
            @endif
        </div>

        @if ($context !== null)
            <div class="mrp-line-year" data-year-so-far>
                <span class="mrp-line-year-head">
                    <span>{{ __('monthly_reports.year_so_far', ['month' => $monthName]) }}</span>
                    <b dir="ltr">{{ $whole($context['ytd']) }} · {{ round($context['ytdPercent']) }}%</b>
                </span>
                <span class="mrp-bar" role="img" aria-label="{{ __('monthly_reports.year_share_label', ['percent' => round($context['ytdPercent'])]) }}">
                    <span @class(['mrp-bar-fill', 'mrp-bar-behind' => $context['behind']]) style="width: {{ min(100, max(2, $context['ytdPercent'])) }}%"></span>
                    <span class="mrp-bar-tick" style="inset-inline-start: calc({{ min(100, $context['expectedPercent']) }}% - 1px)"></span>
                </span>
                <span class="mrp-spark" style="--mrp-cols: {{ count($context['series']) }}" role="img" aria-label="{{ __('monthly_reports.year_chart_label', ['month' => $monthName]) }}">
                    @foreach ($context['series'] as $point)
                        <span @class(['mrp-spark-bar', 'mrp-spark-now' => $point['current'], 'mrp-spark-low' => $point['current'] && $context['vsShare'] !== null && $context['vsShare'] < 80]) style="height: {{ max(8, $point['value'] / $sparkMax * 100) }}%" title="{{ $month?->setMonth($point['month'])->translatedFormat('M') }}: {{ $whole($point['value']) }}"></span>
                    @endforeach
                </span>
                <span class="mrp-line-year-foot">{{ __('monthly_reports.year_target', ['value' => $whole($context['target'])]) }}</span>
            </div>
        @endif

        <div class="mrp-line-tools">
            <button
                type="button"
                x-on:click="open = ! open"
                x-bind:aria-expanded="open.toString()"
                aria-controls="{{ $detailsId }}"
                class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold {{ $mutedText }} hover:bg-secondary-soft focus-visible:outline-2 focus-visible:outline-secondary-medium dark:hover:bg-white/[0.06]"
            >
                @if ($hasExtras)
                    <span class="size-1.5 rounded-full bg-primary dark:bg-brand-text" aria-hidden="true"></span>
                @endif
                <span x-show="! open" @if ($open) style="display: none" @endif>{{ __('monthly_reports.details') }}</span>
                <span x-show="open" @if (! $open) style="display: none" @endif>{{ __('monthly_reports.hide_details') }}</span>
                <flux:icon.chevron-down variant="micro" class="size-3.5 transition-transform" x-bind:class="open && 'rotate-180'" aria-hidden="true" />
            </button>
        </div>
    </div>

    <div id="{{ $detailsId }}" x-show="open" @if (! $open) style="display: none" @endif class="mrp-line-details flex flex-col gap-4">
        @if ($row['secondary_name'] !== null || filled($row['frequency_note']))
            <div class="text-xs {{ $mutedText }}">
                @if ($row['secondary_name'] !== null)
                    <p dir="auto">{{ $row['secondary_name'] }}</p>
                @endif
                @if (filled($row['frequency_note']))
                    <p class="italic" dir="auto">{{ $row['frequency_note'] }}</p>
                @endif
            </div>
        @endif

        @if ($row['disaggregated'] && ! $row['split'])
            <div data-nav-row>
                <x-monthly-reports.line-entry
                    :row="$row"
                    :prefix="'figures.'.$id"
                    :state="$state"
                    :fixed-denominator="$row['fixed_denominator']"
                    :computed="$row['computed']"
                    :computed-key="(string) $id"
                    :editable="$row['editable']"
                    :show-computed="false"
                />
            </div>
        @endif

        @if ($row['split'])
            <div class="flex flex-col gap-3">
                <p class="text-xs {{ $mutedText }}">{{ __('monthly_reports.split_entry_hint') }}</p>
                @foreach ($row['shares'] as $share)
                    <div data-share="{{ $share['id'] }}" wire:key="share-{{ $share['id'] }}" class="flex flex-wrap items-start gap-x-6 gap-y-2 border-t border-secondary-soft pt-3 first:border-t-0 first:pt-0 dark:border-line">
                        <div class="min-w-40 flex-1">
                            <p class="text-sm font-semibold" dir="auto">{{ $share['donor'] ?? __('monthly_reports.donor_unknown') }}</p>
                            @if ($share['proposal'] !== null)
                                @can('proposals.view')
                                    <a href="{{ route('proposals.show', $share['proposal']) }}" wire:navigate class="block text-xs underline {{ $mutedText }}" dir="auto">{{ $share['proposal']->name }}</a>
                                @else
                                    <span class="block text-xs {{ $mutedText }}" dir="auto">{{ $share['proposal']->name }}</span>
                                @endcan
                            @endif
                            <p class="text-xs {{ $mutedText }}">{{ __('monthly_reports.grid_target') }} <bdi dir="ltr" class="tabular-nums">{{ $share['target'] }}</bdi></p>
                        </div>
                        <div data-nav-row>
                            <x-monthly-reports.line-entry
                                :row="$row"
                                :prefix="'figures.'.$id.'.shares.'.$share['id']"
                                :state="$state['shares'][$share['id']] ?? []"
                                :fixed-denominator="$share['fixed_denominator']"
                                :computed="$share['computed']"
                                :computed-key="$id.'-'.$share['id']"
                                :editable="$row['editable']"
                            />
                        </div>
                    </div>
                @endforeach
            </div>
        @endif

        <div class="grid gap-4 @2xl/detail:grid-cols-2">
            <div>
                <p class="mb-1 text-xs font-semibold {{ $mutedText }}">{{ __('monthly_reports.grid_note') }}</p>
                @if ($row['editable'])
                    <textarea
                        rows="2"
                        dir="auto"
                        wire:model.live.blur="{{ $noteModel }}"
                        aria-label="{{ $row['name'] }} — {{ __('monthly_reports.grid_note') }}"
                        class="w-full rounded-md border border-secondary-light bg-white px-2 py-1 text-sm text-secondary-dark outline-none focus:border-secondary-medium focus:ring-2 focus:ring-secondary-light dark:border-field-line dark:bg-surface-2 dark:text-ink dark:focus:border-ink-muted"
                    ></textarea>
                    @error($noteModel)
                        <p class="mt-1 text-xs text-status-critical">{{ $message }}</p>
                    @enderror
                @elseif (filled($row['note']))
                    <p class="text-sm whitespace-pre-line" dir="auto">{{ $row['note'] }}</p>
                @else
                    <span class="text-sm {{ $mutedText }}">—</span>
                @endif
            </div>
            <div>
                <p class="mb-1 text-xs font-semibold {{ $mutedText }}">{{ __('monthly_reports.grid_files') }}</p>
                <x-monthly-reports.files-cell
                    :files="$row['files']"
                    :missing="$row['missing_evidence']"
                    :editable="$row['editable']"
                    :line-id="$id"
                    :label="$row['name']"
                />
            </div>
        </div>

        @error('figures.'.$id)
            <p class="text-xs text-status-critical">{{ $message }}</p>
        @enderror
    </div>
</div>
