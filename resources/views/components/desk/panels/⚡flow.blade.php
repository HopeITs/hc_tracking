<?php

use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Livewire\Concerns\Desk\ReadsDeskFlow;
use Livewire\Component;

/**
 * Project flow: the seven stages from project idea to closing as one
 * proportional thread (segment length = project count, hover lists the top
 * projects), each with how many live projects stand on it, how
 * many of those are off track and how many of those the viewer owns
 * (ReadsDeskFlow). Each stage opens the projects register, which has no
 * stage filter. Nothing to filter, so no funnel.
 */
new class extends Component {
    use IsDeskPanel;
    use ReadsDeskFlow;
};
?>

@php
    $flow = $this->flow;
    $words = __('desk.stages');
    $lastStage = count($flow['stages']) - 1;
@endphp

<x-desk.panel id="flow" class="dk-flow-panel" :filterable="false" register="projects.index" :size="$size" :cols="$gridCols" :height="$gridRows" :index="$index" :total="$total" :count="$flow['total']" :health="$flow['late'] > 0 ? 'warn' : null">
    @if ($flow['total'] > 0)
        <p class="dk-flow-head">
            <strong>{{ trans_choice('desk-cards.flow.headline', $flow['total'], ['count' => $flow['total']]) }}</strong>
            @if ($flow['mine'] > 0)
                <span class="dk-flow-you">{{ trans_choice('desk-cards.flow.need_you', $flow['mine'], ['count' => $flow['mine']]) }}</span>
            @elseif ($flow['late'] > 0)
                <span class="dk-flow-you">{{ trans_choice('desk-cards.flow.late', $flow['late'], ['count' => $flow['late']]) }}</span>
            @else
                <span class="dk-flow-ok">{{ __('desk-cards.flow.all_on_track') }}</span>
            @endif
        </p>
        {{-- One thread: each stage's segment grows with its project count (empty stages keep a short stub), dots mark the boundaries. --}}
        <ol class="dk-flow">
            @foreach ($flow['stages'] as $i => $step)
                <li wire:key="flow-{{ $step['stage'] }}" @class(['dk-flow-step', 'dk-flow-empty' => $step['count'] === 0, 'dk-flow-hot' => $step['late'] > 0, 'dk-flow-end' => $i >= $lastStage - 1]) style="--dk-flow-grow:{{ $step['count'] }}">
                    <a href="{{ route('projects.index') }}" wire:navigate aria-label="{{ __('desk-cards.flow.stage_link', ['stage' => $words[$step['stage']], 'count' => $step['count']]) }}">
                        <b class="dk-flow-n dk-num" x-data="countUp({{ $step['count'] }})" x-text="display">{{ $step['count'] }}</b>
                        <span class="dk-flow-dot" aria-hidden="true"><i></i></span>
                        <span class="dk-flow-w" title="{{ $words[$step['stage']] }}">{{ $words[$step['stage']] }}</span>
                        @if ($step['late'] > 0)
                            <span class="dk-flow-late" aria-hidden="true">
                                @for ($d = 0; $d < min($step['late'], 5); $d++)<i></i>@endfor
                            </span>
                            <span class="sr-only">{{ trans_choice('desk-cards.flow.late', $step['late'], ['count' => $step['late']]) }}</span>
                        @endif
                    </a>
                    @if ($step['top'] !== [])
                        <div class="dk-flow-tip" aria-hidden="true">
                            <p class="dk-flow-tip-h">{{ $words[$step['stage']] }}</p>
                            <ul>
                                @foreach ($step['top'] as $project)
                                    <li @class(['dk-bad' => $project['late']])><i></i><span>{{ $project['name'] }}</span>@if ($project['late'])<em>{{ __('desk-cards.flow_tip.late') }}</em>@endif</li>
                                @endforeach
                            </ul>
                            @if ($step['count'] > count($step['top']))
                                <p class="dk-is">{{ trans_choice('desk-cards.flow_tip.more', $step['count'] - count($step['top']), ['count' => $step['count'] - count($step['top'])]) }}</p>
                            @endif
                        </div>
                    @endif
                </li>
            @endforeach
        </ol>
    @else
        <p class="dk-pempty">{{ __('desk-cards.flow.empty') }}</p>
    @endif
</x-desk.panel>
