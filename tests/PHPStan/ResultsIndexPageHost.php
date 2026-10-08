<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\Results\BuildsResultsFramework;
use App\Livewire\Concerns\Results\BuildsResultsTree;
use App\Livewire\Concerns\Results\ManagesResultForm;
use App\Livewire\Concerns\Results\SummarisesResults;
use Livewire\Component;

/**
 * Stands in for results/index, with every trait that page uses.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the results traits are never
 * analysed. It declares the page's own members the traits read. Never
 * instantiated.
 */
abstract class ResultsIndexPageHost extends Component
{
    use BuildsResultsFramework;
    use BuildsResultsTree;
    use ManagesResultForm;
    use SummarisesResults;

    public string $search = '';

    public string $program = '';

    public string $type = '';

    public string $focus = '';
}
