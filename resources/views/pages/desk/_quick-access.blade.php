{{--
    Quick access (App\Support\Desk\QuickAccess): the pages and "New …" actions this person keeps one
    click away, beside the Needs you figures. Things get here by being dragged out of the Ask bar
    (resources/js/desk-drop.js); the button only shows once there is at least one. While something
    is carried from the Ask bar, the drop target stands in for the button (desk-workspace.css).

    Open, the list is a card of the button's width just under it, fading in as it drops 4px. The
    button's count and each row's number share one column; the icon tiles and names share one line.
    The list stays in the page while closed (inert and hidden) so it fades both ways. Keys (resources/js/desk-quick.js):
    1–9 open that row, ↑ ↓ move, Home / End jump, Esc closes. Rows reorder by dragging and leave by
    their ×.
--}}
<div class="dk-quick" x-data="hcQuickAccess" x-bind:class="{ 'dk-quick-open': open }" x-on:click.outside="close(false)" x-on:keydown="onKey($event)" @class(['dk-quick-none' => $quick === []]) data-quick-access>
    <div class="dk-quick-drop" aria-hidden="true">
        <x-desk.icon name="bolt" />
        <span>{{ __('desk-workspace.quick.drop_here') }}</span>
    </div>

    @if ($quick !== [])
        <button type="button" class="dk-quick-b" x-ref="toggle" x-on:click="toggle()" x-bind:aria-expanded="open.toString()" aria-haspopup="menu" aria-controls="dk-quick-menu" data-quick-toggle>
            <span class="dk-quick-ic" aria-hidden="true"><x-desk.icon name="bolt" /></span>
            <span class="dk-quick-l">{{ __('desk-workspace.quick.button') }}</span>
            <span class="dk-quick-n dk-num">{{ count($quick) }}</span>
        </button>

        <div class="dk-quick-pop" id="dk-quick-menu" role="menu" aria-label="{{ __('desk-workspace.quick.button') }}" x-bind:inert="! open" data-quick-menu>
            <ul class="dk-quick-list" x-ref="list">
                @foreach ($quick as $i => $entry)
                    <li class="dk-quick-row" style="--i: {{ $i }}" draggable="true" wire:key="quick-{{ $entry['key'] }}" data-quick-key="{{ $entry['key'] }}" x-on:dragstart="startSort($event)" x-on:dragover="overSort($event)" x-on:drop.prevent="endSort()" x-on:dragend="endSort()">
                        <a role="menuitem" href="{{ $entry['href'] }}" draggable="false" x-on:click="close(false)" @if ($i < 9) aria-keyshortcuts="{{ $i + 1 }}" @endif data-quick-item>
                            <span class="dk-quick-rI" aria-hidden="true"><x-desk.icon :name="$entry['icon']" /></span>
                            <span class="dk-quick-rT">{{ $entry['label'] }}</span>
                        </a>
                        @if ($i < 9)
                            <span class="dk-quick-key dk-num" aria-hidden="true">{{ $i + 1 }}</span>
                        @endif
                        <button type="button" class="dk-quick-x" wire:click="removeQuickAccess(@js($entry['key']))" aria-label="{{ __('desk-workspace.quick.remove', ['item' => $entry['label']]) }}" title="{{ __('desk-workspace.quick.remove', ['item' => $entry['label']]) }}" data-quick-remove>
                            <x-desk.icon name="x" />
                        </button>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif
</div>
