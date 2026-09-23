@props([
    'code',
    'tone' => 'neutral',
    'icon',
    'terminalLabel',
    'trail' => [],
    'heading',
    'body',
    'retryAfter' => null,
])

@php
    $toneRing = match ($tone) {
        'critical' => 'border-status-critical text-status-critical',
        'warn' => 'border-status-warn text-status-warn',
        'active' => 'border-status-active text-status-active',
        default => 'border-secondary-medium text-secondary-medium dark:border-secondary-light dark:text-secondary-light',
    };

    $toneSoftBg = match ($tone) {
        'critical' => 'bg-status-critical-soft',
        'warn' => 'bg-status-warn-soft',
        'active' => 'bg-status-active-soft',
        default => 'bg-secondary-soft dark:bg-secondary-medium/40',
    };

    $toneBorderVar = match ($tone) {
        'critical' => 'var(--color-status-critical)',
        'warn' => 'var(--color-status-warn)',
        'active' => 'var(--color-status-active)',
        default => 'var(--color-secondary-light)',
    };

    $diagramLabel = collect($trail)->push($terminalLabel)->implode(" \u{2192} ");
@endphp

<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" dir="{{ \App\Enums\Locale::current()->direction() }}" class="dark">
    <head>
        @include('partials.head', ['title' => $heading])
    </head>
    <body class="min-h-screen bg-[#e5e7eb] antialiased dark:bg-secondary-dark">
        <div class="error-page flex min-h-svh flex-col items-center justify-center gap-6 p-6 md:p-10">
            <div class="flex w-full max-w-xl flex-col items-center gap-6">
                <a href="{{ route('home') }}" class="flex flex-col items-center">
                    <img src="{{ asset('logo-tracking.svg') }}" alt="" class="h-11 w-auto dark:hidden" />
                    <img src="{{ asset('logo-tracking-white.svg') }}" alt="" class="hidden h-11 w-auto dark:block" />
                    <span class="sr-only">{{ config('app.name', 'Laravel') }}</span>
                </a>

                <div class="w-full rounded-2xl border border-secondary-soft bg-white p-8 text-center shadow-[0_1px_2px_rgba(16,24,40,0.04),0_1px_3px_rgba(16,24,40,0.06)] sm:p-10 dark:border-secondary-medium dark:bg-secondary-dark">

                    {{-- The connection trail: a real thread of sections, breaking exactly where this page should have been. --}}
                    <div class="error-trail flex items-center justify-center" role="img" aria-label="{{ $diagramLabel }}">
                        @foreach ($trail as $i => $label)
                            @if ($i > 0)
                                <span class="error-trail-line mx-1.5 h-px w-8 shrink-0 bg-secondary-soft sm:w-12 dark:bg-secondary-medium" aria-hidden="true"></span>
                            @endif
                            <span class="error-trail-node flex flex-col items-center gap-2" style="--logframe-order: {{ $i }}" aria-hidden="true">
                                <span class="size-2.5 shrink-0 rounded-full bg-secondary-light dark:bg-secondary-medium"></span>
                                <span class="type-label hidden text-secondary-medium sm:inline dark:text-secondary-soft">{{ $label }}</span>
                            </span>
                        @endforeach

                        <span class="error-trail-line error-trail-line--broken mx-1.5 h-px w-8 shrink-0 sm:w-12" style="border-color: {{ $toneBorderVar }}" aria-hidden="true"></span>

                        <span class="error-trail-node flex flex-col items-center gap-2" style="--logframe-order: {{ count($trail) }}" aria-hidden="true">
                            <span @class(['flex size-14 shrink-0 items-center justify-center rounded-full border-2 bg-white dark:bg-secondary-dark', $toneRing])>
                                <flux:icon :name="$icon" class="size-6" />
                            </span>
                            <span @class(['type-label', $toneRing])>{{ $terminalLabel }}</span>
                        </span>
                    </div>

                    <p class="type-label mt-7 text-secondary-light dark:text-secondary-soft" dir="ltr">HTTP {{ $code }}</p>
                    <flux:heading size="xl" class="mt-2">{{ $heading }}</flux:heading>
                    <p class="mx-auto mt-3 max-w-md text-sm text-pretty text-secondary-medium dark:text-secondary-soft">{{ $body }}</p>

                    @if ($retryAfter !== null)
                        <p class="type-meta mt-3 inline-flex items-center gap-1.5 rounded-full px-3 py-1 {{ $toneSoftBg }}" data-error-retry data-retry-after="{{ $retryAfter }}">
                            <flux:icon name="arrow-path" variant="micro" class="size-3.5" />
                            <span data-error-retry-text>{{ __('errors.checking_again_in', ['seconds' => $retryAfter]) }}</span>
                        </p>
                    @endif

                    <div class="mt-7 flex flex-wrap items-center justify-center gap-3">
                        {{ $secondaryAction ?? '' }}
                        {{ $primaryAction }}
                    </div>
                </div>

                <x-errors.quick-links />

                <p class="text-center text-xs text-secondary-medium dark:text-secondary-soft">
                    {{ __('authentication.copyright_notice', ['year' => now()->year]) }}
                </p>
            </div>
        </div>

        @if ($retryAfter !== null)
            <script>
                (() => {
                    const el = document.querySelector('[data-error-retry]');
                    if (!el) return;
                    let seconds = parseInt(el.dataset.retryAfter, 10) || 0;
                    const textEl = el.querySelector('[data-error-retry-text]');
                    const label = @js(__('errors.checking_again_in', ['seconds' => ':seconds']));
                    const timer = setInterval(() => {
                        seconds -= 1;
                        if (seconds <= 0) {
                            clearInterval(timer);
                            window.location.reload();
                            return;
                        }
                        textEl.textContent = label.replace(':seconds', String(seconds));
                    }, 1000);
                })();
            </script>
        @endif

        @fluxScripts
    </body>
</html>
