<?php

declare(strict_types=1);

namespace App\Support\Desk;

use App\Enums\TaskStatus;
use App\Models\Milestone;
use App\Models\Scopes\TaskVisibilityScope;
use App\Models\Task;
use App\Models\TaskAssignee;
use App\Models\User;
use App\Support\SupervisionMap;
use Illuminate\Database\Eloquent\Builder;

/**
 * Who belongs to someone's team on the Desk (user decision 2026-10-27): the
 * people they supervise (SupervisionMap: HR direct reports plus local
 * supervisions), the people on their projects (assignees of open tasks on
 * projects they own or manage, milestone tasks included, and of open tasks
 * they created) and their HR department. Each person is listed once, under
 * the closest tie: report, then project, then department. An administrator's
 * team is every linked employee. The viewer is never on their own team.
 */
final class TeamScope
{
    public const string Report = 'report';

    public const string Project = 'project';

    public const string Department = 'department';

    public const string Everyone = 'everyone';

    /**
     * @return array<int, string> employee id => relation
     */
    public static function for(User $user): array
    {
        $self = $user->employee_id;

        if ($user->isAdministrator()) {
            $ids = User::query()->whereNotNull('employee_id')->pluck('employee_id')->map(fn (mixed $id): int => (int) $id)->all();

            return self::without(array_fill_keys($ids, self::Everyone), $self);
        }

        if ($self === null) {
            return [];
        }

        $team = array_fill_keys(SupervisionMap::superviseesOf($self), self::Report);
        $team += array_fill_keys(self::projectPeople($user), self::Project);
        $team += array_fill_keys($user->departmentEmployeeIds(), self::Department);

        return self::without($team, $self);
    }

    /**
     * Assignees of open tasks on the user's projects or created by the user.
     * Reads past TaskVisibilityScope: being on a team is about who works
     * together, not about which tasks the viewer may open.
     *
     * @return list<int>
     */
    private static function projectPeople(User $user): array
    {
        $projectIds = $user->ownedProjectIds();

        $tasks = Task::query()
            ->withoutGlobalScope(TaskVisibilityScope::class)
            ->select('id')
            ->where('status', '!=', TaskStatus::Done)
            ->where(fn (Builder $query) => $query
                ->where('created_by', $user->id)
                ->when($projectIds !== [], fn (Builder $query) => $query
                    ->orWhere(fn (Builder $query) => $query->where('source_type', 'project')->whereIn('source_id', $projectIds))
                    ->orWhere(fn (Builder $query) => $query->where('source_type', 'milestone')->whereIn('source_id', Milestone::query()->select('id')->whereIn('project_id', $projectIds)))));

        return array_values(array_unique(TaskAssignee::query()
            ->whereIn('task_id', $tasks)
            ->pluck('employee_id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all()));
    }

    /**
     * @param  array<int, string>  $team
     * @return array<int, string>
     */
    private static function without(array $team, ?int $self): array
    {
        unset($team[$self ?? 0], $team[0]);

        return $team;
    }
}
