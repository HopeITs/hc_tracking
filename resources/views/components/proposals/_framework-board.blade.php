{{--
    The Activity board: progress, the activities that still need an output,
    then the outputs grouped under their outcome, each folding open to its
    activities. Rows link by a button (keyboard and touch) or by drag and drop.
--}}
@php
    $activities = $fw['activities'];
    $outputIds = array_values(array_filter(array_keys($fw['results']), fn (int $id): bool => $fw['results'][$id]['type'] === \App\Enums\ResultType::Output));
    $isLoose = fn (array $activity): bool => $activity['parent'] === null || ! in_array($activity['parent'], $outputIds, true);
    $unfinished = fn (array $activity): bool => $isLoose($activity) || $activity['indicators'] === [] || collect($activity['indicators'])->contains('target', null);
    $query = mb_strtolower(trim($search));
    $shows = fn (array $activity): bool => (! $boardUnfinished || $unfinished($activity)) && ($query === '' || str_contains(mb_strtolower($activity['name']), $query));
    $loose = array_values(array_filter($activities, $isLoose));
    $linkedCount = count($activities) - count($loose);
    $allCounts = collect($activities)->flatMap(fn (array $activity): array => $activity['indicators']);
    $setCount = $allCounts->whereNotNull('target')->count();
    $pct = fn (int $part, int $whole): int => $whole === 0 ? 0 : (int) round($part / $whole * 100);
    $outcomeIds = array_values(array_filter(array_keys($fw['results']), fn (int $id): bool => $fw['results'][$id]['type'] === \App\Enums\ResultType::Outcome));
    $orphanOutputs = array_values(array_filter($outputIds, fn (int $id): bool => ! in_array($fw['results'][$id]['parent'], $outcomeIds, true)));
@endphp

<div class="pp-board" data-test="framework-board"
    x-data="{ dragging: null }"
    x-on:dragend.window="dragging = null; document.querySelectorAll('.pp-over').forEach(el => el.classList.remove('pp-over'))">
    <div class="pp-board-top">
        <span class="pp-bstat"><span @class(['pp-bar', 'pp-bar-full' => $linkedCount === count($activities)])><i style="width: {{ $pct($linkedCount, count($activities)) }}%"></i></span><b>{{ __('proposals.board.n_of', ['count' => $linkedCount, 'total' => count($activities)]) }}</b> {{ __('proposals.board.linked_to_output') }}</span>
        <span class="pp-bstat"><span @class(['pp-bar', 'pp-bar-full' => $setCount === $allCounts->count()])><i style="width: {{ $pct($setCount, $allCounts->count()) }}%"></i></span><b>{{ __('proposals.board.n_of', ['count' => $setCount, 'total' => $allCounts->count()]) }}</b> {{ __('proposals.board.targets_set') }}</span>
        <label class="pp-toggle"><input type="checkbox" wire:model.live="boardUnfinished"> {{ __('proposals.board.only_unfinished') }}</label>
    </div>

    <section @class(['pp-needs', 'pp-needs-clear' => $loose === []]) data-drop="0"
        x-on:dragover.prevent="$el.classList.add('pp-over')" x-on:dragleave="$el.classList.remove('pp-over')"
        x-on:drop.prevent="$el.classList.remove('pp-over'); dragging && $wire.linkActivity(dragging, 0)" data-test="board-loose">
        <div class="pp-needs-h">
            <b>{{ $loose === [] ? __('proposals.board.all_linked') : trans_choice('proposals.board.need_output', count($loose), ['count' => count($loose)]) }}</b>
            @if ($loose !== [])<span class="pp-small">{{ __('proposals.board.need_output_help') }}</span>@endif
        </div>
        @foreach ($loose as $activity)
            @if ($shows($activity))
                @include('components.proposals._framework-activity-row', ['activity' => $activity, 'loose' => true])
            @endif
        @endforeach
    </section>

    <div class="pp-olist">
        @foreach ([...array_map(fn (int $id): array => ['outcome' => $id, 'outputs' => $fw['children'][$id] ?? []], $outcomeIds), ['outcome' => null, 'outputs' => $orphanOutputs]] as $group)
            @php
                $groupOutputs = array_values(array_filter($group['outputs'], fn (int $id): bool => in_array($id, $outputIds, true)));
            @endphp
            @continue($groupOutputs === [])
            <div class="pp-ogroup">
                @if ($group['outcome'] !== null)
                    <div class="pp-og-h"><span class="pp-lvl pp-lvl-outcome">{{ __('proposals.board.outcome') }}</span><span dir="auto">{{ $fw['results'][$group['outcome']]['statement'] }}</span></div>
                @endif
                @foreach ($groupOutputs as $outputId)
                    @php
                        $output = $fw['results'][$outputId];
                        $list = array_values(array_filter($activities, fn (array $activity): bool => $activity['parent'] === $outputId));
                        $shown = array_values(array_filter($list, $shows));
                        $isOpenRow = in_array($outputId, $openOutputs, true);
                        $missing = count(array_filter($list, $unfinished));
                    @endphp
                    <section @class(['pp-orow', 'pp-orow-open' => $isOpenRow]) id="bc-{{ $outputId }}" wire:key="orow-{{ $outputId }}"
                        x-on:dragover.prevent="$el.classList.add('pp-over')" x-on:dragleave="if (! $el.contains($event.relatedTarget)) $el.classList.remove('pp-over')"
                        x-on:drop.prevent="$el.classList.remove('pp-over'); dragging && $wire.linkActivity(dragging, {{ $outputId }})" data-test="board-output-{{ $outputId }}">
                        <button type="button" class="pp-orow-h" wire:click="toggleOutput({{ $outputId }})" aria-expanded="{{ $isOpenRow ? 'true' : 'false' }}">
                            <span class="pp-lvl pp-lvl-output">{{ __('proposals.board.output') }}</span>
                            <span class="pp-orow-t" dir="auto">{{ $output['statement'] }}</span>
                            <span class="pp-bcount">{{ count($list) }}</span>
                            @if ($missing > 0)<span class="pp-mini pp-mini-red">{{ trans_choice('proposals.board.n_unfinished', $missing, ['count' => $missing]) }}</span>@endif
                            <svg @class(['pp-chev', 'pp-chev-up' => $isOpenRow]) width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                        </button>
                        @if ($isOpenRow)
                            <div class="pp-orow-b">
                                @forelse ($shown as $activity)
                                    @include('components.proposals._framework-activity-row', ['activity' => $activity, 'loose' => false])
                                @empty
                                    <div class="pp-bdrop">{{ $list === [] ? __('proposals.board.no_activity_yet') : __('proposals.board.hidden_by_filter') }}</div>
                                @endforelse
                                @if ($canCreate)
                                    <button type="button" class="pp-bcol-add" wire:click="openCreateModal({{ $outputId }})" data-test="board-add-{{ $outputId }}">+ {{ __('proposals.board.add_activity') }}</button>
                                @endif
                            </div>
                        @endif
                    </section>
                @endforeach
            </div>
        @endforeach
    </div>
</div>
