{{--
    The Deadlines tab of proposals/show: everything still owed, grouped
    overdue, soon and later. Included by ⚡show.blade.php, whose top PHP
    block defines $mutedText, $inkText, $hairline, $amount, $term,
    $chronology and $arrivals.
--}}
            @if ($this->deadlines === [])
                <x-ui.empty-state icon="calendar-days" :heading="__('proposals.nothing_is_due')" :body="__('proposals.tranches_reports_tasks_and_end_dates_still_open_will_appear_here')" />
            @else
                @php
                    $deadlineGroups = collect($this->deadlines)->groupBy('state');
                    $kindLabel = [
                        'transfer' => __('proposals.deadline_kind_transfer'),
                        'donor-report' => __('proposals.deadline_kind_donor_report'),
                        'report' => __('proposals.deadline_kind_report'),
                        'task' => __('proposals.deadline_kind_task'),
                        'project' => __('proposals.deadline_kind_project'),
                        'grant' => __('proposals.deadline_kind_grant'),
                    ];
                    $kindIcon = ['transfer' => 'banknotes', 'donor-report' => 'paper-airplane', 'report' => 'document-chart-bar', 'task' => 'clipboard-document-check', 'project' => 'rectangle-stack', 'grant' => 'flag'];
                @endphp
                <div class="flex flex-col gap-8" data-deadlines>
                    @foreach (['overdue', 'soon', 'later'] as $state)
                        @continue(! $deadlineGroups->has($state))
                        <section class="min-w-0" aria-labelledby="deadlines-{{ $state }}" wire:key="deadline-group-{{ $state }}" data-deadline-group="{{ $state }}">
                            <h2 id="deadlines-{{ $state }}" @class(['type-label', 'text-status-critical' => $state === 'overdue', $mutedText => $state !== 'overdue'])>
                                {{ match ($state) { 'overdue' => __('proposals.deadline_group_overdue'), 'soon' => __('proposals.deadline_group_soon', ['days' => 30]), default => __('proposals.deadline_group_later') } }} · {{ $deadlineGroups[$state]->count() }}
                            </h2>
                            <ol class="mt-3 flex flex-col">
                                @foreach ($deadlineGroups[$state] as $item)
                                    <li class="relative flex min-w-0 gap-3 pb-4 last:pb-0" wire:key="deadline-{{ $item['key'] }}" data-deadline="{{ $item['key'] }}">
                                        <span class="relative flex w-3 shrink-0 justify-center" aria-hidden="true">
                                            @unless ($loop->last)
                                                <span class="absolute top-3 bottom-[-1rem] w-px bg-secondary-soft dark:bg-line-strong"></span>
                                            @endunless
                                            <span @class([
                                                'relative z-10 mt-1.5 size-2.5 rounded-full',
                                                'bg-primary dark:bg-status-critical' => $state === 'overdue',
                                                'border-2 border-status-warn bg-white dark:bg-surface-1' => $state === 'soon',
                                                'border-2 border-secondary-light bg-white dark:bg-surface-1' => $state === 'later',
                                            ])></span>
                                        </span>
                                        <div class="flex min-w-0 flex-1 flex-col gap-1 @xl/detail:flex-row @xl/detail:items-baseline @xl/detail:gap-4">
                                            <time datetime="{{ $item['date']->toDateString() }}" class="shrink-0 text-sm font-bold tabular-nums @xl/detail:w-28 {{ $inkText }}">{{ $item['date']->translatedFormat(__('formats.date')) }}</time>
                                            <div class="min-w-0 flex-1">
                                                <p class="flex min-w-0 items-center gap-1.5 text-sm">
                                                    <flux:icon :name="$kindIcon[$item['kind']]" variant="micro" class="size-4 shrink-0 {{ $mutedText }}" aria-hidden="true" />
                                                    @if ($item['url'] !== null)
                                                        <a href="{{ $item['url'] }}" wire:navigate class="truncate font-semibold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary {{ $inkText }}">{{ $item['title'] }}</a>
                                                    @else
                                                        <span class="truncate font-semibold {{ $inkText }}">{{ $item['title'] }}</span>
                                                    @endif
                                                </p>
                                                <p class="mt-0.5 truncate text-xs {{ $mutedText }}">{{ $kindLabel[$item['kind']] }} · {{ $item['detail'] }}</p>
                                            </div>
                                            <span @class(['shrink-0 text-xs font-semibold tabular-nums', 'text-status-critical' => $state === 'overdue', 'text-status-warn' => $state === 'soon', $mutedText => $state === 'later'])>
                                                @if ($item['days'] < 0)
                                                    {{ trans_choice('proposals.count_days_late', -$item['days'], ['count' => -$item['days']]) }}
                                                @elseif ($item['days'] === 0)
                                                    {{ __('proposals.due_today') }}
                                                @else
                                                    {{ trans_choice('proposals.due_in_count_days', $item['days'], ['count' => $item['days']]) }}
                                                @endif
                                            </span>
                                        </div>
                                    </li>
                                @endforeach
                            </ol>
                        </section>
                    @endforeach
                </div>
            @endif
