<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

/**
 * Whether the viewer may see the money behind a page. Money is skipped
 * server-side, not only hidden in Blade (.ai/rules/pages.md), so every page
 * asks the same three questions the same way.
 *
 * Used by calendar, donors, projects/show, proposals (index and show),
 * targets and timeline. The page provides nothing; it only calls the methods.
 */
trait ChecksFinanceVisibility
{
    /**
     * Whether the viewer may see funding agreements and the budgets on them.
     */
    protected function canViewAgreements(): bool
    {
        return auth()->user()?->can('funding-agreements.view') ?? false;
    }

    /**
     * Whether the viewer may see transfers and the amounts sent and received.
     */
    protected function canViewTransfers(): bool
    {
        return auth()->user()?->can('transfers.view') ?? false;
    }

    /**
     * Whether the viewer may open proposals.
     */
    protected function canViewProposals(): bool
    {
        return auth()->user()?->can('proposals.view') ?? false;
    }
}
