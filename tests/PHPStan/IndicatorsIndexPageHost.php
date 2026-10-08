<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\Indicators\BuildsIndicatorRows;
use App\Livewire\Concerns\Indicators\InspectsIndicatorChain;
use App\Livewire\Concerns\Indicators\ManagesIndicatorForm;
use App\Livewire\Concerns\Indicators\ManagesIndicatorReadings;
use App\Livewire\Concerns\Indicators\PresentsIndicators;
use App\Livewire\Concerns\Indicators\SummarisesIndicators;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stands in for indicators/index, with every trait that page uses.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the indicator traits are never
 * analysed. It declares the page's own members the traits read. Never
 * instantiated.
 */
abstract class IndicatorsIndexPageHost extends Component
{
    use BuildsIndicatorRows;
    use InspectsIndicatorChain;
    use ManagesIndicatorForm;
    use ManagesIndicatorReadings;
    use PresentsIndicators;
    use SummarisesIndicators;
    use WithPagination;

    public string $search = '';

    public string $result = '';

    public string $measure = '';

    public string $program = '';

    public string $rag = '';

    public string $focus = '';

    public string $view = 'cards';
}
