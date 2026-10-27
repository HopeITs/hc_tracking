<?php

use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;

test('the modal shell renders as a centered popup, never a side sheet', function () {
    View::share('errors', new ViewErrorBag);

    $html = Blade::render('<x-ui.modal wire:model="showFormModal" size="lg" title="Add thing" close="closeForm"><p>Body</p></x-ui.modal>');

    expect($html)->not->toContain('data-flux-flyout')
        ->and($html)->not->toContain('modal-shell-sheet')
        ->and($html)->toContain('modal-shell-centered')
        ->and($html)->toContain('modal-shell-lg');
});

test('confirm-modal stays a small centered dialog', function () {
    View::share('errors', new ViewErrorBag);

    $html = Blade::render('<x-ui.confirm-modal wire:model="showDeleteModal" title="Delete?" confirm="go" confirm-label="Delete" />');

    expect($html)->not->toContain('data-flux-flyout')
        ->and($html)->toContain('modal-shell-centered')
        ->and($html)->toContain('max-w-md');
});
