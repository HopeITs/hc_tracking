{{--
    "Needs attention" (ReadsProposalAttention): one folded bar with a count
    and a chip per category; open it for filter pills and a card per category.
--}}
@php
    $hiddenKeys = $this->hiddenAttentionKeys;
    $allItems = $this->attentionItems;
    $visible = array_values(array_filter($allItems, fn (array $item): bool => ! in_array($item['key'], $hiddenKeys, true)));
    $hiddenCount = count($allItems) - count($visible);
    $lateCount = count(array_filter($visible, fn (array $item): bool => $item['late']));
    $soonCount = count($visible) - $lateCount;
    $categories = array_values(array_filter($this::ATTENTION_CATEGORIES, fn (string $cat): bool => collect($visible)->contains('cat', $cat)));
    $shown = array_values(array_filter($visible, fn (array $item): bool => match ($attentionFilter) {
        'all' => true,
        'late' => $item['late'],
        'soon' => ! $item['late'],
        default => $item['cat'] === $attentionFilter,
    }));
    $icons = [
        'ready' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'money' => '<rect x="2" y="6" width="20" height="12" rx="2"/><circle cx="12" cy="12" r="2.5"/>',
        'reports' => '<path d="M14 3H6a2 2 0 0 0-2 2v14a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V9Z"/><path d="M14 3v6h6M8 13h8M8 17h5"/>',
        'work' => '<path d="M9 11l3 3L22 4"/><path d="M21 12v7a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h11"/>',
        'projects' => '<path d="M12 22s7-7.5 7-13a7 7 0 0 0-14 0c0 5.5 7 13 7 13Z"/><circle cx="12" cy="9" r="2.5"/>',
        'results' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="5"/><circle cx="12" cy="12" r="1"/>',
        'risks' => '<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/>',
        'grant' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
    ];
@endphp

