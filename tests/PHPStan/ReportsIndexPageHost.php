<?php

declare(strict_types=1);

namespace Tests\PHPStan;

use App\Livewire\Concerns\ManagesReportForm;
use App\Livewire\Concerns\Reports\ReadsReportPortfolio;
use App\Models\Report;
use Illuminate\Database\Eloquent\Builder;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Stands in for reports/index, with every trait that page uses.
 *
 * The real page is a single-file Livewire component inside Blade, which
 * PHPStan cannot read, so without this class the portfolio trait is never
 * analysed. It declares the page's own members the traits read: the #[Url]
 * properties and the query helpers shared with the page's paginated list.
 * Never instantiated.
 */
abstract class ReportsIndexPageHost extends Component
{
    use ManagesReportForm;
    use ReadsReportPortfolio;
    use WithPagination;

    public string $view = 'list';

    public string $target = '';

    /**
     * @return Builder<Report>
     */
    private function scopedReports(): Builder
    {
        return Report::query();
    }

    /**
     * @return Builder<Report>
     */
    private function visibleReports(): Builder
    {
        return $this->scopedReports();
    }

    /**
     * @return array<int, mixed>
     */
    private function targetRelations(): array
    {
        return [];
    }

    protected function reportSaved(Report $report, bool $isCreate): void {}

    protected function reportDeleted(): void {}
}
