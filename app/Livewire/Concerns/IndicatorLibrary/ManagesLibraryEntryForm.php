<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\IndicatorLibrary;

use App\Actions\IndicatorLibrary\DeactivateLibraryIndicator;
use App\Actions\IndicatorLibrary\DeleteLibraryIndicator;
use App\Actions\IndicatorLibrary\ReactivateLibraryIndicator;
use App\Actions\IndicatorLibrary\SaveLibraryIndicator;
use App\Actions\Indicators\AddIndicatorToLibrary;
use App\Actions\Targets\AttachLibraryIndicatorToResult;
use App\Enums\CollectionFrequency;
use App\Enums\IndicatorCollector;
use App\Enums\IndicatorMeasure;
use App\Models\LibraryIndicator;
use App\Models\Program;
use App\Models\ProposalLocation;
use App\Models\Result;
use App\Support\ProposalFramework;
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

    #[Locked]
    public bool $attachingEntry = false;

    public string $entryResultId = '';

    #[Locked]
    public ?int $fixedEntryResultId = null;

    public string $entrySearch = '';

    #[Locked]
    public ?int $entryProposalId = null;

    #[Locked]
    public ?int $entryLocationId = null;

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

    /** Named in the two confirmation dialogs. */
    public string $deactivatingEntryName = '';

    public string $deletingEntryName = '';

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

    protected function openEntryForResult(?Result $result, ?int $proposalId = null, ?int $locationId = null): void
    {
        if (Gate::denies('indicators.create')) {
            Gate::authorize('viewAny', LibraryIndicator::class);
        }

        if ($result?->proposal_id !== null) {
            Gate::authorize('view', $result->proposal()->firstOrFail());
        }

        $this->resetEntryForm();
        $this->attachingEntry = true;
        $this->entryProposalId = $result?->proposal_id ?? $proposalId;
        $this->entryLocationId = $locationId;
        $this->entryResultId = (string) ($result?->id ?? '');
        $this->fixedEntryResultId = $result?->id;
        $this->showEntryModal = true;
        $this->entry['frequency'] = CollectionFrequency::Quarterly->value;

        $this->prefillEntryPlacement($result);
    }

    public function updatedEntryResultId(): void
    {
        if ($this->attachingEntry && ctype_digit($this->entryResultId)) {
            $this->prefillEntryPlacement($this->entryResultsQuery()->find((int) $this->entryResultId));
        }
    }

    private function prefillEntryPlacement(?Result $result): void
    {
        if ($result !== null) {
            $this->entry['institutional'] = $result->program_id === null;
            $this->entry['program_id'] = (string) ($result->program_id ?? '');
            $this->entry['suggested_level'] = $result->type->value;
            $this->entry['level_ref'] = app(AddIndicatorToLibrary::class)->levelRefOf($result);
        }
    }

    #[Computed]
    public function entryResultOptions(): Collection
    {
        return $this->entryResultsQuery()->with('program')->orderBy('statement')->get(['id', 'statement', 'program_id', 'type']);
    }

    /** @return Builder<Result> */
    private function entryResultsQuery(): Builder
    {
        return Result::query()
            ->when($this->fixedEntryResultId !== null, fn (Builder $query) => $query->whereKey($this->fixedEntryResultId))
            ->when($this->entryProposalId !== null, fn (Builder $query) => $query->proposalFramework($this->entryProposalId))
            ->when($this->entryProposalId === null && Gate::denies('proposals.view'), fn (Builder $query) => $query->programFramework())
            ->when($this->entryLocationId !== null, function (Builder $query): void {
                $programId = ProposalLocation::query()->findOrFail($this->entryLocationId)->program_id;
                $query->where(fn (Builder $scope) => $scope->whereNull('program_id')->orWhere('program_id', $programId));
            });
    }

    public function canWriteEntry(): bool
    {
        return $this->editingEntryId !== null
            || ($this->attachingEntry ? Gate::allows('indicators.create') : Gate::allows('create', LibraryIndicator::class));
    }

    #[Computed]
    public function existingEntries(): Collection
    {
        $normalize = fn (?string $name): string => SaveLibraryIndicator::normalizeName($name);
        $search = $normalize($this->entrySearch);
        $typed = array_filter([$normalize($this->entry['name_ar'] ?? null), $normalize($this->entry['name_en'] ?? null)]);
        $result = $this->attachingEntry && ctype_digit($this->entryResultId)
            ? $this->entryResultsQuery()->with('proposal')->find((int) $this->entryResultId)
            : null;
        $programIds = $result?->proposal_id !== null
            ? ProposalFramework::programIds($result->proposal)
            : [$result?->program_id];

        return LibraryIndicator::query()
            ->when($this->attachingEntry, fn (Builder $query) => $query->active())
            ->when($result !== null, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('program_id')->orWhereIn('program_id', array_filter($programIds))))
            ->when($result !== null, fn (Builder $query) => $query->where(fn (Builder $scope) => $scope->whereNull('suggested_level')->orWhere('suggested_level', $result->type->value)))
            ->orderBy('name_ar')
            ->get(['id', 'name_ar', 'name_en', 'code'])
            ->filter(function (LibraryIndicator $entry) use ($normalize, $search, $typed): bool {
                $names = [$normalize($entry->name_ar), $normalize($entry->name_en)];

                return array_intersect($names, $typed) !== [] || $search === ''
                    || str_contains(implode(' ', $names), $search);
            })
            ->sortByDesc(fn (LibraryIndicator $entry): bool => array_intersect([$normalize($entry->name_ar), $normalize($entry->name_en)], $typed) !== [])
            ->take(15);
    }

    public function pickExistingEntry(int $libraryIndicatorId): void
    {
        if (! $this->attachingEntry) {
            $this->openEditModal($libraryIndicatorId);

            return;
        }

        $this->writeAttachedEntry(fn (Result $result) => app(AttachLibraryIndicatorToResult::class)->forResult($result, $libraryIndicatorId, $this->entryLocationId));
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
        if ($this->attachingEntry) {
            $this->writeAttachedEntry(fn (Result $result) => app(SaveLibraryIndicator::class)->createForResult($result, $this->entryInput(), $this->entryLocationId));

            return;
        }

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

        if (! $isCreate) {
            $this->dispatch('indicator-saved');
        }

        Flux::toast(
            variant: 'success',
            text: __($isCreate ? 'indicator_library.entry_created' : 'indicator_library.entry_updated', ['code' => $saved->code]),
        );
    }

    private function writeAttachedEntry(callable $write): void
    {
        Gate::authorize('indicators.create');

        try {
            $this->validate(['entryResultId' => ['required', 'integer']], ['entryResultId.required' => __('indicators.select_the_result_this_indicator_measures')]);
            $result = $this->entryResultsQuery()->findOrFail((int) $this->entryResultId);
            $indicator = $write($result);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => [
                    $key === 'entryResultId' ? $key : 'entry.'.$key => $messages,
                ])->all(),
            );
        }

        $this->closeEntryModal();
        $this->forgetLibrary();
        $this->dispatch('indicator-saved');
        Flux::toast(variant: 'success', text: __('targets.attached', ['code' => $indicator->code]));
    }

    public function closeEntryModal(): void
    {
        $this->showEntryModal = false;
        $this->resetEntryForm();
    }

    public function confirmDeactivate(int $libraryIndicatorId): void
    {
        $entry = LibraryIndicator::query()->findOrFail($libraryIndicatorId);

        Gate::authorize('update', $entry);

        $this->deactivatingEntryName = $entry->localizedName();
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
        $this->deactivatingEntryName = '';
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
        $entry = LibraryIndicator::query()->findOrFail($libraryIndicatorId);

        Gate::authorize('delete', $entry);

        $this->deletingEntryName = $entry->localizedName();
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
        $this->deletingEntryName = '';
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
        $this->attachingEntry = false;
        $this->entryProposalId = null;
        $this->entryLocationId = null;
        $this->entryResultId = '';
        $this->fixedEntryResultId = null;
        $this->entrySearch = '';
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
