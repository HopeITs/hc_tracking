<?php

use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Support\FluxMarkup;

test('an inline icon carries its size and extra classes like flux:icon does', function () {
    $html = (string) FluxMarkup::icon('flag', 'solid', 'size-4 text-status-warn');

    expect($html)->toContain('class="shrink-0 [:where(&)]:size-6 size-4 text-status-warn"')
        ->and($html)->toContain('data-flux-icon')
        ->and($html)->toContain('viewBox="0 0 24 24"');
});

test('an unknown inline icon fails loudly instead of rendering nothing', function () {
    FluxMarkup::icon('no-such-icon');
})->throws(InvalidArgumentException::class);

test('the status pill shows the label and a status icon', function (TaskStatus $case) {
    $html = (string) FluxMarkup::statusPill($case, 'shrink-0');

    expect($html)->toContain('<span class="truncate">'.e($case->label()).'</span>')
        ->and($html)->toContain('data-flux-icon')
        ->and($html)->toContain('shrink-0');
})->with(TaskStatus::cases());

test('only a low priority flag is an outline', function () {
    expect((string) FluxMarkup::priorityFlag(TaskPriority::Low))->toContain('fill="none"')
        ->and((string) FluxMarkup::priorityFlag(TaskPriority::High))->toContain('fill="currentColor"');
});

test('a ghost icon button escapes its label', function () {
    $html = (string) FluxMarkup::ghostIconButton('trash', 'Delete "<b>x</b>"', 'wire:click="confirmDelete(1)"');

    expect($html)->toContain('wire:click="confirmDelete(1)"')
        ->and($html)->not->toContain('<b>x</b>')
        ->and($html)->toContain('aria-label="Delete &quot;&lt;b&gt;x&lt;/b&gt;&quot;"');
});
