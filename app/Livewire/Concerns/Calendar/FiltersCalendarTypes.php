<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Calendar;

/**
 * The type filter on calendar/index: which of the seven item types are
 * switched on, whether the viewer may see each one at all, and the one type
 * the calendar is focused on when only one is on.
 *
 * Used by calendar/index only. The page provides the #[Url]
 * filterEmployeeIds property; jumpToNearest() comes from
 * FindsFocusedTypeDates, canViewProposals(), canViewTransfers() and
 * canViewAgreements() from ChecksFinanceVisibility.
 *
 * @property array<int, string> $filterEmployeeIds
 */
trait FiltersCalendarTypes
{
    public bool $showMilestones = true;

    public bool $showTasks = true;

    public bool $showReports = true;

    public bool $showProposals = true;

    public bool $showTransfers = true;

    public bool $showDonors = true;

    public bool $showProjects = true;

    public bool $showTypeFilterMenu = false;

    /**
     * Flips one type's visibility. The menu stays open across picks — the
     * caller unchecking five of seven boxes to narrow to one type would
     * otherwise have to reopen the menu after every click.
     */
    public function toggleType(string $type): void
    {
        if (! $this->canSeeType($type)) {
            return;
        }

        $property = $this->typeProperty($type);
        $this->$property = ! $this->$property;

        $focused = $this->focusedType();

        if ($focused !== null) {
            $this->jumpToNearest($focused);
        }
    }

    /**
     * The one-click alternative to narrowing by unchecking every other
     * type: switches straight to showing only $type, closes the menu, and
     * jumps to its nearest occurrence.
     */
    public function focusOnlyType(string $type): void
    {
        if (! $this->canSeeType($type)) {
            return;
        }

        $this->showMilestones = $type === 'milestone';
        $this->showTasks = $type === 'task';
        $this->showReports = $type === 'report';
        $this->showProposals = $type === 'proposal';
        $this->showTransfers = $type === 'transfer';
        $this->showDonors = $type === 'donor';
        $this->showProjects = $type === 'project';
        $this->showTypeFilterMenu = false;

        $this->jumpToNearest($type);
    }

    public function resetFilters(): void
    {
        $this->showMilestones = true;
        $this->showTasks = true;
        $this->showReports = true;
        $this->showProposals = true;
        $this->showTransfers = true;
        $this->showDonors = true;
        $this->showProjects = true;
        $this->filterEmployeeIds = [];
    }

    /**
     * Whether the viewer may see donors and their follow-up dates.
     */
    protected function canViewDonors(): bool
    {
        return auth()->user()?->can('donors.view') ?? false;
    }

    /**
     * Whether the viewer may see any occurrence of $type. The 'donor' type
     * covers follow-ups (donors.view) and donor reports (funding-agreements.view),
     * so it is open when either half is; each half is still gated on its own.
     */
    protected function canSeeType(string $type): bool
    {
        return match ($type) {
            'proposal' => $this->canViewProposals(),
            'transfer' => $this->canViewTransfers(),
            'donor' => $this->canViewDonors() || $this->canViewAgreements(),
            default => true,
        };
    }

    /**
     * Whether $type is switched on AND permitted. The show* toggles are public
     * Livewire state a browser can set, so they never decide visibility alone.
     */
    protected function isTypeShown(string $type): bool
    {
        return $this->canSeeType($type) && $this->{$this->typeProperty($type)};
    }

    protected function typeProperty(string $type): string
    {
        return match ($type) {
            'milestone' => 'showMilestones',
            'task' => 'showTasks',
            'report' => 'showReports',
            'proposal' => 'showProposals',
            'transfer' => 'showTransfers',
            'donor' => 'showDonors',
            'project' => 'showProjects',
        };
    }

    /**
     * The single type key ('milestone', 'task', …) when exactly one of the
     * seven show* toggles is on, otherwise null. Drives the "focused" banner,
     * grid highlighting and related-dates panel — all three stay off while
     * more than one type (or none) is visible, since "filtered to one thing"
     * only means something when it is actually just one thing.
     */
    protected function focusedType(): ?string
    {
        $active = array_filter([
            'milestone' => $this->isTypeShown('milestone'),
            'task' => $this->isTypeShown('task'),
            'report' => $this->isTypeShown('report'),
            'proposal' => $this->isTypeShown('proposal'),
            'transfer' => $this->isTypeShown('transfer'),
            'donor' => $this->isTypeShown('donor'),
            'project' => $this->isTypeShown('project'),
        ]);

        return count($active) === 1 ? array_key_first($active) : null;
    }
}
