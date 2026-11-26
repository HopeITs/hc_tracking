<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Results;

use App\Actions\Risks\DeleteFrameworkRisk;
use App\Actions\Risks\SaveFrameworkRisk;
use App\Models\Program;
use App\Models\Risk;
use App\Models\RiskLibrary;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The "Risks" block under each program framework panel of results/index:
 * one query for every program's risks, and the typed risk form (Arabic and
 * English title, likelihood, severity, mitigation). Writes go through
 * SaveFrameworkRisk / DeleteFrameworkRisk, which authorize
 * (`manageFramework`) and load the risk through its program.
 *
 * @property-read Collection<int|string, EloquentCollection<int, Risk>> $programRisks
 * @property-read Collection<int, RiskLibrary> $riskSuggestions
 */
trait ManagesProgramRisks
{
    public bool $showRiskModal = false;

    #[Locked]
    public ?int $riskProgramId = null;

    #[Locked]
    public ?int $editingRiskId = null;

    /** @var array{name: string, name_en: string, likelihood: int|string|null, severity: int|string|null, solution: string} */
    public array $riskForm = ['name' => '', 'name_en' => '', 'likelihood' => null, 'severity' => null, 'solution' => ''];

    /**
     * Every program risk, keyed by program id, in one query.
     *
     * @return Collection<int|string, EloquentCollection<int, Risk>>
     */
    #[Computed]
    public function programRisks(): Collection
    {
        return Risk::query()
            ->whereNotNull('program_id')
            ->whereNull('proposal_id')
            ->orderBy('id')
            ->get(['id', 'program_id', 'name', 'name_en', 'likelihood', 'severity', 'solution'])
            ->groupBy('program_id')
            ->toBase();
    }

    /**
     * Titles typed before, offered while the risk form is open.
     *
     * @return Collection<int, RiskLibrary>
     */
    #[Computed]
    public function riskSuggestions(): Collection
    {
        return $this->showRiskModal ? RiskLibrary::suggestions()->toBase() : collect();
    }

    public function openRiskForm(int $programId, ?int $riskId = null): void
    {
        $program = Program::query()->findOrFail($programId);

        Gate::authorize('manageFramework', [Risk::class, $program]);

        $this->resetRiskForm();
        $this->riskProgramId = $program->id;

        if ($riskId !== null) {
            $risk = Risk::query()->onProgram($program->id)->findOrFail($riskId);

            $this->editingRiskId = $risk->id;
            $this->riskForm = [
                'name' => $risk->name,
                'name_en' => (string) $risk->name_en,
                'likelihood' => $risk->likelihood,
                'severity' => $risk->severity,
                'solution' => (string) $risk->solution,
            ];
        }

        $this->showRiskModal = true;
        unset($this->riskSuggestions);
    }

    public function saveRisk(): void
    {
        $program = Program::query()->findOrFail((int) $this->riskProgramId);

        Gate::authorize('manageFramework', [Risk::class, $program]);

        try {
            $risk = app(SaveFrameworkRisk::class)->handle($program, $this->editingRiskId, $this->riskForm);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [
                    ($field === 'risk' ? 'riskForm.name' : 'riskForm.'.$field) => $messages,
                ])->all(),
            );
        }

        $wasRecentlyCreated = $risk->wasRecentlyCreated;

        $this->closeRiskForm();
        unset($this->programRisks);

        Flux::toast(variant: 'success', text: $wasRecentlyCreated ? __('results_framework.risk_added') : __('results_framework.risk_updated'));
    }

    public function deleteRisk(int $programId, int $riskId): void
    {
        app(DeleteFrameworkRisk::class)->handle(Program::query()->findOrFail($programId), $riskId);

        unset($this->programRisks);

        Flux::toast(variant: 'success', text: __('results_framework.risk_removed'));
    }

    public function closeRiskForm(): void
    {
        $this->showRiskModal = false;
        $this->resetRiskForm();
    }

    protected function resetRiskForm(): void
    {
        $this->resetValidation();
        $this->riskProgramId = null;
        $this->editingRiskId = null;
        $this->riskForm = ['name' => '', 'name_en' => '', 'likelihood' => null, 'severity' => null, 'solution' => ''];
    }
}
