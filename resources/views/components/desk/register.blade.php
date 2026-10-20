@props([
    'title',
    'count' => null,
    'headingId' => null,
    'views' => [],
    'view' => null,
    'viewModel' => 'view',
])

{{--
    A register: the full list behind a Desk panel. "Your desk" back link, the
    title with its count, the Table / Board / Calendar switch and the page's
    actions, then the search-and-filter bar and the list itself. The page keeps
    its own Livewire logic; this is only the frame (desk.js renderRegister).

    $views: ['list' => ['icon' => 'table', 'label' => '…'], …] keyed by the
    value written to the page's $viewModel property.
--}}
<section {{ $attributes->class('dk-register') }} @if ($headingId) aria-labelledby="{{ $headingId }}" @endif data-desk-register>
    <a class="dk-back" href="{{ route('dashboard') }}" wire:navigate data-desk-register-back><x-desk.icon name="back" class="dk-flip" />{{ __('desk.your_desk') }}</a>

    <div class="dk-reg-h">
        <div>
            <h1 @if ($headingId) id="{{ $headingId }}" @endif>{{ $title }}@if ($count !== null) <span class="dk-pc dk-num" data-count-badge>{{ $count }}</span>@endif</h1>
            @isset($figures)
                <p class="dk-reg-figs" data-desk-register-figures>{{ $figures }}</p>
            @endisset
        </div>
        <div class="dk-reg-tools">
            @if (count($views) > 1)
                <div class="dk-reg-views dk-seg" role="group" aria-label="{{ __('desk.register.view') }}">
                    @foreach ($views as $key => $option)
                        <button type="button" wire:click="$set('{{ $viewModel }}', '{{ $key }}')" aria-pressed="{{ $view === $key ? 'true' : 'false' }}" data-desk-register-view="{{ $key }}" @isset($option['test']) data-test="{{ $option['test'] }}" @endisset><x-desk.icon :name="$option['icon']" />{{ $option['label'] }}</button>
                    @endforeach
                </div>
            @endif
            @isset($actions)
                <div class="dk-reg-actions" data-desk-register-actions>{{ $actions }}</div>
            @endisset
        </div>
    </div>

    @if (isset($search) || isset($filters))
        <div class="dk-reg-bar" data-desk-register-bar>
            @isset($search)
                {{ $search }}
            @endisset
            @isset($filters)
                {{ $filters }}
            @endisset
        </div>
    @endif

    <div class="dk-reg-body">
        {{ $slot }}
    </div>
</section>
