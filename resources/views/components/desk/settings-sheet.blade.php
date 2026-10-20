@php
    $rows = app(\App\Support\Desk\Destinations::class)->settingsFor(auth()->user());
@endphp

{{-- Settings and admin: the avatar's side sheet. Each area opens as a full page; locked ones stay listed and say why. --}}
<div
    x-data="{ open: false }"
    x-on:dk-settings-open.window="open = true; window.hcLockPageScroll?.(); $nextTick(() => $refs.close.focus())"
    x-on:keydown.escape.window="if (open) { open = false; window.hcUnlockPageScroll?.(); }"
    data-desk-settings
>
    <template x-teleport="body">
        <div x-show="open" x-cloak class="dk-scope">
            <div class="dk-sh-scrim" x-on:click="open = false; window.hcUnlockPageScroll?.()"></div>
            <div class="dk-sheet" role="dialog" aria-modal="true" aria-labelledby="dk-settings-title">
                <div class="dk-sh-h">
                    <div>
                        <h2 id="dk-settings-title">{{ __('desk.settings_and_admin') }}</h2>
                        <p>{{ __('desk.settings_lede') }}</p>
                    </div>
                    <button type="button" class="dk-iconbtn" x-ref="close" x-on:click="open = false; window.hcUnlockPageScroll?.()" aria-label="{{ __('common.close') }}">
                        <x-desk.icon name="x" />
                    </button>
                </div>

                <div class="dk-sh-b">
                    <ul class="dk-lib">
                        @foreach ($rows as $row)
                            <li @class(['dk-lockd' => ! $row['open']]) data-settings-row="{{ $row['route'] }}">
                                <span class="dk-li-ic"><x-desk.icon :name="$row['open'] ? $row['icon'] : 'lock'" /></span>
                                <span class="dk-li-t">
                                    <strong>{{ $row['label'] }}</strong>
                                    <span class="dk-is">{{ $row['open'] ? $row['hint'] : $row['locked_reason'] }}</span>
                                </span>
                                @if ($row['open'])
                                    <a href="{{ route($row['route']) }}" wire:navigate class="dk-btn dk-sm" x-on:click="open = false; window.hcUnlockPageScroll?.()">{{ __('desk.open') }}</a>
                                @else
                                    <span class="dk-btn dk-sm" aria-disabled="true">{{ __('desk.open') }}</span>
                                @endif
                            </li>
                        @endforeach
                    </ul>

                    {{-- The two old top-bar tools (user decision, .ai/rules/desk.md). Install shows only while the browser offers it (window.hcApp, notifications.js). --}}
                    <ul class="dk-lib" data-settings-extras>
                        <li x-data="{ state: window.hcApp?.state() ?? 'unavailable' }" x-on:hc-app-install-change.window="state = $event.detail" x-show="state === 'available'" x-cloak data-settings-install>
                            <span class="dk-li-ic"><x-desk.icon name="download" /></span>
                            <span class="dk-li-t"><strong>{{ __('desk.install_app') }}</strong><span class="dk-is">{{ __('desk.install_app_hint') }}</span></span>
                            <button type="button" class="dk-btn dk-sm" x-on:click="open = false; window.hcUnlockPageScroll?.(); $flux.modal('install-app').show()" aria-haspopup="dialog" data-test="settings-install-app">{{ __('desk.install') }}</button>
                        </li>
                        <li data-report-bug>
                            <span class="dk-li-ic"><x-desk.icon name="flag" /></span>
                            <span class="dk-li-t"><strong>{{ __('navigation.report_a_problem') }}</strong><span class="dk-is">{{ __('desk.report_problem_hint') }}</span></span>
                            <a href="https://forms.gle/5QfGGarkeejAe3HS6" target="_blank" rel="noopener noreferrer" class="dk-btn dk-sm">{{ __('desk.open') }}</a>
                        </li>
                    </ul>
                </div>

                <div class="dk-sh-f">
                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="dk-btn dk-ghost" data-test="logout-button">{{ __('common.log_out') }}</button>
                    </form>
                    <button type="button" class="dk-btn dk-ghost" x-on:click="open = false; window.hcUnlockPageScroll?.()">{{ __('common.close') }}</button>
                </div>
            </div>
        </div>
    </template>
</div>
