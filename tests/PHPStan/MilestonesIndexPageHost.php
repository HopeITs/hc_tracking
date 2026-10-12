<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\Milestones\ManagesMilestoneDirectoryForm;
use Livewire\Component;

/**
 * Stands in for milestones/index, with every trait that page uses.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the milestones trait is never
 * analysed. Never instantiated.
 */
abstract class MilestonesIndexPageHost extends Component
{
    use ManagesMilestoneDirectoryForm;
}