<section class="pp-attn" id="attn" data-test="attention">
    <button type="button" class="pp-attn-bar" wire:click="$toggle('attentionOpen')" aria-expanded="{{ $attentionOpen ? 'true' : 'false' }}" data-test="attention-toggle">
        <span @class(['pp-attn-count', 'pp-zero' => $lateCount === 0])>{{ $lateCount ?: ($soonCount ?: '✓') }}</span>
        <span class="pp-attn-t">
            <b>
                @if ($lateCount > 0)
                    {{ trans_choice('proposals.attention.things_need_attention', $lateCount, ['count' => $lateCount]) }}
                @elseif ($soonCount > 0)
                    {{ __('proposals.attention.nothing_late') }}
                @else
                    {{ __('proposals.attention.all_clear') }}
                @endif
            </b>
            <span class="pp-small">
                @if ($lateCount > 0 && $soonCount > 0)
                    {{ trans_choice('proposals.attention.plus_soon', $soonCount, ['count' => $soonCount]) }} ·
                @endif
                {{ $attentionOpen ? __('proposals.attention.click_to_fold') : __('proposals.attention.click_to_see') }}
            </span>
        </span>
        <span class="pp-attn-cats">
            @foreach ($categories as $cat)
                @php
                    $catLate = count(array_filter($visible, fn (array $item): bool => $item['cat'] === $cat && $item['late']));
                    $catSoon = count(array_filter($visible, fn (array $item): bool => $item['cat'] === $cat && ! $item['late']));
                @endphp
                <span class="pp-cat-chip" data-test="attention-chip-{{ $cat }}">
                    {{ __('proposals.attention.cat_'.$cat) }}
                    @if ($catLate > 0)<span class="pp-n">{{ $catLate }}</span>@endif
                    @if ($catSoon > 0)<span class="pp-n pp-n-soon">{{ $catLate > 0 ? '+' : '' }}{{ trans_choice('proposals.attention.n_soon', $catSoon, ['count' => $catSoon]) }}</span>@endif
                </span>
            @endforeach
        </span>
        <svg @class(['pp-chev', 'pp-chev-up' => $attentionOpen]) width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
    </button>

    @if ($attentionOpen)
        <div class="pp-attn-body" data-test="attention-body">
            <div class="pp-attn-filter" role="group" aria-label="{{ __('proposals.attention.filter') }}">
                <button type="button" @class(['pp-on' => $attentionFilter === 'all']) wire:click="$set('attentionFilter', 'all')" aria-pressed="{{ $attentionFilter === 'all' ? 'true' : 'false' }}">{{ __('proposals.attention.filter_all', ['count' => count($visible)]) }}</button>
                <button type="button" @class(['pp-on' => $attentionFilter === 'late']) wire:click="$set('attentionFilter', 'late')" aria-pressed="{{ $attentionFilter === 'late' ? 'true' : 'false' }}">{{ __('proposals.attention.filter_late', ['count' => $lateCount]) }}</button>
                <button type="button" @class(['pp-on' => $attentionFilter === 'soon']) wire:click="$set('attentionFilter', 'soon')" aria-pressed="{{ $attentionFilter === 'soon' ? 'true' : 'false' }}">{{ __('proposals.attention.filter_soon', ['count' => $soonCount]) }}</button>
                @foreach ($categories as $cat)
                    <button type="button" @class(['pp-on' => $attentionFilter === $cat]) wire:click="$set('attentionFilter', '{{ $cat }}')" aria-pressed="{{ $attentionFilter === $cat ? 'true' : 'false' }}">{{ __('proposals.attention.cat_'.$cat) }}</button>
                @endforeach
            </div>

            @if ($shown === [])
                <div class="pp-empty">{{ __('proposals.attention.nothing_here') }}</div>
            @else
                <div class="pp-attn-grid">
                    @foreach ($this::ATTENTION_CATEGORIES as $cat)
                        @php
                            $catItems = array_values(array_filter($shown, fn (array $item): bool => $item['cat'] === $cat));
                        @endphp
                        @continue($catItems === [])
                        <div class="pp-cat" wire:key="attn-cat-{{ $cat }}" data-test="attention-cat-{{ $cat }}">
                            <div class="pp-cat-h">
                                <span class="pp-cat-ic"><svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">{!! $icons[$cat] !!}</svg></span>
                                <b>{{ __('proposals.attention.cat_'.$cat) }}</b>
                                <span class="pp-small">{{ count($catItems) }}</span>
                            </div>
                            @foreach ($catItems as $item)
                                <div class="pp-item" wire:key="attn-{{ $item['key'] }}" data-attention-item="{{ $item['key'] }}">
                                    <span @class(['pp-sev', 'pp-sev-soon' => ! $item['late']]) aria-hidden="true"></span>
                                    <span class="pp-item-txt" dir="auto">{{ $item['text'] }}</span>
                                    <div class="pp-item-meta">
                                        <span @class(['pp-when', 'pp-when-soon' => ! $item['late']])>{{ $item['when'] }}</span>
                                        <span class="pp-sp"></span>
                                        <button type="button" class="pp-link" wire:click="hideAttentionItem('{{ $item['key'] }}')" data-test="attention-hide">{{ __('proposals.attention.hide') }}</button>
                                        <button type="button" class="pp-btn pp-btn-sm" wire:click="showMe('{{ $item['section'] }}')">{{ __('proposals.attention.show_me') }}</button>
                                        @if ($item['fix'] !== null)
                                            @if (isset($item['fix']['url']))
                                                <a class="pp-btn pp-btn-sm pp-btn-primary" href="{{ $item['fix']['url'] }}" wire:navigate>{{ $item['fix']['label'] }}</a>
                                            @else
                                                <button type="button" class="pp-btn pp-btn-sm pp-btn-primary" wire:click="{{ $item['fix']['call'] }}" data-test="attention-fix">{{ $item['fix']['label'] }}</button>
                                            @endif
                                        @endif
                                    </div>
                                </div>
                            @endforeach
                        </div>
                    @endforeach
                </div>
            @endif

            <div class="pp-attn-foot">
                <span class="pp-small">{{ __('proposals.attention.legend') }}</span>
                @if ($hiddenCount > 0)
                    <button type="button" class="pp-link" wire:click="showHiddenAttention" data-test="attention-unhide">{{ trans_choice('proposals.attention.show_hidden', $hiddenCount, ['count' => $hiddenCount]) }}</button>
                @endif
            </div>
        </div>
    @endif
</section>
