<?php

use App\Actions\Sectors\DeactivateSector;
use App\Actions\Sectors\DeleteSector;
use App\Actions\Sectors\MoveSector;
use App\Actions\Sectors\ReactivateSector;
use App\Actions\Sectors\SaveSector;
use App\Models\Sector;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/*
 * The managed sector list (contracts.md §12.5, D-47). Everyone with
 * sectors.view reads it; MEAL and the General Manager (sectors.manage)
 * add, rename, reorder, deactivate and delete. Every write goes through
 * an App\Actions\Sectors action, which authorizes again under lock.
 */
new #[Title('titles.sectors')] class extends Component {
    public bool $showSectorModal = false;

    public ?int $editingSectorId = null;

    public string $nameAr = '';

    public string $nameEn = '';

    public bool $showDeactivateModal = false;

    public ?int $deactivatingSectorId = null;

    public bool $showDeleteModal = false;

    public ?int $deletingSectorId = null;

    /** A used sector cannot be deleted: the delete dialog offers to deactivate it instead. */
    public bool $deletingSectorInUse = false;

    /** Read out by the polite live region after a move, so a keyboard user hears the new position. */
    public string $announcement = '';

    public function mount(): void
    {
        Gate::authorize('viewAny', Sector::class);
    }

    /** @return Collection<int, Sector> */
    #[Computed]
    public function sectors(): Collection
    {
        return Sector::query()->ordered()->withCount('projects')->get();
    }

    public function openCreateModal(): void
    {
        Gate::authorize('create', Sector::class);

        $this->resetSectorForm();
        $this->showSectorModal = true;
    }

    public function openEditModal(int $sectorId): void
    {
        $sector = Sector::query()->findOrFail($sectorId);

        Gate::authorize('update', $sector);

        $this->resetSectorForm();
        $this->editingSectorId = $sector->id;
        $this->nameAr = $sector->name_ar;
        $this->nameEn = (string) $sector->name_en;
        $this->showSectorModal = true;
    }

    public function saveSector(): void
    {
        try {
            $saved = app(SaveSector::class)->handle($this->editingSectorId, ['name_ar' => $this->nameAr, 'name_en' => $this->nameEn]);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(
                collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => [match ($key) {
                    'name_en' => 'nameEn',
                    default => 'nameAr',
                } => $messages])->all(),
            );
        }

        $isCreate = $this->editingSectorId === null;

        $this->closeSectorModal();
        unset($this->sectors);

        Flux::toast(variant: 'success', text: __($isCreate ? 'sectors.created' : 'sectors.updated', ['name' => $saved->localizedName()]));
    }

    public function closeSectorModal(): void
    {
        $this->showSectorModal = false;
        $this->resetSectorForm();
    }

    public function confirmDeactivate(int $sectorId): void
    {
        Gate::authorize('update', Sector::query()->findOrFail($sectorId));

        $this->deactivatingSectorId = $sectorId;
        $this->showDeactivateModal = true;
    }

    public function deactivateSector(): void
    {
        if ($this->deactivatingSectorId === null) {
            return;
        }

        $sectorId = $this->deactivatingSectorId;
        $this->cancelDeactivate();

        $this->runSectorAction(fn (): mixed => app(DeactivateSector::class)->handle($sectorId), 'sectors.deactivated');
    }

    public function cancelDeactivate(): void
    {
        $this->showDeactivateModal = false;
        $this->deactivatingSectorId = null;
    }

    /**
     * Turning a sector back on loses nothing, so it needs no confirmation.
     */
    public function reactivateSector(int $sectorId): void
    {
        $this->runSectorAction(fn (): mixed => app(ReactivateSector::class)->handle($sectorId), 'sectors.reactivated');
    }

    public function confirmDelete(int $sectorId): void
    {
        $sector = Sector::query()->findOrFail($sectorId);

        Gate::authorize('delete', $sector);

        $this->deletingSectorId = $sector->id;
        $this->deletingSectorInUse = $sector->projects()->exists();
        $this->showDeleteModal = true;
    }

    public function deleteSector(): void
    {
        if ($this->deletingSectorId === null) {
            return;
        }

        $sectorId = $this->deletingSectorId;
        $this->cancelDelete();

        $this->runSectorAction(fn (): mixed => app(DeleteSector::class)->handle($sectorId), 'sectors.deleted');
    }

    /**
     * The delete dialog of a used sector: deactivate it, as the message explains.
     */
    public function deactivateInsteadOfDelete(): void
    {
        if ($this->deletingSectorId === null) {
            return;
        }

        $sectorId = $this->deletingSectorId;
        $this->cancelDelete();

        $this->runSectorAction(fn (): mixed => app(DeactivateSector::class)->handle($sectorId), 'sectors.deactivated');
    }

    public function cancelDelete(): void
    {
        $this->showDeleteModal = false;
        $this->deletingSectorId = null;
        $this->deletingSectorInUse = false;
    }

    /**
     * @param  'up'|'down'  $direction
     */
    public function moveSector(int $sectorId, string $direction): void
    {
        $position = app(MoveSector::class)->handle($sectorId, towardsStart: $direction === 'up');

        unset($this->sectors);

        $sector = $this->sectors->firstWhere('id', $sectorId);

        $this->announcement = __('sectors.moved_to', [
            'name' => $sector?->localizedName() ?? '',
            'position' => $position,
            'total' => $this->sectors->count(),
        ]);

        // Flux disables the clicked button while the request runs, which
        // drops keyboard focus; the list puts it back on the moved row.
        $this->dispatch('sector-moved', sectorId: $sectorId, direction: $direction === 'up' ? 'up' : 'down');
    }

    /**
     * Run a deactivate, reactivate or delete; a refusal (in use, stale)
     * becomes a toast rather than a field error, since there is no form.
     */
    private function runSectorAction(callable $action, string $successKey): void
    {
        try {
            $action();
        } catch (ValidationException $exception) {
            unset($this->sectors);

            Flux::toast(variant: 'danger', text: (string) collect($exception->errors())->flatten()->first());

            return;
        }

        unset($this->sectors);

        Flux::toast(variant: 'success', text: __($successKey));
    }

    private function resetSectorForm(): void
    {
        $this->resetValidation();
        $this->editingSectorId = null;
        $this->nameAr = '';
        $this->nameEn = '';
    }
};
?>

