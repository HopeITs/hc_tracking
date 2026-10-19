@props([
    'rows',
    'figures' => [],
])

@php
    /*
     * The lines of one monthly report (T17B; contracts §13, §13.1 item 1,
     * D-46, D-48a, D-50). Rows are prebuilt by ReadsMonthlyReport::rows(), so
     * the grid runs no query. One table row per line; in `split` attribution
     * one sub-row per donor share plus the line total. MEAL lines carry a
     * "Filled by MEAL" badge and only take input from the validator.
     *
     * Keyboard (same idea as x-ui.targets-grid): arrows move between inputs
     * (left/right flip in RTL), Enter moves down, Escape leaves the input,
     * which saves it (wire:model.blur).
     */
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $stickyCell = 'sticky start-0 z-[2] bg-white dark:bg-surface-1';
    $headCell = 'sticky top-0 z-[1] border-b border-secondary-soft bg-white px-3 py-2 text-start text-xs font-semibold dark:border-line dark:bg-surface-1 '.$mutedText;
@endphp

<div
    class="overflow-x-auto rounded-xl border border-secondary-soft bg-white shadow-card dark:border-transparent dark:bg-surface-1 dark:shadow-raised"
    data-lines-grid
    x-data="{
        isRtl() { return getComputedStyle(this.$root).direction === 'rtl'; },
        rowsWithInputs() { return [...this.$root.querySelectorAll('tr')].filter((row) => row.querySelector('[data-nav]')); },
        go(event, rowStep, colStep) {
            const input = event.target;
            const row = input.closest('tr');
            const inputs = [...row.querySelectorAll('[data-nav]')];
            const col = inputs.indexOf(input);
            let target = null;
            if (rowStep === 0) {
                target = inputs[col + colStep] ?? null;
            } else {
                const rows = this.rowsWithInputs();
                const next = rows[rows.indexOf(row) + rowStep];
                if (next) {
                    const nextInputs = [...next.querySelectorAll('[data-nav]')];
                    target = nextInputs[Math.min(col, nextInputs.length - 1)] ?? null;
                }
            }
            if (target) { event.preventDefault(); target.focus(); target.select(); }
        },
    }"
    x-on:keydown.enter="if ($event.target.matches('[data-nav]')) { $event.preventDefault(); go($event, 1, 0); }"
    x-on:keydown.arrow-down="if ($event.target.matches('[data-nav]')) go($event, 1, 0)"
    x-on:keydown.arrow-up="if ($event.target.matches('[data-nav]')) go($event, -1, 0)"
    x-on:keydown.arrow-right="if ($event.target.matches('[data-nav]') && $event.target.selectionEnd === $event.target.value.length) go($event, 0, isRtl() ? -1 : 1)"
    x-on:keydown.arrow-left="if ($event.target.matches('[data-nav]') && $event.target.selectionStart === 0) go($event, 0, isRtl() ? 1 : -1)"
    x-on:keydown.escape="if ($event.target.matches('[data-nav], textarea')) $event.target.blur()"
