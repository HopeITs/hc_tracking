@props(['transfer' => null, 'state' => null, 'missingNet' => false])

@php
    /**
     * One tranche's state in the vocabulary every funding page shares
     * (TransfersTracking::state()). Received money nobody recorded a net
     * amount for is its own reading: it must never look like money landed.
     */
    if ($transfer !== null) {
        $state = $transfer->state();
        $missingNet = $transfer->isMissingNetAmount();
    }

    [$dot, $tone] = match (true) {
        (bool) $missingNet => ['money-unrecorded', 'text-status-warn'],
        $state === 'received' => ['bg-funding', 'text-funding'],
        $state === 'transit' => ['bg-status-warn', 'text-status-warn'],
        $state === 'late', $state === 'failed' => ['bg-primary dark:bg-status-critical', 'text-status-critical'],
        default => ['money-pending', 'text-secondary-medium dark:text-ink-muted'],
    };

    /* One place writes every state key out in full: LocalizationTest reads lang keys literally, so a key glued together at runtime would fail it. */
    $label = \App\Support\Tranches::stateLabel((string) $state, (bool) $missingNet);
@endphp

<span {{ $attributes->class('inline-flex items-center gap-1.5 text-xs font-semibold whitespace-nowrap '.$tone) }} data-state="{{ $missingNet ? 'net-missing' : $state }}">
    <span class="size-2 shrink-0 rounded-full {{ $dot }}" aria-hidden="true"></span>{{ $label }}
</span>
