<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\Reports\BuildsMatrixActivities;
use App\Livewire\Concerns\Reports\BuildsMatrixBreakdowns;
use App\Livewire\Concerns\Reports\BuildsMonthlyMatrix;
use App\Livewire\Concerns\Reports\ExportsMonthlyMatrix;
use App\Livewire\Concerns\Reports\GroupsMonthlyMatrix;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stands in for reports/monthly-matrix, with every trait that page uses.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the matrix traits are never
 * analysed. It declares the page's own #[Url] and locked properties the
 * traits read. Never instantiated.
 */
abstract class MonthlyMatrixPageHost extends Component
{
    use BuildsMatrixActivities;
    use BuildsMatrixBreakdowns;
    use BuildsMonthlyMatrix;
    use ExportsMonthlyMatrix;
    use GroupsMonthlyMatrix;
    use WithPagination;

    public string $proposal = '';

    public string $donor = '';

    public string $center = '';

    public string $city = '';

    public string $program = '';

    public string $indicator = '';

    public string $pillar = '';

    public string $from = '';

    public string $to = '';

    public string $year = '';

    public bool $mine = false;

    public string $view = 'rows';

    public string $tab = 'matrix';

    public bool $pwd = false;

    /** @var list<string> */
    public array $filterOrder = [];
}
