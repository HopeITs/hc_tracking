@props([
    'call',
    'name',
    'disabled' => false,
    'labelled' => false,
    'class' => 'dk-btn dk-ghost dk-sm',
])

{{--
    The Poke button and its note popover, shared by My team, Waiting on
    others and the project story. $call is the Livewire call without its
    last argument (e.g. "nudge(12" or "nudge('task-3'"); the note is appended
    as the last argument, or the call is closed when $call has no "(".
    The popover is teleported and fixed to the button, so a panel's
    overflow never clips it and the panel never resizes.
--}}
@php
    $limit = \App\Actions\Desk\SendNudge::NoteLimit;
    $method = str_contains($call, '(') ? $call.', note)' : $call.'(note)';
@endphp

<span class="dk-poke" x-data="{ open: false, note: '', top: 0, left: 0,
        show() { const r = this.$refs.btn.getBoundingClientRect(); const w = Math.min(300, window.innerWidth - 24); this.top = Math.min(r.bottom + 6, window.innerHeight - 250); this.left = Math.max(12, Math.min(document.dir === 'rtl' ? r.left : r.right - w, window.innerWidth - w - 12)); this.open = true; this.$nextTick(() => this.$refs.note?.focus()) },
        send() { const note = this.note; this.open = false; this.note = ''; $wire.{{ $method }} } }"
    x-on:keydown.escape.window="open = false">
    @if ($disabled)
        <button type="button" class="{{ $class }}" disabled title="{{ __('desk.poke_no_account') }}" aria-label="{{ __('desk.nudge_named', ['name' => $name]) }}"><x-desk.icon name="bell" />@if ($labelled){{ __('desk.nudge') }}@endif</button>
    @else
        <button type="button" class="{{ $class }}" x-ref="btn" x-on:click="open ? open = false : show()" x-bind:aria-expanded="open" aria-haspopup="dialog" aria-label="{{ __('desk.nudge_named', ['name' => $name]) }}" title="{{ __('desk.nudge_named', ['name' => $name]) }}" {{ $attributes }}><x-desk.icon name="bell" />@if ($labelled){{ __('desk.nudge') }}@endif</button>
        <template x-teleport="body">
            <div class="dk-scope dk-poke-pop" role="dialog" aria-label="{{ __('desk.nudge_named', ['name' => $name]) }}" x-show="open" x-cloak x-transition.opacity x-on:click.outside="open = false" x-bind:style="`top:${top}px;left:${left}px`">
                <p class="dk-poke-h">{{ __('desk.nudge_named', ['name' => $name]) }}</p>
                <div class="dk-poke-chips">
                    @foreach (__('desk.poke_chips') as $chip)
                        <button type="button" class="dk-chip" x-on:click="note = @js($chip); $refs.note.focus()">{{ $chip }}</button>
                    @endforeach
                </div>
                <textarea x-ref="note" x-model="note" maxlength="{{ $limit }}" rows="3" placeholder="{{ __('desk.poke_note') }}" aria-label="{{ __('desk.poke_note') }}"></textarea>
                <div class="dk-poke-f">
                    <span class="dk-is dk-num" x-text="`${note.length}/{{ $limit }}`"></span>
                    <button type="button" class="dk-btn dk-ghost dk-sm" x-on:click="open = false">{{ __('common.cancel') }}</button>
                    <button type="button" class="dk-btn dk-sm" x-on:click="send()" data-test="poke-send">{{ __('desk.poke_send') }}</button>
                </div>
            </div>
        </template>
    @endif
</span>
