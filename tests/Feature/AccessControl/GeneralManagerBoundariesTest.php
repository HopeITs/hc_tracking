<?php

use Livewire\Livewire;

/*
| UAT GM-05 and GM-06: the General Manager reads and decides on donors and the
| activity log, but never confirms templates, sets targets, reopens or marks
| reports seen, or drafts with AI. The AI usage ledger has no page yet, so GM-06
| is the permission itself.
*/

test('the General Manager holds none of the MEAL-only or AI drafting permissions', function (string $permission) {
    expect(userWithRole('General Manager')->can($permission))->toBeFalse();
})->with(['report-templates.manage', 'targets.create', 'targets.update', 'reports.reopen', 'reports.mark-seen', 'ai.draft']);

test('the General Manager cannot create a target', function () {
    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::targets.index')
        ->call('openCreateModal')
        ->assertForbidden();
});

test('the General Manager can see the AI usage log, the Managers role cannot', function () {
    expect(userWithRole('General Manager')->can('ai.view-usage'))->toBeTrue()
        ->and(userWithRole('Managers')->can('ai.view-usage'))->toBeFalse();
});
