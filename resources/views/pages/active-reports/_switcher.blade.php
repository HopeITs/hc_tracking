{{-- The kind switcher (the way back to the doors is the page's back link); nothing for a viewer with one kind. --}}
@unless ($single)
    <div class="rt-switchbar">
        <nav class="rt-switch" aria-label="{{ __('active_reports.kinds') }}">
            @foreach ($offered as $option)
                <button type="button" wire:click="chooseKind('{{ $option }}')" @if ($option === $kind) aria-current="page" @endif data-tracker-switch="{{ $option }}">
                    {{ __('active_reports.short_'.$option) }}<span class="rt-switch-n dk-num" data-tracker-badge="{{ $option }}">{{ $badges[$option] }}</span>
                </button>
            @endforeach
        </nav>
    </div>
@endunless
