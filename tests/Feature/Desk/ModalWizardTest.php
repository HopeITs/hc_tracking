<?php

use Illuminate\Support\Facades\Blade;

test('every x-ui.modal is a centered dialog carrying the wizard stepper and step buttons', function () {
    $html = Blade::render('<x-ui.modal wire:model="open" title="T" submit="save"><x-ui.form-section title="One">a</x-ui.form-section><x-slot:actions><button>Save</button></x-slot:actions></x-ui.modal>');

    expect($html)->toContain('modal-shell-centered')
        ->not->toContain('data-flux-flyout')
        ->toContain('data-modal-steps')
        ->toContain('data-modal-next')
        ->toContain('data-modal-back');
});
