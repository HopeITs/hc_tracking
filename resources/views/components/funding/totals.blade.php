@props(['lines' => []])

@php
    $toneClass = fn (?string $tone): string => match ($tone) {
        'good' => 'text-funding',
        'warn' => 'text-status-warn',
        'critical' => 'text-status-critical',
        'muted' => 'text-secondary-medium dark:text-ink-muted',
        default => 'text-secondary-dark dark:text-ink',
    };
@endphp

{{-- One typographic line per currency; currencies are never added together. --}}
@if (count($lines) > 0)
    <div {{ $attributes->class('flex flex-col divide-y divide-secondary-soft dark:divide-line') }} data-register-totals>
        @foreach ($lines as $line)
            <div class="flex flex-wrap items-start gap-x-8 gap-y-3 py-4 first:pt-0" wire:key="totals-{{ $line['currency'] }}" data-totals-currency="{{ $line['currency'] }}">
                <div class="w-24 shrink-0">
                    <p class="text-xl leading-none font-bold text-secondary-dark dark:text-ink">{{ $line['currency'] }}</p>
                    @if (filled($line['caption'] ?? null))
                        <p class="mt-1 text-xs text-secondary-medium dark:text-ink-muted">{{ $line['caption'] }}</p>
                    @endif
                </div>
                <dl class="flex min-w-0 flex-1 flex-wrap gap-x-9 gap-y-3">
                    @foreach ($line['figures'] as $figure)
                        <div data-total="{{ $figure['key'] }}">
                            <dt class="type-label text-secondary-medium dark:text-ink-faint">{{ $figure['label'] }}</dt>
                            <dd class="mt-0.5 flex items-baseline gap-1.5">
                                <span dir="ltr" class="text-lg font-bold tabular-nums {{ $toneClass($figure['tone'] ?? null) }}">{{ $figure['prefix'] ?? '' }}{{ number_format((float) $figure['value'], 2) }}</span>
                                @if (filled($figure['note'] ?? null))
                                    <span class="text-xs font-semibold text-secondary-medium dark:text-ink-muted">{{ $figure['note'] }}</span>
                                @endif
                            </dd>
                        </div>
                    @endforeach
                </dl>
                @if (($line['notes'] ?? []) !== [])
                    <ul class="basis-full ps-32 text-xs text-secondary-medium dark:text-ink-muted max-sm:ps-0">
                        @foreach ($line['notes'] as $note)
                            <li>{{ $note }}</li>
                        @endforeach
                    </ul>
                @endif
            </div>
        @endforeach
    </div>
@endif
