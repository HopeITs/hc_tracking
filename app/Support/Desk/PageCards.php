<?php

declare(strict_types=1);

namespace App\Support\Desk;

use App\Models\User;
use App\Support\Desk\Cards\AccountsCard;
use App\Support\Desk\Cards\ActivityCard;
use App\Support\Desk\Cards\AgreementsCard;
use App\Support\Desk\Cards\AnnualPlansCard;
use App\Support\Desk\Cards\CitiesCard;
use App\Support\Desk\Cards\DonorReportsCard;
use App\Support\Desk\Cards\EvidenceTypesCard;
use App\Support\Desk\Cards\IndicatorsCard;
use App\Support\Desk\Cards\LibraryCard;
use App\Support\Desk\Cards\NarrativeQuestionsCard;
use App\Support\Desk\Cards\PageCard;
use App\Support\Desk\Cards\ProgramsCard;
use App\Support\Desk\Cards\ReportsCard;
use App\Support\Desk\Cards\ResultsCard;
use App\Support\Desk\Cards\RiskLibraryCard;
use App\Support\Desk\Cards\SectorsCard;
use App\Support\Desk\Cards\StrategiesCard;
use App\Support\Desk\Cards\UsersCard;

/**
 * The register pages that have a card of their own (App\Support\Desk\Cards):
 * every page the Desk has no hand-made panel for. A card is offered exactly
 * when its page is (Destinations::allows), so the two can never disagree.
 *
 * Desk item id: "p-<page slug>" (Destinations::slugOf).
 */
final class PageCards
{
    public const string Prefix = 'p-';

    /**
     * @var array<string, class-string<PageCard>> route => card
     */
    private const array Cards = [
        'strategies.index' => StrategiesCard::class,
        'programs.index' => ProgramsCard::class,
        'annual-plans.index' => AnnualPlansCard::class,
        'results.index' => ResultsCard::class,
        'indicator-library.index' => LibraryCard::class,
        'indicators.index' => IndicatorsCard::class,
        'reports.index' => ReportsCard::class,
        'donor-reports.index' => DonorReportsCard::class,
        'funding-agreements.index' => AgreementsCard::class,
        'admin.accounts.index' => AccountsCard::class,
        'admin.users.index' => UsersCard::class,
        'activity.index' => ActivityCard::class,
        'admin.cities.index' => CitiesCard::class,
        'admin.sectors.index' => SectorsCard::class,
        'admin.risk-library.index' => RiskLibraryCard::class,
        'admin.evidence-types.index' => EvidenceTypesCard::class,
        'admin.narrative-questions.index' => NarrativeQuestionsCard::class,
    ];

    /**
     * Each page's icon in the Desk's own set (components/desk/icon).
     *
     * @var array<string, string>
     */
    private const array Icons = [
        'strategies.index' => 'chart', 'programs.index' => 'folder', 'annual-plans.index' => 'cal', 'results.index' => 'chart',
        'indicator-library.index' => 'file', 'indicators.index' => 'chart', 'reports.index' => 'file', 'donor-reports.index' => 'file',
        'funding-agreements.index' => 'coins', 'admin.accounts.index' => 'coins', 'admin.users.index' => 'users', 'activity.index' => 'undo',
        'admin.cities.index' => 'map', 'admin.sectors.index' => 'grid', 'admin.risk-library.index' => 'info', 'admin.evidence-types.index' => 'file',
        'admin.narrative-questions.index' => 'info',
    ];

    public function __construct(private readonly Destinations $destinations) {}

    /**
     * Every card route, in library order.
     *
     * @return list<string>
     */
    public function routes(): array
    {
        return array_keys(self::Cards);
    }

    public static function idFor(string $route): string
    {
        return self::Prefix.Destinations::slugOf($route);
    }

    /**
     * The route a card id stands for; null for an id that is no card.
     */
    public function routeOf(string $id): ?string
    {
        foreach (array_keys(self::Cards) as $route) {
            if (self::idFor($route) === $id) {
                return $route;
            }
        }

        return null;
    }

    public function has(string $route): bool
    {
        return isset(self::Cards[$route]);
    }

    /**
     * The card for a page; null when the page has none.
     */
    public function for(string $route): ?PageCard
    {
        return isset(self::Cards[$route]) ? app(self::Cards[$route]) : null;
    }

    /**
     * The card for an id, if the user may open its page.
     */
    public function forUser(User $user, string $id): ?PageCard
    {
        $route = $this->routeOf($id);

        return $route !== null && $this->allows($user, $route) ? $this->for($route) : null;
    }

    public function allows(User $user, string $route): bool
    {
        return $this->has($route) && $this->destinations->allows($user, $route);
    }

    public function iconOf(string $route): string
    {
        return self::Icons[$route] ?? 'grid';
    }

    public static function isCard(string $id): bool
    {
        return str_starts_with($id, self::Prefix);
    }
}
