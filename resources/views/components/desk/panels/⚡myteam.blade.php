<?php

use App\Actions\Desk\SendNudge;
use App\Enums\MonthlyReportStatus;
use App\Enums\TaskStatus;
use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Dashboard\ReadsDashboardWork;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\HrEmployee;
use App\Models\MonthlyReport;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\User;
use App\Support\Desk\TeamScope;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * My team: the viewer's team (TeamScope via the dashboard's myTeam():
 * reports, project teams, department) with their overdue tasks and late
 * monthly reports. Anyone with a tracking account can be poked, with an
 * optional note; the poke is about their oldest overdue task, else their
 * oldest late monthly report, else nothing in particular. The subject is
 * looked up only when Poke is pressed, so the panel costs the same queries
 * for any team. Each person also shows a load meter (open tasks, the late
 * share in red) and a word: Free (nothing open), Busy, or Overloaded (any
 * task late, or more than TeamScope::OverloadedAbove open).
 */
new class extends Component {
    use IsDeskPanel;
    use ReadsDashboardAttention;
    use ReadsDashboardWork;

    /**
     * The name (or job title) typed in the panel's search box.
     */
    public string $search = '';

    public function updatedSearch(): void
    {
        $this->page = 0;
    }

    /**
     * @return list<array{employeeId: int, name: string, title: string, overdue: int, open: int, load: string, lateReports: int, userId: int|null, relation: string}>
     */
    #[Computed]
    public function people(): array
    {
        $team = $this->myTeam;

        if ($team === []) {
            return [];
        }

        $ids = array_column($team, 'employeeId');
        $users = User::query()->whereIn('employee_id', $ids)->get(['id', 'employee_id', 'hr_user_id'])->keyBy('employee_id');
        $lateReports = $this->canView('reports') ? MonthlyReport::query()
            ->whereIn('prepared_by', $users->pluck('id')->all())
            ->where('status', MonthlyReportStatus::Draft)
            ->where('period', '<', CarbonImmutable::today()->startOfMonth()->toDateString())
            ->selectRaw('prepared_by, count(*) as total')
            ->groupBy('prepared_by')
            ->pluck('total', 'prepared_by') : collect();
        $open = TaskAssignee::query()
            ->whereIn('employee_id', $ids)
            ->whereIn('task_id', $this->visible(Task::query(), 'tasks')->select('id')->where('status', '!=', TaskStatus::Done))
            ->groupBy('employee_id')
            ->selectRaw('employee_id, count(*) as total')
            ->pluck('total', 'employee_id');

        return array_values(array_map(function (array $person) use ($users, $lateReports, $open): array {
            $user = $users->get($person['employeeId']);
            $openTasks = (int) ($open[$person['employeeId']] ?? 0);

            return [
                ...$person,
                'open' => $openTasks,
                'load' => TeamScope::load($openTasks, $person['overdue']),
                'title' => (string) (HrEmployee::positionSummary($person['employeeId']) ?? ''),
                'lateReports' => $user === null ? 0 : (int) ($lateReports[$user->id] ?? 0),
                'userId' => $user?->id,
            ];
        }, $team));
    }

    /**
     * The person is read back from people(), never trusted from the request.
     */
    public function nudge(int $employeeId, SendNudge $nudge, ?string $note = null): void
    {
        $person = collect($this->people)->firstWhere('employeeId', $employeeId);

        if ($person === null || $person['userId'] === null) {
            return;
        }

        $sent = $nudge->handle(auth()->user(), $this->nudgeSubject($person['employeeId'], $person['userId']), $employeeId, $note);
        Flux::toast(text: $sent ? __('desk.nudged') : __('desk.nudged_already'), variant: $sent ? 'success' : null);
    }

    /**
     * @return array<string, string>
     */
    protected function facetsOf(mixed $row): array
    {
        return ['relation' => __('desk.relation.'.$row['relation'])];
    }

    /**
     * What a poke to this person is about: their oldest overdue task the
     * viewer may see, else their oldest late monthly report.
     */
    private function nudgeSubject(int $employeeId, ?int $userId): Task|MonthlyReport|null
    {
        $today = CarbonImmutable::today();

        $task = $this->visible(Task::query(), 'tasks')
            ->whereHas('taskAssignees', fn ($query) => $query->where('employee_id', $employeeId))
            ->where('status', '!=', TaskStatus::Done)
            ->whereDate('due_date', '<', $today)
            ->oldest('due_date')
            ->first();

        if ($task !== null || $userId === null || ! $this->canView('reports')) {
            return $task;
        }

        return MonthlyReport::query()
            ->with('project')
            ->where('prepared_by', $userId)
            ->where('status', MonthlyReportStatus::Draft)
            ->where('period', '<', $today->startOfMonth()->toDateString())
            ->oldest('period')
            ->first();
    }
};
?>

