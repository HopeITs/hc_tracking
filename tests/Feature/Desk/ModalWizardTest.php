<?php

use Illuminate\Support\Facades\Blade;

function modalWithSections(int $count): string
{
    $sections = collect(range(1, $count))->map(fn (int $i): string => "<x-ui.form-section title=\"S{$i}\">f</x-ui.form-section>")->implode('');

    return Blade::render('<x-ui.modal wire:model="open" title="T" submit="save">'.$sections.'</x-ui.modal>');
}

test('a modal with five sections gets the section index and the wide size', function () {
    expect(modalWithSections(5))
        ->toContain('data-modal-index')
        ->toContain('data-modal-chips')
        ->toContain('modal-shell-main');
});

test('a modal with four sections has no section index', function () {
    expect(modalWithSections(4))
        ->not->toContain('data-modal-index')
        ->not->toContain('modal-shell-main');
});

test('a closed modal with an empty body has no section index', function () {
    expect(Blade::render('<x-ui.modal wire:model="open" title="T"></x-ui.modal>'))
        ->not->toContain('data-modal-index');
});

test('an x-ui.modal with many sections is never a step wizard', function () {
    $sections = collect(range(1, 6))->map(fn (int $i): string => "<x-ui.form-section title=\"S{$i}\">f</x-ui.form-section>")->implode('');
    $html = Blade::render('<x-ui.modal wire:model="open" title="T" submit="save">'.$sections.'<x-slot:actions><button data-save>Save</button></x-slot:actions></x-ui.modal>');

    expect($html)->toContain('modal-shell-centered')
        ->not->toContain('data-modal-steps')
        ->not->toContain('data-modal-next')
        ->not->toContain('data-modal-back')
        ->not->toContain('data-wizard')
        ->toContain('data-save')
        ->toContain('data-modal-errors');
});

test('the section index tags every step and turns its ring solid with a tick once every required field is filled', function () {
    $html = modalWithSections(5);

    expect($html)
        ->toContain('x-text="kindLabel(entry.kind)"')
        ->toContain('Needed for review')
        ->toContain('Required')
        ->toContain('Optional')
        ->toContain("'is-complete': required > 0 && filled === required")
        ->toContain('modal-shell-index-ring-tick');
});
