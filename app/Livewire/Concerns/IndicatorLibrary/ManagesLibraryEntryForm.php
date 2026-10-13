<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\IndicatorLibrary;

use App\Actions\IndicatorLibrary\DeactivateLibraryIndicator;
use App\Actions\IndicatorLibrary\DeleteLibraryIndicator;
use App\Actions\IndicatorLibrary\ReactivateLibraryIndicator;
use App\Actions\IndicatorLibrary\SaveLibraryIndicator;
use App\Enums\IndicatorCollector;
use App\Enums\IndicatorMeasure;
use App\Models\LibraryIndicator;
use App\Models\Program;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The Indicator Library's entry dialog and its deactivate, reactivate and
 * delete confirmations. Every write goes through the
 * App\Actions\IndicatorLibrary actions, which authorize, lock the row and
 * re-check the in-use and has-targets rules themselves; the flags kept here
 * only shape the form.
 *
 * Used by indicator-library/index only. The page provides forgetLibrary().
 */
trait ManagesLibraryEntryForm
{
    #[Locked]
    public ?int $editingEntryId = null;

    public bool $showEntryModal = false;

    /**
     * The form, keyed by library_indicators column, plus `institutional`.
     *
     * @var array<string, mixed>
     */
    public array $entry = [];

    /** The code of the entry being edited; null while creating. */
    #[Locked]
    public ?string $editingCode = null;

    /** An indicator uses the entry, so its program and level are frozen. */
    #[Locked]
    public bool $entryIsInUse = false;

    /** A linked indicator has targets, so the measure is locked. */
    #[Locked]
    public bool $measureIsLocked = false;

    #[Locked]
    public ?int $deletingEntryId = null;

    public bool $showDeleteModal = false;

    #[Locked]
    public ?int $deactivatingEntryId = null;

    public bool $showDeactivateModal = false;

    /**
     * Programs offered in the form.
     *
     * @return Collection<int, Program>
     */
    #[Computed]
    public function entryProgramOptions(): Collection
    {
        return Program::options();
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', LibraryIndicator::class);

        $this->resetEntryForm();
        $this->showEntryModal = true;
    }

    public function openEditModal(int $libraryIndicatorId): void
    {
        $entry = LibraryIndicator::query()
            ->withCount('indicators')
            ->withExists(['indicators as has_targets' => fn (Builder $query): Builder => $query->has('planIndicators')])
            ->findOrFail($libraryIndicatorId);

        Gate::authorize('update', $entry);

        $this->resetEntryForm();
        $this->editingEntryId = $entry->id;
        $this->editingCode = $entry->code;
        $this->entryIsInUse = $entry->indicators_count > 0;
        $this->measureIsLocked = (bool) $entry->has_targets;
        $this->entry = [
            'institutional' => $entry->program_id === null,
            'program_id' => $entry->program_id === null ? '' : (string) $entry->program_id,
            'level_ref' => $entry->level_ref,
            'name_ar' => $entry->name_ar,
            'name_en' => (string) $entry->name_en,
            'definition' => (string) $entry->definition,
            'calculation_method' => (string) $entry->calculation_method,
            'measure' => $entry->measure->value,
            'unit' => (string) $entry->unit,
            'numerator_label' => (string) $entry->numerator_label,
            'denominator_label' => (string) $entry->denominator_label,
            'denominator_basis' => (string) $entry->denominator_basis?->value,
            'is_disaggregated' => $entry->is_disaggregated,
            'collected_by' => $entry->collected_by->value,
            'is_cumulative' => $entry->is_cumulative,
            'frequency' => $entry->frequency->value,
            'frequency_note' => (string) $entry->frequency_note,
            'follow_up_months' => $entry->follow_up_months === null ? '' : (string) $entry->follow_up_months,
            'suggested_level' => (string) $entry->suggested_level?->value,
            'data_source' => (string) $entry->data_source,
            'means_of_verification' => (string) $entry->means_of_verification,
        ];
        $this->showEntryModal = true;
    }

