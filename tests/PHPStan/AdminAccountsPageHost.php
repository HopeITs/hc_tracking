<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\Admin\ExportsAccountRegister;
use App\Livewire\Concerns\Admin\ManagesAccountForm;
use App\Livewire\Concerns\Admin\ReadsAccountRegister;
use App\Livewire\Concerns\FiltersFinanceRegister;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stands in for admin/accounts, with every trait that page uses.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the accounts register traits are
 * never analysed. It declares the page's own members the traits read. Never
 * instantiated.
 */
abstract class AdminAccountsPageHost extends Component
{
    use ExportsAccountRegister;
    use FiltersFinanceRegister;
    use ManagesAccountForm;
    use ReadsAccountRegister;
    use WithPagination;

    /** @var list<string> */
    public const FLAGS = ['has_transfers', 'no_transfers', 'in_transit'];

    public string $search = '';

    public string $state = '';

    public string $currencyFilter = '';

    public string $countryFilter = '';

    public string $flag = '';
}
