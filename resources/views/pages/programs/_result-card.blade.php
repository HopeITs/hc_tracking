@use('App\Enums\ResultType')
{{--
    One result card on the program's Results tab, from a card array built by
    ManagesProgramResults::resultCards() (never a Result to query). The head
    shows the level, the statement, its counts and average achievement; it
    folds open to list the indicators a page at a time (each opening to its
    per-center targets), an activity's delivery, the results under it and the
    proposals contributing to it. Expects $card and $rootIndex (null when
    nested); keeps data-tree-depth / data-result-type for the tests.
--}}
@php
    $node = $card['node'];
    $result = $node['result'];
    $isFlat = $this->resultLevel !== '';
    $indicatorLimit = $this->resultIndicatorLimit($result->id);
    $shownRows = array_slice($card['indicators'], 0, $indicatorLimit);
    $rowsLeft = count($card['indicators']) - count($shownRows);
    $children = $card['children'];
    $contributions = $card['contributions'];
    $startsOpen = $board['narrowed'] || ($rootIndex !== null && ($card['subtreeIssues'] > 0 || $rootIndex === 0));
    $panelId = 'result-card-body-'.$result->id;
    $lineage = $isFlat ? $card['path'] : array_map(fn ($ancestor): string => $ancestor->statement, $node['lineage']);
    $figure = fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ','), '0'), '.');
    $targetTones = ['achieved' => 'dk-good', 'in_progress' => 'dk-active'];
    $progressTone = match (true) {
        $card['progress'] === null => '',
        $card['progress'] >= 100 => 'dk-good',
        default => 'dk-active',
    };
@endphp

