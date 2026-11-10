<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Models\Strategy;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Strategies in card form: the one in force now, the ones before and after
 * it, and how many programs each carries.
 */
final class StrategiesCard extends PageCard
{
    public function route(): string
    {
        return 'strategies.index';
    }

    public function figures(User $user): array
    {
        $year = now()->year;
        $current = Strategy::query()->where('start_year', '<=', $year)->where('end_year', '>=', $year)->orderByDesc('start_year')->first();

        return array_values(array_filter([
            $current !== null ? $this->figure(__('desk-workspace.card.figure.in_force'), $current->start_year.'–'.$current->end_year, 'good') : null,
            $this->figure(__('desk-workspace.card.figure.strategies'), Strategy::query()->count()),
            $current !== null ? $this->figure(__('desk-workspace.card.figure.programs'), $current->programs()->count(), 'active') : null,
        ]));
    }

    public function hero(User $user): ?array
    {
        $year = now()->year;
        $strategies = Strategy::query()->orderBy('start_year')->get(['id', 'name', 'start_year', 'end_year']);

        if ($strategies->isEmpty()) {
            return null;
        }

        $first = (int) $strategies->min('start_year');
        $last = max((int) $strategies->max('end_year'), $year) + 1;
        $span = max(1, $last - $first);
        $at = fn (int|float $value): float => round(($value - $first) / $span * 100, 2);
        $current = $strategies->first(fn (Strategy $strategy): bool => $strategy->start_year <= $year && $strategy->end_year >= $year);
        $step = $span > 8 ? 2 : 1;

        return [
            'kind' => 'ruler',
            'big' => $current !== null ? $current->start_year.'–'.$current->end_year : (string) $strategies->count(),
            'caption' => (string) __($current !== null ? 'desk-workspace.card.figure.in_force' : 'desk-workspace.card.figure.strategies'),
            'tone' => 'good',
            'now' => $at($year + now()->dayOfYear / 366),
            'ticks' => collect(range($first, $last, $step))->map(fn (int $tick): array => ['label' => (string) $tick, 'at' => $at($tick)])->all(),
            'items' => $strategies->map(fn (Strategy $strategy): array => [
                'label' => $strategy->start_year.'–'.$strategy->end_year,
                'from' => $at($strategy->start_year),
                'to' => $at($strategy->end_year + 1),
                'tone' => match (true) {
                    $strategy->start_year > $year => 'active',
                    $strategy->end_year < $year => 'neutral',
                    default => 'good',
                },
            ])->all(),
        ];
    }

    public function layout(): string
    {
        return 'timeline';
    }

    protected function records(User $user, string $search): Collection
    {
        $year = now()->year;
        $strategies = Strategy::query()->withCount('programs')->orderByDesc('start_year')->limit(self::RowLimit)->get();
        $first = (int) ($strategies->min('start_year') ?? $year);
        $last = max((int) ($strategies->max('end_year') ?? $year), $year);
        $span = max(1, $last - $first + 1);
        $at = fn (int|float $value): float => ($value - $first) / $span * 100;

        return $strategies->map(function (Strategy $strategy) use ($year, $at): array {
            $tone = match (true) {
                $strategy->start_year > $year => 'active',
                $strategy->end_year < $year => 'neutral',
                default => 'good',
            };

            return $this->shaped(
                $this->row(
                    'st-'.$strategy->id,
                    $strategy->name,
                    $strategy->start_year.'–'.$strategy->end_year.' · '.trans_choice('desk-workspace.card.programs', (int) $strategy->programs_count, ['count' => (int) $strategy->programs_count]),
                    match ($tone) {
                        'active' => ['label' => (string) __('desk-workspace.card.upcoming'), 'tone' => 'active'],
                        'neutral' => ['label' => (string) __('desk-workspace.card.ended'), 'tone' => 'neutral'],
                        default => ['label' => (string) __('desk-workspace.card.in_force'), 'tone' => 'good'],
                    },
                    route('strategies.show', $strategy),
                ),
                bar: ['from' => $at($strategy->start_year), 'to' => $at($strategy->end_year + 1), 'now' => $at($year + (now()->dayOfYear / 366)), 'tone' => $tone],
            );
        });
    }
}
