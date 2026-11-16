<?php

use App\Models\Proposal;
use App\Models\Result;
use App\Models\Risk;
use App\Models\RiskLibrary;
use Livewire\Livewire;

function riskLibraryEntryInEveryState(): array
{
    $used = RiskLibrary::factory()->create(['title_en' => 'Used risk', 'sort_order' => 1]);
    $proposal = Proposal::factory()->create();
    $result = Result::factory()->create(['proposal_id' => $proposal->id]);
    Risk::factory()->create(['project_id' => null, 'library_risk_id' => $used->id, 'proposal_id' => $proposal->id, 'result_id' => $result->id]);

    return [
        'inuse' => $used,
        'unused' => RiskLibrary::factory()->create(['title_en' => 'Fresh risk', 'sort_order' => 2]),
        'off' => RiskLibrary::factory()->create(['title_en' => 'Old risk', 'sort_order' => 3, 'is_active' => false]),
    ];
}

it('counts in use, not used yet and switched off as three disjoint buttons that match a click', function () {
    riskLibraryEntryInEveryState();

    $page = Livewire::actingAs(mealManager())->test('pages::admin.risk-library');

    expect($page->instance()->focusCounts)->toMatchArray(['inuse' => 1, 'unused' => 1, 'off' => 1, 'total' => 3]);

    $page->call('setFocus', 'unused')
        ->assertSee('Fresh risk')
        ->assertDontSee('Used risk')
        ->assertDontSee('Old risk')
        ->call('setFocus', 'unused')
        ->assertSet('focus', '')
        ->assertSee('Old risk')
        ->call('setFocus', 'nonsense')
        ->assertSet('focus', '');
});

it('searches by title or category and keeps each entry\'s place in the whole library', function () {
    riskLibraryEntryInEveryState();

    $page = Livewire::actingAs(mealManager())->test('pages::admin.risk-library')
        ->set('search', 'old')
        ->assertSee('Old risk')
        ->assertDontSee('Fresh risk')
        ->assertSeeHtml('aria-label="Position 3"');

    // While a search narrows the list, a move would be a move in the whole library, so the arrows are off.
    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$page->html());
    $arrows = (new DOMXPath($dom))->query('//*[@data-test="move-entry-up" or @data-test="move-entry-down"]');
    expect($arrows->length)->toBe(2);
    foreach ($arrows as $arrow) {
        expect($arrow->hasAttribute('disabled'))->toBeTrue()
            // A disabled button takes no pointer events, so the reason must sit on the wrapper the pointer reaches.
            ->and($arrow->parentNode->getAttribute('data-test'))->toBe('move-entry-arrows')
            ->and($arrow->parentNode->getAttribute('title'))->toBe(__('risk_library.clear_to_reorder'));
    }

    $page->set('search', 'zzz-no-match')
        ->assertSee(__('risk_library.no_match'))
        ->assertSeeHtml('data-test="empty-reset-filters"')
        ->call('clearFilters')
        ->assertSet('search', '')
        ->assertDontSee(__('risk_library.clear_to_reorder'))
        ->assertSee('Fresh risk');
});

it('names the entry in the switch off and delete dialogs', function () {
    $entries = riskLibraryEntryInEveryState();

    Livewire::actingAs(mealManager())->test('pages::admin.risk-library')
        ->call('confirmDeactivate', $entries['unused']->id)
        ->assertSet('showDeactivateModal', true)
        ->assertSee('Switch off “Fresh risk”?')
        ->call('cancelDeactivate')
        ->call('confirmDelete', $entries['unused']->id)
        ->assertSee('Delete “Fresh risk”?');
});

it('keeps the buttons and rows inside the component and moves switching and deleting into the menu', function () {
    $entries = riskLibraryEntryInEveryState();

    $html = Livewire::actingAs(mealManager())->test('pages::admin.risk-library')->html();

    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($dom);
    $root = '//section[@*[name()="wire:name"]="pages::admin.risk-library"]';

    foreach (['focus-tab-inuse', 'focus-tab-unused', 'focus-tab-off'] as $control) {
        expect($xpath->query($root.'//*[@data-test="'.$control.'"]')->length)->toBeGreaterThan(0, "{$control} sits outside the component");
    }
    expect($xpath->query($root.'//ol[@data-risk-library-list]/li')->length)->toBe(3)
        ->and($xpath->query($root.'//*[@data-test="risk-library-menu-'.$entries['off']->id.'"]')->length)->toBe(1)
        ->and($html)->toContain('Switch on');
});
