<?php

use Illuminate\Support\Facades\Blade;

test('the register, its heading, the totals, the pills and the pager print the hooks other pages rely on', function () {
    $register = Blade::render('<x-funding.register size="xl" caption="Cap" class="mt-4"><x-slot:head><x-funding.register.th sort="amount" active="amount" dir="desc" numeric>Amount</x-funding.register.th><x-funding.register.th>Plain</x-funding.register.th></x-slot:head><tr><td>1</td><td>2</td></tr><x-slot:foot><tr><td>F</td></tr></x-slot:foot></x-funding.register>');

    expect($register)->toContain('data-register')->toContain('data-data-table')->toContain('register-xl')->toContain('mt-4')
        ->toContain('wire:click="sortRegister(\'amount\')"')->toContain('aria-sort="descending"')->toContain('<tfoot>')
        ->and(substr_count($register, '<th scope='))->toBe(2);

    $totals = Blade::render('<x-funding.totals :lines="$lines" />', ['lines' => [[
        'currency' => 'USD', 'caption' => '2 tranches',
        'figures' => [['key' => 'shortfall', 'label' => 'Shortfall', 'value' => 10.0, 'prefix' => '−', 'tone' => 'critical', 'note' => '1%']],
        'notes' => ['A note'],
    ]]]);

    expect($totals)->toContain('data-register-totals')->toContain('data-totals-currency="USD"')->toContain('data-total="shortfall"')
        ->toContain('−10.00')->toContain('A note')->toContain('1%')
        ->and(Blade::render('<x-funding.totals :lines="[]" />'))->not->toContain('data-register');

    $filters = Blade::render('<x-funding.filters :active="true"><x-funding.pills label="Year" :pills="$pills" /></x-funding.filters>', ['pills' => [
        ['label' => '2026', 'click' => "\$set('year', '2026')", 'active' => true, 'count' => 3, 'test' => 'year-2026'],
        null,
    ]]);

    expect($filters)->toContain('data-finance-filters')->not->toContain('data-register')
        ->toContain('aria-pressed="true"')->toContain('data-test="year-2026"')->toContain('data-test="reset-filters"');
});
