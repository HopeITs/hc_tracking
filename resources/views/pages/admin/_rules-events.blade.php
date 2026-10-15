{{--
    Event Notifications: one row per EventCatalog key, grouped by module.
    Each row binds events.{index}.enabled / .importance / .oversight_roles
    into the page draft (saved with the rest of the rules from the save bar).
    Scheduled (scanner) events keep their catalog importance; events without
    oversight take no extra roles.
--}}
@php
    $eventGroups = $this->eventGroups();
    $roleOptions = $this->oversightRoleOptions;
    $stale = $this->staleOverrides;
    $searchTerms = collect($eventGroups)->flatMap(fn (array $group): array => array_map(fn (array $row): string => mb_strtolower($group['label'].' '.$row['title'].' '.$row['key']), $group['rows']))->values()->all();
@endphp

<section
    id="rules-events"
    aria-labelledby="rules-events-heading"
    class="rules-group mt-12"
    data-rule-field
    data-tour="notification-events"
    x-data="{
        query: '',
        terms: @js($searchTerms),
        matches(text) { return this.query.trim() === '' || text.includes(this.query.trim().toLowerCase()); },
        get anyMatch() { return this.terms.some((text) => this.matches(text)); },
    }"
>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <div class="min-w-0">
            <h2 id="rules-events-heading" class="rules-group-title">{{ __('notifications.settings.events.title') }}</h2>
            <p class="rules-group-hint">{{ __('notifications.settings.events.hint') }}</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <label class="rules-event-search">
                <flux:icon.magnifying-glass variant="micro" class="shrink-0" />
                <span class="sr-only">{{ __('notifications.settings.events.search') }}</span>
                <input type="search" x-model="query" placeholder="{{ __('notifications.settings.events.search') }}" data-test="event-search" />
            </label>
            <button type="button" class="rules-default" x-show="isCustom('events')" x-cloak x-on:click="useDefault('events')">{{ __('notifications.settings.events.use_defaults') }}</button>
        </div>
    </div>

    @if ($stale['keys'] !== [] || $stale['roles'] !== [])
        <div class="rules-event-stale" role="status" data-test="event-stale">
            <flux:icon.exclamation-triangle variant="micro" class="mt-0.5 shrink-0" />
            <div class="min-w-0">
                <p class="font-semibold">{{ __('notifications.settings.events.stale_heading') }}</p>
                @if ($stale['keys'] !== [])
                    <p>{{ __('notifications.settings.events.stale_keys') }} <span dir="ltr" class="font-mono">{{ implode(', ', $stale['keys']) }}</span></p>
                @endif
                @if ($stale['roles'] !== [])
                    <p>{{ __('notifications.settings.events.stale_roles') }} <span dir="auto">{{ implode(', ', $stale['roles']) }}</span></p>
                @endif
                <p class="rules-row-hint">{{ __('notifications.settings.events.stale_hint') }}</p>
            </div>
        </div>
    @endif

    <div class="mt-6 grid gap-10">
        @foreach ($eventGroups as $group)
            @php
                $groupTerms = array_map(fn (array $row): string => mb_strtolower($group['label'].' '.$row['title'].' '.$row['key']), $group['rows']);
            @endphp

            <div class="rules-event-group" wire:key="event-group-{{ $group['module'] }}" x-show="@js($groupTerms).some((text) => matches(text))">
                <h3 class="rules-event-module">{{ $group['label'] }}</h3>

                <div class="rules-rows">
                    @foreach ($group['rows'] as $row)
                        @php
                            $index = $row['index'];
                            $selectedRoles = $events[$index]['oversight_roles'] ?? [];
                        @endphp

                        <div
                            class="rules-event"
                            wire:key="event-{{ $row['key'] }}"
                            x-show="matches(@js(mb_strtolower($group['label'].' '.$row['title'].' '.$row['key'])))"
                            x-bind:class="! $wire.events[{{ $index }}].enabled && 'is-off'"
                            data-event-row="{{ $row['key'] }}"
                        >
                            <div class="min-w-0">
                                <p id="event-{{ $index }}-title" class="rules-row-label">{{ $row['title'] }}</p>
                                <p class="rules-row-hint flex flex-wrap items-center gap-x-2 gap-y-1">
                                    <span dir="ltr" class="font-mono text-xs">{{ $row['key'] }}</span>
                                    @if ($row['critical'])
                                        <span class="rules-event-badge">
                                            <flux:icon.shield-exclamation variant="micro" class="size-3.5" />
                                            {{ __('notifications.settings.events.critical') }}
                                        </span>
                                    @endif
                                </p>
                                @error("events.{$index}.key") <p class="rules-row-error" data-rule-error>{{ $message }}</p> @enderror
                            </div>

                            <div class="rules-event-controls">
                                <label class="rules-switch">
                                    <input type="checkbox" role="switch" wire:model="events.{{ $index }}.enabled" aria-describedby="event-{{ $index }}-title" @checked($events[$index]['enabled'] ?? true) />
                                    <span class="rules-switch-track" aria-hidden="true"><span class="rules-switch-thumb"></span></span>
                                    <span class="rules-switch-label" x-text="$wire.events[{{ $index }}].enabled ? @js(__('notifications.settings.events.on')) : @js(__('notifications.settings.events.off'))">{{ ($events[$index]['enabled'] ?? true) ? __('notifications.settings.events.on') : __('notifications.settings.events.off') }}</span>
                                </label>

                                @if ($row['scheduled'])
                                    <p class="rules-event-fixed">{{ __('notifications.settings.events.scheduled') }}</p>
                                @else
                                    <div class="rules-segment" role="radiogroup" aria-label="{{ __('notifications.settings.events.importance') }}">
                                        @foreach (App\Enums\EventImportance::cases() as $importance)
                                            <label>
                                                <input type="radio" name="event-{{ $index }}-importance" value="{{ $importance->value }}" wire:model="events.{{ $index }}.importance" @checked(($events[$index]['importance'] ?? null) === $importance->value) />
                                                <span>{{ $importance->label() }}</span>
                                            </label>
                                        @endforeach
                                    </div>
                                @endif

                                @if ($row['oversight'])
                                    <flux:dropdown position="bottom" align="end">
                                        <button type="button" class="rules-event-roles" aria-label="{{ __('notifications.settings.events.oversight_roles_for', ['event' => $row['title']]) }}">
                                            <flux:icon.user-group variant="micro" class="shrink-0" />
                                            <span class="truncate" x-text="$wire.events[{{ $index }}].oversight_roles.length ? $wire.events[{{ $index }}].oversight_roles.join(', ') : @js(__('notifications.settings.events.no_extra_roles'))">{{ $selectedRoles === [] ? __('notifications.settings.events.no_extra_roles') : implode(', ', $selectedRoles) }}</span>
                                            <flux:icon.chevron-down variant="micro" class="shrink-0" />
                                        </button>

                                        <flux:menu keep-open class="min-w-56">
                                            <flux:menu.heading>{{ __('notifications.settings.events.oversight_roles') }}</flux:menu.heading>
                                            <flux:menu.checkbox.group wire:model="events.{{ $index }}.oversight_roles">
                                                @foreach ($roleOptions as $role)
                                                    <flux:menu.checkbox value="{{ $role }}">{{ $role }}</flux:menu.checkbox>
                                                @endforeach
                                            </flux:menu.checkbox.group>
                                        </flux:menu>
                                    </flux:dropdown>
                                @else
                                    <p class="rules-event-fixed">{{ __('notifications.settings.events.no_oversight') }}</p>
                                @endif
                            </div>

                            @foreach (['enabled', 'importance', 'oversight_roles'] as $field)
                                @error("events.{$index}.{$field}") <p class="rules-row-error" data-rule-error>{{ $message }}</p> @enderror
                            @endforeach
                            @foreach ($errors->get("events.{$index}.oversight_roles.*") as $messages)
                                @foreach ($messages as $message)
                                    <p class="rules-row-error" data-rule-error>{{ $message }}</p>
                                @endforeach
                            @endforeach
                        </div>
                    @endforeach
                </div>
            </div>
        @endforeach

        <p class="rules-group-hint" x-show="! anyMatch" x-cloak role="status">{{ __('notifications.settings.events.no_match') }}</p>
    </div>
</section>
