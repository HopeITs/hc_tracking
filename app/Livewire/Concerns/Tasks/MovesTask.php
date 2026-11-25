<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Tasks;

use App\Actions\Tasks\MoveTask;
use App\Models\Task;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * "Move to" on the task page: a small dialog that moves a top-level task,
 * with its subtasks, to the program itself or one of its projects (see
 * MoveTask). Expects the page's own task() computed.
 *
 * @property-read Task $task
 * @property-read array{program: array{id: int, name: string}, projects: list<array{id: int, name: string}>}|null $moveDestinations
 */
trait MovesTask
{
    public bool $showMoveModal = false;

    /** The picked place, as "program" or "location:ID". */
    public string $moveTarget = '';

    /**
     * @return array{program: array{id: int, name: string}, projects: list<array{id: int, name: string}>}|null
     */
    #[Computed]
    public function moveDestinations(): ?array
    {
        return app(MoveTask::class)->destinations($this->task);
    }

    /** Where the task sits now, in moveTarget's format. */
    public function currentMoveTarget(): string
    {
        // Y-16 (D-93): a place's target reads "location:ID", not the morph
        // name; MoveTask owns that spelling, so it writes it.
        return app(MoveTask::class)->targetFor((string) $this->task->source_type, (int) $this->task->source_id);
    }

    public function openMoveModal(): void
    {
        Gate::authorize('update', $this->task);

        $this->moveTarget = $this->currentMoveTarget();
        $this->showMoveModal = true;
    }

    public function closeMoveModal(): void
    {
        $this->showMoveModal = false;
    }

    public function moveTask(): void
    {
        if (! app(MoveTask::class)->handle($this->task, $this->moveTarget)) {
            Flux::toast(variant: 'danger', text: __('tasks.move_pick_new_place'));

            return;
        }

        $this->showMoveModal = false;
        unset($this->task, $this->moveDestinations);

        Flux::toast(variant: 'success', text: __('tasks.task_moved'));
    }
}
