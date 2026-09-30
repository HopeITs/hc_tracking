@php
    $current = \App\Enums\Locale::current();
@endphp

<form
    method="POST"
    action="{{ route('locale.update') }}"
    {{ $attributes->class('inline-flex items-center gap-0.5 rounded-full border border-secondary-soft bg-secondary-soft/40 p-0.5 dark:border-line dark:bg-surface-2') }}
    aria-label="{{ __('navigation.switch_language') }}"
    data-locale-toggle
>
    @csrf
    @foreach (\App\Enums\Locale::cases() as $case)
        @php($isCurrent = $case === $current)
        <button
            type="submit"
            name="locale" value="{{ $case->value }}"
            aria-pressed="{{ $isCurrent ? 'true' : 'false' }}"
            @class([
                'touch-manipulation rounded-full px-3 py-1 text-xs font-semibold transition-colors',
                'bg-white text-secondary-dark shadow-sm dark:bg-surface-3 dark:shadow-raised dark:text-ink' => $isCurrent,
                'text-secondary-medium hover:text-secondary-dark dark:text-ink-muted dark:hover:text-ink' => ! $isCurrent,
            ])
        >
            {{ $case === \App\Enums\Locale::Arabic ? 'AR' : 'EN' }}
        </button>
    @endforeach
</form>
