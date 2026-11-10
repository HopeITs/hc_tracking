<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Enums\ResultType;
use App\Models\Result;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The programs' results framework in card form: how many results sit at each
 * level, and the newest statements.
 */
final class ResultsCard extends PageCard
{
    private const array Tones = ['impact' => 'good', 'outcome' => 'active', 'output' => 'warn', 'activity' => 'neutral', 'cross-cutting' => 'neutral'];

    public function route(): string
    {
        return 'results.index';
    }

    public function figures(User $user): array
    {
        $counts = $this->counts();

        return [
            $this->figure(__('desk-workspace.card.figure.results'), array_sum($counts), 'active'),
            $this->figure(ResultType::Outcome->label(), $counts[ResultType::Outcome->value] ?? 0),
            $this->figure(ResultType::Output->label(), $counts[ResultType::Output->value] ?? 0),
        ];
    }

    public function breakdown(User $user): array
    {
        $counts = $this->counts();

        return $this->parts(array_map(fn (ResultType $type): array => [
            'label' => (string) $type->label(),
            'count' => (int) ($counts[$type->value] ?? 0),
            'tone' => self::Tones[$type->value] ?? 'neutral',
        ], ResultType::cases()));
    }

    public function hero(User $user): ?array
    {
        $counts = $this->counts();
        $most = max(1, (int) max($counts ?: [0]));

        return [
            'kind' => 'pyramid',
            'big' => $this->number(array_sum($counts)),
            'caption' => (string) __('desk-workspace.card.figure.results'),
            'tone' => 'active',
            'items' => array_map(fn (ResultType $type): array => [
                'label' => (string) $type->label(),
                'value' => $this->number((int) ($counts[$type->value] ?? 0)),
                'share' => max(46.0, $this->share((int) ($counts[$type->value] ?? 0), $most)),
                'tone' => self::Tones[$type->value] ?? 'neutral',
            ], ResultType::cases()),
        ];
    }

    public function layout(): string
    {
        return 'tagged';
    }

    protected function records(User $user, string $search): Collection
    {
        return Result::query()->programFramework()->with('program')->latest('id')->limit(self::RowLimit)->get()
            ->map(fn (Result $result): array => $this->row(
                'rs-'.$result->id,
                $result->statement,
                (string) ($result->program?->name ?? ''),
                ['label' => (string) $result->type->label(), 'tone' => self::Tones[$result->type->value] ?? 'neutral'],
                route('results.index'),
            ));
    }

    /**
     * @return array<string, int> type => results of that type in the programs' frameworks
     */
    private function counts(): array
    {
        return Result::query()->programFramework()->toBase()->selectRaw('type, count(*) as total')->groupBy('type')->pluck('total', 'type')
            ->map(fn (mixed $total): int => (int) $total)->all();
    }
}
