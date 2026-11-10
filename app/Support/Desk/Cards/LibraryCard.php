<?php

declare(strict_types=1);

namespace App\Support\Desk\Cards;

use App\Enums\IndicatorMeasure;
use App\Models\LibraryIndicator;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * The indicator library in card form: the ready indicators by how they
 * measure, and the list by code.
 */
final class LibraryCard extends PageCard
{
    private const array Tones = ['numeric' => 'active', 'percentage' => 'good', 'average' => 'warn'];

    public function route(): string
    {
        return 'indicator-library.index';
    }

    public function figures(User $user): array
    {
        return [
            $this->figure(__('desk-workspace.card.figure.in_library'), LibraryIndicator::query()->active()->count(), 'good'),
            $this->figure(__('desk-workspace.card.figure.disaggregated'), LibraryIndicator::query()->active()->where('is_disaggregated', true)->count()),
            $this->figure(__('desk-workspace.card.figure.in_use'), LibraryIndicator::query()->active()->has('indicators')->count(), 'active'),
        ];
    }

    public function breakdown(User $user): array
    {
        $counts = LibraryIndicator::query()->active()->toBase()->selectRaw('measure, count(*) as total')->groupBy('measure')->pluck('total', 'measure');

        return $this->parts(array_map(fn (IndicatorMeasure $measure): array => [
            'label' => (string) $measure->label(),
            'count' => (int) ($counts[$measure->value] ?? 0),
            'tone' => self::Tones[$measure->value] ?? 'neutral',
        ], IndicatorMeasure::cases()));
    }

    public function hero(User $user): ?array
    {
        $counts = LibraryIndicator::query()->active()->toBase()->selectRaw('measure, count(*) as total')->groupBy('measure')->pluck('total', 'measure');

        return [
            'kind' => 'tally',
            'big' => $this->number((int) $counts->sum()),
            'caption' => (string) __('desk-workspace.card.figure.in_library'),
            'tone' => 'good',
            'items' => array_map(fn (IndicatorMeasure $measure): array => [
                'label' => (string) $measure->label(),
                'value' => $this->number((int) ($counts[$measure->value] ?? 0)),
                'tone' => self::Tones[$measure->value] ?? 'neutral',
            ], IndicatorMeasure::cases()),
        ];
    }

    public function layout(): string
    {
        return 'coded';
    }

    protected function records(User $user, string $search): Collection
    {
        return LibraryIndicator::query()->active()->orderBy('code')->limit(self::RowLimit)->get()
            ->map(fn (LibraryIndicator $indicator): array => $this->shaped(
                $this->row(
                    'li-'.$indicator->id,
                    $indicator->localizedName(),
                    collect([$indicator->measure->label(), $indicator->frequency->label()])->filter()->implode(' · '),
                    null,
                    route('indicator-library.index'),
                    $indicator->code.' '.$indicator->name_en.' '.$indicator->name_ar,
                ),
                lead: ['kind' => 'code', 'big' => (string) $indicator->code],
            ));
    }
}
