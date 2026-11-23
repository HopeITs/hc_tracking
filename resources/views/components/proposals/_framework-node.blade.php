{{-- One result of the Results chain, folded until opened, with its children below. Expects $resultId. --}}
@php
    $node = $fw['results'][$resultId];
    $isOpen = in_array($resultId, $openIds, true) || $search !== '';
    $typeKey = $node['type']->value;
    $kids = array_values(array_filter($fw['children'][$resultId] ?? [], fn (int $id): bool => in_array($id, $visible, true)));
    $childType = match ($node['type']) {
        \App\Enums\ResultType::Impact => 'outcome',
        \App\Enums\ResultType::Outcome => 'output',
        default => null,
    };
    $countFor = [
        'indicators' => count($node['indicators']),
        'activities' => count($node['activities']),
        'risks' => count($node['risks']),
        'assumptions' => count($node['assumptions']),
    ];
@endphp

@if (in_array($resultId, $visible, true))
    <div @class(['pp-node', 'pp-issue' => $node['issues'] !== []]) id="n-{{ $resultId }}" wire:key="node-{{ $resultId }}" data-result-type="{{ $typeKey }}">
        <div class="pp-node-h" role="button" tabindex="0" aria-expanded="{{ $isOpen ? 'true' : 'false' }}" wire:click="toggleOpen({{ $resultId }})" x-on:keydown.enter.prevent="$wire.toggleOpen({{ $resultId }})" x-on:keydown.space.prevent="$wire.toggleOpen({{ $resultId }})" data-test="node-toggle-{{ $resultId }}">
            <span class="pp-lvl pp-lvl-{{ $typeKey }}">{{ $node['type']->label() }}</span>
            <span class="pp-node-txt" dir="auto">{{ $node['statement'] }}</span>
            <span class="pp-node-sum">
                @foreach ($countFor as $layer => $n)
                    @if (($layers[$layer] ?? false) && $n > 0)
                        <span class="pp-mini">{{ trans_choice('proposals.framework.count_'.$layer, $n, ['count' => $n]) }}</span>
                    @endif
                @endforeach
                @foreach ($node['issues'] as $issue)
                    <span class="pp-mini pp-mini-red">{{ $issue }}</span>
                @endforeach
            </span>
            @if ($canEdit)
                <span class="pp-node-sum" x-on:click.stop>
                    <button type="button" class="pp-btn pp-btn-ghost" wire:click="openEditModal({{ $resultId }})" aria-label="{{ __('proposals.framework.edit_item', ['item' => $node['statement']]) }}"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M12 20h9"/><path d="M16.5 3.5a2.1 2.1 0 0 1 3 3L7 19l-4 1 1-4Z"/></svg></button>
                    <button type="button" class="pp-btn pp-btn-ghost" wire:click="confirmDelete({{ $resultId }})" aria-label="{{ __('proposals.framework.delete_item', ['item' => $node['statement']]) }}"><svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M3 6h18M8 6V4h8v2M19 6l-1 14H6L5 6"/></svg></button>
                </span>
            @endif
            <svg @class(['pp-chev', 'pp-chev-up' => $isOpen]) width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
        </div>

        @if ($isOpen)
            <div class="pp-node-b" data-test="node-body-{{ $resultId }}">
                @include('components.proposals._framework-blocks', ['only' => null])

                @if ($canEdit && $childType !== null)
                    <div class="pp-node-foot">
                        <button type="button" class="pp-btn pp-btn-sm" wire:click="openCreateModal({{ $resultId }})" data-test="add-child-result-{{ $resultId }}">+ {{ __('proposals.framework.add_child_'.$childType) }}</button>
                    </div>
                @endif
            </div>
        @endif

        @if ($kids !== [])
            <div class="pp-kids">
                @foreach ($kids as $kidId)
                    @include('components.proposals._framework-node', ['resultId' => $kidId])
                @endforeach
            </div>
        @endif
    </div>
@endif
