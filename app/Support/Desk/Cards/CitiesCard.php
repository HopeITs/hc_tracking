<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Models\HrCity;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Cities in card form: where the work happens, busiest city first.
 */
final class CitiesCard extends PageCard
{
    public function route(): string
    {
        return 'admin.cities.index';
    }

    public function figures(User $user): array
    {
        $projects = $this->projectsByCity();

        return [
            $this->figure(__('desk-workspace.card.figure.active_cities'), HrCity::query()->where('is_active', true)->count(), 'good'),
            $this->figure(__('desk-workspace.card.figure.cities_with_work'), $projects->filter()->count()),
            $this->figure(__('desk-workspace.card.figure.projects'), $projects->sum()),
        ];
    }

    public function hero(User $user): ?array
    {
        $projects = $this->projectsByCity();
        $top = collect($projects)->filter()->sortDesc()->take(3);
        $names = HrCity::query()->whereIn('id', $top->keys())->get(['id', 'name_en', 'name_ar'])->keyBy('id');
        $most = max(1, (int) $top->max());

        return [
            'kind' => 'podium',
            'big' => $this->number((int) collect($projects)->filter()->count()),
            'caption' => (string) __('desk-workspace.card.figure.cities_with_work'),
            'tone' => 'active',
            'items' => $top->map(fn (int|string $count, int|string $id): array => [
                'label' => (string) ($names[$id]?->name ?? '—'),
                'value' => $this->number((int) $count),
                'share' => $this->share((int) $count, $most),
                'tone' => 'active',
            ])->values()->all(),
        ];
    }

    public function layout(): string
    {
        return 'rank';
    }

    protected function records(User $user, string $search): Collection
    {
        $projects = $this->projectsByCity();
        $most = (int) (collect($projects)->max() ?? 0);
        $place = 0;

        return HrCity::query()->select(['id', 'name_en', 'name_ar', 'is_active'])->orderBy(HrCity::nameColumn())->get()
            ->sortByDesc(fn (HrCity $city): int => (int) ($projects[$city->id] ?? 0))
            ->map(function (HrCity $city) use ($projects, $most, &$place): array {
                $count = (int) ($projects[$city->id] ?? 0);
                $place++;

                return $this->shaped(
                    $this->row(
                        'ct-'.$city->id,
                        (string) $city->name,
                        trans_choice('desk-workspace.card.projects', $count, ['count' => $count]),
                        $city->is_active ? null : $this->activeBadge(false),
                        route($this->route()),
                        $city->name_en.' '.$city->name_ar,
                    ),
                    lead: ['kind' => 'rank', 'big' => (string) $place, 'tone' => $place <= 3 && $count > 0 ? 'active' : 'neutral'],
                    end: ['big' => $this->number($count)],
                    bar: ['to' => $this->share($count, $most), 'tone' => 'active'],
                );
            })->values();
    }

    /**
     * @return Collection<int, int> city id => projects there
     */
    private function projectsByCity(): Collection
    {
        return Project::query()->toBase()->whereNotNull('city_id')->selectRaw('city_id, count(*) as total')->groupBy('city_id')->pluck('total', 'city_id')
            ->map(fn (mixed $total): int => (int) $total);
    }
}