@php
    $sectors = $this->sectors;
    $canManage = auth()->user()->can('sectors.manage');
@endphp

<section
    class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink"
    aria-labelledby="sectors-heading"
    data-tour="sectors-page"
>
    <x-ui.page-header heading-id="sectors-heading" :title="__('titles.sectors')" :count="$sectors->count()">
        @if ($canManage)
            <x-slot:actions>
                <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-sector" data-tour="sectors-create">
                    {{ __('sectors.add_sector') }}
                </x-ui.button>
            </x-slot:actions>
        @endif
    </x-ui.page-header>

    @unless ($canManage)
        <p class="type-meta text-secondary-medium dark:text-ink-muted" data-test="sectors-read-only">{{ __('sectors.read_only_note') }}</p>
    @endunless

    <p class="sr-only" aria-live="polite" aria-atomic="true">{{ $announcement }}</p>

    @if ($sectors->isEmpty())
        <x-ui.empty-state icon="squares-2x2" :heading="__('sectors.empty_heading')" :body="__('sectors.empty_body')">
            @if ($canManage)
                <x-slot:action>
                    <x-ui.button tone="primary" icon="plus" wire:click="openCreateModal" data-test="empty-create-sector">{{ __('sectors.add_sector') }}</x-ui.button>
                </x-slot:action>
            @endif
        </x-ui.empty-state>
    @else
        @include('pages.admin._sectors-list')
    @endif

    @include('pages.admin._sectors-modal')

    @include('pages.admin._sectors-confirm-modals')
</section>
