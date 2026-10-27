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
 * My team: the people the viewer supervises (SupervisionMap, via the
 * dashboard's myTeam()) with their overdue tasks and late monthly reports.
 * Nudging someone behind is about their oldest overdue task, or their
 * oldest late monthly report when no task is overdue, looked up only when
 * the button is pressed so the panel costs the same queries for any team.
 */
new class extends Component {
    use IsDeskPanel;
    use ReadsDashboardAttention;
    use ReadsDashboardWork;

    /**
     * @return list<array{employeeId: int, name: string, initials: string, title: string, overdue: int, lateReports: int, userId: int|null}>
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
    public function nudge(int $employeeId, SendNudge $nudge): void
    {
        $person = collect($this->people)->firstWhere('employeeId', $employeeId);

        $subject = $person === null ? null : $this->nudgeSubject($person['employeeId'], $person['userId']);

        if ($subject === null) {
            return;
        }

        $sent = $nudge->handle(auth()->user(), $subject, $employeeId);
        Flux::toast(text: $sent ? __('desk.nudged') : __('desk.nudged_already'), variant: $sent ? 'success' : null);
    }

    /**
     * What a nudge to this person is about: their oldest overdue task the
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
    <ul class="dk-people">
        @foreach ($pg['slice'] as $person)
            @php
                $behind = $person['overdue'] + $person['lateReports'];
            @endphp
            <li wire:key="pp-{{ $person['employeeId'] }}">
                <span class="dk-av">{{ $person['initials'] }}</span>
                <span class="dk-pm"><strong>{{ $person['name'] }}</strong>@if ($person['title'] !== '')<span class="dk-is">{{ $person['title'] }}</span>@endif</span>
                <span @class(['dk-small', 'dk-bad' => $behind > 0, 'dk-muted' => $behind === 0])>{{ $behind > 0 ? __('desk.team_behind', ['tasks' => $person['overdue'], 'reports' => $person['lateReports']]) : __('desk.nothing_overdue') }}</span>
                @if ($behind > 0)
                    <button type="button" class="dk-btn dk-ghost dk-sm" wire:click="nudge({{ $person['employeeId'] }})" aria-label="{{ __('desk.nudge_named', ['name' => $person['name']]) }}"><x-desk.icon name="bell" /></button>
                @endif
            </li>
        @endforeach
    </ul>

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
