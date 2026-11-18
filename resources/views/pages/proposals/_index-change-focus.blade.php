{{--
    The change-request buttons over the proposals register (Y-09 slice 2):
    "Changes to review" for a Finance or MEAL Manager holding a review slot,
    "Changes to approve" for a holder of proposals.approve. Each count is the
    count of the list the button shows. Pressing one replaces the register's
    body with that list; pressing it again shows the register. Nothing renders
    for a viewer who may see neither list. Included by ⚡index.blade.php.
--}}
@php
    $changeTabs = array_values(array_filter([
        $this->heldChangeSlots !== [] ? ['key' => 'review', 'label' => __('proposals.change.to_review'), 'count' => $this->changesToReview->count()] : null,
        $this->mayApproveChanges() ? ['key' => 'approve', 'label' => __('proposals.change.to_approve'), 'count' => $this->changesToApprove->count()] : null,
    ]));
@endphp

@if ($changeTabs !== [])
    <div class="mb-4 flex flex-wrap items-stretch gap-2" data-test="change-focus-tabs">
        @foreach ($changeTabs as $tab)
            <button
                type="button"
                wire:click="setFocus('{{ $tab['key'] }}')"
                wire:key="change-focus-{{ $tab['key'] }}"
                aria-pressed="{{ $this->focus === $tab['key'] ? 'true' : 'false' }}"
                data-test="change-focus-{{ $tab['key'] }}"
                data-change-count="{{ $tab['count'] }}"
                @class([
                    'flex min-h-14 flex-1 basis-40 cursor-pointer items-center gap-3 rounded-xl border px-4 py-2 text-start transition-colors focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium sm:flex-none',
                    'border-secondary-dark bg-white shadow-card ring-1 ring-secondary-dark dark:border-ink dark:bg-surface-2 dark:ring-ink' => $this->focus === $tab['key'],
                    'border-secondary-soft bg-white hover:border-secondary-light dark:border-line dark:bg-surface-1' => $this->focus !== $tab['key'],
                ])
            >
                <span @class([
                    'text-2xl font-bold leading-none tabular-nums',
                    'text-status-warn' => $tab['count'] > 0,
                    'text-secondary-dark dark:text-ink' => $tab['count'] === 0,
                ])>{{ $tab['count'] }}</span>
                <span class="text-sm font-semibold text-secondary-dark dark:text-ink">{{ $tab['label'] }}</span>
            </button>
        @endforeach
    </div>
@endif
