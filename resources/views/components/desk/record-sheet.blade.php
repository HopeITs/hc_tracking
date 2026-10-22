@props([
    'register',
    'registerParams' => [],
    'closeUrl',
    'title',
    'subtitle' => null,
    'width' => 'xwide',
])

@php
    /*
     * One record opened as a side sheet over its register (Tracking Desk stage 6,
     * user decision 2026-10-22): the old show URL keeps working and renders the
     * register behind, inert, with the record's own page logic in the sheet.
     * Closing (X, Esc, scrim) navigates back to the register, so the browser's
     * Back button and shared links behave like any page. Esc is left alone
     * while one of the record's own dialogs is open.
     */
    $titleId = 'dk-record-title-'.substr(md5($register.$closeUrl), 0, 10);
@endphp

<div class="dk-record-host" data-desk-record-host>
    <div class="dk-record-behind" inert aria-hidden="true" data-desk-record-behind>
        @livewire($register, $registerParams + ['defer' => true], key('record-register-'.$register))
    </div>

    <div
        x-data="{ leave() { Livewire.navigate(@js($closeUrl)) } }"
        x-init="window.hcLockPageScroll?.(); $nextTick(() => $refs.close.focus())"
        x-on:keydown.escape.window="if (! document.querySelector('dialog[open]')) { window.hcUnlockPageScroll?.(); leave() }"
        x-on:livewire:navigating.window="window.hcUnlockPageScroll?.()"
    >
        <div class="dk-sh-scrim" x-on:click="leave()" data-desk-record-scrim></div>
        <div @class(['dk-sheet', 'dk-record-sheet', 'dk-'.$width => $width !== 'normal']) role="dialog" aria-modal="true" aria-labelledby="{{ $titleId }}" data-desk-record>
            <div class="dk-sh-h">
                <div class="min-w-0">
                    <h2 id="{{ $titleId }}">{{ $title }}</h2>
                    @if ($subtitle !== null && $subtitle !== '')
                        <p>{{ $subtitle }}</p>
                    @endif
                </div>
                <a href="{{ $closeUrl }}" wire:navigate class="dk-iconbtn" x-ref="close" aria-label="{{ __('common.close') }}" data-desk-record-close>
                    <x-desk.icon name="x" />
                </a>
            </div>
            <div class="dk-sh-b dk-record-body">
                {{ $slot }}
            </div>
        </div>
    </div>
</div>
