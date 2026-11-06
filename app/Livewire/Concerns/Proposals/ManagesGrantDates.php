<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Proposals\SetGrantDates;
use App\Models\Proposal;
use App\Models\User;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;

/**
 * The "Set grant dates" dialog of proposals/show: fills in the actual start
 * and end of a donor-approved grant that has none (SetGrantDates). The dates
 * start from the planned ones, for the user to confirm. `?grant_dates=1`
 * opens it once for a viewer who may set them (the donor report builder links
 * here). Used by a page that provides forgetReadings() and the #[Computed]
 * proposal().
 */
trait ManagesGrantDates
{
    public bool $showGrantDatesModal = false;

    public string $grantDateBegin = '';

    public string $grantDateEnd = '';

    /**
     * Deep link: opens the dialog once, then clears itself.
     */
    #[Url(as: 'grant_dates', except: false)]
    public bool $grantDatesLink = false;

    public function openGrantDatesFromLink(): void
    {
        if ($this->grantDatesLink && $this->canSetGrantDates()) {
            $this->openGrantDatesModal();
        }

        $this->grantDatesLink = false;
    }

    public function openGrantDatesModal(): void
    {
        $proposal = $this->grantProposal();

        Gate::authorize('update', $proposal);

        $this->resetValidation();
        $this->grantDateBegin = ($proposal->actual_date_begin ?? $proposal->planned_date_begin)?->toDateString() ?? '';
        $this->grantDateEnd = $proposal->planned_date_end?->toDateString() ?? '';
        $this->showGrantDatesModal = true;
    }

    public function closeGrantDatesModal(): void
    {
        $this->showGrantDatesModal = false;
        $this->resetValidation();
    }

    public function saveGrantDates(): void
    {
        /** @var User $user */
        $user = auth()->user();

        $this->resetValidation();

        app(SetGrantDates::class)->handle($this->grantProposal(), [
            'begin' => $this->grantDateBegin,
            'end' => $this->grantDateEnd,
        ], $user);

        $this->closeGrantDatesModal();
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('proposals.grant_dates_saved'));
    }

    /**
     * Whether the viewer may set this proposal's grant dates now: the donor
     * approved it, its dates are missing and they may update it.
     */
    public function canSetGrantDates(): bool
    {
        $proposal = $this->grantProposal();

        return $proposal->status->isApprovedByDonor()
            && $proposal->lacksGrantDates()
            && Gate::allows('update', $proposal);
    }

    private function grantProposal(): Proposal
    {
        return Proposal::query()
            ->select(['id', 'status', 'planned_date_begin', 'planned_date_end', 'actual_date_begin', 'actual_date_end', 'extended_date_end'])
            ->findOrFail($this->proposalId);
    }
}