<article wire:key="result-card-{{ $result->id }}-{{ $isFlat ? 'flat' : 'tree' }}"
    class="dk-prs-card dk-prs-{{ $result->type->value }}" :class="{ 'dk-prs-card-on': open }"
    data-tree-depth="{{ $card['depth'] }}" data-result-type="{{ $result->type->value }}" data-result-card="{{ $result->id }}"
    x-data="{ open: @js($startsOpen) }" x-on:results-fold.window="open = $event.detail">

    {{-- 1. The head: click anywhere but a link to fold. --}}
    <div class="dk-prs-head" x-on:click="if (! $event.target.closest('a, button')) open = ! open">
        <div class="dk-prs-main">
            <p class="dk-prs-kicker">
                <span class="dk-prs-level"><span class="dk-prs-dot ring-2 ring-inset {{ $levelMarks[$result->type->value] }}" aria-hidden="true"></span>{{ $result->type->label() }}</span>
                @if ($node['proposal'] !== null)
                    <span class="dk-prs-proposal" data-chain-proposal>
                        <x-desk.icon name="file" />
                        @if ($node['proposal']['url'] !== null)
                            <a href="{{ $node['proposal']['url'] }}" wire:navigate dir="auto">{{ $node['proposal']['name'] }}</a>
                        @else
                            <span dir="auto">{{ $node['proposal']['name'] }}</span>
                        @endif
                        @if ($node['proposal']['donor'] !== null)
                            <span aria-hidden="true">·</span>
                            @if ($node['proposal']['donorUrl'] !== null)
                                <a href="{{ $node['proposal']['donorUrl'] }}" wire:navigate dir="auto">{{ $node['proposal']['donor'] }}</a>
                            @else
                                <span dir="auto">{{ $node['proposal']['donor'] }}</span>
                            @endif
                        @endif
                    </span>
                @endif
                @foreach ($node['gaps'] as $gap)
                    <span class="dk-prog-pill dk-warn" data-chain-gap="{{ $gap }}">{{ __('results_framework.chain_gap_'.$gap) }}</span>
                @endforeach
            </p>

            @if ($lineage !== [])
                <p class="dk-prs-lineage" data-chain-lineage>
                    <span>{{ __('results_framework.chain_lineage') }}</span>
                    @foreach ($lineage as $statement)
                        <span class="dk-prs-lineage-s" dir="auto" title="{{ $statement }}">{{ str($statement)->limit(60) }}</span>
                        @unless ($loop->last)<x-desk.icon name="chev" class="dk-flip" />@endunless
                    @endforeach
                </p>
            @endif

            <p class="dk-prs-stmt" :class="{ 'dk-prs-clamp': ! open }" dir="auto">{{ $result->statement }}</p>

            <p class="dk-prs-facts">
                @if ($card['indicatorTotal'] > 0)
                    <span><x-desk.icon name="chart" />{{ trans_choice('programs.results_indicator_count', $card['indicatorTotal'], ['count' => $card['indicatorTotal']]) }}</span>
                @endif
                @if ($children !== [])
                    <span>{{ trans_choice('programs.logframe_count_contributing', count($children), ['count' => count($children)]) }}</span>
                @endif
                @if ($contributions !== [])
                    <span>{{ trans_choice('results_framework.chain_contributions', count($contributions), ['count' => count($contributions)]) }}</span>
                @endif
                @if ($card['subtreeIssues'] > 0)
                    <span class="dk-prog-pill dk-warn" data-result-issues="{{ $card['subtreeIssues'] }}">{{ trans_choice('programs.results_issue_count', $card['subtreeIssues'], ['count' => $card['subtreeIssues']]) }}</span>
                @endif
            </p>
        </div>

        <div class="dk-prs-side">
            <div class="dk-prs-prog {{ $progressTone }}">
                <p>
                    <span>{{ __('programs.results_avg_progress') }}</span>
                    <b class="dk-num">{{ $card['progress'] === null ? '—' : $card['progress'].'%' }}</b>
                </p>
                <span class="dk-prog-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('programs.results_avg_progress') }}" @if ($card['progress'] !== null) aria-valuenow="{{ $card['progress'] }}" @endif><i style="width: {{ min(100, $card['progress'] ?? 0) }}%"></i></span>
            </div>
            <button type="button" class="dk-prs-toggle" x-on:click="open = ! open" :aria-expanded="open.toString()" aria-controls="{{ $panelId }}" aria-label="{{ __('programs.results_toggle') }}">
                <x-desk.icon name="chev" class="dk-prs-chev" />
            </button>
        </div>
    </div>

    {{-- 2. The body: indicators a page at a time, delivery, then what sits under it. --}}
    <div id="{{ $panelId }}" class="dk-prs-body" x-show="open" x-collapse @unless ($startsOpen) x-cloak @endunless>
        @if ($shownRows !== [])
            <ul class="dk-prs-inds" aria-label="{{ __('results_framework.chain_indicators') }}">
                @foreach ($shownRows as $row)
                    @php
                        $targets = $row['targets'];
                        $rowTone = match ($row['state']) {
                            'achieved' => 'dk-good',
                            'in_progress' => 'dk-active',
                            default => '',
                        };
                    @endphp
                    <li class="dk-prs-ind" wire:key="result-{{ $result->id }}-indicator-{{ $row['indicator']->id }}" data-chain-indicator="{{ $row['indicator']->id }}" data-indicator-state="{{ $row['state'] }}" x-data="{ targets: false }">
                        <div class="dk-prs-ind-h" @if ($targets !== []) role="button" tabindex="0" x-on:click="targets = ! targets" x-on:keydown.enter.prevent="targets = ! targets" x-on:keydown.space.prevent="targets = ! targets" :aria-expanded="targets.toString()" aria-controls="result-targets-{{ $result->id }}-{{ $row['indicator']->id }}" @endif>
                            <span class="dk-prs-ind-n">
                                @if ($row['code'] !== null)
                                    <bdi dir="ltr" class="dk-prs-code">{{ $row['code'] }}</bdi>
                                @endif
                                <span class="dk-prs-ind-name" dir="auto" title="{{ $row['name'] }}">{{ $row['name'] }}</span>
                                @if (! $row['linked'])
                                    <span class="dk-prs-flag" title="{{ __('targets.not_linked_to_library') }}"><x-desk.icon name="info" /><span class="sr-only">{{ __('targets.not_linked_to_library') }}</span></span>
                                @elseif ($row['inactive'])
                                    <span class="dk-prog-pill">{{ __('results_framework.chain_entry_inactive') }}</span>
                                @endif
                            </span>
                            <span class="dk-prs-ind-c">{{ trans_choice('programs.results_center_count', $row['centers'], ['count' => $row['centers']]) }}</span>
                            <span class="dk-prs-ind-p {{ $rowTone }}">
                                <span class="dk-prog-bar" aria-hidden="true"><i style="width: {{ min(100, $row['progress'] ?? 0) }}%"></i></span>
                                <b class="dk-num">{{ $row['progress'] === null ? '—' : $row['progress'].'%' }}</b>
                            </span>
                            <span class="dk-prs-ind-s"><span class="dk-prog-pill {{ $stateTones[$row['state']] }}">{{ __('programs.results_filter_'.$row['state']) }}</span></span>
                            <span class="dk-prs-ind-x" aria-hidden="true">
                                @if ($targets !== [])
                                    <x-desk.icon name="chev" class="dk-prs-chev" ::class="{ 'dk-prs-chev-on': targets }" />
                                @endif
                            </span>
                        </div>

                        @if ($targets !== [])
                            <div id="result-targets-{{ $result->id }}-{{ $row['indicator']->id }}" x-show="targets" x-collapse x-cloak>
                                <ul class="dk-prs-targets" aria-label="{{ __('results_framework.chain_per_center') }}">
                                    @foreach ($targets as $target)
                                        <li data-chain-target="{{ $target['id'] }}" data-percent="{{ $target['percent'] === null ? '' : (int) round($target['percent']) }}" wire:key="result-target-{{ $result->id }}-{{ $target['id'] }}">
                                            <div class="dk-prs-target-who">
                                                <p dir="auto">{{ $target['center'] }}</p>
                                                @if ($target['source'] !== null)
                                                    <span dir="auto">{{ $target['source'] }}</span>
                                                @endif
                                                @foreach ($target['issues'] as $issue)
                                                    <span class="dk-prs-issue" data-chain-issue><x-desk.icon name="info" />{{ __($issue) }}</span>
                                                @endforeach
                                            </div>
                                            <div class="dk-prs-prog {{ $targetTones[$target['status']] ?? '' }}">
                                                <p>
                                                    <span dir="ltr" class="dk-num"><strong>{{ $figure($target['achieved']) }}</strong> / {{ $figure($target['target']) }}</span>
                                                    <b class="dk-num" dir="ltr">{{ $target['percent'] === null ? '—' : (int) round($target['percent']).'%' }}</b>
                                                </p>
                                                <span class="dk-prog-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('results_framework.chain_achievement_for', ['center' => $target['center']]) }}" @if ($target['percent'] !== null) aria-valuenow="{{ min(100, (int) round($target['percent'])) }}" @endif><i style="width: {{ min(100, (int) round($target['percent'] ?? 0)) }}%"></i></span>
                                            </div>
                                        </li>
                                    @endforeach
                                </ul>
                                @if ($canOpenTargets)
                                    <a href="{{ app(\App\Notifications\SubjectLinks::class)->frameworkRoute($row['indicator']) ?? route('results.index') }}" wire:navigate class="dk-prs-open">{{ __('programs.results_open_targets') }}<x-desk.icon name="arrow" class="dk-flip" /></a>
                                @endif
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>

            @if ($rowsLeft > 0 || $indicatorLimit > $this::RESULT_INDICATOR_PAGE)
                <div class="dk-prog-more-row dk-prs-more">
                    <span class="dk-prs-help dk-num">{{ __('programs.results_shown_of', ['shown' => count($shownRows), 'total' => count($card['indicators'])]) }}</span>
                    @if ($rowsLeft > 0)
                        <button type="button" class="dk-prog-more" wire:click="showMoreResultIndicators({{ $result->id }})" wire:loading.attr="disabled" wire:target="showMoreResultIndicators({{ $result->id }})" data-test="result-indicators-more">
                            {{ __('programs.results_load_more', ['count' => min($rowsLeft, $this::RESULT_INDICATOR_STEP)]) }}
                        </button>
                    @endif
                    @if ($indicatorLimit > $this::RESULT_INDICATOR_PAGE)
                        <button type="button" class="dk-prog-more" wire:click="showFewerResultIndicators({{ $result->id }})">{{ __('programs.results_show_fewer') }}</button>
                    @endif
                </div>
            @endif
        @endif

        @if ($result->type === ResultType::Activity)
            <div class="dk-prs-delivery">
                <x-ui.logframe-activity :activity-id="$result->id" :schedule="$node['schedule']" :statuses="$node['activityStatuses'] ?? []" />
            </div>
        @endif

        @if ($children !== [])
            <div class="dk-prs-kids">
                @foreach ($children as $child)
                    @include('pages.programs._result-card', ['card' => $child, 'rootIndex' => null])
                @endforeach
            </div>
        @endif

        @if ($contributions !== [])
            <div class="dk-prs-contrib" x-data="{ contributions: @js($board['narrowed']) }" data-chain-contributions>
                <button type="button" class="dk-prs-contrib-t" x-on:click="contributions = ! contributions" :aria-expanded="contributions.toString()" aria-controls="result-contributions-{{ $result->id }}">
                    <x-desk.icon name="file" />{{ trans_choice('results_framework.chain_contributions', count($contributions), ['count' => count($contributions)]) }}
                    <x-desk.icon name="chev" class="dk-prs-chev" ::class="{ 'dk-prs-chev-on': contributions }" />
                </button>
                <div id="result-contributions-{{ $result->id }}" class="dk-prs-kids" x-show="contributions" x-collapse @unless ($board['narrowed']) x-cloak @endunless>
                    @foreach ($contributions as $child)
                        @include('pages.programs._result-card', ['card' => $child, 'rootIndex' => null])
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</article>