@php
    $people = $this->people;
    $query = mb_strtolower(trim($search));
    $found = collect($people)->when($query !== '', fn ($list) => $list->filter(fn (array $person): bool => str_contains(mb_strtolower($person['name'].' '.$person['title']), $query))->values());
    $pg = $this->paginate($found, self::PerPage);
@endphp

<x-desk.panel :empty="count($people) === 0" :facets="$pg['facets']" search="search" :search-value="$search" id="myteam" :size="$size" :cols="$gridCols" :height="$gridRows" :index="$index" :total="$total" :count="count($people)">
    @if ($people !== [])
        @php
            $loads = array_count_values(array_column($people, 'load'));
        @endphp
        <p class="dk-load-sum">
            <span class="dk-load-dot dk-free" aria-hidden="true"></span>{{ __('desk-cards.team.summary', ['free' => $loads['free'] ?? 0, 'overloaded' => $loads['overloaded'] ?? 0]) }}
        </p>
    @endif
    @if ($pg['total'] > 0)
        <ul class="dk-team" {{ $this->fitAttributes($pg) }}>
            @foreach ($pg['slice'] as $person)
                @php
                    $behind = $person['overdue'] + $person['lateReports'];
                @endphp
                <li wire:key="pp-{{ $person['employeeId'] }}">
                    <x-ui.avatar :src="\App\Models\HrEmployee::avatarUrl($person['employeeId'])" class="dk-av" aria-hidden="true" />
                    <span class="dk-tm-who">
                        <strong title="{{ $person['name'] }}">{{ $person['name'] }}</strong>
                        <span class="dk-is" title="{{ $person['title'] }}">{{ __('desk.relation.'.$person['relation']) }}@if ($person['title'] !== '') · {{ $person['title'] }}@endif</span>
                    </span>
                    @php
                        $scale = max(TeamScope::OverloadedAbove, $person['open']);
                    @endphp
                    <span class="dk-tm-meter" title="{{ __('desk-cards.team.meter', ['open' => $person['open'], 'late' => $person['overdue']]) }}">
                        <span class="dk-load-word dk-{{ $person['load'] }}">{{ __('desk-cards.team.status.'.$person['load']) }}</span>
                        <span class="dk-load-bar" aria-hidden="true"><b style="width:{{ round($person['open'] / $scale * 100) }}%"></b><i style="width:{{ round(min($person['overdue'], $person['open']) / $scale * 100) }}%"></i></span>
                        <span class="dk-load-n dk-num">{{ trans_choice('desk-cards.team.open', $person['open'], ['count' => $person['open']]) }}</span>
                    </span>
                    <span class="dk-tm-load">
                        @if ($behind > 0)
                            @if ($person['overdue'] > 0)<span class="dk-bad" title="{{ __('desk.team_behind', ['tasks' => $person['overdue'], 'reports' => $person['lateReports']]) }}"><b class="dk-num">{{ $person['overdue'] }}</b> {{ trans_choice('desk.team_tasks_late', $person['overdue']) }}</span>@endif
                            @if ($person['lateReports'] > 0)<span class="dk-warn"><b class="dk-num">{{ $person['lateReports'] }}</b> {{ trans_choice('desk.team_reports_late', $person['lateReports']) }}</span>@endif
                        @else
                            <span class="dk-muted">{{ __('desk.nothing_overdue') }}</span>
                        @endif
                    </span>
                    <x-desk.poke :call="'nudge('.$person['employeeId']" :name="$person['name']" :disabled="$person['userId'] === null" class="dk-iconbtn dk-sm" />
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ $people !== [] ? __('desk.search_none', ['query' => $search]) : __('desk.panel.myteam.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