>
    <table class="min-w-full border-separate border-spacing-0 text-sm" aria-label="{{ __('monthly_reports.indicators_this_month') }}">
        <thead>
            <tr>
                <th scope="col" class="{{ $headCell }} start-0 z-[3] min-w-48 border-e sm:min-w-72">{{ __('monthly_reports.grid_indicator') }}</th>
                <th scope="col" class="{{ $headCell }} min-w-28">{{ __('monthly_reports.grid_target') }}</th>
                <th scope="col" class="{{ $headCell }} min-w-64">{{ __('monthly_reports.grid_entry') }}</th>
                <th scope="col" class="{{ $headCell }} min-w-48">{{ __('monthly_reports.grid_note') }}</th>
            </tr>
        </thead>

        <tbody>
            @foreach ($rows as $row)
                @php
                    $state = $figures[$row['id']] ?? [];
                    $noteModel = 'figures.'.$row['id'].'.note';
                    $rowCount = $row['split'] ? count($row['shares']) + 1 : 1;
                @endphp

                <tr data-line="{{ $row['id'] }}" data-line-kind="{{ $row['kind']->value }}" wire:key="line-{{ $row['id'] }}" class="align-top">
                    <th scope="row" class="{{ $stickyCell }} border-b border-e border-secondary-soft px-3 py-3 text-start font-normal dark:border-line" @if ($row['split']) rowspan="{{ $rowCount + 1 }}" @endif>
                        <bdi dir="ltr" class="block font-mono text-[11px] {{ $mutedText }}">{{ $row['code'] }}</bdi>
                        <span class="block font-semibold text-secondary-dark dark:text-ink" dir="auto">{{ $row['name'] }}</span>
                        @if ($row['secondary_name'] !== null)
                            <span class="block text-xs {{ $mutedText }}" dir="auto">{{ $row['secondary_name'] }}</span>
                        @endif
                        @if (filled($row['frequency_note']))
                            <span class="mt-1 block text-[11px] italic {{ $mutedText }}" dir="auto">{{ $row['frequency_note'] }}</span>
                        @endif
                        @if ($row['meal'])
                            <span class="mt-1.5 inline-flex items-center gap-1 rounded-md border border-secondary-light px-1.5 py-0.5 text-[11px] font-semibold text-secondary-dark dark:border-line-strong dark:text-ink">
                                <flux:icon.clipboard-document-check variant="micro" class="size-3" aria-hidden="true" />
                                {{ __('monthly_reports.filled_by_meal') }}
                            </span>
                        @endif
                    </th>

                    <td class="border-b border-secondary-soft px-3 py-3 dark:border-line">
                        <bdi dir="ltr" class="block font-semibold tabular-nums" data-target="{{ $row['id'] }}">{{ $row['target'] }}</bdi>
                        @if ($row['split'])
                            <span class="block text-[11px] {{ $mutedText }}">{{ __('monthly_reports.split_target_hint') }}</span>
                        @endif
                    </td>

                    <td class="border-b border-secondary-soft px-3 py-3 dark:border-line">
                        @if ($row['split'])
                            <span class="text-xs {{ $mutedText }}">{{ __('monthly_reports.split_entry_hint') }}</span>
                        @else
                            <x-monthly-reports.line-entry
                                :row="$row"
                                :prefix="'figures.'.$row['id']"
                                :state="$state"
                                :fixed-denominator="$row['fixed_denominator']"
                                :computed="$row['computed']"
                                :computed-key="(string) $row['id']"
                                :editable="$row['editable']"
                            />
                        @endif
                    </td>

                    <td class="border-b border-secondary-soft px-3 py-3 dark:border-line">
                        @if ($row['editable'])
                            <textarea
                                rows="2"
                                dir="auto"
                                wire:model.blur="{{ $noteModel }}"
                                aria-label="{{ $row['name'] }} — {{ __('monthly_reports.grid_note') }}"
                                class="w-full min-w-44 rounded-md border border-secondary-light bg-white px-2 py-1 text-sm text-secondary-dark outline-none focus:border-primary focus:ring-2 focus:ring-primary/30 dark:border-field-line dark:bg-surface-2 dark:text-ink dark:focus:border-brand"
                            ></textarea>
                            @error($noteModel)
                                <p class="mt-1 text-xs text-status-critical">{{ $message }}</p>
                            @enderror
                            @error('figures.'.$row['id'])
                                <p class="mt-1 text-xs text-status-critical">{{ $message }}</p>
                            @enderror
                        @elseif (filled($row['note']))
                            <p class="text-sm whitespace-pre-line {{ $mutedText }}" dir="auto">{{ $row['note'] }}</p>
                        @else
                            <span class="{{ $mutedText }}">—</span>
                        @endif
                    </td>
                </tr>

                @if ($row['split'])
                    @foreach ($row['shares'] as $share)
                        <tr data-share="{{ $share['id'] }}" wire:key="share-{{ $share['id'] }}" class="align-top">
                            <td class="border-b border-secondary-soft bg-board/40 px-3 py-2.5 dark:border-line dark:bg-surface-2/40">
                                <span class="block text-xs font-semibold" dir="auto">{{ $share['donor'] ?? __('monthly_reports.donor_unknown') }}</span>
                                @if ($share['proposal'] !== null)
                                    @can('proposals.view')
                                        <a href="{{ route('proposals.show', $share['proposal']) }}" wire:navigate class="block text-[11px] underline {{ $mutedText }}" dir="auto">{{ $share['proposal']->name }}</a>
                                    @else
                                        <span class="block text-[11px] {{ $mutedText }}" dir="auto">{{ $share['proposal']->name }}</span>
                                    @endcan
                                @endif
                                <bdi dir="ltr" class="mt-1 block text-xs font-semibold tabular-nums">{{ $share['target'] }}</bdi>
                            </td>
                            <td class="border-b border-secondary-soft bg-board/40 px-3 py-2.5 dark:border-line dark:bg-surface-2/40" colspan="2">
                                <x-monthly-reports.line-entry
                                    :row="$row"
                                    :prefix="'figures.'.$row['id'].'.shares.'.$share['id']"
                                    :state="$state['shares'][$share['id']] ?? []"
                                    :fixed-denominator="$share['fixed_denominator']"
                                    :computed="$share['computed']"
                                    :computed-key="$row['id'].'-'.$share['id']"
                                    :editable="$row['editable']"
                                />
                            </td>
                        </tr>
                    @endforeach

                    <tr wire:key="line-total-{{ $row['id'] }}">
                        <td class="border-b border-secondary-soft px-3 py-2.5 text-xs font-semibold dark:border-line" colspan="3">
                            <span class="{{ $mutedText }}">{{ __('monthly_reports.split_total') }}</span>
                            <bdi dir="ltr" class="ms-2 text-base font-extrabold tabular-nums" data-split-total="{{ $row['id'] }}">{{ $row['computed'] }}</bdi>
                        </td>
                    </tr>
                @endif
            @endforeach
        </tbody>
    </table>
</div>
