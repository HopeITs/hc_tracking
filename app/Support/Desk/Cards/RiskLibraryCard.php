<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Models\RiskLibrary;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The risk library in card form: the ready-made risks, their categories and
 * how often projects picked each one.
 */
final class RiskLibraryCard extends PageCard
{
    public function route(): string
    {
        return 'admin.risk-library.index';
    }

    public function figures(User $user): array
    {
        return [
            $this->figure(__('desk-workspace.card.figure.offered'), RiskLibrary::query()->active()->count(), 'good'),
            $this->figure(__('desk-workspace.card.figure.categories'), RiskLibrary::query()->active()->whereNotNull('category')->distinct()->count('category')),
            $this->figure(__('desk-workspace.card.figure.picked'), RiskLibrary::query()->has('risks')->count()),
        ];
    }

    public function hero(User $user): ?array
    {
        $categories = RiskLibrary::query()->active()->whereNotNull('category')->toBase()->selectRaw('category, count(*) as total')->groupBy('category')->orderByDesc('total')->limit(6)->pluck('total', 'category');

        return [
            'kind' => 'tags',
            'big' => $this->number(RiskLibrary::query()->active()->count()),
            'caption' => (string) __('desk-workspace.card.figure.offered'),
            'tone' => 'warn',
            'items' => $categories->map(fn (int|string $count, string $category): array => ['label' => $category, 'value' => $this->number((int) $count), 'tone' => 'warn'])->values()->all(),
        ];
    }

    public function layout(): string
    {
        return 'meter';
    }

    protected function records(User $user, string $search): Collection
    {
        $arabic = app()->getLocale() === 'ar';
        $entries = RiskLibrary::query()->ordered()->withCount('risks')->get();
        $most = (int) $entries->max('risks_count');

        return $entries->map(fn (RiskLibrary $entry): array => $this->shaped(
            $this->row(
                'rl-'.$entry->id,
                $arabic ? $entry->title_ar : $entry->title_en,
                collect([
                    $entry->category,
                    trans_choice('desk-workspace.card.picked_times', (int) $entry->risks_count, ['count' => (int) $entry->risks_count]),
                ])->filter()->implode(' · '),
                $entry->is_active ? null : $this->activeBadge(false),
                route($this->route()),
                $entry->title_en.' '.$entry->title_ar,
            ),
            end: ['big' => $this->number((int) $entry->risks_count)],
            bar: ['to' => $this->share((int) $entry->risks_count, $most), 'tone' => 'warn'],
        ));
    }
}
