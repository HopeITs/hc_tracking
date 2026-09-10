<?php

use App\Models\City;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('City directory')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    public bool $showCityModal = false;
    public bool $showDeleteModal = false;
    public ?int $editingCityId = null;
    public ?int $deletingCityId = null;
    public string $name = '';
    public string $deletingCityName = '';
    public ?string $deleteError = null;

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function clearSearch(): void
    {
        $this->search = '';
        $this->resetPage();
    }

    /**
     * @return LengthAwarePaginator<int, City>
     */
    #[Computed]
    public function cities(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return City::query()
            ->select(['id', 'name', 'created_at', 'updated_at'])
            ->withCount('projects')
            ->when($search !== '', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);
    }

    public function openCreateModal(): void
    {
        $this->resetCityForm();
        $this->showCityModal = true;
    }

    public function openEditModal(int $cityId): void
    {
        $city = City::query()->findOrFail($cityId);

        $this->resetValidation();
        $this->editingCityId = $city->id;
        $this->name = $city->name;
        $this->showCityModal = true;
    }

    public function saveCity(): void
    {
        $city = $this->editingCityId === null
            ? new City
            : City::query()->findOrFail($this->editingCityId);

        $this->name = trim($this->name);

        $validated = $this->validate([
            'name' => [
                'required',
                'string',
                'max:100',
                Rule::unique(City::class)->ignore($city->exists ? $city : null),
            ],
        ], [
            'name.required' => __('Enter a city name.'),
            'name.max' => __('City names may not exceed 100 characters.'),
            'name.unique' => __('A city with this name already exists.'),
        ]);

        try {
            $city->fill(['name' => $validated['name']])->save();
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            $this->addError('name', __('A city with this name already exists.'));

            return;
        }

        $wasRecentlyCreated = $city->wasRecentlyCreated;

        $this->showCityModal = false;
        $this->resetCityForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('City created.') : __('City updated.'),
        );
    }

    public function confirmCityDeletion(int $cityId): void
    {
        $city = City::query()->withCount('projects')->findOrFail($cityId);

        $this->deletingCityId = $city->id;
        $this->deletingCityName = $city->name;
        $this->deleteError = $city->projects_count > 0
            ? trans_choice(
                'This city is assigned to :count project and cannot be deleted.|This city is assigned to :count projects and cannot be deleted.',
                $city->projects_count,
                ['count' => $city->projects_count],
            )
            : null;
        $this->showDeleteModal = true;
    }

    public function deleteCity(): void
    {
        $city = City::query()->findOrFail($this->deletingCityId);

        if ($city->projects()->exists()) {
            $this->setReferencedCityDeleteError($city);

            return;
        }

        try {
            $city->delete();
        } catch (QueryException $exception) {
            if ((string) $exception->getCode() !== '23000') {
                throw $exception;
            }

            $this->setReferencedCityDeleteError($city);

            return;
        }

        $this->showDeleteModal = false;
        $this->resetDeleteState();
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('City deleted.'));
    }

    public function closeCityModal(): void
    {
        $this->showCityModal = false;
        $this->resetCityForm();
    }

    public function closeDeleteModal(): void
    {
        $this->showDeleteModal = false;
        $this->resetDeleteState();
    }

    protected function resetCityForm(): void
    {
        $this->resetValidation();
        $this->editingCityId = null;
        $this->name = '';
    }

    protected function resetDeleteState(): void
    {
        $this->deletingCityId = null;
        $this->deletingCityName = '';
        $this->deleteError = null;
    }

    protected function setReferencedCityDeleteError(City $city): void
    {
        $projectsCount = $city->projects()->count();

        $this->deleteError = trans_choice(
            'This city is assigned to :count project and cannot be deleted.|This city is assigned to :count projects and cannot be deleted.',
            $projectsCount,
            ['count' => $projectsCount],
        );
    }
};
?>

@php
    $controlClasses = 'border-secondary-light! bg-white! text-secondary-dark! placeholder:text-secondary-light! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!';
    $primaryButtonClasses = 'border-primary! bg-primary! text-white! hover:border-primary! hover:bg-primary!';
    $secondaryButtonClasses = 'border-secondary-light! bg-white! text-secondary-dark! hover:bg-secondary-soft! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white! dark:hover:bg-secondary-medium!';
    $editButtonClasses = 'border-0! bg-transparent! text-secondary-dark! hover:bg-secondary-soft! dark:text-white! dark:hover:bg-secondary-medium!';
    $deleteButtonClasses = 'border-0! bg-transparent! text-primary! hover:bg-primary! hover:text-white!';
