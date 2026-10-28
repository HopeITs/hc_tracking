@props([
    'call',
    'label',
    'heading',
    'placeholder',
    'required' => false,
    'class' => 'dk-btn dk-ghost dk-sm',
])

{{--
    A button that opens a small teleported note popover, then calls
    $wire.<call>, note) — e.g. call="move(12, 'in progress'" sends the task
    back with the note. With $required the send button waits for a note.
    Same shape as x-desk.poke (which adds quick chips).
--}}
@php
    $limit = \App\Actions\Desk\SendNudge::NoteLimit;
@endphp

<span class="dk-poke" x-data="{ open: false, note: '', top: 0, left: 0,
        show() { const r = this.$refs.btn.getBoundingClientRect(); const w = Math.min(300, window.innerWidth - 24); this.top = Math.min(r.bottom + 6, window.innerHeight - 220); this.left = Math.max(12, Math.min(document.dir === 'rtl' ? r.left : r.right - w, window.innerWidth - w - 12)); this.open = true; this.$nextTick(() => this.$refs.note?.focus()) },
        send() { const note = this.note; this.open = false; this.note = ''; $wire.{{ $call }}, note) } }"
    x-on:keydown.escape.window="open = false">
    <button type="button" class="{{ $class }}" x-ref="btn" x-on:click="open ? open = false : show()" x-bind:aria-expanded="open" aria-haspopup="dialog" {{ $attributes }}>{{ $label }}</button>
    <template x-teleport="body">
        <div class="dk-scope dk-poke-pop" role="dialog" aria-label="{{ $heading }}" x-show="open" x-cloak x-transition.opacity x-on:click.outside="open = false" x-bind:style="`top:${top}px;left:${left}px`">
            <p class="dk-poke-h">{{ $heading }}</p>
            <textarea x-ref="note" x-model="note" maxlength="{{ $limit }}" rows="3" placeholder="{{ $placeholder }}" aria-label="{{ $placeholder }}"></textarea>
            <div class="dk-poke-f">
                <span class="dk-is dk-num" x-text="`${note.length}/{{ $limit }}`"></span>
                <button type="button" class="dk-btn dk-ghost dk-sm" x-on:click="open = false">{{ __('common.cancel') }}</button>
                <button type="button" class="dk-btn dk-sm" x-on:click="send()" @if ($required) x-bind:disabled="note.trim() === ''" @endif>{{ $label }}</button>
            </div>
        </div>
    </template>
</span>
