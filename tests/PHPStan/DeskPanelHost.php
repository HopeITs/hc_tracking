<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\Dashboard\ChartsDashboard;
use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Dashboard\ReadsDashboardWork;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Livewire\Concerns\Desk\ReadsDeskAgenda;
use Livewire\Component;

/**
 * Stands in for the components/desk/panels/⚡*.blade.php panels, which PHPStan
 * cannot read. IsDeskPanel provides the module checks the dashboard traits
 * call. Never instantiated.
 */
abstract class DeskPanelHost extends Component
{
    use ChartsDashboard;
    use IsDeskPanel;
    use ReadsDashboardAttention;
    use ReadsDashboardWork;
    use ReadsDeskAgenda;
}
