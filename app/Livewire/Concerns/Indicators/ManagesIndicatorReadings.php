<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Indicators;

use App\Enums\IndicatorMeasure;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The reading dialog on indicators/index: log a reading, correct one, and
 * delete one inline from the chain inspector's history.
 *
 * The host provides its indicator scope and cache invalidation.
 *
 * @property ?int $inspectingIndicatorId InspectsIndicatorChain
 */
trait ManagesIndicatorReadings
{
    public bool $showProgressModal = false;

    #[Locked]
    public ?int $progressIndicatorId = null;

    #[Locked]
    public ?int $editingReadingId = null;

    #[Locked]
    public ?int $deletingReadingId = null;

    public string $progressDate = '';

    public string $progressValue = '';

    public string $progressNotes = '';

    public string $progressSourceOfVerification = '';

    public function openProgressModal(int $indicatorId): void
    {
        Gate::authorize('indicators.record');

        $this->readingIndicators()->findOrFail($indicatorId);

        $this->resetValidation();
        $this->progressIndicatorId = $indicatorId;
        $this->editingReadingId = null;
        $this->progressDate = now()->toDateString();
        $this->progressValue = '';
        $this->progressNotes = '';
        $this->progressSourceOfVerification = '';
        $this->showProgressModal = true;
    }

    /**
     * Correct a reading already logged: a mistyped figure should not be
     * permanent, and is not a reason to log a second, contradicting one.
     */
    public function openEditReadingModal(int $readingId): void
    {
        Gate::authorize('indicators.record');

        $reading = IndicatorProgress::query()->findOrFail($readingId);
        $this->readingIndicators()->findOrFail($reading->indicator_id);

        $this->resetValidation();
        $this->progressIndicatorId = $reading->indicator_id;
        $this->editingReadingId = $reading->id;
        $this->progressDate = $reading->date->toDateString();
        $this->progressValue = (string) $reading->value;
        $this->progressNotes = (string) ($reading->notes ?? '');
        $this->progressSourceOfVerification = (string) ($reading->source_of_verification ?? '');
        $this->showProgressModal = true;
    }

    /**
     * A gentle prompt, never a block, when the typed reading is at least double
     * the indicator's target: usually a slipped digit or a unit mix-up.
     */
    #[Computed]
    public function progressValueWarning(): ?string
    {
        if ($this->progressIndicatorId === null || ! is_numeric($this->progressValue)) {
            return null;
        }

        $target = Indicator::query()->whereKey($this->progressIndicatorId)->value('target');

        if ($target === null || (float) $target <= 0 || (float) $this->progressValue < 2 * (float) $target) {
            return null;
        }

        return __('indicators.reading_far_above_target', [
            'percent' => number_format((float) $this->progressValue / (float) $target * 100, 0),
            'target' => rtrim(rtrim(number_format((float) $target, 2), '0'), '.'),
        ]);
    }

    public function saveProgress(): void
    {
        Gate::authorize('indicators.record');

        if ($this->progressIndicatorId === null) {
            return;
        }

        $indicator = $this->readingIndicators()->findOrFail($this->progressIndicatorId);
        $isPercentage = $indicator->measure === IndicatorMeasure::Percentage;

        $validated = $this->validate([
            // A reading records something that has happened, never a forecast.
            'progressDate' => ['required', 'date', 'before_or_equal:today'],
            'progressValue' => ['required', 'numeric', 'min:0', $isPercentage ? 'max:100' : 'max:9999999999.99'],
            'progressNotes' => ['nullable', 'string'],
            'progressSourceOfVerification' => ['nullable', 'string', 'max:255'],
        ], [
            'progressDate.required' => __('indicators.enter_the_date_of_this_reading'),
            'progressDate.before_or_equal' => __('indicators.a_reading_cannot_be_dated_in_the_future'),
            'progressValue.required' => __('indicators.enter_the_value_recorded'),
        ] + ($isPercentage ? ['progressValue.max' => __('indicators.a_percentage_cannot_exceed_100')] : []));

        $this->authorizeReadingPeriod($indicator, CarbonImmutable::parse($validated['progressDate']));

        $attributes = [
            'date' => $validated['progressDate'],
            'value' => $validated['progressValue'],
            'notes' => $validated['progressNotes'] === '' ? null : $validated['progressNotes'],
            'source_of_verification' => $validated['progressSourceOfVerification'] === '' ? null : $validated['progressSourceOfVerification'],
        ];

        if ($this->editingReadingId === null) {
            IndicatorProgress::query()->create($attributes + [
                'indicator_id' => $this->progressIndicatorId,
                'recorded_by' => auth()->id(),
            ]);
        } else {
            // Scoped to the indicator so a tampered id cannot edit another indicator's reading.
            IndicatorProgress::query()
                ->where('indicator_id', $this->progressIndicatorId)
                ->findOrFail($this->editingReadingId)
                ->fill($attributes)
                ->save();
        }

        $wasEdit = $this->editingReadingId !== null;

        $this->closeProgressModal();
        $this->forgetIndicators();

        Flux::toast(variant: 'success', text: $wasEdit ? __('indicators.reading_updated') : __('indicators.reading_logged'));
    }

    public function closeProgressModal(): void
    {
        $this->showProgressModal = false;
        $this->progressIndicatorId = null;
        $this->editingReadingId = null;
    }

    /**
     * Ask, inline in the reading history, whether to remove one reading.
     */
    public function confirmDeleteReading(int $readingId): void
    {
        Gate::authorize('indicators.record');

        $this->deletingReadingId = $readingId;
    }

    public function cancelDeleteReading(): void
    {
        $this->deletingReadingId = null;
    }

    public function deleteReading(): void
    {
        Gate::authorize('indicators.record');

        $indicatorId = $this->inspectingIndicatorId ?? null;

        if ($this->deletingReadingId === null || $indicatorId === null) {
            return;
        }

        $this->readingIndicators()->findOrFail($indicatorId);

        IndicatorProgress::query()
            ->where('indicator_id', $indicatorId)
            ->findOrFail($this->deletingReadingId)
            ->delete();

        $this->deletingReadingId = null;
        $this->forgetIndicators();

        Flux::toast(variant: 'success', text: __('indicators.reading_deleted'));
    }

    /** @return Builder<Indicator> */
    protected function readingIndicators(): Builder
    {
        return Indicator::query();
    }

    protected function authorizeReadingPeriod(Indicator $indicator, CarbonImmutable $date): void {}

    /**
     * Drop every cached read after a write; the page's own method.
     */
    abstract protected function forgetIndicators(): void;
}
