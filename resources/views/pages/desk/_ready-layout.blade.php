{{--
    One ready layout in the Customize sheet's list: only its name, with a quiet
    mark. Everything else about it shows on the stage beside the list.
--}}
@php
    $key = $ready['key'];
@endphp

<button type="button" class="dk-cz-row" data-ready-layout="{{ $key }}" wire:key="ready-{{ $key }}"
    x-on:click="view('{{ $key }}')"
    aria-pressed="false" x-bind:aria-pressed="(viewing === '{{ $key }}').toString()"
    x-show="! signalsRead || signals['{{ $key }}']">
    <span class="dk-cz-row-t">{{ $ready['title'] }}</span>
    <span class="dk-cz-sug" x-show="suggested === '{{ $key }}'" data-ready-suggested>{{ __('desk.ready.suggested') }}</span>
    <i class="dk-cz-alert" x-cloak x-show="suggested !== '{{ $key }}' && signals['{{ $key }}']?.tone === 'critical'" aria-hidden="true"></i>
</button>
