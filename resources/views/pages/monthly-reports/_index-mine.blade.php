{{-- "My reports" on ⚡index.blade.php: every generated month of the user's projects, oldest first, on one thread per project. --}}
@if ($this->myMonths === [])
    <x-ui.empty-state icon="calendar-days" :heading="__('monthly_reports.empty_mine')" :body="__('monthly_reports.empty_mine_body')" />
@else
    <div class="flex flex-col gap-10">
        @foreach ($this->myMonths as $group)
            @php
                $groupProject = $group['project'];
            @endphp
            <section class="flex flex-col gap-3" aria-labelledby="project-months-{{ $groupProject->id }}" wire:key="project-months-{{ $groupProject->id }}">
                <h2 id="project-months-{{ $groupProject->id }}" class="text-lg font-bold" dir="auto">
                    @can('projects.view')
                        <a href="{{ route('projects.show', $groupProject) }}" wire:navigate class="hover:underline">{{ $groupProject->name }}</a>
                    @else
                        {{ $groupProject->name }}
                    @endcan
                </h2>

                <ol class="relative flex flex-col before:absolute before:inset-y-3 before:start-[5px] before:w-px before:bg-secondary-soft dark:before:bg-line" role="list">
                    @foreach ($group['months'] as $row)
                        @php
                            $rowReport = $row['report'];
                        @endphp
                        <li
                            class="relative flex flex-wrap items-center gap-x-4 gap-y-2 py-3 ps-7"
                            data-month="{{ $row['key'] }}" data-month-state="{{ $row['state'] }}"
                            wire:key="month-{{ $rowReport->id }}"
                        >
                            <span @class([
                                'absolute start-0 top-1/2 size-[11px] -translate-y-1/2 rounded-full ring-4 ring-white dark:ring-surface-0',
                                'bg-status-good' => $row['state'] === 'validated',
                                'bg-secondary-dark dark:bg-ink' => $row['state'] === 'submitted',
                                'bg-status-warn' => in_array($row['state'], ['returned', 'reopen_pending'], true),
                                'bg-primary' => $row['overdue'],
                                'border-2 border-secondary-light bg-white dark:border-line-strong dark:bg-surface-0' => ! $row['overdue'] && in_array($row['state'], ['draft', 'upcoming'], true),
                            ]) aria-hidden="true"></span>

                            <div class="min-w-36 flex-1">
                                <p class="flex flex-wrap items-center gap-2">
                                    <span class="text-base font-bold">{{ $rowReport->period->translatedFormat('F Y') }}</span>
                                    <x-monthly-reports.state-chip :state="$row['state']" />
                                    @if ($row['overdue'])
                                        <span class="inline-flex items-center gap-1 rounded-md border border-primary bg-status-critical-soft px-1.5 py-0.5 text-xs font-semibold text-status-critical" data-overdue="{{ $row['key'] }}">
                                            <flux:icon.exclamation-triangle variant="micro" class="size-3.5" aria-hidden="true" />
                                            {{ __('monthly_reports.overdue') }}
                                        </span>
                                    @endif
                                </p>
                                <p class="mt-0.5 flex flex-wrap gap-x-3 type-meta text-secondary-medium dark:text-ink-muted">
                                    @if ($row['due'] !== null)
                                        <span class="tabular-nums">{{ __('monthly_reports.due_on', ['date' => $row['due']->translatedFormat(__('formats.date'))]) }}</span>
                                    @else
                                        <span>{{ __('monthly_reports.no_indicators_this_month') }}</span>
                                    @endif
                                    @if ($row['blocked'] !== null)
                                        <span class="inline-flex items-center gap-1" data-blocked="{{ $row['key'] }}">
                                            <flux:icon.lock-closed variant="micro" class="size-3" aria-hidden="true" />
                                            {{ $row['blocked'] }}
                                        </span>
                                    @endif
                                </p>
                            </div>

                            <div class="flex shrink-0 items-center gap-2">
                                <x-ui.button tone="ghost" size="sm" icon="eye" :href="route('monthly-reports.show', $rowReport)" wire:navigate :aria-label="__('common.view_name', ['name' => $rowReport->period->translatedFormat('F Y')])" />
                                @if ($row['can_fill'])
                                    <x-ui.button
                                        size="sm"
                                        tone="primary"
                                        icon="pencil-square"
                                        wire:click="fillMonth({{ $rowReport->id }})"
                                        wire:loading.attr="disabled"
                                        wire:target="fillMonth({{ $rowReport->id }})"
                                        data-fill="{{ $rowReport->id }}"
                                    >{{ __('monthly_reports.fill') }}</x-ui.button>
                                @endif
                            </div>
                        </li>
                    @endforeach
                </ol>
            </section>
        @endforeach
    </div>
@endif
