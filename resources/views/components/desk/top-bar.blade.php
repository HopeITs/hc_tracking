@php
    $user = auth()->user();
    $current = \App\Enums\Locale::current();
    $other = $current === \App\Enums\Locale::Arabic ? \App\Enums\Locale::English : \App\Enums\Locale::Arabic;
    $initials = collect(preg_split('/\s+/u', trim((string) $user?->name)) ?: [])
        ->filter()
        ->take(2)
        ->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))
        ->implode('');
@endphp

{{-- The Desk header: logo, the Ask bar, and four tools. There is no menu; every page opens from the Ask bar or a Desk panel. --}}
<header class="dk-top dk-scope" data-desk-top-bar>
    <div class="dk-wrap" id="top">
        <a class="dk-logo" href="{{ route('dashboard') }}" wire:navigate aria-label="{{ __('desk.logo_label') }}" data-desk-logo>
            <img class="dk-logo-img dk-logo-light" src="{{ asset('logo-tracking.svg') }}" alt="{{ config('app.name') }}" data-no-dim />
            <img class="dk-logo-img dk-logo-dark" src="{{ asset('logo-tracking-white.svg') }}" alt="{{ config('app.name') }}" data-no-dim />
        </a>

        <button type="button" class="dk-ask" x-data x-on:click="$dispatch('dk-ask-open')" aria-label="{{ __('desk.ask_placeholder') }}" data-desk-ask>
            <span class="dk-inner">
                <x-desk.icon name="search" />
                <span class="dk-ph">{{ __('desk.ask_placeholder') }}</span>
                <kbd>/</kbd>
            </span>
        </button>

        <div class="dk-tools" data-desk-tools>
            <a class="dk-iconbtn" href="https://docs.google.com/document/d/1-cQQQrmqRirFbkwU22RL9YkpIY1_D8n8/edit" target="_blank" rel="noopener noreferrer" aria-label="{{ __('navigation.guideline') }}" title="{{ __('navigation.guideline') }}" data-guideline>
                <svg class="dk-i" viewBox="0 0 24 24" aria-hidden="true"><path d="M4 5a2 2 0 0 1 2-2h13v16H6a2 2 0 0 0-2 2z"/><path d="M4 19V5M8 7h7M8 11h7"/></svg>
            </a>

            <button type="button" class="dk-iconbtn dk-tourbtn" x-data="hcTourTrigger" x-on:click="begin()" x-on:hc-tour-seen.window="fresh = false" aria-haspopup="dialog" aria-label="{{ __('tour.start') }}" title="{{ __('tour.start') }}" data-tour="help" data-test="tour-start-button">
                <x-desk.icon name="spark" />
            </button>

            <form method="POST" action="{{ route('locale.update') }}" class="contents" data-locale-toggle>
                @csrf
                <button type="submit" name="locale" value="{{ $other->value }}" class="dk-iconbtn" aria-label="{{ __('desk.switch_language_to', ['language' => $other === \App\Enums\Locale::Arabic ? 'العربية' : 'English']) }}" lang="{{ $other->value }}">
                    {{ $other === \App\Enums\Locale::Arabic ? 'ع' : 'EN' }}
                </button>
            </form>

            <button type="button" class="dk-iconbtn" x-data x-on:click="hcToggleAppearance()" aria-label="{{ __('common.appearance') }}" data-theme-toggle>
                <x-desk.icon name="sun" x-show="! $flux.dark" x-cloak />
                <x-desk.icon name="moon" x-show="$flux.dark" x-cloak />
            </button>

            <button type="button" class="dk-iconbtn" x-data x-on:click="$dispatch('dk-settings-open')" aria-label="{{ __('desk.settings_and_admin') }}" aria-haspopup="dialog" data-tour="profile" data-desk-settings-trigger>
                <span class="dk-avatar">{{ $initials }}</span>
            </button>
        </div>
    </div>
</header>
