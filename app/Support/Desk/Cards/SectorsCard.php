<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Models\Sector;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Sectors in card form: the active list and how many projects each tags.
 */
final class SectorsCard extends PageCard
{
    public function route(): string
    {
        return 'admin.sectors.index';
    }

    public function figures(User $user): array
    {
        $active = Sector::query()->active()->count();

        return [
            $this->figure(__('desk-workspace.card.figure.offered'), $active, 'good'),
            $this->figure(__('desk-workspace.card.figure.tagged_projects'), Sector::query()->has('projects')->count()),
            $this->figure(__('desk-workspace.card.figure.inactive'), Sector::query()->count() - $active),
        ];
    }

    public function hero(User $user): ?array
    {
        $sectors = Sector::query()->active()->withCount('projects')->orderByDesc('projects_count')->limit(6)->get();
        $most = max(1, (int) $sectors->max('projects_count'));

        return [
            'kind' => 'bubbles',
            'big' => $this->number(Sector::query()->has('projects')->count()),
            'caption' => (string) __('desk-workspace.card.figure.tagged_projects'),
            'tone' => 'good',
            'items' => $sectors->map(fn (Sector $sector): array => [
                'label' => $sector->localizedName(),
                'value' => $this->number((int) $sector->projects_count),
                'share' => max(36.0, $this->share((int) $sector->projects_count, $most)),
                'tone' => $sector->projects_count > 0 ? 'good' : 'neutral',
            ])->all(),
        ];
    }

    public function layout(): string
    {
        return 'meter';
    }

    protected function records(User $user, string $search): Collection
    {
        $sectors = Sector::query()->ordered()->withCount('projects')->get();
        $most = (int) $sectors->max('projects_count');

        return $sectors->map(fn (Sector $sector): array => $this->shaped(
            $this->row(
                'sc-'.$sector->id,
                $sector->localizedName(),
                trans_choice('desk-workspace.card.projects', (int) $sector->projects_count, ['count' => (int) $sector->projects_count]),
                $sector->is_active ? null : $this->activeBadge(false),
                route($this->route()),
                $sector->name_en.' '.$sector->name_ar,
            ),
            end: ['big' => $this->number((int) $sector->projects_count)],
            bar: ['to' => $this->share((int) $sector->projects_count, $most), 'tone' => $sector->is_active ? 'good' : 'neutral'],
        ));
    }
}
