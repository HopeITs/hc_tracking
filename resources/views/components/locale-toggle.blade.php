@php
    $current = \App\Enums\Locale::current();
@endphp

<form
    method="POST"
    action="{{ route('locale.update') }}"
    {{ $attributes->class('inline-flex items-center gap-1 rounded-full bg-secondary-soft/40 p-1 dark:bg-surface-2') }}
    aria-label="{{ __('navigation.switch_language') }}"
    data-locale-toggle
>
    @csrf
    @foreach (\App\Enums\Locale::cases() as $case)
        @php
            $isCurrent = $case === $current;
        @endphp
        <button
            type="submit"
            name="locale" value="{{ $case->value }}"
            aria-pressed="{{ $isCurrent ? 'true' : 'false' }}"
            @class([
                'cursor-pointer touch-manipulation rounded-full px-3.5 py-2 text-xs transition-colors',
                'bg-white font-bold text-secondary-dark shadow-sm dark:bg-surface-3 dark:shadow-raised dark:text-ink' => $isCurrent,
                'font-semibold text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => ! $isCurrent,
            ])
        >
            {{ $case === \App\Enums\Locale::Arabic ? 'AR' : 'EN' }}
        </button>
    @endforeach
</form>
