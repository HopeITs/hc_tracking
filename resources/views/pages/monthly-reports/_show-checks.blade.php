{{-- The smart check of ⚡show.blade.php (App\Support\Ai\MonthlyReportChecks): what a reviewer would ask about, before Submit or Validate. Advisory only. --}}
@php
    $checks = $this->qualityChecks;
@endphp

@if ($checks !== [])
    <section aria-labelledby="monthly-report-checks-heading" class="flex flex-col gap-2" data-monthly-report-checks>
        <div>
            <h2 id="monthly-report-checks-heading" class="inline-flex items-center gap-1.5 text-base font-bold">
                <flux:icon.light-bulb variant="micro" class="size-4" aria-hidden="true" />{{ __('ai.checks.heading') }}
            </h2>
            <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('ai.checks.help') }}</p>
        </div>

        <ul class="flex max-w-3xl flex-col gap-1.5 text-sm" role="list">
            @foreach ($checks as $check)
                <li class="flex items-start gap-2" data-check="{{ $check['kind'] }}" dir="auto">
                    <span class="mt-1.5 size-2 shrink-0 rounded-full bg-status-warn" aria-hidden="true"></span>
                    <span>{{ $check['text'] }}</span>
                </li>
            @endforeach
        </ul>
    </section>
@endif
