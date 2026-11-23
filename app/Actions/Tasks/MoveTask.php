<?php

declare(strict_types=1);

namespace App\Actions\Tasks;

use App\Models\Program;
use App\Models\Project;
use App\Models\ProposalLocation;
use App\Models\Task;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Move a task, with its subtasks, somewhere else inside its own program: the
 * program itself ("program") or one of its location rows ("location:ID";
 * "project:ID" is still accepted for an unmapped legacy task). A subtask always follows its parent, so
 * it never moves on its own, and a task raised outside a program (a proposal,
 * a report…) has nowhere to move to.
 *
 * The same list of places feeds the "Goes to" field of the Add task dialog
 * (placesInProgram(), resolveTarget()).
 */
class MoveTask
{
    /**
     * Where the task may go, or null when it cannot be moved at all.
     *
     * @return array{program: array{id: int, name: string}, projects: list<array{id: int, name: string}>}|null
     */
    public function destinations(Task $task): ?array
    {
        if ($task->parent_task_id !== null) {
            return null;
        }

        $programId = $this->programIdOfSource($task->source_type, $task->source_id);

        return $programId === null ? null : $this->placesInProgram($programId);
    }

    /**
     * Every place inside a program a task can belong to, or null when the program is gone.
     *
     * @return array{program: array{id: int, name: string}, projects: list<array{id: int, name: string}>}|null
     */
    public function placesInProgram(int $programId): ?array
    {
        $program = Program::query()->find($programId);

        if ($program === null) {
            return null;
        }

        $projects = Project::query()
            ->select(['id', 'name'])
            ->where('program_id', $program->id)
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project): array => ['id' => $project->id, 'name' => $project->name]);

        return [
            'program' => ['id' => $program->id, 'name' => $program->name],
            'projects' => array_values($projects->all()),
        ];
    }

    /**
     * @return bool false when the task cannot move there (a subtask, outside its program, or where it already is)
     */
    public function handle(Task $task, string $target): bool
    {
        Gate::authorize('update', $task);

        $programId = $task->parent_task_id === null ? $this->programIdOfSource($task->source_type, $task->source_id) : null;
        $destination = $programId === null ? null : $this->resolveTarget($programId, $target);

        if ($destination === null || ($task->source_type === $destination['type'] && $task->source_id === $destination['id'])) {
            return false;
        }

        DB::transaction(function () use ($task, $destination): void {
            $task->forceFill(['source_type' => $destination['type'], 'source_id' => $destination['id']])->save();
            $task->subtasks()->update(['source_type' => $destination['type'], 'source_id' => $destination['id']]);
        });

        return true;
    }

    /**
     * A target ("program", "project:ID") inside the program, or null for anything else.
     *
     * @return array{type: string, id: int}|null
     */
    public function resolveTarget(int $programId, string $target): ?array
    {
        if ($target === 'program') {
            return Program::query()->whereKey($programId)->exists() ? ['type' => 'program', 'id' => $programId] : null;
        }

        [$type, $id] = array_pad(explode(':', $target, 2), 2, '');
        $id = (int) $id;

        // "location:ID" is where a task moves from Y-12 on; "project:ID" is
        // still accepted for tasks the backfill could not map (until Y-18).
        $belongs = match ($type) {
            'location' => ProposalLocation::query()->whereKey($id)->where('program_id', $programId)->exists(),
            'project' => Project::query()->whereKey($id)->where('program_id', $programId)->exists(),
            default => false,
        };

        if (! $belongs) {
            return null;
        }

        return ['type' => $type === 'location' ? 'proposal_location' : $type, 'id' => $id];
    }

    /**
     * A record's place in the target format: "program" or "project:ID".
     */
    public function targetFor(string $type, int $id): string
    {
        return match ($type) {
            'program' => 'program',
            'proposal_location' => 'location:'.$id,
            default => $type.':'.$id,
        };
    }

    /**
     * The program a task source lives in: itself, or a project's.
     */
    public function programIdOfSource(string $type, int $id): ?int
    {
        if ($type === 'program') {
            return $id;
        }

        $programId = match ($type) {
            'proposal_location' => ProposalLocation::programIdFor($id),
            'project' => Project::query()->whereKey($id)->value('program_id'),
            default => null,
        };

        return $programId === null ? null : (int) $programId;
    }
}
