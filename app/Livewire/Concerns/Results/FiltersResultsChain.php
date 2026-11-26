<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Results;

use App\Models\ProposalLocation;
use App\Support\Locations\ProjectSuccessor;
use Livewire\Attributes\Url;

/**
 * The three filters of the read-only results chain (T16): library indicator,
 * center and donor, kept in the URL.
 *
 * Used by programs/show, annual-plans/show and the proposal Logical Framework
 * tab. The raw values go to App\Support\ResultsChain, which checks them against
 * the loaded sets (Rule::in) and treats an unknown value as "All"; the view
 * binds each picker's :value to the resolved filter the chain returns.
 *
 * Y-16 (D-93): the center filter holds a **location row** id. A saved link
 * with a project id is answered by the row that took that project over (D-94),
 * translated once by adoptLegacyChainCenter(); an unknown value falls back to
 * "All" as before. Y-18 drops the translation.
 */
trait FiltersResultsChain
{
    #[Url(as: 'chain_indicator', except: '')]
    public string $chainIndicator = '';

    #[Url(as: 'chain_center', except: '')]
    public string $chainCenter = '';

    #[Url(as: 'chain_donor', except: '')]
    public string $chainDonor = '';

    /**
     * @return array{indicator: string, center: string, donor: string}
     */
    protected function chainFilters(): array
    {
        return [
            'indicator' => $this->chainIndicator,
            'center' => $this->chainCenter,
            'donor' => $this->chainDonor,
        ];
    }

    public function resetChainFilters(): void
    {
        $this->reset('chainIndicator', 'chainCenter', 'chainDonor');
    }

    /**
     * Turn a `?chain_center=<project id>` from a saved link into the location
     * row that took that project over (Y-16, D-94).
     *
     * Called from the host's mount(). A value that is already a row id is
     * left alone, and one that matches nothing falls through to the chain's
     * own "unknown value means All".
     */
    protected function adoptLegacyChainCenter(): void
    {
        if ($this->chainCenter === '' || ! ctype_digit($this->chainCenter)) {
            return;
        }

        $id = (int) $this->chainCenter;

        if (ProposalLocation::query()->whereKey($id)->exists()) {
            return;
        }

        $row = app(ProjectSuccessor::class)->rowFor($id);

        if ($row !== null) {
            $this->chainCenter = (string) $row->id;
        }
    }
}
