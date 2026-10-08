<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Indicators;

use App\Enums\IndicatorMeasure;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;

/**
 * The reading dialog on indicators/index: log a reading, correct one, and
 * delete one inline from the chain inspector's history.
 *
 * Used by indicators/index only. The page provides forgetIndicators();
 * the inspected indicator's id comes from InspectsIndicatorChain.
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
        Gate::authorize('indicators.update');

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
        Gate::authorize('indicators.update');

        $reading = IndicatorProgress::query()->findOrFail($readingId);

        $this->resetValidation();
        $this->progressIndicatorId = $reading->indicator_id;
        $this->editingReadingId = $reading->id;
        $this->progressDate = $reading->date->toDateString();
        $this->progressValue = (string) $reading->value;
        $this->progressNotes = (string) ($reading->notes ?? '');
        $this->progressSourceOfVerification = (string) ($reading->source_of_verification ?? '');
        $this->showProgressModal = true;
    }

    public function saveProgress(): void
    {
        Gate::authorize('indicators.update');

        if ($this->progressIndicatorId === null) {
            return;
        }

        $isPercentage = Indicator::query()->whereKey($this->progressIndicatorId)->value('measure') === IndicatorMeasure::Percentage->value;

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
        Gate::authorize('indicators.update');

        $this->deletingReadingId = $readingId;
    }

    public function cancelDeleteReading(): void
    {
        $this->deletingReadingId = null;
    }

    public function deleteReading(): void
    {
        Gate::authorize('indicators.update');

        if ($this->deletingReadingId === null || $this->inspectingIndicatorId === null) {
            return;
        }

        IndicatorProgress::query()
            ->where('indicator_id', $this->inspectingIndicatorId)
            ->findOrFail($this->deletingReadingId)
            ->delete();

        $this->deletingReadingId = null;
        $this->forgetIndicators();

        Flux::toast(variant: 'success', text: __('indicators.reading_deleted'));
    }

    /**
     * Drop every cached read after a write; the page's own method.
     */
    abstract protected function forgetIndicators(): void;
}
