<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Enums\IndicatorMeasure;
use App\Models\Indicator;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Indicators in card form: how many are measured and how, and each one with
 * the result or project it measures; a row opens its inspector on the page.
 */
final class IndicatorsCard extends PageCard
{
    private const array Tones = ['numeric' => 'active', 'percentage' => 'good', 'average' => 'warn'];

    public function route(): string
    {
        return 'indicators.index';
    }

    public function figures(User $user): array
    {
        return [
            $this->figure(__('desk-workspace.card.figure.indicators'), Indicator::query()->count(), 'active'),
            $this->figure(__('desk-workspace.card.figure.with_target'), Indicator::query()->whereNotNull('target')->where('target', '!=', '')->count(), 'good'),
            $this->figure(__('desk-workspace.card.figure.tracking_only'), Indicator::query()->where('is_tracking_only', true)->count()),
        ];
    }

    public function breakdown(User $user): array
    {
        $counts = Indicator::query()->toBase()->selectRaw('measure, count(*) as total')->groupBy('measure')->pluck('total', 'measure');

        return $this->parts(array_map(fn (IndicatorMeasure $measure): array => [
            'label' => (string) $measure->label(),
            'count' => (int) ($counts[$measure->value] ?? 0),
            'tone' => self::Tones[$measure->value] ?? 'neutral',
        ], IndicatorMeasure::cases()));
    }

    public function hero(User $user): ?array
    {
        $all = Indicator::query()->count();
        $withTarget = Indicator::query()->whereNotNull('target')->where('target', '!=', '')->count();
        $share = $all > 0 ? round($withTarget / $all * 100) : 0;

        return [
            'kind' => 'gauge',
            'big' => $share.'%',
            'caption' => (string) __('desk-workspace.card.figure.with_target'),
            'share' => (float) $share,
            'tone' => $share >= 80 ? 'good' : ($share >= 50 ? 'warn' : 'critical'),
            'items' => [
                ['label' => (string) __('desk-workspace.card.figure.indicators'), 'value' => $this->number($all), 'tone' => 'active'],
                ['label' => (string) __('desk-workspace.card.figure.with_target'), 'value' => $this->number($withTarget), 'tone' => 'good'],
            ],
        ];
    }

    public function layout(): string
    {
        return 'tagged';
    }

    protected function records(User $user, string $search): Collection
    {
        return Indicator::query()->with(['result:id,statement', 'project:id,name'])->latest('id')->limit(self::RowLimit)->get()
            ->map(fn (Indicator $indicator): array => $this->row(
                'in-'.$indicator->id,
                collect([$indicator->code, $indicator->name])->filter()->implode(' · '),
                (string) ($indicator->project?->name ?? $indicator->result?->statement ?? ''),
                ['label' => (string) $indicator->measure->label(), 'tone' => self::Tones[$indicator->measure->value] ?? 'neutral'],
                route('indicators.index', ['inspect' => $indicator->id]),
            ));
    }
}
