@props(['amount', 'currency'])

@php
    if ($currency === null) {
        throw new InvalidArgumentException('x-ui.money requires a currency; amounts are never shown bare because USD, EUR and SYP are not convertible in this system.');
    }

    $currencyCode = $currency instanceof \App\Enums\Currency ? $currency->value : (string) $currency;
    $formattedAmount = number_format((float) $amount, 2);
@endphp

<span {{ $attributes->class('inline-flex items-baseline gap-1 tabular-nums whitespace-nowrap') }}>
    <span class="font-semibold">{{ $formattedAmount }}</span>
    <span class="text-xs font-medium text-secondary-medium dark:text-secondary-soft">{{ $currencyCode }}</span>
</span>
