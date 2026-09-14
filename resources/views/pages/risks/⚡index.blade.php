<?php

use App\Models\Project;
use App\Models\Risk;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Risks')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $project = '';

    #[Url(except: '')]
    public string $status = '';

    public bool $showRiskModal = false;

    public ?int $editingRiskId = null;

    public ?int $deletingRiskId = null;

    public string $name = '';

    public string $impact = '';

    public string $solution = '';

    public bool $happened = false;

    public string $riskProjectId = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedProject(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->project = '';
        $this->status = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Risk> */
    #[Computed]
    public function risks(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Risk::query()
            ->select(['id', 'name', 'impact', 'solution', 'happened', 'project_id', 'updated_at'])
            ->with(['project:id,name,city_id', 'project.city:id,name'])
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhere('impact', 'like', '%'.$search.'%')
                        ->orWhereHas('project', fn ($query) => $query->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($this->project !== '', fn ($query) => $query->where('project_id', (int) $this->project))
            ->when($this->status === 'happened', fn ($query) => $query->where('happened', true))
            ->when($this->status === 'watching', fn ($query) => $query->where('happened', false))
            ->orderByDesc('happened')
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);
    }

    /** @return Collection<int, Project> */
    #[Computed]
    public function projectOptions(): Collection
    {
        return Project::query()
            ->select(['id', 'name'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }

    public function openCreateModal(): void
    {
        $this->resetRiskForm();
        $this->showRiskModal = true;
    }

    public function openEditModal(int $riskId): void
    {
        $risk = Risk::query()
            ->select(['id', 'name', 'impact', 'solution', 'happened', 'project_id'])
            ->findOrFail($riskId);

        $this->resetValidation();
        $this->editingRiskId = $risk->id;
        $this->name = $risk->name;
        $this->impact = $risk->impact ?? '';
        $this->solution = $risk->solution ?? '';
        $this->happened = $risk->happened;
        $this->riskProjectId = (string) $risk->project_id;
        $this->showRiskModal = true;
    }

    public function saveRisk(): void
    {
        $this->name = trim($this->name);
        $this->impact = trim($this->impact);
        $this->solution = trim($this->solution);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'impact' => ['required', 'string'],
            'solution' => ['nullable', 'string'],
            'happened' => ['boolean'],
            'riskProjectId' => ['required', 'integer', Rule::exists(Project::class, 'id')],
        ], [
            'name.required' => __('Enter a short risk name.'),
            'impact.required' => __('Describe what happens if this risk materialises.'),
            'riskProjectId.required' => __('Select the project this risk affects.'),
            'riskProjectId.exists' => __('The selected project is no longer available.'),
        ]);

        $risk = $this->editingRiskId === null
            ? new Risk
            : Risk::query()->findOrFail($this->editingRiskId);

        $risk->fill([
            'name' => $validated['name'],
            'impact' => $validated['impact'],
            'solution' => $validated['solution'] === '' ? null : $validated['solution'],
            'happened' => $validated['happened'],
            'project_id' => (int) $validated['riskProjectId'],
        ])->save();

        $wasRecentlyCreated = $risk->wasRecentlyCreated;

        $this->showRiskModal = false;
        $this->resetRiskForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('Risk logged.') : __('Risk updated.'),
        );
    }

    public function confirmDelete(int $riskId): void
    {
        $this->deletingRiskId = $riskId;
    }

    /**
     * Risks delete freely: no foreign key anywhere points at this table, so
     * removing one cannot take another row with it.
     */
    public function deleteRisk(): void
    {
        if ($this->deletingRiskId === null) {
            return;
        }

        Risk::query()->findOrFail($this->deletingRiskId)->delete();

        $this->deletingRiskId = null;
        $this->resetPage();

        Flux::toast(variant: 'success', text: __('Risk deleted.'));
    }

    public function closeRiskModal(): void
    {
        $this->showRiskModal = false;
        $this->resetRiskForm();
    }

    protected function resetRiskForm(): void
    {
        $this->resetValidation();
        $this->editingRiskId = null;
        $this->name = '';
        $this->impact = '';
        $this->solution = '';
        $this->happened = false;
        $this->riskProjectId = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $project !== '' || $status !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="risk-register-heading">
    <x-ui.page-header
        heading-id="risk-register-heading"
        :title="__('Risks')"
        :count="$this->risks->total()"
        :description="__('Risks logged against delivery projects, materialised first.')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('Search risks')" :placeholder="__('Risk, impact, or project')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('Project')" wire:model.live="project">
                <flux:select.option value="">{{ __('All projects') }}</flux:select.option>
                @foreach ($this->projectOptions as $projectOption)
                    <flux:select.option :value="$projectOption->id" :wire:key="'risk-project-filter-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('State')" wire:model.live="status">
                <flux:select.option value="">{{ __('All risks') }}</flux:select.option>
                <flux:select.option value="happened">{{ __('Materialised') }}</flux:select.option>
                <flux:select.option value="watching">{{ __('Watching') }}</flux:select.option>
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-risk-button">
                {{ __('Log risk') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('{1} :count matching risk|[2,*] :count matching risks', $this->risks->total(), ['count' => $this->risks->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! hover:bg-secondary-soft! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->risks->isEmpty())
            <x-ui.empty-state
                icon="exclamation-triangle"
                :heading="$filtersAreActive ? __('No matching risks') : __('No risks logged')"
                :body="$filtersAreActive ? __('Change the search or filters and try again.') : __('Log the first risk against a project.')"
            >
                <x-slot:action>
                    @if ($filtersAreActive)
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('Reset filters') }}</x-ui.button>
                    @else
                        <x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateModal">{{ __('Log risk') }}</x-ui.button>
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->risks as $risk)
                    <article class="flex flex-col gap-2 p-3.5" wire:key="risk-{{ $risk->id }}">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold">{{ $risk->name }}</p>
                                <a href="{{ route('projects.show', $risk->project) }}" wire:navigate class="text-xs text-secondary-medium hover:underline dark:text-secondary-soft">
                                    {{ $risk->project->name }} · {{ $risk->project->city->name }}
                                </a>
                            </div>
                            <div class="flex shrink-0 items-center gap-1.5">
                                @if ($risk->happened)
                                    <span class="rounded-md border border-primary bg-primary px-2 py-0.5 text-xs font-semibold text-white">{{ __('Materialised') }}</span>
                                @else
                                    <span class="rounded-md border border-secondary-light px-2 py-0.5 text-xs font-semibold text-secondary-medium dark:text-secondary-soft">{{ __('Watching') }}</span>
                                @endif
                                <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! touch-manipulation @sm/directory:size-9!" wire:click="openEditModal({{ $risk->id }})" :aria-label="__('Edit :name', ['name' => $risk->name])" />
                                <x-ui.button tone="ghost" size="sm" icon="trash" class="size-11! touch-manipulation @sm/directory:size-9!" wire:click="confirmDelete({{ $risk->id }})" :aria-label="__('Delete :name', ['name' => $risk->name])" />
                            </div>
                        </div>
                        <p class="text-xs text-secondary-medium dark:text-secondary-soft"><span class="font-semibold">{{ __('Impact') }}:</span> {{ $risk->impact ?? '—' }}</p>
                        @if ($risk->solution !== null)
                            <p class="text-xs text-secondary-medium dark:text-secondary-soft"><span class="font-semibold">{{ __('Mitigation') }}:</span> {{ $risk->solution }}</p>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->risks" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#risk-register-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showRiskModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveRisk" class="flex flex-col gap-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingRiskId === null ? __('Log risk') : __('Edit risk') }}</flux:heading>
                <flux:subheading>{{ __('Record what could go wrong, what it would cost, and how it is mitigated.') }}</flux:subheading>
            </div>

            <x-ui.input wire:model="name" :label="__('Risk name')" type="text" maxlength="255" required autofocus />

            <flux:textarea wire:model="impact" :label="__('Impact')" rows="3" required class="border-secondary-light! bg-white! text-secondary-dark! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!" />

            <flux:textarea wire:model="solution" :label="__('Mitigation')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.select wire:model="riskProjectId" :label="__('Project')" required>
                    <flux:select.option value="">{{ __('Select a project') }}</flux:select.option>
                    @foreach ($this->projectOptions as $projectOption)
                        <flux:select.option :value="$projectOption->id" :wire:key="'risk-project-option-'.$projectOption->id">{{ $projectOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <flux:checkbox wire:model="happened" :label="__('This risk has materialised')" class="self-end" />
            </div>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closeRiskModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" data-test="save-risk-button">
                    {{ $editingRiskId === null ? __('Log risk') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>

    <flux:modal :open="$deletingRiskId !== null" wire:close="$set('deletingRiskId', null)" class="w-full max-w-md border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <div class="flex flex-col gap-4">
            <flux:heading size="lg">{{ __('Delete this risk?') }}</flux:heading>
            <flux:subheading>{{ __('The risk and its mitigation notes are removed. Nothing else is affected.') }}</flux:subheading>
            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="$set('deletingRiskId', null)">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="button" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="deleteRisk" data-test="confirm-delete-risk">{{ __('Delete risk') }}</x-ui.button>
            </div>
        </div>
    </flux:modal>
</section>
