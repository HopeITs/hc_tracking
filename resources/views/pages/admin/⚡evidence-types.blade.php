<?php

use App\Actions\EvidenceTypes\DeactivateEvidenceType;
use App\Actions\EvidenceTypes\DeleteEvidenceType;
use App\Actions\EvidenceTypes\MoveEvidenceType;
use App\Actions\EvidenceTypes\ReactivateEvidenceType;
use App\Actions\EvidenceTypes\SaveEvidenceType;
use App\Models\EvidenceType;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The managed evidence type list (contracts.md §12.4, D-42). MEAL
 * (evidence-types.manage) reads it and adds, renames, reorders, deactivates
 * and deletes; there is no read-only view. Every write goes through an
 * App\Actions\EvidenceTypes action, which authorizes again under lock.
 */
new #[Layout('layouts::desk'), Title('titles.evidence_types')] class extends Component {
    public bool $showEvidenceTypeModal = false;

    public ?int $editingEvidenceTypeId = null;

    public string $nameAr = '';

    public string $nameEn = '';

    public bool $showDeactivateModal = false;

    public ?int $deactivatingEvidenceTypeId = null;

    public bool $showDeleteModal = false;

    public ?int $deletingEvidenceTypeId = null;

    /** A used type cannot be deleted: the delete dialog offers to deactivate it instead. */
    public bool $deletingEvidenceTypeInUse = false;

    /** Read out by the polite live region after a move, so a keyboard user hears the new position. */
    public string $announcement = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', EvidenceType::class);
    }

    /** @return Collection<int, EvidenceType> */
    #[Computed]
    public function evidenceTypes(): Collection
    {
        return EvidenceType::query()->ordered()->get();
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', EvidenceType::class);

        $this->resetEvidenceTypeForm();
        $this->showEvidenceTypeModal = true;
    }

    public function openEditModal(int $evidenceTypeId): void
    {
        $evidenceType = EvidenceType::query()->findOrFail($evidenceTypeId);

        Gate::authorize('update', $evidenceType);

        $this->resetEvidenceTypeForm();
        $this->editingEvidenceTypeId = $evidenceType->id;
        $this->nameAr = $evidenceType->name_ar;
        $this->nameEn = (string) $evidenceType->name_en;
        $this->showEvidenceTypeModal = true;
    }

    public function saveEvidenceType(): void
    {
        try {
            $saved = app(SaveEvidenceType::class)->handle($this->editingEvidenceTypeId, ['name_ar' => $this->nameAr, 'name_en' => $this->nameEn]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => [match ($key) {
                    'name_en' => 'nameEn',
                    default => 'nameAr',
                } => $messages])->all(),
            );
        }

        $isCreate = $this->editingEvidenceTypeId === null;

        $this->closeEvidenceTypeModal();
        unset($this->evidenceTypes);

        Flux::toast(variant: 'success', text: __($isCreate ? 'evidence_types.created' : 'evidence_types.updated', ['name' => $saved->localizedName()]));
    }

    public function closeEvidenceTypeModal(): void
    {
        $this->showEvidenceTypeModal = false;
        $this->resetEvidenceTypeForm();
    }

    public function confirmDeactivate(int $evidenceTypeId): void
    {
        Gate::authorize('update', EvidenceType::query()->findOrFail($evidenceTypeId));

        $this->deactivatingEvidenceTypeId = $evidenceTypeId;
        $this->showDeactivateModal = true;
    }

    public function deactivateEvidenceType(): void
    {
        if ($this->deactivatingEvidenceTypeId === null) {
            return;
        }

        $evidenceTypeId = $this->deactivatingEvidenceTypeId;
        $this->cancelDeactivate();

        $this->runEvidenceTypeAction(fn (): mixed => app(DeactivateEvidenceType::class)->handle($evidenceTypeId), 'evidence_types.deactivated');
    }

    public function cancelDeactivate(): void
    {
        $this->showDeactivateModal = false;
        $this->deactivatingEvidenceTypeId = null;
    }

    /**
     * Turning a type back on loses nothing, so it needs no confirmation.
     */
    public function reactivateEvidenceType(int $evidenceTypeId): void
    {
        $this->runEvidenceTypeAction(fn (): mixed => app(ReactivateEvidenceType::class)->handle($evidenceTypeId), 'evidence_types.reactivated');
    }

    public function confirmDelete(int $evidenceTypeId): void
    {
        $evidenceType = EvidenceType::query()->findOrFail($evidenceTypeId);

        Gate::authorize('delete', $evidenceType);

        $this->deletingEvidenceTypeId = $evidenceType->id;
        $this->deletingEvidenceTypeInUse = $evidenceType->isInUse();
        $this->showDeleteModal = true;
    }

    public function deleteEvidenceType(): void
    {
        if ($this->deletingEvidenceTypeId === null) {
            return;
        }

        $evidenceTypeId = $this->deletingEvidenceTypeId;
        $this->cancelDelete();

        $this->runEvidenceTypeAction(fn (): mixed => app(DeleteEvidenceType::class)->handle($evidenceTypeId), 'evidence_types.deleted');
    }

    /**
     * The delete dialog of a used type: deactivate it, as the message explains.
     */
    public function deactivateInsteadOfDelete(): void
    {
        if ($this->deletingEvidenceTypeId === null) {
            return;
        }

        $evidenceTypeId = $this->deletingEvidenceTypeId;
        $this->cancelDelete();

        $this->runEvidenceTypeAction(fn (): mixed => app(DeactivateEvidenceType::class)->handle($evidenceTypeId), 'evidence_types.deactivated');
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingEvidenceTypeId = null;
        $this->deletingEvidenceTypeInUse = false;
    }

    /**
     * @param  'up'|'down'  $direction
     */
    public function moveEvidenceType(int $evidenceTypeId, string $direction): void
    {
        $position = app(MoveEvidenceType::class)->handle($evidenceTypeId, towardsStart: $direction === 'up');

        unset($this->evidenceTypes);

        $evidenceType = $this->evidenceTypes->firstWhere('id', $evidenceTypeId);

        $this->announcement = __('evidence_types.moved_to', [
            'name' => $evidenceType?->localizedName() ?? '',
            'position' => $position,
            'total' => $this->evidenceTypes->count(),
        ]);

        // Flux disables the clicked button while the request runs, which
        // drops keyboard focus; the list puts it back on the moved row.
        $this->dispatch('evidence-type-moved', evidenceTypeId: $evidenceTypeId, direction: $direction === 'up' ? 'up' : 'down');
    }

    /**
     * Run a deactivate, reactivate or delete; a refusal (in use, stale)
     * becomes a toast rather than a field error, since there is no form.
     */
    private function runEvidenceTypeAction(callable $action, string $successKey): void
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            unset($this->evidenceTypes);

            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        }

        unset($this->evidenceTypes);

        Flux::toast(variant: 'success', text: __($successKey));
    }

    private function resetEvidenceTypeForm(): void
    {
        $this->resetValidation();
        $this->editingEvidenceTypeId = null;
        $this->nameAr = '';
        $this->nameEn = '';
    }
};
?>

@php
    $evidenceTypes = $this->evidenceTypes;
@endphp

<x-desk.register class="@container/directory" heading-id="evidence-types-heading" :title="__('titles.evidence_types')" :count="$evidenceTypes->count()" data-tour="evidence-types-page">
        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-evidence-type" data-tour="evidence-types-create">
                {{ __('evidence_types.add_evidence_type') }}
            </x-ui.button>
        </x-slot:actions>
    
    <p class="sr-only" aria-live="polite" aria-atomic="true">{{ $announcement }}</p>

    @if ($evidenceTypes->isEmpty())
        <x-ui.empty-state icon="paper-clip" :heading="__('evidence_types.empty_heading')" :body="__('evidence_types.empty_body')">
            <x-slot:action>
                <x-ui.button tone="primary" icon="plus" wire:click="openCreateModal" data-test="empty-create-evidence-type">{{ __('evidence_types.add_evidence_type') }}</x-ui.button>
            </x-slot:action>
        </x-ui.empty-state>
    @else
        @include('pages.admin._evidence-types-list')
    @endif

    @include('pages.admin._evidence-types-modal')

    @include('pages.admin._evidence-types-confirm-modals')
</x-desk.register>
