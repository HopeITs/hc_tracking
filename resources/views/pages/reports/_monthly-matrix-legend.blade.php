{{--
    The cell-state legend of the Monthly Matrix: every state with its icon and
    its words, so colour never stands alone. Included by ⚡monthly-matrix.blade.php.
--}}
@use('App\Enums\MatrixCellState')

<ul data-matrix-legend class="flex flex-wrap items-center gap-x-5 gap-y-2 text-xs text-secondary-medium dark:text-ink-muted" aria-label="{{ __('reports.matrix.legend') }}">
    @foreach (MatrixCellState::cases() as $state)
        <li class="inline-flex items-center gap-1.5">
            <flux:icon :name="$state->icon()" variant="micro" class="size-4" aria-hidden="true" />
            <span>{{ $state->label() }}</span>
        </li>
    @endforeach
    <li class="inline-flex items-center gap-1.5">
        <span class="font-semibold" aria-hidden="true">—</span>
        <span>{{ __('reports.matrix.legend_not_due_note') }}</span>
    </li>
</ul>
