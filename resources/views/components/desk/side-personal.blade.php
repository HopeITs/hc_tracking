{{-- The Pinned list (kept live by hcSideNav as pins change) and the most used records (RemembersRecentRecord), shared by the expanded sidebar and the rail's fly-out. --}}
<ul class="dk-side-pins" data-side-pins>
    <template x-for="route in pins" :key="route">
        <li class="dk-side-li">
            <a x-bind:href="pages[route]?.href" wire:navigate class="dk-side-a dk-side-sub" x-bind:data-label="pages[route]?.label" x-bind:data-side-pinned="route" x-bind:data-desk-route="route">
                <span class="dk-side-l" x-text="pages[route]?.label"></span>
                <span class="dk-side-badge dk-num" x-show="pages[route]?.count > 0" x-text="pages[route]?.count > 99 ? '99+' : pages[route]?.count"></span>
            </a>
            <button type="button" class="dk-side-pin dk-pinned" x-on:click.prevent.stop="pin(route)" x-bind:aria-label="@js(__('navigation.unpin_page', ['page' => ':page'])).replace(':page', pages[route]?.label ?? '')">
                <x-desk.icon name="pushpin" class="dk-pin-ic" />
            </button>
        </li>
    </template>
    <li x-show="pins.length === 0"><p class="dk-side-empty">{{ __('navigation.pin_hint') }}</p></li>
</ul>
@if ($recent !== [])
    <p class="dk-side-gh">{{ __('navigation.recent') }}</p>
    <ul class="dk-side-recent" data-side-recent>
        @foreach ($recent as $entry)
            <li>
                <a href="{{ $entry['href'] }}" wire:navigate class="dk-side-a dk-side-sub dk-side-rec" data-label="{{ $entry['label'] }}">
                    <span class="dk-side-l"><span class="dk-side-rt">{{ $entry['label'] }}</span><small>{{ $entry['kind'] }}</small></span>
                </a>
            </li>
        @endforeach
    </ul>
@endif
