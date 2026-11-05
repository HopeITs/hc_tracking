<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\AttachLibraryRisk;
use App\Actions\Proposals\DetachRisk;
use App\Models\Proposal;
use App\Models\Result;
use App\Models\Risk;
use App\Models\RiskLibrary;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;

/**
 * "Risks & assumptions" of the proposal Logical Framework tab (W-09, D-61).
 *
 * A node of the tree opens a dialog on one outcome or output: it lists the
 * risks already on that result and, for holders of the attach ability, a
 * picker over the active MEAL library. There is no free-text path: every
 * write is AttachLibraryRisk / DetachRisk, which authorize and load the
 * result and the risk through the proposal again. The tab reads the
 * proposal's risks in one query, whatever the framework size.
 *
 * Requires `proposal(): Proposal` and `$proposalId` from the host component.
 *
 * @property-read Collection<int, Collection<int, Risk>> $proposalRisks risks grouped by result id
 */
trait ManagesProposalRisks
{
    public bool $showRisksDrawer = false;

    public ?int $riskResultId = null;

    /** @var list<int|string> */
    public array $riskPicks = [];

    /**
     * Every risk of the proposal, grouped by the result it sits on.
     *
     * @return Collection<int, Collection<int, Risk>>
     */
    #[Computed]
    public function proposalRisks(): Collection
    {
        return Risk::query()
            ->select(['id', 'proposal_id', 'result_id', 'name', 'name_en', 'likelihood', 'severity', 'happened', 'solution'])
            ->where('proposal_id', $this->proposalId)
            ->orderBy('id')
            ->get()
            ->groupBy('result_id');
    }

    /**
     * How many risks each result carries, for the node badges.
     *
     * @return array<int, int>
     */
    #[Computed]
    public function riskCounts(): array
    {
        return $this->proposalRisks->map(fn (Collection $risks): int => $risks->count())->all();
    }

    /**
     * Rated risks per heat-map square (keyed "likelihood:severity") and the
     * number left off the map because they are not rated yet.
     *
     * @return array{cells: array<string, int>, unrated: int, total: int}
     */
    #[Computed]
    public function riskHeat(): array
    {
        $risks = $this->proposalRisks->flatten(1);
        $rated = $risks->filter(fn (Risk $risk): bool => $risk->heatScore() !== null);

        return [
            'cells' => $rated->countBy(fn (Risk $risk): string => $risk->likelihood.':'.$risk->severity)->all(),
            'unrated' => $risks->count() - $rated->count(),
            'total' => $risks->count(),
        ];
    }

    /**
     * The active library entries the picker offers, by category.
     *
     * @return Collection<string, Collection<int, RiskLibrary>>
     */
    #[Computed]
    public function riskLibraryOptions(): Collection
    {
        if (! $this->showRisksDrawer) {
            return collect();
        }

        return RiskLibrary::query()->active()->ordered()->get()
            ->groupBy(fn (RiskLibrary $entry): string => (string) $entry->category);
    }

    /**
     * The result whose dialog is open.
     */
    #[Computed]
    public function riskResult(): ?Result
    {
        return $this->riskResultId === null
            ? null
            : $this->proposal->results()->select(['id', 'proposal_id', 'statement', 'type'])->whereKey($this->riskResultId)->first();
    }

    #[Computed]
    public function canManageRisks(): bool
    {
        return Gate::allows('attach', [Risk::class, $this->proposal]);
    }

    public function openRisks(int $resultId): void
    {
        $result = $this->proposal->results()->select(['id', 'proposal_id'])->whereKey($resultId)->firstOrFail();

        $this->riskResultId = $result->id;
        $this->riskPicks = [];
        $this->resetValidation();
        $this->showRisksDrawer = true;
    }

    public function closeRisks(): void
    {
        $this->showRisksDrawer = false;
        $this->riskResultId = null;
        $this->riskPicks = [];
        $this->resetValidation();
    }

    /**
     * Attach every picked library risk to the open result, all or none.
     */
    public function attachRisks(): void
    {
        if ($this->riskResultId === null || $this->riskPicks === []) {
            throw ValidationException::withMessages(['riskPicks' => __('risks.pick_at_least_one')]);
        }

        $resultId = $this->riskResultId;
        $picks = array_values(array_unique(array_map(intval(...), $this->riskPicks)));

        try {
            DB::transaction(function () use ($resultId, $picks): void {
                foreach ($picks as $libraryRiskId) {
                    app(AttachLibraryRisk::class)->handle($this->proposal, $resultId, $libraryRiskId, []);
                }
            });
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages([
                'riskPicks' => (string) collect($exception->errors())->flatten()->first(),
            ]);
        }

        $this->riskPicks = [];
        unset($this->proposalRisks, $this->riskCounts, $this->riskHeat);

        Flux::toast(variant: 'success', text: trans_choice('risks.attached', count($picks), ['count' => count($picks)]));
    }

    public function detachRisk(int $riskId): void
    {
        app(DetachRisk::class)->handle($this->proposal, $riskId);

        unset($this->proposalRisks, $this->riskCounts, $this->riskHeat);

        Flux::toast(variant: 'success', text: __('risks.detached'));
    }
}
