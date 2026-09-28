<?php

use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;

/*
| Every public method on a Livewire page can be called from the browser, and
| its return value is sent back. The pages' view-only helpers are protected so
| nobody can use them to read records the page itself does not show.
*/
test('the browser cannot call a page helper that returns data', function (string $page, string $method, array $arguments) {
    expect(fn () => Livewire::actingAs(userWithRole('General Manager'))
        ->test($page)
        ->call($method, ...$arguments))
        ->toThrow(MethodNotFoundException::class);
})->with([
    'annual plan row' => ['pages::annual-plans.index', 'planFor', [1, 2026]],
    'agreement money' => ['pages::proposals.index', 'agreementMoney', [1]],
    'donor money' => ['pages::donors.index', 'donorMoney', [1]],
    'portfolio gantt' => ['pages::timeline.index', 'portfolioGanttPayload', []],
    'delete check' => ['pages::milestones.index', 'canDelete', [1]],
]);
