<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Indicators;

use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Support\Ai\IndicatorForecast;
use Carbon\CarbonImmutable;
use Illuminate\Support\Collection as BaseCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The chain inspector on indicators/index: one indicator's every reading
 * and every target committed against it.
 *
 * Used by indicators/index only. The rows come from BuildsIndicatorRows;
 * closing the inspector also drops the inline reading delete held by
 * ManagesIndicatorReadings.
 *
 * @property-read array<int, array<string, mixed>> $rows  #[Computed] rows()
 * @property-read array<string, mixed>|null $inspected  #[Computed] inspected()
 * @property ?int $deletingReadingId ManagesIndicatorReadings
 */
trait InspectsIndicatorChain
{
    public bool $showInspectModal = false;

    #[Locked]
    public ?int $inspectingIndicatorId = null;

    /**
     * The indicator open in the chain inspector, if any.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function inspected(): ?array
    {
        return $this->inspectingIndicatorId === null
            ? null
            : ($this->rows[$this->inspectingIndicatorId] ?? null);
    }

    /**
     * Every reading on the inspected indicator, newest first.
     *
     * @return BaseCollection<int, array<string, mixed>>
     */
    #[Computed]
    public function inspectedReadings(): BaseCollection
    {
        $row = $this->inspected;

        if ($row === null) {
            return collect();
        }

        $readings = $row['indicator']->progress;

        return $readings
            ->map(fn (IndicatorProgress $reading, int $index): array => [
                'reading' => $reading,
                'value' => (float) $reading->value,
                'delta' => $index === 0 ? null : (float) $reading->value - (float) $readings[$index - 1]->value,
            ])
            ->reverse()
            ->values();
    }

    /**
     * Where the inspected indicator is heading by the end of this year, from
     * the trend of its readings; null with too few readings or no target.
     *
     * @return array{state: 'reaches'|'falls_short'|'moving_away'|'flat', projected: float, projected_percent: float|null, reaches_on: CarbonImmutable|null, window_end: CarbonImmutable, per_month: float}|null
     */
    #[Computed]
    public function inspectedForecast(): ?array
    {
        $row = $this->inspected;

        if ($row === null || ! $row['indicator'] instanceof Indicator) {
            return null;
        }

        return IndicatorForecast::for(
            $row['indicator']->progress,
            (float) $row['baseline'],
            $row['targetValue'] === null ? null : (float) $row['targetValue'],
            CarbonImmutable::now()->endOfYear()->startOfDay(),
        );
    }

    public function openInspectModal(int $indicatorId): void
    {
        $this->inspectingIndicatorId = $indicatorId;
        $this->showInspectModal = true;
    }

    public function closeInspectModal(): void
    {
        $this->showInspectModal = false;
        $this->inspectingIndicatorId = null;
        $this->deletingReadingId = null;
    }
}
