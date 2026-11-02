<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Desk;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;

/**
 * Past the deadline: every item of the viewer's agenda (ReadsDeskAgenda:
 * their tasks, milestones, monthly and indicator reports, donor reports and
 * the pending tranches they may see) whose date has passed, most late
 * first. The same rules as Coming up, read backwards, so both cards always
 * agree on what is the viewer's. The host also uses ReadsDeskAgenda,
 * ReadsDashboardAttention and IsDeskPanel.
 *
 * @property-read Collection<int, array{key: string, kind: string, date: CarbonImmutable, title: string, context: string, project: string|null, href: string, daysLate: int}> $overdueItems  #[Computed] overdueItems()
 */
trait ReadsDeskOverdue
{
    /**
     * How far back the card looks for items still open.
     */
    private const int OverdueLookBackYears = 10;

    /**
     * @return Collection<int, array{key: string, kind: string, date: CarbonImmutable, title: string, context: string, project: string|null, href: string, daysLate: int}>
     */
    #[Computed]
    public function overdueItems(): Collection
    {
        if (auth()->user() === null) {
            return new Collection;
        }

        $today = CarbonImmutable::today();

        return $this->agendaBetween($today->subYears(self::OverdueLookBackYears), $today->subDay())
            ->filter(fn (array $item): bool => $item['date']->lt($today))
            ->map(fn (array $item): array => [...$item, 'daysLate' => max(1, (int) $item['date']->startOfDay()->diffInDays($today))])
            ->sortByDesc('daysLate')
            ->values();
    }
}
