<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Risks\DeleteFrameworkRisk;
use App\Actions\Risks\SaveFrameworkRisk;
use App\Models\Proposal;
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
 * The one "Risks" section under the whole proposal Logical Framework: the
 * logframe risks (copied from the program or typed for this proposal) and
 * the typed risk form shared with the results framework page
 * (components/risks/form-modal). Copied risks are remove only. Writes go
 * through SaveFrameworkRisk / DeleteFrameworkRisk, which authorize
 * (`manageFramework`) and load the risk through the proposal again.
 *
 * Requires `proposal(): Proposal` and `$proposalId` from the host component.
 *
 * @property-read EloquentCollection<int, Risk> $proposalRisks
 * @property-read Collection<int, RiskLibrary> $riskSuggestions
 */
trait ManagesProposalRisks
{
    public bool $showRiskModal = false;

    #[Locked]
    public ?int $editingRiskId = null;

    /** @var array{name: string, name_en: string, likelihood: int|string|null, severity: int|string|null, solution: string} */
    public array $riskForm = ['name' => '', 'name_en' => '', 'likelihood' => null, 'severity' => null, 'solution' => ''];

    /**
     * Every logframe risk of the proposal, legacy per-result rows included.
     *
     * @return EloquentCollection<int, Risk>
     */
    #[Computed]
    public function proposalRisks(): EloquentCollection
    {
        return Risk::query()
            ->onLogframeOf($this->proposalId)
            ->orderBy('id')
            ->get(['id', 'proposal_id', 'result_id', 'copied_from_id', 'name', 'name_en', 'likelihood', 'severity', 'happened', 'solution']);
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

    #[Computed]
    public function canManageRisks(): bool
    {
        return Gate::allows('manageFramework', [Risk::class, $this->proposal]);
    }

    /**
     * Open the form empty, or on one proposal-specific risk (copied risks are remove only).
     */
    public function openRiskForm(?int $riskId = null): void
    {
        Gate::authorize('manageFramework', [Risk::class, $this->proposal]);

        $this->resetRiskForm();

        if ($riskId !== null) {
            $risk = Risk::query()->onLogframeOf($this->proposalId)->findOrFail($riskId);

            if ($risk->isCopied()) {
                throw ValidationException::withMessages(['riskForm.name' => __('risks.copied_is_remove_only')]);
            }

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
        try {
            $risk = app(SaveFrameworkRisk::class)->handle($this->proposal, $this->editingRiskId, $this->riskForm);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $field): array => [
                    ($field === 'risk' ? 'riskForm.name' : 'riskForm.'.$field) => $messages,
                ])->all(),
            );
        }

        $wasRecentlyCreated = $risk->wasRecentlyCreated;

        $this->closeRiskForm();
        $this->forgetRisks();

        Flux::toast(variant: 'success', text: $wasRecentlyCreated ? __('results_framework.risk_added') : __('results_framework.risk_updated'));
    }

    public function removeRisk(int $riskId): void
    {
        app(DeleteFrameworkRisk::class)->handle($this->proposal, $riskId);

        $this->forgetRisks();

        Flux::toast(variant: 'success', text: __('results_framework.risk_removed'));
    }

    public function closeRiskForm(): void
    {
        $this->showRiskModal = false;
        $this->resetRiskForm();
    }

    /**
     * Drop the memoised risk reading after a write; the host may widen it.
     */
    protected function forgetRisks(): void
    {
        unset($this->proposalRisks);
    }

    protected function resetRiskForm(): void
    {
        $this->resetValidation();
        $this->editingRiskId = null;
        $this->riskForm = ['name' => '', 'name_en' => '', 'likelihood' => null, 'severity' => null, 'solution' => ''];
    }
}
