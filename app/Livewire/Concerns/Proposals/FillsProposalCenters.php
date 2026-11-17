<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\SaveProposal;
use App\Models\HrCenter;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;

/**
 * The "Add centers" dialog of proposals/show (Y-01 slice 2, D-83): after a
 * donor approves, a row with no center and not city-wide may be given its
 * centers, or be marked field work, once. Every other row is shown locked and
 * goes back to SaveProposal exactly as stored, so the whole set travels as the
 * `locations` payload and SaveProposal's approved-fill rule decides.
 *
 * Rows are always read through the proposal's own relation, so an id the
 * browser invents is ignored. Used by a page that provides forgetReadings().
 */
trait FillsProposalCenters
{
    public bool $showCenterFillModal = false;

    /**
     * What the dialog edits, keyed by location row id. Only rows that were
     * fillable when the dialog opened have an entry.
     *
     * @var array<int, array{center_ids: list<string>, city_wide: bool}>
     */
    public array $centerFill = [];

    abstract private function forgetReadings(): void;

    /**
     * Whether the "Add centers" button applies: a donor approved the
     * proposal, the viewer may edit it and some row has no center yet.
     */
    #[Computed]
    public function canFillCenters(): bool
    {
        $proposal = $this->proposal;

        return $proposal->status->isApprovedByDonor()
            && auth()->user()?->can('update', $proposal) === true
            && collect($this->centerFillRows)->contains('fillable', true);
    }

    /**
     * Every row of the proposal in order, with the city the picker needs and
     * whether the row may still be filled.
     *
     * @return list<array{id: int, cityId: int, fillable: bool}>
     */
    #[Computed]
    public function centerFillRows(): array
    {
        return $this->proposal->locations()
            ->with('centers')
            ->orderBy('sort')
            ->orderBy('id')
            ->get()
            ->map(fn (ProposalLocation $row): array => [
                'id' => $row->id,
                'cityId' => (int) $row->city_id,
                'fillable' => $this->isFillableLocation($row),
            ])->all();
    }

    /**
     * Active centers by city id, for the pickers.
     *
     * @return array<int, list<array{id: int, name: string}>>
     */
    #[Computed]
    public function centerFillCentersByCity(): array
    {
        $byCity = [];

        foreach (HrCenter::options() as $center) {
            $byCity[(int) $center->city_id][] = ['id' => (int) $center->id, 'name' => $center->name];
        }

        return $byCity;
    }

    public function openCenterFill(): void
    {
        $proposal = Proposal::query()->findOrFail($this->proposalId);

        Gate::authorize('update', $proposal);

        $this->resetValidation();
        $this->centerFill = [];

        if (! $proposal->status->isApprovedByDonor()) {
            return;
        }

        foreach ($proposal->locations()->with('centers')->get() as $row) {
            if ($this->isFillableLocation($row)) {
                $this->centerFill[$row->id] = ['center_ids' => [], 'city_wide' => false];
            }
        }

        if ($this->centerFill === []) {
            return;
        }

        $this->showCenterFillModal = true;
    }

    public function closeCenterFill(): void
    {
        $this->showCenterFillModal = false;
        $this->centerFill = [];
        $this->resetValidation();
    }

    /**
     * Ticking field work clears the row's picks, as on the form.
     */
    public function updatedCenterFill(mixed $value, string $path): void
    {
        [$rowId, $field] = array_pad(explode('.', $path, 2), 2, null);

        if ($field === 'city_wide' && isset($this->centerFill[(int) $rowId])) {
            $this->centerFill[(int) $rowId]['city_wide'] = (bool) $this->centerFill[(int) $rowId]['city_wide'];

            if ($this->centerFill[(int) $rowId]['city_wide']) {
                $this->centerFill[(int) $rowId]['center_ids'] = [];
            }
        }
    }

    public function saveCenterFill(): void
    {
        $proposal = Proposal::query()->findOrFail($this->proposalId);

        Gate::authorize('update', $proposal);

        $locations = [];

        foreach ($proposal->locations()->with(['centers', 'programRequest'])->get() as $row) {
            $payload = $this->storedLocationPayload($row);

            if ($this->isFillableLocation($row) && isset($this->centerFill[$row->id])) {
                $fill = $this->centerFill[$row->id];
                $cityWide = (bool) ($fill['city_wide'] ?? false);

                $payload['city_wide'] = $cityWide;
                $payload['center_ids'] = $cityWide ? [] : array_values(array_map('strval', (array) ($fill['center_ids'] ?? [])));
            }

            $locations[] = $payload;
        }

        app(SaveProposal::class)->handle($proposal, [
            'name' => $proposal->name,
            'donorId' => $proposal->donor_id,
            'plannedDateBegin' => $proposal->planned_date_begin?->toDateString(),
            'plannedDateEnd' => $proposal->planned_date_end?->toDateString(),
            'notes' => $proposal->notes,
            'locations' => $locations,
        ], auth()->user());

        $this->closeCenterFill();
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.center_fill_saved'));
    }

    /**
     * A row with no center that is not field work: the only kind the dialog fills.
     */
    private function isFillableLocation(ProposalLocation $row): bool
    {
        return ! $row->city_wide && $row->centers->isEmpty();
    }

    /**
     * The row as SaveProposal receives it, the same shape the form sends.
     *
     * @return array{city_id: string, program_id: string|null, responsible_employee_id: string|null, center_ids: list<string>, city_wide: bool, is_new: bool, new_program_name_en: string, new_program_name_ar: string}
     */
    private function storedLocationPayload(ProposalLocation $row): array
    {
        return [
            'city_id' => (string) $row->city_id,
            'program_id' => $row->program_id !== null ? (string) $row->program_id : null,
            'responsible_employee_id' => $row->responsible_employee_id !== null ? (string) $row->responsible_employee_id : null,
            'center_ids' => array_map('strval', $row->centerIds()),
            'city_wide' => (bool) $row->city_wide,
            'is_new' => (bool) $row->is_new,
            'new_program_name_en' => $row->is_new ? ($row->programRequest->name_en ?? '') : '',
            'new_program_name_ar' => $row->is_new ? ($row->programRequest->name_ar ?? '') : '',
        ];
    }
}
