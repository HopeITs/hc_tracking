<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\Dashboard\ChartsDashboard;
use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Dashboard\ReadsDashboardWork;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Component;

/**
 * Stands in for dashboard/index, with every trait that page uses.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the dashboard traits are never
 * analysed. It declares the three module-access methods the page itself owns
 * and the traits call. Never instantiated.
 */
abstract class DashboardIndexPageHost extends Component
{
    use ChartsDashboard;
    use ReadsDashboardAttention;
    use ReadsDashboardWork;

    /**
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    abstract protected function visible(Builder $query, string $module): Builder;

    abstract protected function canView(string $module): bool;

    abstract protected function canViewFundingPosition(): bool;
}
