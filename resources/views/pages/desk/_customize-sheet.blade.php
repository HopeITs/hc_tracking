@php
    $library = $this->library;
    $starting = app(\App\Support\Desk\Panels::class)->startingLayoutFor(auth()->user());
@endphp

{{--
    Customize your desk: what is on it (size, remove), what can be added, and
    a reset to the role's layout. Every change here is a draft kept in the
    browser (deskCustomize, resources/js/desk-customize.js) until "Done"
    saves it in one call (saveLayout); closing the sheet any other way drops it.
    Every library panel is rendered once in each list and shown or hidden by
    the draft, so the rows keep their server-rendered icons.
--}}
<div x-data="deskCustomize(@js($starting))" data-layout="{{ json_encode($layout) }}" x-on:dk-customize-open.window="openSheet()" x-on:keydown.escape.window="if (open) { discard() }" data-desk-customize>
    <template x-teleport="body">
        <div x-show="open" x-cloak class="dk-scope">
            <div class="dk-sh-scrim" x-on:click="discard()"></div>
            <div class="dk-sheet" role="dialog" aria-modal="true" aria-labelledby="dk-customize-title">
                <div class="dk-sh-h">
                    <div>
                        <h2 id="dk-customize-title">{{ __('desk.customize_title') }}</h2>
                        <p>{{ __('desk.customize_lede') }}</p>
                    </div>
                    <button type="button" class="dk-iconbtn" x-on:click="discard()" aria-label="{{ __('common.close') }}"><x-desk.icon name="x" /></button>
                </div>

                <div class="dk-sh-b">
                    <section class="dk-adm">
                        <h3 x-text="{{ \Illuminate\Support\Js::from(__('desk.on_your_desk', ['count' => '__N__'])) }}.replace('__N__', draft.length)">{{ __('desk.on_your_desk', ['count' => count($layout)]) }}</h3>
                        <ul class="dk-lib dk-lib-order">
                            @foreach ($library as $panel)
                                <li wire:key="cz-on-{{ $panel['id'] }}" x-show="has('{{ $panel['id'] }}')" x-bind:style="{ order: position('{{ $panel['id'] }}') }" data-customize-on="{{ $panel['id'] }}">
                                    <span class="dk-li-ic"><x-desk.icon :name="$panel['icon']" /></span>
                                    <span class="dk-li-t"><strong>{{ $panel['title'] }}</strong><span class="dk-is">{{ $panel['description'] }}</span></span>
                                    <label class="dk-sr" for="cz-{{ $panel['id'] }}">{{ __('desk.size') }}</label>
                                    <select class="dk-mini" id="cz-{{ $panel['id'] }}" x-bind:value="sizeOf('{{ $panel['id'] }}')" x-on:change="resize('{{ $panel['id'] }}', $event.target.value)">
                                        @foreach (array_keys(\App\Support\Desk\Panels::Sizes) as $option)
                                            <option value="{{ $option }}">{{ __("desk.size_{$option}") }}</option>
                                        @endforeach
                                    </select>
                                    <button type="button" class="dk-iconbtn dk-sm" x-on:click="remove('{{ $panel['id'] }}')" aria-label="{{ __('desk.remove') }}"><x-desk.icon name="x" /></button>
                                </li>
                            @endforeach
                        </ul>
                    </section>

                    <section class="dk-adm">
                        <h3>{{ __('desk.add_more') }}</h3>
                        <ul class="dk-lib">
                            @foreach ($library as $panel)
                                <li wire:key="cz-add-{{ $panel['id'] }}" x-show="! has('{{ $panel['id'] }}')" data-customize-add="{{ $panel['id'] }}">
                                    <span class="dk-li-ic"><x-desk.icon :name="$panel['icon']" /></span>
                                    <span class="dk-li-t"><strong>{{ $panel['title'] }}</strong><span class="dk-is">{{ $panel['description'] }}</span></span>
                                    <button type="button" class="dk-btn dk-sm" x-on:click="add('{{ $panel['id'] }}', '{{ $panel['size'] }}')"><x-desk.icon name="plus" />{{ __('desk.add') }}</button>
                                </li>
                            @endforeach
                            @if ($this->locked === [])
                                <li x-show="draft.length === {{ count($library) }}"><span class="dk-li-t"><span class="dk-is">{{ __('desk.nothing_to_add') }}</span></span></li>
                            @endif
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
                    <button type="button" class="dk-btn" x-on:click="reset()"><x-desk.icon name="undo" />{{ __('desk.reset_layout') }}</button>
                    <span class="dk-pspace"></span>
                    <button type="button" class="dk-btn dk-ghost" x-on:click="discard()">{{ __('common.cancel') }}</button>
                    <button type="button" class="dk-btn dk-primary" x-on:click="save()" data-customize-done>{{ __('desk.done') }}</button>
                </div>
            </div>
        </div>
    </template>
</div>
