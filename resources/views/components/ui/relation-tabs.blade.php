@props(['tabs' => [], 'active' => null, 'wireTarget' => 'tab'])

<<<<<<< HEAD
<div role="tablist" {{ $attributes->class('flex flex-wrap items-center gap-1 border-b border-secondary-soft dark:border-line') }}>
=======
<div role="tablist" data-relation-tabs {{ $attributes->class('flex flex-wrap items-center gap-1 border-b border-secondary-soft dark:border-secondary-medium') }}>
>>>>>>> gitlab/security-mai
    @foreach ($tabs as $tab)
        @php $isActive = $tab['key'] === $active; @endphp
        <button
            type="button"
            role="tab"
            wire:key="relation-tab-{{ $tab['key'] }}"
            wire:click="$set('{{ $wireTarget }}', '{{ $tab['key'] }}')"
            aria-selected="{{ $isActive ? 'true' : 'false' }}"
            @class([
                'inline-flex min-h-11 touch-manipulation items-center gap-1.5 border-b-2 px-3 text-sm font-medium transition-colors sm:min-h-10',
                'border-primary text-secondary-dark dark:text-ink' => $isActive,
                'border-transparent text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => ! $isActive,
            ])
        >
            {{ $tab['label'] }}
            @if (($tab['count'] ?? null) !== null)
                <span class="inline-flex min-w-5 items-center justify-center rounded-md border border-secondary-light px-1 text-[11px] font-semibold tabular-nums">{{ $tab['count'] }}</span>
            @endif
        </button>
    @endforeach
</div>
