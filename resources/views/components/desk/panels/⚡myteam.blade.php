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
use App\Models\User;
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
 * for any team.
 */
new class extends Component {
    use IsDeskPanel;
    use ReadsDashboardAttention;
    use ReadsDashboardWork;

    /**
     * @return list<array{employeeId: int, name: string, initials: string, title: string, overdue: int, lateReports: int, userId: int|null, relation: string}>
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

        return array_values(array_map(function (array $person) use ($users, $lateReports): array {
            $user = $users->get($person['employeeId']);

            return [
                ...$person,
                'initials' => $user?->initials() ?? mb_substr($person['name'], 0, 1),
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
    $pg = $this->paginate(collect($people), self::PerPage);
@endphp

<x-desk.panel :facets="$pg['facets']" id="myteam" :size="$size" :index="$index" :total="$total" :count="count($people)">
    @if ($pg['total'] > 0)
        <ul class="dk-team">
            @foreach ($pg['slice'] as $person)
                @php
                    $behind = $person['overdue'] + $person['lateReports'];
                @endphp
                <li wire:key="pp-{{ $person['employeeId'] }}">
                    <span class="dk-av" aria-hidden="true">{{ $person['initials'] }}</span>
                    <span class="dk-tm-who">
                        <strong title="{{ $person['name'] }}">{{ $person['name'] }}</strong>
                        <span class="dk-is" title="{{ $person['title'] }}">{{ __('desk.relation.'.$person['relation']) }}@if ($person['title'] !== '') · {{ $person['title'] }}@endif</span>
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
        <p class="dk-pempty">{{ __('desk.panel.myteam.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
