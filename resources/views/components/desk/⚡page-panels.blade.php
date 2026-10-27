<?php

use App\Livewire\Concerns\Desk\ArrangesPanels;
use App\Support\Desk\Panels;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * The top bar's Panels popup: a grid of Desk panels for the page you are on.
 * Each page (by route name) keeps its own set per user on users.page_panels,
 * starting from the page's default (Panels::defaultPageLayoutFor). The panels
 * are only mounted once the popup is first opened, so a page load costs
 * nothing until someone asks for them. On the Desk the button opens the
 * Desk's own Customize popup instead.
 */
new class extends Component {
    use ArrangesPanels;

    #[Locked]
    public string $routeName = '';

    #[Locked]
    public ?string $pageTitle = null;

    /**
     * Whether the panels have been mounted on this page yet.
     */
    public bool $loaded = false;

    public function load(): void
    {
        $this->loaded = true;
    }

    /**
     * @return list<array{panel: string, size: string}>
     */
    #[Computed]
    public function layout(): array
    {
        return app(Panels::class)->pageLayoutFor(auth()->user(), $this->routeName);
    }

    /**
     * @return array<string, array{id: string, title: string, description: string, icon: string, size: string, register: string|null}>
     */
    #[Computed]
    public function library(): array
    {
        return app(Panels::class)->availableTo(auth()->user());
    }

    /**
     * @param  list<array{panel: string, size: string}>|null  $layout
     */
    protected function storeLayout(?array $layout): void
    {
        app(Panels::class)->savePageLayout(auth()->user(), $this->routeName, $layout);
        unset($this->layout);
    }
};
?>

@php
    $isDesk = $routeName === 'dashboard';
    $layout = $loaded ? $this->layout : [];
    $placed = array_column($layout, 'panel');
    $addable = $loaded ? array_diff_key($this->library, array_flip($placed)) : [];
@endphp

<div
    x-data="{ open: false, adding: false }"
    @if ($isDesk)
        x-on:dk-panels-open.window="$dispatch('dk-customize-open')"
    @else
        x-on:dk-panels-open.window="open = true; window.hcLockPageScroll?.(); if (! $wire.loaded) { $wire.load() } $nextTick(() => $refs.close?.focus())"
        x-on:keydown.escape.window="if (open && ! document.querySelector('dialog[open]')) { if (adding) { adding = false } else { open = false; window.hcUnlockPageScroll?.() } }"
        x-on:livewire:navigating.window="if (open) { open = false; window.hcUnlockPageScroll?.() }"
    @endif
    data-page-panels
>
    @unless ($isDesk)
        <template x-teleport="body">
            <div x-show="open" x-cloak class="dk-scope">
                <div class="dk-sh-scrim" x-on:click="open = false; window.hcUnlockPageScroll?.()"></div>
                <div class="dk-sheet dk-xwide dk-pp" role="dialog" aria-modal="true" aria-labelledby="dk-pp-title" data-page-panels-dialog>
                    <div class="dk-sh-h">
                        <div class="min-w-0">
                            <h2 id="dk-pp-title">{{ __('navigation.panels') }}</h2>
                            <p>{{ filled($pageTitle) ? __('desk.panels_for_page', ['page' => $pageTitle]) : __('desk.panels_lede') }}</p>
                        </div>
                        <div class="dk-pp-tools">
                            @if (! $loaded || $addable !== [])
                            <span class="dk-pmenu" x-on:click.outside="adding = false">
                                <button type="button" class="dk-btn" x-on:click="adding = ! adding" x-bind:aria-expanded="adding.toString()" aria-haspopup="menu" data-page-panels-add>
                                    <x-desk.icon name="plus" />{{ __('desk.add_panel') }}
                                </button>
                                <div class="dk-pop dk-pp-add" role="menu" x-show="adding" x-cloak>
                                    @forelse ($addable as $panel)
                                        <button type="button" role="menuitem" wire:click="addPanel('{{ $panel['id'] }}')" x-on:click="adding = false" wire:key="pp-add-{{ $panel['id'] }}" data-page-panels-option="{{ $panel['id'] }}">
                                            <span class="dk-li-ic"><x-desk.icon :name="$panel['icon']" /></span>
                                            <span class="dk-li-t"><strong>{{ $panel['title'] }}</strong><span class="dk-is">{{ $panel['description'] }}</span></span>
                                        </button>
                                    @empty
                                        <p class="dk-pempty">{{ $loaded ? __('desk.nothing_more_to_add') : __('desk.loading') }}</p>
                                    @endforelse
                                </div>
                            </span>
                            @endif
                            <button type="button" class="dk-btn dk-ghost" wire:click="resetLayout" title="{{ __('desk.reset_page_panels') }}" data-page-panels-reset>
                                <x-desk.icon name="undo" /><span class="dk-pp-tl">{{ __('desk.reset_page_panels') }}</span>
                            </button>
                            <button type="button" class="dk-iconbtn" x-ref="close" x-on:click="open = false; window.hcUnlockPageScroll?.()" aria-label="{{ __('common.close') }}">
                                <x-desk.icon name="x" />
                            </button>
                        </div>
                    </div>

                    <div class="dk-sh-b dk-pp-body">
                        @if (! $loaded)
                            <div class="dk-grid" aria-busy="true">
                                @foreach (range(1, 4) as $placeholder)
                                    <div class="dk-panel dk-pp-ghost" style="--span:6"></div>
                                @endforeach
                            </div>
                        @elseif ($layout === [])
                            <div class="dk-pp-empty" data-page-panels-empty>
                                <x-desk.icon name="grid" />
                                <strong>{{ __('desk.panels_empty_title') }}</strong>
                                <p>{{ __('desk.panels_empty_body') }}</p>
                                <button type="button" class="dk-btn" x-on:click.stop="adding = true"><x-desk.icon name="plus" />{{ __('desk.add_panel') }}</button>
                            </div>
                        @else
                            <div class="dk-grid" data-desk-grid>
                                @foreach ($layout as $i => $entry)
                                    <livewire:is :component="'desk.panels.'.$entry['panel']" :size="$entry['size']" :index="$i" :total="count($layout)" :wire:key="'pp-panel-'.$routeName.'-'.$entry['panel']" />
                                @endforeach
                            </div>
                        @endif
                    </div>
                </div>
            </div>
        </template>
    @endunless
</div>
