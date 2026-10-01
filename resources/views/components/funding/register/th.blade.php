@props(['sort' => null, 'active' => '', 'dir' => 'asc', 'numeric' => false])

@php
    $isActive = $sort !== null && $sort === $active;
@endphp

<th scope="col" {{ $attributes->class(['num' => $numeric]) }} @if ($isActive) aria-sort="{{ $dir === 'desc' ? 'descending' : 'ascending' }}" @endif @if ($sort !== null) data-sort="{{ $sort }}" @endif>
    @if ($sort === null)
        {{ $slot }}
    @else
        <button type="button" wire:click="sortRegister('{{ $sort }}')" class="register-sort" aria-label="{{ __('finance_tables.sort_by', ['column' => trim(strip_tags((string) $slot))]) }}">
            <span>{{ $slot }}</span>
            @if (! $isActive)
                <flux:icon.chevron-up-down variant="micro" class="size-3.5 opacity-50" />
            @elseif ($dir === 'desc')
                <flux:icon.chevron-down variant="micro" class="size-3.5" />
            @else
                <flux:icon.chevron-up variant="micro" class="size-3.5" />
            @endif
        </button>
    @endif
</th>
