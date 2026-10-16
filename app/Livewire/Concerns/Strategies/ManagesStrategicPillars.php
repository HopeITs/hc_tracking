<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Strategies;

use App\Actions\Strategies\AssignProgramToPillar;
use App\Actions\Strategies\DeleteStrategicPillar;
use App\Actions\Strategies\SaveStrategicPillar;
use App\Models\Program;
use App\Models\StrategicPillar;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The Pillars section of strategies/show (T32, D-35): one card per pillar
 * listing its programs, a "No pillar" group, and the dialogs to create, edit
 * and delete a pillar and to place a program under one. Every write goes
 * through its action; the page only maps the action's error keys to its own
 * fields. Programs come from allPrograms(), never the paginated programs()
 * (.ai/rules/strategies.md).
 *
 * @property-read Collection<int, StrategicPillar> $pillars
 * @property-read array{pillars: list<array{pillar: StrategicPillar, programs: list<Program>}>, unassigned: list<Program>} $pillarGroups
 */
trait ManagesStrategicPillars
{
    public bool $showPillarModal = false;

    public bool $showAssignModal = false;

    public bool $showDeletePillarModal = false;

    #[Locked]
    public ?int $editingPillarId = null;

    #[Locked]
    public ?int $deletingPillarId = null;

    #[Locked]
    public ?int $assigningProgramId = null;

    public string $pillarCode = '';

    public string $pillarNameAr = '';

    public string $pillarNameEn = '';

    public string $pillarGoalAr = '';

    public string $pillarGoalEn = '';

    public int|string $pillarSortOrder = 0;

    public int|string|null $assignPillarId = null;

    /**
     * @return Collection<int, StrategicPillar>
     */
    #[Computed]
    public function pillars(): Collection
    {
        return StrategicPillar::query()
            ->where('strategy_id', $this->strategyId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get(['id', 'strategy_id', 'code', 'name_ar', 'name_en', 'goal_ar', 'goal_en', 'sort_order']);
    }

    /**
     * The programs of the strategy grouped by pillar, in memory.
     *
     * @return array{pillars: list<array{pillar: StrategicPillar, programs: list<Program>}>, unassigned: list<Program>}
     */
    #[Computed]
    public function pillarGroups(): array
    {
        $byPillar = $this->allPrograms
            ->sortBy(fn (Program $program): string => mb_strtolower($program->name))
            ->groupBy(fn (Program $program): int => (int) $program->strategic_pillar_id);

        return [
            'pillars' => $this->pillars
                ->map(fn (StrategicPillar $pillar): array => ['pillar' => $pillar, 'programs' => $byPillar->get($pillar->id, collect())->values()->all()])
                ->values()
                ->all(),
            'unassigned' => $byPillar->get(0, collect())->values()->all(),
        ];
    }

    public function openCreatePillarModal(): void
    {
        Gate::authorize('create', StrategicPillar::class);

        $this->resetPillarForm();
        $this->pillarSortOrder = (int) $this->pillars->max('sort_order') + 1;
        $this->showPillarModal = true;
    }

    public function openEditPillarModal(int $pillarId): void
    {
        $pillar = $this->pillars->firstWhere('id', $pillarId) ?? abort(404);

        Gate::authorize('update', $pillar);

        $this->resetPillarForm();
        $this->editingPillarId = $pillar->id;
        $this->pillarCode = $pillar->code;
        $this->pillarNameAr = $pillar->name_ar;
        $this->pillarNameEn = (string) $pillar->name_en;
        $this->pillarGoalAr = (string) $pillar->goal_ar;
        $this->pillarGoalEn = (string) $pillar->goal_en;
        $this->pillarSortOrder = $pillar->sort_order;
        $this->showPillarModal = true;
    }

    public function savePillar(): void
    {
        try {
            app(SaveStrategicPillar::class)->handle($this->strategyId, $this->editingPillarId, [
                'code' => $this->pillarCode,
                'name_ar' => $this->pillarNameAr,
                'name_en' => $this->pillarNameEn,
                'goal_ar' => $this->pillarGoalAr,
                'goal_en' => $this->pillarGoalEn,
                'sort_order' => $this->pillarSortOrder === '' ? 0 : $this->pillarSortOrder,
            ]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => [match ($key) {
                    'code' => 'pillarCode',
                    'name_en' => 'pillarNameEn',
                    'goal_ar' => 'pillarGoalAr',
                    'goal_en' => 'pillarGoalEn',
                    'sort_order' => 'pillarSortOrder',
                    default => 'pillarNameAr',
                } => $messages])->all(),
            );
        }

        $this->closePillarModal();
        $this->forgetPillars();

        Flux::toast(variant: 'success', text: __('strategies.pillar_saved'));
    }

    public function closePillarModal(): void
    {
        $this->showPillarModal = false;
        $this->resetPillarForm();
    }

    public function confirmDeletePillar(int $pillarId): void
    {
        $pillar = $this->pillars->firstWhere('id', $pillarId) ?? abort(404);

        Gate::authorize('delete', $pillar);

        $this->deletingPillarId = $pillar->id;
        $this->showDeletePillarModal = true;
    }

    public function deletePillar(): void
    {
        if ($this->deletingPillarId === null) {
            return;
        }

        $pillarId = $this->deletingPillarId;
        $this->cancelDeletePillar();

        try {
            app(DeleteStrategicPillar::class)->handle($pillarId);
        } catch (ValidationException $exception) {
            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->forgetPillars();

        Flux::toast(variant: 'success', text: __('strategies.pillar_deleted'));
    }

    public function cancelDeletePillar(): void
    {
        $this->showDeletePillarModal = false;
        $this->deletingPillarId = null;
    }

    public function openAssignModal(int $programId): void
    {
        Gate::authorize('strategies.update');

        $program = $this->allPrograms->firstWhere('id', $programId) ?? abort(404);

        $this->resetErrorBag('assignPillarId');
        $this->assigningProgramId = $program->id;
        $this->assignPillarId = $program->strategic_pillar_id;
        $this->showAssignModal = true;
    }

    public function assignProgram(): void
    {
        if ($this->assigningProgramId === null) {
            return;
        }

        $program = $this->allPrograms->firstWhere('id', $this->assigningProgramId) ?? abort(404);
        $pillarId = $this->assignPillarId === null || $this->assignPillarId === '' ? null : (int) $this->assignPillarId;

        if ($pillarId !== null && $this->pillars->firstWhere('id', $pillarId) === null) {
            throw ValidationException::withMessages(['assignPillarId' => __('strategies.pillar_other_strategy')]);
        }

        try {
            app(AssignProgramToPillar::class)->handle($program->id, $pillarId);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['assignPillarId' => collect($exception->errors())->flatten()->all()]);
        }

        $this->closeAssignModal();
        $this->forgetPillars();

        Flux::toast(variant: 'success', text: __('strategies.program_pillar_saved'));
    }

    public function closeAssignModal(): void
    {
        $this->showAssignModal = false;
        $this->assigningProgramId = null;
        $this->assignPillarId = null;
    }

    private function resetPillarForm(): void
    {
        $this->resetErrorBag();
        $this->editingPillarId = null;
        $this->pillarCode = '';
        $this->pillarNameAr = '';
        $this->pillarNameEn = '';
        $this->pillarGoalAr = '';
        $this->pillarGoalEn = '';
        $this->pillarSortOrder = 0;
    }

    private function forgetPillars(): void
    {
        unset($this->pillars, $this->pillarGroups, $this->allPrograms, $this->programs);
    }
}
