{{-- One activity on the board: a compact row, or its in-place editor. Expects $activity and $loose. --}}
@php
    $when = match ($activity['mode']) {
        'dates' => $activity['starts']?->translatedFormat(__('formats.date_short')).' – '.$activity['ends']?->translatedFormat(__('formats.date_short')),
        'event' => __('proposals.board.mode_event'),
        default => __('proposals.board.mode_continuous'),
    };
    $counts = $activity['indicators'];
    $missingTargets = count(array_filter($counts, fn (array $count): bool => $count['target'] === null));
    $num = fn (?float $value): string => $value === null ? '?' : rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
@endphp

@if ($editingActivityId === $activity['id'])
    @include('components.proposals._framework-activity-editor')
@else
    <div class="pp-arow" id="ac-{{ $activity['id'] }}" wire:key="arow-{{ $activity['id'] }}"
        @if ($canEdit) draggable="true" x-on:dragstart="dragging = {{ $activity['id'] }}; $event.dataTransfer.effectAllowed = 'move'" @endif
        data-test="board-activity-{{ $activity['id'] }}">
        <button type="button" class="pp-ar-main" @if ($canEdit) wire:click="editActivity({{ $activity['id'] }})" @endif>
            <b dir="auto">{{ $activity['name'] }}</b>
            <span class="pp-ar-sub">
                <span class="pp-chip">{{ $when }}</span>
                <span @class(['pp-spill', 'pp-st-'.$activity['status']])>{{ __('proposals.framework.status_'.$activity['status']) }}</span>
                @if ($counts !== [])
                    <span class="pp-small" dir="auto">{{ collect($counts)->map(fn (array $count): string => $count['name'].' · '.$num($count['target']))->implode('  |  ') }}</span>
                @endif
            </span>
        </button>
        <span class="pp-ar-flags">
            @if ($counts === [])
                <span class="pp-mini pp-mini-red">{{ __('proposals.board.nothing_counted') }}</span>
            @elseif ($missingTargets > 0)
                <span class="pp-mini pp-mini-red">{{ trans_choice('proposals.board.targets_missing', $missingTargets, ['count' => $missingTargets]) }}</span>
            @else
                <span class="pp-mini">{{ __('proposals.board.targets_ok') }}</span>
            @endif
        </span>
        @if ($canEdit)
            <span>
                <button type="button" @class(['pp-btn pp-btn-sm', 'pp-btn-primary' => $loose]) wire:click="pickFor({{ $activity['id'] }})" aria-expanded="{{ $pickingId === $activity['id'] ? 'true' : 'false' }}" data-test="board-pick-{{ $activity['id'] }}">{{ $loose ? __('proposals.board.link_to_output') : __('proposals.board.move') }}</button>
            </span>
        @endif
        @if ($pickingId === $activity['id'])
            <div class="pp-lpop" role="group" aria-label="{{ __('proposals.board.which_output') }}" data-test="board-chooser">
                <h4>{{ __('proposals.board.which_output') }}</h4>
                @foreach ($outcomeIds as $outcomeId)
                    @php
                        $outs = array_values(array_filter($fw['children'][$outcomeId] ?? [], fn (int $id): bool => in_array($id, $outputIds, true)));
                    @endphp
                    @continue($outs === [])
                    <div class="pp-lp-oc" dir="auto">{{ \Illuminate\Support\Str::limit($fw['results'][$outcomeId]['statement'], 70) }}</div>
                    @foreach ($outs as $outId)
                        <button type="button" @class(['pp-lp-o', 'pp-on' => $activity['parent'] === $outId]) wire:click="linkActivity({{ $activity['id'] }}, {{ $outId }})" data-test="board-link-{{ $activity['id'] }}-{{ $outId }}">
                            <span dir="auto">{{ $fw['results'][$outId]['statement'] }}</span>
                            <span class="pp-small">{{ trans_choice('proposals.framework.count_activities', count($fw['results'][$outId]['activities']), ['count' => count($fw['results'][$outId]['activities'])]) }}</span>
                        </button>
                    @endforeach
                @endforeach
                @foreach ($orphanOutputs as $outId)
                    <button type="button" @class(['pp-lp-o', 'pp-on' => $activity['parent'] === $outId]) wire:click="linkActivity({{ $activity['id'] }}, {{ $outId }})"><span dir="auto">{{ $fw['results'][$outId]['statement'] }}</span></button>
                @endforeach
                @if (! $loose)
                    <button type="button" class="pp-lp-o" wire:click="linkActivity({{ $activity['id'] }}, 0)" data-test="board-unlink-{{ $activity['id'] }}">{{ __('proposals.board.unlink') }}</button>
                @endif
                @if ($outputIds === [])
                    <span class="pp-small">{{ __('proposals.board.no_outputs') }}</span>
                @endif
            </div>
        @endif
    </div>
@endif
