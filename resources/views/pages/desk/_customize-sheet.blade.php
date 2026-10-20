@php
    $library = $this->library;
    $onDesk = array_column($layout, 'size', 'panel');
@endphp

{{-- Customize your desk: what is on it (size, remove), what can be added, and a reset to the role's layout. --}}
<div x-data="{ open: false }" x-on:dk-customize-open.window="open = true; window.hcLockPageScroll?.()" x-on:keydown.escape.window="if (open) { open = false; window.hcUnlockPageScroll?.(); }" data-desk-customize>
    <template x-teleport="body">
        <div x-show="open" x-cloak class="dk-scope">
            <div class="dk-sh-scrim" x-on:click="open = false; window.hcUnlockPageScroll?.()"></div>
            <div class="dk-sheet" role="dialog" aria-modal="true" aria-labelledby="dk-customize-title">
                <div class="dk-sh-h">
                    <div>
                        <h2 id="dk-customize-title">{{ __('desk.customize_title') }}</h2>
                        <p>{{ __('desk.customize_lede') }}</p>
                    </div>
                    <button type="button" class="dk-iconbtn" x-on:click="open = false; window.hcUnlockPageScroll?.()" aria-label="{{ __('common.close') }}"><x-desk.icon name="x" /></button>
                </div>

                <div class="dk-sh-b">
                    <section class="dk-adm">
                        <h3>{{ __('desk.on_your_desk', ['count' => count($onDesk)]) }}</h3>
                        <ul class="dk-lib">
                            @foreach ($onDesk as $panel => $size)
                                <li wire:key="cz-on-{{ $panel }}">
                                    <span class="dk-li-ic"><x-desk.icon :name="$library[$panel]['icon']" /></span>
                                    <span class="dk-li-t"><strong>{{ $library[$panel]['title'] }}</strong><span class="dk-is">{{ $library[$panel]['description'] }}</span></span>
                                    <label class="dk-sr" for="cz-{{ $panel }}">{{ __('desk.size') }}</label>
                                    <select class="dk-mini" id="cz-{{ $panel }}" x-on:change="$wire.resizePanel('{{ $panel }}', $event.target.value)">
                                        @foreach (array_keys(\App\Support\Desk\Panels::Sizes) as $option)
                                            <option value="{{ $option }}" @selected($option === $size)>{{ __("desk.size_{$option}") }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="dk-iconbtn dk-sm" wire:click="removePanel('{{ $panel }}')" aria-label="{{ __('desk.remove') }}"><x-desk.icon name="x" /></button>
                                </li>
                            @endforeach
                        </ul>
                    </section>

                    <section class="dk-adm">
                        <h3>{{ __('desk.add_more') }}</h3>
                        <ul class="dk-lib">
                            @forelse (array_diff_key($library, $onDesk) as $panel)
                                <li wire:key="cz-add-{{ $panel['id'] }}">
                                    <span class="dk-li-ic"><x-desk.icon :name="$panel['icon']" /></span>
                                    <span class="dk-li-t"><strong>{{ $panel['title'] }}</strong><span class="dk-is">{{ $panel['description'] }}</span></span>
                                    <button type="button" class="dk-btn dk-sm" wire:click="addPanel('{{ $panel['id'] }}')"><x-desk.icon name="plus" />{{ __('desk.add') }}</button>
                                </li>
                            @empty
                                @if ($this->locked === [])
                                    <li><span class="dk-li-t"><span class="dk-is">{{ __('desk.nothing_to_add') }}</span></span></li>
                                @endif
                            @endforelse
                            @foreach ($this->locked as $panel)
                                <li wire:key="cz-lock-{{ $panel['id'] }}" class="dk-lockd" data-locked-panel="{{ $panel['id'] }}">
                                    <span class="dk-li-ic"><x-desk.icon name="lock" /></span>
                                    <span class="dk-li-t"><strong>{{ $panel['title'] }}</strong><span class="dk-is">{{ $panel['reason'] }}</span></span>
                                </li>
                            @endforeach
                        </ul>
                    </section>
                </div>

                <div class="dk-sh-f">
                    <button type="button" class="dk-btn" wire:click="resetLayout"><x-desk.icon name="undo" />{{ __('desk.reset_layout') }}</button>
                    <button type="button" class="dk-btn dk-ghost" x-on:click="open = false; window.hcUnlockPageScroll?.()">{{ __('desk.done') }}</button>
                </div>
            </div>
        </div>
    </template>
</div>
