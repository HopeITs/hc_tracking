@props([
    'row',
    'state' => [],
])

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
@endphp

<div data-line="{{ $id }}" data-line-kind="{{ $row['kind']->value }}" wire:key="line-{{ $id }}" x-data="{ open: @js($open) }" class="border-b border-secondary-soft py-4 dark:border-line">
    <div class="flex flex-wrap items-center gap-x-6 gap-y-3">
        <div class="min-w-0 flex-1 basis-64">
            <p class="font-semibold text-secondary-dark dark:text-ink" dir="auto">{{ $row['name'] }}</p>
            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs {{ $mutedText }}">
                <bdi dir="ltr" class="font-mono text-[11px]">{{ $row['code'] }}</bdi>
                <span aria-hidden="true">·</span>
                <span>{{ __('monthly_reports.grid_target') }} <bdi dir="ltr" class="font-semibold tabular-nums text-secondary-dark dark:text-ink" data-target="{{ $id }}">{{ $row['target'] }}</bdi></span>
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

        @if ($inlineEntry)
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

        <div class="ms-auto flex shrink-0 items-center gap-2">
            @if ($row['split'])
                <bdi dir="ltr" @class(['min-w-16 text-end tabular-nums', 'text-2xl font-extrabold' => $row['computed'] !== '—', 'text-base text-secondary-light dark:text-ink-faint' => $row['computed'] === '—']) data-split-total="{{ $id }}">{{ $row['computed'] }}</bdi>
            @else
                <bdi dir="ltr" @class(['min-w-16 text-end tabular-nums', 'text-2xl font-extrabold' => $row['computed'] !== '—', 'text-base text-secondary-light dark:text-ink-faint' => $row['computed'] === '—']) data-computed="{{ $id }}">{{ $row['computed'] }}</bdi>
            @endif
            <button
                type="button"
                x-on:click="open = ! open"
                x-bind:aria-expanded="open.toString()"
                aria-controls="{{ $detailsId }}"
                class="inline-flex items-center gap-1 rounded-md px-2 py-1 text-xs font-semibold {{ $mutedText }} hover:bg-secondary-soft focus-visible:outline-2 focus-visible:outline-primary dark:hover:bg-white/[0.06]"
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

    <div id="{{ $detailsId }}" x-show="open" @if (! $open) style="display: none" @endif class="mt-3 flex flex-col gap-4 rounded-lg bg-board/50 p-3 dark:bg-surface-2/40">
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
                        wire:model.blur="{{ $noteModel }}"
                        aria-label="{{ $row['name'] }} — {{ __('monthly_reports.grid_note') }}"
                        class="w-full rounded-md border border-secondary-light bg-white px-2 py-1 text-sm text-secondary-dark outline-none focus:border-primary focus:ring-2 focus:ring-primary/30 dark:border-field-line dark:bg-surface-2 dark:text-ink dark:focus:border-brand"
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