@endphp

<section
    class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white [&_[data-flux-cell]]:border-secondary-soft! [&_[data-flux-cell]]:text-secondary-medium! [&_[data-flux-column]]:border-secondary-soft! [&_[data-flux-column]]:text-secondary-dark! [&_[data-flux-heading]]:text-secondary-dark! [&_[data-flux-label]]:text-secondary-dark! [&_[data-flux-subheading]]:text-secondary-medium! dark:[&_[data-flux-cell]]:border-secondary-medium! dark:[&_[data-flux-cell]]:text-secondary-soft! dark:[&_[data-flux-column]]:border-secondary-medium! dark:[&_[data-flux-column]]:text-white! dark:[&_[data-flux-heading]]:text-white! dark:[&_[data-flux-label]]:text-white! dark:[&_[data-flux-subheading]]:text-secondary-soft!"
    aria-labelledby="city-directory-heading"
>
    <div class="flex flex-col gap-3 border-b border-secondary-soft pb-4 dark:border-secondary-medium @3xl/directory:flex-row @3xl/directory:items-end">
        <div class="min-w-40 @3xl/directory:me-auto">
            <div class="flex items-center gap-2">
                <flux:heading id="city-directory-heading" size="lg">{{ __('Cities') }}</flux:heading>
                <span class="inline-flex min-w-7 items-center justify-center rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
                    {{ $this->cities->total() }}
                </span>
            </div>
            <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('Manage the city records used by projects.') }}</p>
        </div>

        <div class="grid w-full gap-2 @sm/directory:grid-cols-[minmax(12rem,1fr)_auto] @3xl/directory:max-w-[32rem]">
            <flux:input
                type="search"
                icon="magnifying-glass"
                :label="__('Search cities')"
                :placeholder="__('City name')"
                :class="$controlClasses"
                wire:model.live.debounce.300ms="search"
                clearable
            />

            <flux:button
                variant="primary"
                icon="plus"
                :class="$primaryButtonClasses.' min-h-11 self-end touch-manipulation @sm/directory:min-h-10'"
                wire:click="openCreateModal"
                data-test="create-city-button"
            >
                {{ __('Add city') }}
            </flux:button>
        </div>
    </div>

    <div class="overflow-hidden rounded-lg border border-secondary-light bg-white dark:border-secondary-medium dark:bg-secondary-dark">
        <div class="flex min-h-10 flex-wrap items-center justify-between gap-2 border-b border-secondary-soft bg-white px-3.5 py-2 dark:border-secondary-medium dark:bg-secondary-dark">
            <p class="text-xs font-semibold text-secondary-medium dark:text-secondary-soft">
                {{ trans_choice('{1} :count matching city|[2,*] :count matching cities', $this->cities->total(), ['count' => $this->cities->total()]) }}
            </p>

            <div class="flex items-center gap-2">
                <span wire:loading.delay.flex wire:target="search,clearSearch" class="hidden items-center gap-1.5 text-xs text-secondary-medium dark:text-secondary-soft">
                    <flux:icon.arrow-path class="size-3.5 animate-spin" />
                    {{ __('Updating') }}
                </span>

                @if ($search !== '')
                    <flux:button
                        size="sm"
                        variant="ghost"
                        class="text-secondary-medium! hover:bg-secondary-soft! hover:text-secondary-dark! dark:text-secondary-soft! dark:hover:bg-secondary-medium! dark:hover:text-white!"
                        wire:click="clearSearch"
                    >
                        {{ __('Clear search') }}
                    </flux:button>
                @endif
            </div>
        </div>

        @if ($this->cities->isEmpty())
            <div class="flex min-h-56 flex-col items-center justify-center gap-3 px-5 py-10 text-center">
                <div class="flex size-11 items-center justify-center rounded-lg border border-secondary-light bg-white text-secondary-medium dark:bg-secondary-dark dark:text-secondary-soft">
                    <flux:icon.map-pin class="size-5" />
                </div>
                <div class="space-y-1">
                    <flux:heading size="base">{{ __('No matching cities') }}</flux:heading>
                    <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('Change the search and try again.') }}</p>
                </div>
                <flux:button size="sm" :class="$secondaryButtonClasses" wire:click="clearSearch">
                    {{ __('Clear search') }}
                </flux:button>
            </div>
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium @3xl/directory:hidden">
                @foreach ($this->cities as $city)
                    <article class="flex flex-col gap-3 p-3.5" wire:key="mobile-city-{{ $city->id }}">
                        <div class="flex min-w-0 items-center justify-between gap-3">
                            <div class="flex min-w-0 items-center gap-2.5">
                                <div class="flex size-9 shrink-0 items-center justify-center rounded-md bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white">
                                    <flux:icon.map-pin class="size-4.5" />
                                </div>
                                <p class="truncate text-sm font-semibold text-secondary-dark dark:text-white">{{ $city->name }}</p>
                            </div>

                            <span class="inline-flex shrink-0 rounded-md bg-secondary-soft px-2 py-1 text-xs font-semibold text-secondary-dark dark:bg-secondary-medium dark:text-white">
                                {{ trans_choice(':count project|:count projects', $city->projects_count, ['count' => $city->projects_count]) }}
                            </span>
                        </div>

                        <div class="flex min-h-11 items-center justify-between gap-3 border-t border-secondary-soft pt-2.5 dark:border-secondary-medium">
                            <span class="text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ __('Updated :date', ['date' => $city->updated_at?->format('M j, Y')]) }}
                            </span>

                            <div class="flex items-center justify-end gap-1">
                                <flux:button
                                    size="sm"
                                    icon="pencil-square"
                                    :class="$editButtonClasses.' size-11! touch-manipulation'"
                                    wire:click="openEditModal({{ $city->id }})"
                                    :aria-label="__('Edit :name', ['name' => $city->name])"
                                />
                                <flux:button
                                    size="sm"
                                    icon="trash"
                                    :class="$deleteButtonClasses.' size-11! touch-manipulation'"
                                    wire:click="confirmCityDeletion({{ $city->id }})"
                                    :aria-label="__('Delete :name', ['name' => $city->name])"
                                />
                            </div>
                        </div>
                    </article>
                @endforeach
            </div>

            <div class="hidden @3xl/directory:block">
                <flux:table class="table-fixed [&_[data-flux-cell]]:px-3.5! [&_[data-flux-cell]]:py-2! [&_[data-flux-column]]:px-3.5! [&_[data-flux-column]]:py-2.5! [&_tbody_tr]:transition-colors [&_tbody_tr]:hover:bg-secondary-soft dark:[&_tbody_tr]:hover:bg-secondary-medium">
                    <flux:table.columns class="bg-secondary-soft text-xs font-semibold dark:bg-secondary-medium">
                        <flux:table.column class="w-[50%]">{{ __('City') }}</flux:table.column>
                        <flux:table.column class="w-[16%]" align="center">{{ __('Projects') }}</flux:table.column>
                        <flux:table.column class="w-[22%]" align="center">{{ __('Last updated') }}</flux:table.column>
                        <flux:table.column class="w-[12%]" align="center">{{ __('Actions') }}</flux:table.column>
                    </flux:table.columns>

                    <flux:table.rows>
                        @foreach ($this->cities as $city)
                            <flux:table.row :key="$city->id">
                                <flux:table.cell variant="strong" class="text-secondary-dark! dark:text-white!">
                                    <div class="flex min-w-0 items-center gap-2.5">
                                        <div class="flex size-8 shrink-0 items-center justify-center rounded-md bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white">
                                            <flux:icon.map-pin class="size-4" />
                                        </div>
                                        <p class="truncate font-semibold">{{ $city->name }}</p>
                                    </div>
                                </flux:table.cell>
                                <flux:table.cell align="center">
                                    <span class="inline-flex min-w-8 items-center justify-center rounded-md bg-secondary-soft px-2 py-1 text-xs font-semibold text-secondary-dark dark:bg-secondary-medium dark:text-white">
                                        {{ $city->projects_count }}
                                    </span>
                                </flux:table.cell>
                                <flux:table.cell align="center">
                                    <time datetime="{{ $city->updated_at?->toDateString() }}">{{ $city->updated_at?->format('M j, Y') }}</time>
                                </flux:table.cell>
                                <flux:table.cell align="center">
                                    <div class="inline-flex items-center justify-center gap-1">
                                        <flux:button
                                            size="sm"
                                            icon="pencil-square"
                                            :class="$editButtonClasses.' size-8!'"
                                            wire:click="openEditModal({{ $city->id }})"
                                            :aria-label="__('Edit :name', ['name' => $city->name])"
                                        />
                                        <flux:button
                                            size="sm"
                                            icon="trash"
                                            :class="$deleteButtonClasses.' size-8!'"
                                            wire:click="confirmCityDeletion({{ $city->id }})"
                                            :aria-label="__('Delete :name', ['name' => $city->name])"
                                        />
                                    </div>
                                </flux:table.cell>
                            </flux:table.row>
                        @endforeach
                    </flux:table.rows>
                </flux:table>
            </div>

            <div class="border-t border-secondary-soft px-3 pb-3 dark:border-secondary-medium">
                <flux:pagination
                    :paginator="$this->cities"
                    class="text-secondary-medium! [&_button]:text-secondary-medium! [&_button:hover]:bg-secondary-soft! dark:text-secondary-soft! dark:[&_button]:text-secondary-soft! dark:[&_button:hover]:bg-secondary-medium!"
                    scroll-to="#city-directory-heading"
                />
            </div>
        @endif
    </div>

    <flux:modal
        wire:model="showCityModal"
        class="w-full max-w-lg border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!"
    >
        <form wire:submit="saveCity" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">
                    {{ $editingCityId === null ? __('Add city') : __('Edit city') }}
                </flux:heading>
                <flux:subheading>
                    {{ $editingCityId === null
                        ? __('Create a city record for project assignment.')
                        : __('Update this city record.') }}
                </flux:subheading>
            </div>

            <flux:input
                wire:model="name"
                :class="$controlClasses"
                :label="__('City name')"
                type="text"
                autocomplete="address-level2"
                maxlength="100"
                required
                autofocus
            />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <flux:button type="button" variant="filled" :class="$secondaryButtonClasses.' min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto'" wire:click="closeCityModal">
                    {{ __('Cancel') }}
                </flux:button>
                <flux:button type="submit" variant="primary" :class="$primaryButtonClasses.' min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto'" data-test="save-city-button">
                    {{ $editingCityId === null ? __('Create city') : __('Save changes') }}
                </flux:button>
            </div>
        </form>
    </flux:modal>

    <flux:modal
        wire:model="showDeleteModal"
        class="w-full max-w-lg border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!"
    >
        <form wire:submit="deleteCity" class="space-y-5">
            <div class="flex items-start gap-3">
                <div class="flex size-10 shrink-0 items-center justify-center rounded-lg bg-primary text-white">
                    <flux:icon.trash class="size-5" />
                </div>
                <div>
                    <flux:heading size="lg">
                        {{ $deleteError ? __('City cannot be deleted') : __('Delete city?') }}
                    </flux:heading>
                    <flux:subheading>
                        {{ $deleteError
                            ? __('Resolve the linked project records before deleting :name.', ['name' => $deletingCityName])
                            : __('Permanently delete :name. This action cannot be undone.', ['name' => $deletingCityName]) }}
                    </flux:subheading>
                </div>
            </div>

            @if ($deleteError)
                <div class="rounded-lg border border-primary bg-white p-3 text-sm text-primary dark:bg-primary dark:text-white" role="alert">
                    <p class="font-semibold">{{ __('Unable to delete city') }}</p>
                    <p>{{ $deleteError }}</p>
                </div>
            @endif

            <div class="grid gap-2 {{ $deleteError ? 'grid-cols-1' : 'grid-cols-2' }} sm:flex sm:justify-end">
                <flux:button type="button" variant="filled" :class="$secondaryButtonClasses.' min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto'" wire:click="closeDeleteModal">
                    {{ $deleteError ? __('Close') : __('Cancel') }}
                </flux:button>
                @if (! $deleteError)
                    <flux:button type="submit" :class="$primaryButtonClasses.' min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto'" data-test="confirm-delete-city-button">
                        {{ __('Delete city') }}
                    </flux:button>
                @endif
            </div>
        </form>
    </flux:modal>
</section>
