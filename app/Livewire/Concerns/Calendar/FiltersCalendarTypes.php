<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Calendar;

use App\Models\MonthlyReport;

/**
 * The type filter on calendar/index: which of the eight item types are
 * switched on, whether the viewer may see each one at all, and the one type
 * the calendar is focused on when only one is on.
 *
 * The switched-on types travel in the #[Url] shownTypes property (a comma
 * list, '' for all, 'none' for none), so a reload or a shared link keeps the
 * filter. The show* toggles stay the working state; mount reads the URL into
 * them and every response writes them back.
 *
 * Used by calendar/index only. The page provides the #[Url] shownTypes
 * property; jumpToNearest() comes from
 * FindsFocusedTypeDates, canViewProposals(), canViewTransfers() and
 * canViewAgreements() from ChecksFinanceVisibility.
 *
 * @property string $shownTypes
 */
trait FiltersCalendarTypes
{
    /**
     * Every calendar type, in the order the filter menu lists them.
     *
     * @var array<int, string>
     */
    protected const array CALENDAR_TYPES = ['task', 'report', 'monthly_report', 'proposal', 'transfer', 'donor', 'project'];

    public bool $showTasks = true;

    public bool $showReports = true;

    public bool $showMonthlyReports = true;

    public bool $showProposals = true;

    public bool $showTransfers = true;

    public bool $showDonors = true;

    public bool $showProjects = true;

    public bool $showTypeFilterMenu = false;

    /**
     * Applies the types named in the URL. Unknown names are ignored, so a
     * hand-edited link cannot switch anything else.
     */
    public function mountFiltersCalendarTypes(): void
    {
        if ($this->shownTypes === '') {
            return;
        }

        $named = explode(',', $this->shownTypes);

        foreach (self::CALENDAR_TYPES as $type) {
            $this->{$this->typeProperty($type)} = in_array($type, $named, true);
        }
    }

    /**
     * Writes the switched-on types back to the URL property before every
     * response, whichever action changed them.
     */
    public function dehydrateFiltersCalendarTypes(): void
    {
        $shown = array_values(array_filter(self::CALENDAR_TYPES, fn (string $type): bool => $this->{$this->typeProperty($type)}));

        $this->shownTypes = match (count($shown)) {
            count(self::CALENDAR_TYPES) => '',
            0 => 'none',
            default => implode(',', $shown),
        };
    }

    /**
     * Flips one type's visibility. The menu stays open across picks — the
     * caller unchecking five of eight boxes to narrow to one type would
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

        foreach (self::CALENDAR_TYPES as $calendarType) {
            $this->{$this->typeProperty($calendarType)} = $calendarType === $type;
        }

        $this->showTypeFilterMenu = false;

        $this->jumpToNearest($type);
    }

    /**
     * "Clear filter" switches every type back on. The people picked in the
     * responsible filter stay: the button sits on the type banner and clears
     * only what it names.
     */
    public function resetFilters(): void
    {
        foreach (self::CALENDAR_TYPES as $type) {
            $this->{$this->typeProperty($type)} = true;
        }
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
     * An unknown $type (toggleType() and focusOnlyType() are browser-callable)
     * is never visible, so both return before touching any toggle.
     */
    protected function canSeeType(string $type): bool
    {
        return match ($type) {
            'proposal' => $this->canViewProposals(),
            'transfer' => $this->canViewTransfers(),
            'donor' => $this->canViewDonors() || $this->canViewAgreements(),
            'monthly_report' => auth()->user()?->can('viewAny', MonthlyReport::class) ?? false,
            'task', 'report', 'project' => true,
            default => false,
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
            'task' => 'showTasks',
            'report' => 'showReports',
            'monthly_report' => 'showMonthlyReports',
            'proposal' => 'showProposals',
            'transfer' => 'showTransfers',
            'donor' => 'showDonors',
            'project' => 'showProjects',
        };
    }

    /**
     * The single type key ('task', 'report', …) when exactly one of the
     * seven show* toggles is on, otherwise null. Drives the "focused" banner,
     * grid highlighting and related-dates panel — all three stay off while
     * more than one type (or none) is visible, since "filtered to one thing"
     * only means something when it is actually just one thing.
     */
    protected function focusedType(): ?string
    {
        $active = array_values(array_filter(self::CALENDAR_TYPES, fn (string $type): bool => $this->isTypeShown($type)));

        return count($active) === 1 ? $active[0] : null;
    }
}