    public function saveEntry(): void
    {
        try {
            $saved = app(SaveLibraryIndicator::class)->handle($this->editingEntryId, $this->entryInput());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => ['entry.'.$key => $messages])->all(),
            );
        }

        $isCreate = $this->editingEntryId === null;

        $this->closeEntryModal();
        $this->forgetLibrary();

        Flux::toast(
            variant: 'success',
            text: __($isCreate ? 'indicator_library.entry_created' : 'indicator_library.entry_updated', ['code' => $saved->code]),
        );
    }

    public function closeEntryModal(): void
    {
        $this->showEntryModal = false;
        $this->resetEntryForm();
    }

    public function confirmDeactivate(int $libraryIndicatorId): void
    {
        Gate::authorize('update', LibraryIndicator::query()->findOrFail($libraryIndicatorId));

        $this->deactivatingEntryId = $libraryIndicatorId;
        $this->showDeactivateModal = true;
    }

    public function deactivateEntry(): void
    {
        if ($this->deactivatingEntryId === null) {
            return;
        }

        $entryId = $this->deactivatingEntryId;
        $this->cancelDeactivate();

        $this->runEntryAction(fn (): mixed => app(DeactivateLibraryIndicator::class)->handle($entryId), 'indicator_library.entry_deactivated');
    }

    public function cancelDeactivate(): void
    {
        $this->showDeactivateModal = false;
        $this->deactivatingEntryId = null;
    }

    /**
     * Turning an entry back on loses nothing, so it needs no confirmation.
     */
    public function reactivateEntry(int $libraryIndicatorId): void
    {
        $this->runEntryAction(fn (): mixed => app(ReactivateLibraryIndicator::class)->handle($libraryIndicatorId), 'indicator_library.entry_reactivated');
    }

    public function confirmDelete(int $libraryIndicatorId): void
    {
        Gate::authorize('delete', LibraryIndicator::query()->findOrFail($libraryIndicatorId));

        $this->deletingEntryId = $libraryIndicatorId;
        $this->showDeleteModal = true;
    }

    public function deleteEntry(): void
    {
        if ($this->deletingEntryId === null) {
            return;
        }

        $entryId = $this->deletingEntryId;
        $this->cancelDelete();

        $this->runEntryAction(fn (): mixed => app(DeleteLibraryIndicator::class)->handle($entryId), 'indicator_library.entry_deleted');
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingEntryId = null;
    }

    /**
     * The denominator an entry divides by when MEAL leaves the choice empty.
     */
    public function defaultBasisLabel(): string
    {
        return (IndicatorCollector::tryFrom((string) ($this->entry['collected_by'] ?? '')) ?? IndicatorCollector::ProjectTeam)
            ->defaultDenominatorBasis()
            ->label();
    }

    /**
     * Run a deactivate, reactivate or delete; a refusal (in use, stale)
     * becomes a toast rather than a field error, since there is no form.
     */
    private function runEntryAction(callable $action, string $successKey): void
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            $this->forgetLibrary();

            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        }

        $this->forgetLibrary();

        Flux::toast(variant: 'success', text: __($successKey));
    }

    /**
     * The form as the action reads it: empty text becomes null.
     *
     * @return array<string, mixed>
     */
    private function entryInput(): array
    {
        return collect($this->entry)
            ->map(fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value))
            ->all();
    }

    private function resetEntryForm(): void
    {
        $this->resetValidation();
        $this->editingEntryId = null;
        $this->editingCode = null;
        $this->entryIsInUse = false;
        $this->measureIsLocked = false;
        $this->entry = [
            'institutional' => false,
            'program_id' => '',
            'level_ref' => '',
            'name_ar' => '',
            'name_en' => '',
            'definition' => '',
            'calculation_method' => '',
            'measure' => IndicatorMeasure::Numeric->value,
            'unit' => '',
            'numerator_label' => '',
            'denominator_label' => '',
            'denominator_basis' => '',
            'is_disaggregated' => false,
            'collected_by' => IndicatorCollector::ProjectTeam->value,
            'is_cumulative' => false,
            'frequency' => '',
            'frequency_note' => '',
            'follow_up_months' => '',
            'suggested_level' => '',
            'data_source' => '',
            'means_of_verification' => '',
        ];
    }
}
