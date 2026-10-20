@php
    $rows = app(\App\Support\Desk\Destinations::class)->settingsFor(auth()->user());
    $groups = collect($rows)->groupBy('group');
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
                    @foreach ($groups as $group => $groupRows)
                        <section class="dk-adm" data-settings-group="{{ $group }}">
                            <h3>{{ __('desk.settings_group.'.$group) }}</h3>
                            <ul>
                                @foreach ($groupRows as $row)
                                    @if ($row['open'])
                                        <li data-settings-row="{{ $row['route'] }}">
                                            <a href="{{ route($row['route']) }}" wire:navigate class="dk-adm-row" x-on:click="open = false; window.hcUnlockPageScroll?.()">
                                                <strong>{{ $row['label'] }}</strong>
                                                <span>{{ $row['hint'] }}</span>
                                            </a>
                                        </li>
                                    @else
                                        <li class="dk-lockd" data-settings-row="{{ $row['route'] }}" title="{{ $row['locked_reason'] }}">
                                            <span class="dk-adm-row" aria-disabled="true">
                                                <strong><x-desk.icon name="lock" />{{ $row['label'] }}</strong>
                                                <span>{{ $row['locked_reason'] }}</span>
                                            </span>
                                        </li>
                                    @endif
                                @endforeach

                                @if ($group === 'you')
                                    {{-- The two old top-bar tools (user decision, .ai/rules/desk.md). Install shows only while the browser offers it (window.hcApp, notifications.js). --}}
                                    <li x-data="{ state: window.hcApp?.state() ?? 'unavailable' }" x-on:hc-app-install-change.window="state = $event.detail" x-show="state === 'available'" x-cloak data-settings-install>
                                        <div class="dk-adm-row" style="display:flex;align-items:center;justify-content:space-between;gap:12px">
                                            <span>
                                                <strong>{{ __('desk.install_app') }}</strong>
                                                <span>{{ __('desk.install_app_hint') }}</span>
                                            </span>
                                            <button type="button" class="dk-btn" x-on:click="open = false; window.hcUnlockPageScroll?.(); $flux.modal('install-app').show()" aria-haspopup="dialog" data-test="settings-install-app">{{ __('desk.install') }}</button>
                                        </div>
                                    </li>
                                    <li data-report-bug>
                                        <a href="https://forms.gle/5QfGGarkeejAe3HS6" target="_blank" rel="noopener noreferrer" class="dk-adm-row">
                                            <strong>{{ __('navigation.report_a_problem') }}</strong>
                                            <span>{{ __('desk.report_problem_hint') }}</span>
                                        </a>
                                    </li>
                                @endif
                            </ul>
                        </section>
                    @endforeach
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
