<?php

use App\Livewire\Concerns\Dashboard\ChartsDashboard;
use App\Livewire\Concerns\Dashboard\ReadsDashboardAttention;
use App\Livewire\Concerns\Dashboard\ReadsDashboardWork;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.dashboard')] class extends Component {
    use ChartsDashboard;
    use ReadsDashboardAttention;
    use ReadsDashboardWork;

    /**
     * Keep a query empty unless the viewer may view its module, so the
     * dashboard never shows figures or rows from a module they cannot open.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @return Builder<TModel>
     */
    private function visible(Builder $query, string $module): Builder
    {
        return $this->canView($module) ? $query : $query->whereRaw('1 = 0');
    }

    /**
     * Whether the viewer may open a module, so its sections, tiles and links
     * render (and its queries run) only for someone who can follow them.
     */
    protected function canView(string $module): bool
    {
        return auth()->user()?->can("{$module}.view") === true;
    }

    /**
     * Whether the funding position may be shown: it sets what agreements
     * committed against what transfers received, so it needs both modules.
     */
    protected function canViewFundingPosition(): bool
    {
        return $this->canView('funding-agreements') && $this->canView('transfers');
    }
};
?>

@php
    $counts = $this->attentionCounts;
    $shown = $this->attentionShown;
    $myWork = $this->myWork;
    $cardClasses = 'rounded-xl border border-secondary-soft bg-white shadow-card dark:border-transparent dark:bg-surface-1 dark:shadow-raised';
    $iconTone = fn (string $tone): string => match ($tone) {
        'critical' => 'bg-status-critical-soft text-primary dark:bg-status-critical-soft dark:text-status-critical',
        'warn' => 'bg-status-warn-soft text-status-warn',
        'funding' => 'bg-funding-soft text-funding',
        'good' => 'bg-status-good-soft text-status-good',
        default => 'bg-secondary-soft text-secondary-dark dark:bg-surface-2 dark:text-ink',
    };
    // Figures sit on the card ground: tone tokens only reach contrast there in
    // light mode, so dark mode keeps figures white and lets the icon chip carry tone.
    $figureTone = fn (string $tone): string => match ($tone) {
        'critical' => 'text-primary dark:text-ink',
        'warn' => 'text-status-warn dark:text-ink',
        'funding' => 'text-funding dark:text-ink',
        default => 'text-secondary-dark dark:text-ink',
    };
    $canViewProposals = $this->canView('proposals');
    $canViewTransfers = $this->canView('transfers');
    $canViewFundingPosition = $this->canViewFundingPosition();
    $focusTabs = [
        'all' => __('dashboard.all'),
        ...($counts['reviews'] > 0 ? ['reviews' => __('dashboard.reviews')] : []),
        'delivery' => __('dashboard.delivery'),
        ...($canViewTransfers ? ['funding' => __('dashboard.funding')] : []),
        'risks' => __('dashboard.risks'),
    ];
    // The chart row shares twelve columns between the panels this viewer may see.
    $chartPanels = 1 + (int) $canViewProposals + (int) $canViewTransfers;
    $chartSpan = match ($chartPanels) {
        3 => '@5xl/dashboard:col-span-4',
        2 => '@5xl/dashboard:col-span-6',
        default => '@5xl/dashboard:col-span-12',
    };
    $receiptsSpan = $chartPanels === 3 ? '@3xl/dashboard:col-span-2 @5xl/dashboard:col-span-4' : $chartSpan;
    $hiddenCounts = collect($focusTabs)->map(fn (string $label, string $key): int => max(0, $counts[$key] - $shown[$key]))->all();
    $receipts = $this->receiptsChart;
@endphp

<section class="@container/dashboard flex w-full flex-1 flex-col gap-5 pb-10" aria-labelledby="dashboard-heading" data-dashboard>
    @include('pages.dashboard._index-greeting')

    @include('pages.dashboard._index-my-team')

    @include('pages.dashboard._index-row-one')

    @include('pages.dashboard._index-row-two')

    @include('pages.dashboard._index-row-three')
</section>
