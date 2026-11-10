<?php

use App\Enums\ReportStatus;
use App\Models\Report;
use App\Models\User;
use Livewire\Livewire;

function seedReportsInEveryState(): array
{
    return [
        'late' => Report::factory()->create(['status' => ReportStatus::Draft, 'planned_date' => now()->subMonth()->toDateString(), 'value' => 111]),
        'waiting' => Report::factory()->create(['status' => ReportStatus::Reviewed, 'planned_date' => now()->subDays(3)->toDateString(), 'value' => 222]),
        'draft' => Report::factory()->create(['status' => ReportStatus::Draft, 'planned_date' => now()->addMonth()->toDateString(), 'value' => 333]),
        'approved' => Report::factory()->create(['status' => ReportStatus::Approved, 'planned_date' => now()->subMonths(2)->toDateString(), 'value' => 444]),
    ];
}

test('the three headline counts are disjoint, add up to the total and match what a click shows', function () {
    $reports = seedReportsInEveryState();

    $page = Livewire::actingAs(User::factory()->role('Media Team')->create())->test('pages::reports.index');

    expect($page->instance()->focusCounts)->toMatchArray(['attention' => 2, 'progress' => 1, 'approved' => 1, 'total' => 4]);

    $page->call('setFocus', 'attention')
        ->assertSee(route('reports.show', $reports['late']), false)
        ->assertSee(route('reports.show', $reports['waiting']), false)
        ->assertDontSee(route('reports.show', $reports['draft']), false)
        ->assertDontSee(route('reports.show', $reports['approved']), false)
        ->call('setFocus', 'attention')
        ->assertSet('focus', '')
        ->assertSee(route('reports.show', $reports['approved']), false)
        ->call('setFocus', 'nonsense')
        ->assertSet('focus', '');
});

test('each report names one status word and late drafts come before waiting ones, then drafts, then approved', function () {
    seedReportsInEveryState();

    Livewire::actingAs(User::factory()->role('Media Team')->create())
        ->test('pages::reports.index')
        ->assertSeeInOrder(['111', '222', '333', '444'])
        ->assertSeeText('Late draft')
        ->assertSeeText('Waiting for approval')
        ->assertSeeText('Approved');
});

test('author and MEAL review sit behind More filters and the controls stay inside the component', function () {
    seedReportsInEveryState();

    $html = Livewire::actingAs(User::factory()->role('Media Team')->create())
        ->test('pages::reports.index')
        ->html();

    $dom = new DOMDocument;
    @$dom->loadHTML('<?xml encoding="utf-8"?>'.$html);
    $xpath = new DOMXPath($dom);
    $root = '//section[@*[name()="wire:name"]="pages::reports.index"]';

    foreach (['more-filters-button', 'focus-tab-attention', 'focus-tab-progress', 'focus-tab-approved'] as $control) {
        expect($xpath->query($root.'//*[@data-test="'.$control.'"]')->length)->toBeGreaterThan(0, "{$control} sits outside the component");
    }
    expect($xpath->query($root.'//*[@data-test="report-list"]//article')->length)->toBe(4);
    expect($xpath->query($root.'//*[@data-test="report-list"]//article/div[@data-report-row-actions and contains(@class, "md:grid-cols-[max-content_2.25rem]")]')->length)->toBe(4);
});

test('only a draft offers edit and delete in the row menu; an approved report shows why not', function () {
    $reports = seedReportsInEveryState();

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::reports.index')
        ->assertSeeHtml('data-test="delete-report-'.$reports['late']->id.'"')
        ->assertDontSeeHtml('data-test="delete-report-'.$reports['approved']->id.'"')
        ->assertSeeText('Only draft reports can be edited');
});
