@props([
    'rows',
    'figures' => [],
])

@php
    /*
     * The indicators of one monthly report (T17B; contracts §13, §13.1 item 1,
     * D-46, D-48a, D-50): one x-monthly-reports.line-row per line. MEAL lines
     * the viewer cannot fill fold into one line at the end; the validator
     * sees them in order with their inputs. Rows are prebuilt by
     * ReadsMonthlyReport::rows(), so this runs no query.
     *
     * Keyboard (same idea as x-ui.targets-grid): arrows move between visible
     * inputs (left/right flip in RTL), up/down and Enter move to the next
     * group of inputs, Escape leaves the input, which saves it
     * (wire:model.blur).
     */
    $mealRows = array_values(array_filter($rows, fn (array $row): bool => $row['meal']));
    $foldsMeal = $mealRows !== [] && ! collect($mealRows)->contains(fn (array $row): bool => $row['editable']);
    $listed = $foldsMeal ? array_values(array_filter($rows, fn (array $row): bool => ! $row['meal'])) : $rows;
@endphp

<div
    role="list"
    aria-label="{{ __('monthly_reports.indicators_this_month') }}"
    class="border-t border-secondary-soft dark:border-line"
    data-lines-grid
    x-data="{
        isRtl() { return getComputedStyle(this.$root).direction === 'rtl'; },
        groups() {
            return [...this.$root.querySelectorAll('[data-nav-row]')]
                .map((group) => [...group.querySelectorAll('[data-nav]')].filter((input) => input.offsetParent !== null))
                .filter((inputs) => inputs.length > 0);
        },
        go(event, rowStep, colStep) {
            const input = event.target;
            const groups = this.groups();
            const row = groups.findIndex((inputs) => inputs.includes(input));
            if (row === -1) { return; }
            const col = groups[row].indexOf(input);
            let target = null;
            if (rowStep === 0) {
                target = groups[row][col + colStep] ?? null;
            } else {
                const next = groups[row + rowStep];
                target = next ? next[Math.min(col, next.length - 1)] : null;
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
    @foreach ($listed as $row)
        <div role="listitem">
            <x-monthly-reports.line-row :row="$row" :state="$figures[$row['id']] ?? []" />
        </div>
    @endforeach

    @if ($foldsMeal)
        <div role="listitem" x-data="{ open: false }" class="py-3">
            <button
                type="button"
                x-on:click="open = ! open"
                x-bind:aria-expanded="open.toString()"
                class="inline-flex items-center gap-1.5 rounded-md px-1 py-0.5 text-xs font-semibold text-secondary-medium hover:text-secondary-dark focus-visible:outline-2 focus-visible:outline-primary dark:text-ink-muted dark:hover:text-ink"
            >
                <flux:icon.lock-closed variant="micro" class="size-3.5" aria-hidden="true" />
                {{ trans_choice('monthly_reports.meal_lines_group', count($mealRows), ['count' => count($mealRows)]) }}
                <flux:icon.chevron-down variant="micro" class="size-3.5 transition-transform" x-bind:class="open && 'rotate-180'" aria-hidden="true" />
            </button>
            <div x-show="open" style="display: none" class="mt-2">
                @foreach ($mealRows as $row)
                    <x-monthly-reports.line-row :row="$row" :state="$figures[$row['id']] ?? []" />
                @endforeach
            </div>
        </div>
    @endif
</div>
