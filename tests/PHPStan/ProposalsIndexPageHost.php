<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\ChecksFinanceVisibility;
use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Livewire\Concerns\Proposals\ChartsProposalPipeline;
use App\Livewire\Concerns\Proposals\ExportsProposalRegister;
use App\Livewire\Concerns\Proposals\ManagesProposalForm;
use App\Livewire\Concerns\Proposals\ManagesProposalStages;
use App\Livewire\Concerns\Proposals\ReadsProposalRegister;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stands in for proposals/index, with every trait that page uses.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the proposal register traits
 * are never analysed. It declares the page's own members the traits read.
 * Never instantiated.
 */
abstract class ProposalsIndexPageHost extends Component
{
    use ChartsProposalPipeline;
    use ChecksFinanceVisibility;
    use ExportsProposalRegister;
    use FiltersFinanceRegister;
    use ManagesProposalForm;
    use ManagesProposalStages;
    use ReadsProposalRegister;
    use WithPagination;

    /** @var list<string> */
    public const FLAGS = ['no_agreement', 'overdue', 'report_overdue'];

    public string $search = '';

    public string $status = '';

    public string $phase = '';

    public string $donor = '';

    public string $currency = '';

    public string $flag = '';
}
