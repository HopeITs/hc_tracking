@props(['route' => null])

@php
    $deskUser = auth()->user();
    $route ??= \App\Support\Desk\DeskTab::pageRouteName();
    $deskPanels = app(\App\Support\Desk\Panels::class);
    $library = $deskUser !== null && $route !== null ? $deskPanels->availableTo($deskUser) : [];
    $main = $route !== null && $library !== [] ? $deskPanels->itemForRoute($deskUser, $route) : null;
    $pageButton = $route !== null ? \App\Support\Desk\Shortcuts::PagePrefix.\App\Support\Desk\Destinations::slugOf($route) : null;
    $action = $route !== null && $deskUser !== null ? app(\App\Support\Desk\Shortcuts::class)->actionForRoute($deskUser, $route) : null;
    /* What this page can put on the Desk: what stands for it, a button that opens it, and its "New …" button. */
    $choices = collect([
        $main => ['label' => $main !== null && ($library[$main]['kind'] ?? '') === 'page' ? __('desk-workspace.add.as_button') : __('desk-workspace.add.as_card'), 'hint' => __('desk-workspace.add.as_card_hint')],
        $pageButton => ['label' => __('desk-workspace.add.as_button'), 'hint' => __('desk-workspace.add.as_button_hint')],
        $action['id'] ?? null => ['label' => __('desk-workspace.add.as_action', ['action' => $action['label'] ?? '']), 'hint' => __('desk-workspace.add.as_action_hint')],
    ])->filter(fn (array $choice, string $id): bool => $id !== '' && isset($library[$id]))->all();
    /* Buttons are kept in Quick access, never on the Desk itself: either counts as "on". */
    $placed = $choices === [] ? [] : array_values(array_intersect(array_keys($choices), [...array_column($deskPanels->layoutFor($deskUser), 'panel'), ...app(\App\Support\Desk\QuickAccess::class)->itemIds($deskUser)]));
    $texts = [
        'added' => __('desk-workspace.add.added', ['item' => '__I__']),
        'removed' => __('desk-workspace.add.removed', ['item' => '__I__']),
        'quick_added' => __('desk-workspace.add.quick_added', ['item' => '__I__']),
        'quick_removed' => __('desk-workspace.add.quick_removed', ['item' => '__I__']),
        'failed' => __('desk-workspace.add.failed'),
    ];
@endphp

{{--
    "Add to desk" in a page's header: puts what stands for this page on My desk (its panel or
    page card), and from the menu a button that opens the page, or the page's "New …" button,
    both kept in Quick access.
    Pressed again it takes it off. Works the same inside a Desk tab, where the Desk updates at once
    (resources/js/desk-items.js). Only what the viewer may see is offered.
--}}
@if ($main !== null && isset($choices[$main]))
    <div class="dk-addesk dk-pmenu" x-data="hcAddToDesk(@js(['placed' => $placed, 'texts' => $texts]))" x-on:click.outside="open = false" x-on:keydown.escape.stop="open = false" wire:ignore data-add-to-desk="{{ $route }}">
        <button type="button" class="dk-btn dk-addesk-main" x-bind:class="{ 'dk-on': isOn(@js($main)) }" x-on:click="set(@js($main))" x-bind:aria-pressed="isOn(@js($main)).toString()" x-bind:disabled="busy" data-add-to-desk-main>
            <x-desk.icon name="pin" />
            <span x-show="! isOn(@js($main))">{{ __('desk-workspace.add.to_desk') }}</span>
            <span x-show="isOn(@js($main))" x-cloak>{{ __('desk-workspace.add.on_desk') }}</span>
        </button>
        @if (count($choices) > 1)
            <button type="button" class="dk-btn dk-addesk-more" x-on:click="open = ! open" x-bind:aria-expanded="open.toString()" aria-haspopup="menu" aria-label="{{ __('desk-workspace.add.more') }}" data-add-to-desk-more>
                <flux:icon.chevron-down variant="micro" class="size-4" />
            </button>
            <div class="dk-pop dk-menu dk-addesk-pop" role="menu" x-show="open" x-cloak>
                <p class="dk-pop-h">{{ __('desk-workspace.add.menu_title') }}</p>
                @foreach ($choices as $id => $choice)
                    <button type="button" role="menuitemcheckbox" x-bind:aria-checked="isOn(@js($id)).toString()" x-on:click="set(@js($id))" data-add-to-desk-choice="{{ $id }}">
                        <span class="dk-addesk-check" aria-hidden="true"><x-desk.icon name="check" /></span>
                        <span class="dk-menu-t"><strong>{{ $choice['label'] }}</strong><small>{{ $choice['hint'] }}</small></span>
                    </button>
                @endforeach
            </div>
        @endif
        <span class="dk-sr" role="status" aria-live="polite" x-text="said"></span>
    </div>
@endif
