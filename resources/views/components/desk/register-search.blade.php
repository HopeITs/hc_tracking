@props(['label' => null])

{{-- The register bar's search box (desk.js .reg-q). Pass wire:model.live.debounce.300ms="…" through. --}}
<label class="dk-reg-q" data-desk-register-search>
    <x-desk.icon name="search" />
    <span class="dk-sr">{{ $label ?? __('desk.search') }}</span>
    <input type="search" {{ $attributes->merge(['placeholder' => __('desk.register.search_list'), 'aria-label' => $label ?? __('desk.search')]) }}>
</label>
