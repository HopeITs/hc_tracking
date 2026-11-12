{{--
    Below the plans list: the CEO summary for the General Manager (one row
    per approved plan: activities done and late, units held against the plan
    so far, people reached in the latest month, status per governorate); for
    everyone else, the grid of every centre's monthly updates and where each
    one stands (the MEAL manager's view), plus the open follow-up tasks.
--}}
@php
    $executions = $this->approvedExecutions;
    $today = \Carbon\CarbonImmutable::today();
    $isCeo = auth()->user()->hasRole('General Manager') && ! auth()->user()->hasRole('Super Admin');
    $cellTone = fn (?\App\Models\PlanUpdate $update, \Carbon\CarbonImmutable $month): array => match (true) {
        $update === null || $update->status === \App\Enums\PlanUpdateStatus::Draft => $today->gt(\App\Support\PlanCalendar::dueDateOf($month)) ? ['dk-bad', __('annual_plans.up.late')] : ($today->gte($month->addMonth()) ? ['dk-warn', __('annual_plans.grid.due')] : ['', '·']),
        $update->status === \App\Enums\PlanUpdateStatus::Sent => ['dk-warn', __('annual_plans.grid.officer')],
        $update->status === \App\Enums\PlanUpdateStatus::OfficerApproved => ['dk-warn', $update->seen_at !== null ? __('annual_plans.grid.meal') : __('annual_plans.grid.meal_pm')],
        $update->status === \App\Enums\PlanUpdateStatus::Reviewed => ['dk-act', __('annual_plans.grid.pm')],
        $update->status === \App\Enums\PlanUpdateStatus::Seen => ['dk-good', '✓'],
        $update->status === \App\Enums\PlanUpdateStatus::Returned => ['dk-bad', __('annual_plans.grid.returned')],
    };
@endphp

@if ($executions !== [])
    @if ($isCeo)
        <section class="ap-card" data-ceo-summary>
            <h2>{{ __('annual_plans.grid.ceo_title') }}</h2>
            <p class="ap-sm ap-muted">{{ __('annual_plans.grid.ceo_intro') }}</p>
            <div class="ap-tw"><table class="ap-t">
                <thead><tr><th>{{ __('annual_plans.wf.col_plan') }}</th><th>{{ __('annual_plans.grid.col_done') }}</th><th>{{ __('annual_plans.grid.col_late') }}</th><th>{{ __('annual_plans.grid.col_units') }}</th><th>{{ __('annual_plans.grid.col_people') }}</th><th>{{ __('annual_plans.grid.col_governorates') }}</th></tr></thead>
                <tbody>
                    @foreach ($executions as $execution)
                        @php
                            $plan = $execution->plan; $held = 0; $planned = 0; $people = 0; $late = 0; $byCity = [];
                            foreach ($plan->items as $item) {
                                $isLate = false;
                                foreach ($execution->projects() as $centre) {
                                    if (! in_array(\App\Support\MealCoverage::cityIdOf($centre), $item->cityIds(), true)) { continue; }
                                    $held += $execution->held($centre, $item); $planned += $execution->plannedToDate($centre, $item);
                                    $key = $execution->status($centre, $item)['key'];
                                    $city = \App\Support\MealCoverage::cityIdOf($centre);
                                    $byCity[$city] = max($byCity[$city] ?? 0, match ($key) { 'late', 'cancelled' => 2, 'suspended' => 1, default => 0 });
                                    $isLate = $isLate || $key === 'late';
                                }
                                $late += $isLate ? 1 : 0;
                            }
                            foreach ($execution->projects() as $centre) {
                                $latest = $execution->updates()->filter(fn ($u) => $u->project_id === $centre->id && $u->status->isOnThePlan())->sortByDesc('month')->first();
                                $people += (int) $latest?->males + (int) $latest?->females;
                            }
                            $cityNames = \App\Support\ActivityPlanScope::cityNames(array_keys($byCity));
                        @endphp
                        <tr wire:key="ceo-{{ $plan->id }}">
                            <td><b dir="auto">{{ $plan->proposal->name }}</b><span class="ap-xs ap-faint" style="display: block">{{ $plan->program?->name }}</span></td>
                            <td class="ap-num">{{ $plan->items->filter(fn ($item) => $execution->isDoneEverywhere($item))->count() }} / {{ $plan->items->count() }}</td>
                            <td class="ap-num">{{ $late }}</td>
                            <td class="ap-num">{{ $planned > 0 ? round($held / $planned * 100).'%' : '—' }}</td>
                            <td class="ap-num">{{ $people }}</td>
                            <td>@foreach ($byCity as $city => $level)<span class="dk-pill {{ [0 => 'dk-good', 1 => 'dk-warn', 2 => 'dk-bad'][$level] }}" style="margin: 2px">{{ $cityNames[$city] ?? $city }}: {{ [__('annual_plans.live.status_on_track'), __('annual_plans.live.status_suspended'), __('annual_plans.grid.late_word')][$level] }}</span>@endforeach</td>
                        </tr>
                    @endforeach
                </tbody>
            </table></div>
        </section>
    @else
        <section class="ap-card" data-updates-grid>
            <h2>{{ __('annual_plans.grid.title') }}</h2>
            <p class="ap-sm ap-muted">{{ __('annual_plans.grid.intro') }}</p>
            @foreach ($executions as $execution)
                @php $months = $execution->calendar->months(); @endphp
                <div class="ap-grp" wire:key="grid-{{ $execution->plan->id }}">
                    <h3 dir="auto">{{ $execution->plan->proposal->name }} · {{ $execution->plan->program?->name }}</h3>
                    <div class="ap-tw" style="padding: 10px">
                        <div class="ap-grid" style="grid-template-columns: 190px repeat({{ count($months) }}, minmax(0, 1fr))">
                            <span class="is-h is-l">{{ __('annual_plans.up.col_centre') }}</span>
                            @foreach ($months as $month)<span class="is-h">{{ $month->translatedFormat('M') }}</span>@endforeach
                            @foreach ($execution->projects() as $centre)
                                <span class="is-l" dir="auto">{{ $centre->name }}</span>
                                @foreach ($months as $month)
                                    @php $update = $execution->update($centre, $month); [$tone, $text] = $cellTone($update, $month); @endphp
                                    <span>@if ($month->lte($today))<a class="dk-pill {{ $tone }}" href="{{ route('plan-updates.show', [$execution->plan, $centre, $month->format('Y-m')]) }}" wire:navigate>{{ $text }}@if ($execution->calendar->isQuarterEnd($month)) · {{ __('annual_plans.grid.quarter_short') }}@endif</a>@endif</span>
                                @endforeach
                            @endforeach
                        </div>
                    </div>
                </div>
            @endforeach
            <p class="ap-xs ap-faint">{{ __('annual_plans.grid.legend') }}</p>

            @php $tasks = $this->followUpTasks; @endphp
            @if ($tasks->isNotEmpty())
                <div class="ap-grp" data-follow-up-tasks>
                    <h3>{{ __('annual_plans.grid.tasks_title') }}</h3>
                    @foreach ($tasks as $task)
                        <a class="ap-msg" href="{{ route('tasks.show', $task) }}" wire:navigate wire:key="task-{{ $task->id }}">
                            <span class="dk-pill {{ $task->status === \App\Enums\TaskStatus::Done ? 'dk-good' : ($task->due_date?->isPast() ? 'dk-bad' : 'dk-warn') }}">{{ $task->status === \App\Enums\TaskStatus::Done ? $task->status->label() : __('annual_plans.up.due_on', ['date' => $task->due_date?->translatedFormat('j M')]) }}</span>
                            <b class="ap-sp" dir="auto">{{ $task->title }}</b>
                        </a>
                    @endforeach
                </div>
            @endif
        </section>
    @endif
@endif
