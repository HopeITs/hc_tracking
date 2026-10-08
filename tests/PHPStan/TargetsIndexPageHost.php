<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\ChecksFinanceVisibility;
use App\Livewire\Concerns\Targets\FiltersTargets;
use App\Livewire\Concerns\Targets\ManagesTargetForm;
use App\Livewire\Concerns\Targets\NarrowsTargetFormOptions;
use App\Livewire\Concerns\Targets\ReadsTargets;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stands in for targets/index, with every trait that page uses.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the targets traits are never
 * analysed. It declares the page's own #[Url] filter properties the traits
 * read. Never instantiated.
 */
abstract class TargetsIndexPageHost extends Component
{
    use ChecksFinanceVisibility;
    use FiltersTargets;
    use ManagesTargetForm;
    use NarrowsTargetFormOptions;
    use ReadsTargets;
    use WithPagination;

    public string $search = '';

    public string $sourceType = '';

    public string $project = '';

    public string $period = '';

    public string $status = '';

    public string $donor = '';

    public string $program = '';

    public string $year = '';

    public string $indicator = '';

    public string $result = '';
}
