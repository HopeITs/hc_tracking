<?php

use App\Actions\RiskLibrary\DeactivateRiskLibraryEntry;
use App\Actions\RiskLibrary\DeleteRiskLibraryEntry;
use App\Actions\RiskLibrary\MoveRiskLibraryEntry;
use App\Actions\RiskLibrary\ReactivateRiskLibraryEntry;
use App\Actions\RiskLibrary\SaveRiskLibraryEntry;
use App\Models\RiskLibrary;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The MEAL-managed risk library (contracts.md §7, W-09, D-61). A proposal's
 * logframe picks its risks from this list and nowhere else. Only holders of
 * risk-library.manage (MEAL, General Manager) open the page and add, edit,
 * reorder, deactivate and delete entries. Every write goes through an
 * App\Actions\RiskLibrary action, which authorizes again under lock.
 */
new #[Layout('layouts::desk'), Title('titles.risk_library')] class extends Component {
    public bool $showEntryModal = false;

    public ?int $editingEntryId = null;

    public string $titleEn = '';

    public string $titleAr = '';

    public string $category = '';

    public string $defaultLikelihood = '';

    public string $defaultSeverity = '';

    public bool $showDeactivateModal = false;

    public ?int $deactivatingEntryId = null;

    public bool $showDeleteModal = false;

    public ?int $deletingEntryId = null;

    /** A used entry cannot be deleted: the delete dialog offers to deactivate it instead. */
    public bool $deletingEntryInUse = false;

    /** Read out by the polite live region after a move, so a keyboard user hears the new position. */
    public string $announcement = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', RiskLibrary::class);
    }

    /** @return Collection<int, RiskLibrary> */
    #[Computed]
    public function entries(): Collection
    {
        return RiskLibrary::query()->ordered()->withCount('risks')->get();
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', RiskLibrary::class);

        $this->resetEntryForm();
        $this->showEntryModal = true;
    }

    public function openEditModal(int $entryId): void
    {
        $entry = RiskLibrary::query()->findOrFail($entryId);

        Gate::authorize('update', $entry);

        $this->resetEntryForm();
        $this->editingEntryId = $entry->id;
        $this->titleEn = $entry->title_en;
        $this->titleAr = $entry->title_ar;
        $this->category = (string) $entry->category;
        $this->defaultLikelihood = (string) ($entry->default_likelihood ?? '');
        $this->defaultSeverity = (string) ($entry->default_severity ?? '');
        $this->showEntryModal = true;
    }

    public function saveEntry(): void
    {
        try {
            $saved = app(SaveRiskLibraryEntry::class)->handle($this->editingEntryId, [
                'title_en' => $this->titleEn,
                'title_ar' => $this->titleAr,
                'category' => $this->category,
                'default_likelihood' => $this->defaultLikelihood,
                'default_severity' => $this->defaultSeverity,
            ]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => [match ($key) {
                    'title_en' => 'titleEn',
                    'title_ar' => 'titleAr',
                    'default_likelihood' => 'defaultLikelihood',
                    'default_severity' => 'defaultSeverity',
                    default => 'category',
                } => $messages])->all(),
            );
        }

        $isCreate = $this->editingEntryId === null;

        $this->closeEntryModal();
        unset($this->entries);

        Flux::toast(variant: 'success', text: __($isCreate ? 'risk_library.created' : 'risk_library.updated', ['name' => $saved->localizedTitle()]));
    }

    public function closeEntryModal(): void
    {
        $this->showEntryModal = false;
        $this->resetEntryForm();
    }

    public function confirmDeactivate(int $entryId): void
    {
        Gate::authorize('update', RiskLibrary::query()->findOrFail($entryId));

        $this->deactivatingEntryId = $entryId;
        $this->showDeactivateModal = true;
    }

    public function deactivateEntry(): void
    {
        if ($this->deactivatingEntryId === null) {
            return;
        }

        $entryId = $this->deactivatingEntryId;
        $this->cancelDeactivate();

        $this->runEntryAction(fn (): mixed => app(DeactivateRiskLibraryEntry::class)->handle($entryId), 'risk_library.deactivated');
    }

    public function cancelDeactivate(): void
    {
        $this->showDeactivateModal = false;
        $this->deactivatingEntryId = null;
    }

    /**
     * Turning an entry back on loses nothing, so it needs no confirmation.
     */
    public function reactivateEntry(int $entryId): void
    {
        $this->runEntryAction(fn (): mixed => app(ReactivateRiskLibraryEntry::class)->handle($entryId), 'risk_library.reactivated');
    }

    public function confirmDelete(int $entryId): void
    {
        $entry = RiskLibrary::query()->findOrFail($entryId);

        Gate::authorize('delete', $entry);

        $this->deletingEntryId = $entry->id;
        $this->deletingEntryInUse = $entry->risks()->exists();
        $this->showDeleteModal = true;
    }

    public function deleteEntry(): void
    {
        if ($this->deletingEntryId === null) {
            return;
        }

        $entryId = $this->deletingEntryId;
        $this->cancelDelete();

        $this->runEntryAction(fn (): mixed => app(DeleteRiskLibraryEntry::class)->handle($entryId), 'risk_library.deleted');
    }

    /**
     * The delete dialog of a used entry: deactivate it, as the message explains.
     */
    public function deactivateInsteadOfDelete(): void
    {
        if ($this->deletingEntryId === null) {
            return;
        }

        $entryId = $this->deletingEntryId;
        $this->cancelDelete();

        $this->runEntryAction(fn (): mixed => app(DeactivateRiskLibraryEntry::class)->handle($entryId), 'risk_library.deactivated');
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingEntryId = null;
        $this->deletingEntryInUse = false;
    }

    /**
     * @param  'up'|'down'  $direction
     */
    public function moveEntry(int $entryId, string $direction): void
    {
        $position = app(MoveRiskLibraryEntry::class)->handle($entryId, towardsStart: $direction === 'up');

        unset($this->entries);

        $entry = $this->entries->firstWhere('id', $entryId);

        $this->announcement = __('risk_library.moved_to', [
            'name' => $entry?->localizedTitle() ?? '',
            'position' => $position,
            'total' => $this->entries->count(),
        ]);

        // Flux disables the clicked button while the request runs, which
        // drops keyboard focus; the list puts it back on the moved row.
        $this->dispatch('risk-library-moved', entryId: $entryId, direction: $direction === 'up' ? 'up' : 'down');
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
            unset($this->entries);

            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        }

        unset($this->entries);

        Flux::toast(variant: 'success', text: __($successKey));
    }

    private function resetEntryForm(): void
    {
        $this->resetValidation();
        $this->editingEntryId = null;
        $this->titleEn = '';
        $this->titleAr = '';
        $this->category = '';
        $this->defaultLikelihood = '';
        $this->defaultSeverity = '';
    }
};
?>

@php
    $entries = $this->entries;
@endphp

<x-desk.register class="@container/directory" heading-id="risk-library-heading" :title="__('titles.risk_library')" :count="$entries->count()" data-tour="risk-library-page">
    <x-slot:actions>
        <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-risk-library-entry" data-tour="risk-library-create">
            {{ __('risk_library.add_entry') }}
        </x-ui.button>
    </x-slot:actions>

    <p class="sr-only" aria-live="polite" aria-atomic="true">{{ $announcement }}</p>

    @if ($entries->isEmpty())
        <x-ui.empty-state icon="shield-exclamation" :heading="__('risk_library.empty_heading')" :body="__('risk_library.empty_body')">
            <x-slot:action>
                <x-ui.button tone="primary" icon="plus" wire:click="openCreateModal" data-test="empty-create-risk-library-entry">{{ __('risk_library.add_entry') }}</x-ui.button>
            </x-slot:action>
        </x-ui.empty-state>
    @else
        @include('pages.admin._risk-library-list')
    @endif

    @include('pages.admin._risk-library-modal')

    @include('pages.admin._risk-library-confirm-modals')
</x-desk.register>
