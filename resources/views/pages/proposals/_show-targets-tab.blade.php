{{--
    The Targets tab of proposals/show: each committed target with what has
    been reported against it. Included by ⚡show.blade.php, whose top PHP
    block defines $mutedText, $inkText, $hairline, $amount, $term,
    $chronology and $arrivals.
--}}
            @if ($this->targets->isEmpty())
                <x-ui.empty-state icon="chart-bar" :heading="__('common.no_targets_yet')" :body="__('proposals.indicator_targets_committed_in_this_proposal_will_appear_her')" />
            @else
                <div class="flex flex-col gap-7">
                    @foreach ($this->targets as $target)
                        @php
                            $achieved = $target->achievedValue();
                            $pendingValue = $target->pendingValue();
                            $targetValue = (float) $target->target;
                            $achievedPercent = $targetValue > 0 ? min(100, round($achieved / $targetValue * 100, 2)) : 0;
                            $pendingPercent = $targetValue > 0 ? min(100 - $achievedPercent, round($pendingValue / $targetValue * 100, 2)) : 0;
                            $issues = $target->chainIssues();
                        @endphp
                        <article class="min-w-0" wire:key="target-{{ $target->id }}" data-target="{{ $target->id }}">
                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <div class="min-w-0">
                                    <p class="text-base font-bold {{ $inkText }}">{{ $target->indicator->name }}</p>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs {{ $mutedText }}">
                                        <span>{{ $target->project?->name ?? __('common.organisation_wide') }}</span>
                                        @if ($target->indicator->result !== null)
                                            <span aria-hidden="true">·</span>
                                            <span class="truncate">{{ $target->indicator->result->statement }}</span>
                                        @endif
                                    </p>
                                </div>

                                <div class="flex shrink-0 items-baseline gap-2">
                                    <x-ui.enum-badge :case="$target->period" />
                                    <span class="text-xl font-extrabold tracking-tight tabular-nums {{ $inkText }}">{{ number_format($targetValue, 2) }}</span>
                                </div>
                            </div>

                            <div class="mt-3 flex h-2 w-full gap-0.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-2" role="img" aria-label="{{ __('proposals.reported_of_target', ['reported' => number_format($achieved, 2), 'target' => number_format($targetValue, 2)]) }}">
                                <span class="money-grow h-full rounded-s-full bg-funding" style="width: {{ $achievedPercent }}%"></span>
                                <span class="money-grow money-pending h-full" style="width: {{ $pendingPercent }}%"></span>
                            </div>

                            <p class="mt-2 flex flex-wrap items-center gap-x-3 gap-y-0.5 text-xs tabular-nums {{ $mutedText }}">
                                @if ($target->reports->isEmpty())
                                    <span class="font-semibold text-status-critical">{{ __('proposals.not_reported_yet') }}</span>
                                @else
                                    <span><span class="font-bold text-funding">{{ number_format($achieved, 2) }}</span> {{ __('proposals.reported_lower') }}</span>
                                    <span>{{ trans_choice('common.count_report', $target->reports->count(), ['count' => $target->reports->count()]) }}</span>
                                @endif
                            </p>

                            @foreach ($issues as $issue)
                                <p class="mt-2 flex items-center gap-1.5 text-xs font-semibold text-status-critical" wire:key="issue-{{ $target->id }}-{{ $loop->index }}">
                                    <flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0" />
                                    {{ __($issue) }}
                                </p>
                            @endforeach
                        </article>
                    @endforeach
                </div>
            @endif
