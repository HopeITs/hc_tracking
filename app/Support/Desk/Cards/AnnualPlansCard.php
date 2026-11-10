<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Models\AnnualPlan;
use App\Models\Program;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Annual plans in card form: this year's plans first, then the years before,
 * and which programs still have no plan this year.
 */
final class AnnualPlansCard extends PageCard
{
    public function route(): string
    {
        return 'annual-plans.index';
    }

    public function figures(User $user): array
    {
        $year = now()->year;
        $planned = AnnualPlan::query()->where('year', $year)->distinct()->count('program_id');

        return [
            $this->figure(__('desk-workspace.card.figure.plans_year', ['year' => $year]), AnnualPlan::query()->where('year', $year)->count(), 'good'),
            $this->figure(__('desk-workspace.card.figure.no_plan_yet'), max(0, Program::query()->count() - $planned), 'warn'),
            $this->figure(__('desk-workspace.card.figure.plans_year', ['year' => $year + 1]), AnnualPlan::query()->where('year', $year + 1)->count(), 'active'),
        ];
    }

    public function hero(User $user): ?array
    {
        $year = now()->year;
        $years = range($year - 2, $year + 1);
        $counts = AnnualPlan::query()->whereIn('year', $years)->toBase()->selectRaw('year, count(*) as total')->groupBy('year')->pluck('total', 'year');
        $most = max(1, (int) $counts->max());

        return [
            'kind' => 'years',
            'big' => $this->number((int) ($counts[$year] ?? 0)),
            'caption' => (string) __('desk-workspace.card.figure.plans_year', ['year' => $year]),
            'tone' => 'good',
            'items' => array_map(fn (int $each): array => [
                'label' => (string) $each,
                'value' => $this->number((int) ($counts[$each] ?? 0)),
                'share' => $this->share((int) ($counts[$each] ?? 0), $most),
                'tone' => $each === $year ? 'good' : ($each > $year ? 'active' : 'neutral'),
                'on' => $each === $year,
            ], $years),
        ];
    }

    public function layout(): string
    {
        return 'agenda';
    }

    protected function records(User $user, string $search): Collection
    {
        $year = now()->year;

        return AnnualPlan::query()->with('program')->orderByDesc('year')->orderBy('id')->limit(self::RowLimit)->get()
            ->map(function (AnnualPlan $plan) use ($year): array {
                $badge = match (true) {
                    $plan->year === $year => ['label' => (string) __('desk-workspace.card.this_year'), 'tone' => 'good'],
                    $plan->year > $year => ['label' => (string) __('desk-workspace.card.upcoming'), 'tone' => 'active'],
                    default => ['label' => (string) __('desk-workspace.card.ended'), 'tone' => 'neutral'],
                };

                return $this->shaped(
                    $this->row('ap-'.$plan->id, (string) ($plan->program?->name ?? '—'), (string) __('desk-workspace.card.plan_for', ['year' => $plan->year]), $badge, route('annual-plans.show', $plan)),
                    lead: ['kind' => 'year', 'big' => (string) $plan->year, 'small' => (string) __('desk-workspace.card.plan_word'), 'tone' => $badge['tone']],
                );
            });
    }
}
