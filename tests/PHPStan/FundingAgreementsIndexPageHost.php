<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\FiltersFinanceRegister;
use App\Livewire\Concerns\FundingAgreements\ChartsAgreementCalendar;
use App\Livewire\Concerns\FundingAgreements\ExportsAgreementRegister;
use App\Livewire\Concerns\FundingAgreements\ReadsAgreementRegister;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stands in for funding-agreements/index, with every trait that page gained
 * when it was split (its shared agreement form trait is analysed through
 * AgreementFormHost).
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the agreement register traits
 * are never analysed. It declares the page's own members the traits read.
 * Never instantiated.
 */
abstract class FundingAgreementsIndexPageHost extends Component
{
    use ChartsAgreementCalendar;
    use ExportsAgreementRegister;
    use FiltersFinanceRegister;
    use ReadsAgreementRegister;
    use WithPagination;

    /** @var list<string> */
    public const STAGES = ['overdue', 'failed', 'upcoming', 'unscheduled', 'in_transit', 'received'];

    /** @var list<string> */
    public const FLAGS = ['report_overdue', 'net_missing', 'other_currency', 'no_tranches'];

    public string $search = '';

    public string $donor = '';

    public string $currency = '';

    public string $stage = '';

    public string $flag = '';

    public string $min = '';

    public string $max = '';
}
