<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Results;

use Livewire\Attributes\Url;

/**
 * The three filters of the read-only results chain (T16): library indicator,
 * center (project) and donor, kept in the URL.
 *
 * Used by programs/show, annual-plans/show and the proposal Logical Framework
 * tab. The raw values go to App\Support\ResultsChain, which checks them against
 * the loaded sets (Rule::in) and treats an unknown value as "All"; the view
 * binds each picker's :value to the resolved filter the chain returns.
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
}
