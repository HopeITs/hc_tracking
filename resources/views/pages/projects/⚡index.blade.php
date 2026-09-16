<?php

use App\Enums\ProjectStatus;
use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\Program;
use App\Models\Project;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('titles.projects')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $city = '';

    #[Url(except: '')]
    public string $program = '';

    #[Url(except: '')]
    public string $strategy = '';

    #[Url(except: '')]
    public string $owner = '';

    #[Url(except: '')]
    public string $status = '';

    public bool $showProjectModal = false;

    public ?int $editingProjectId = null;

    public string $name = '';

    public string $description = '';

    public string $projectStatus = 'draft';

    public string $startDate = '';

    public string $endDate = '';

    public string $targetBeneficiaries = '';

    public string $targetMen = '';

    public string $targetWomen = '';

    public string $targetBoys = '';

    public string $targetGirls = '';

    public string $cityId = '';

    public string $programId = '';

    public string $userId = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedCity(): void
    {
        $this->resetPage();
    }

    public function updatedProgram(): void
    {
        $this->resetPage();
    }

    public function updatedStrategy(): void
    {
        $this->resetPage();
    }

    public function updatedOwner(): void
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
        $this->city = '';
        $this->program = '';
        $this->strategy = '';
        $this->owner = '';
        $this->status = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Project> */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        $search = trim($this->search);

        // Cities are in the HR database, so a city-name search resolves to ids
        // first instead of a whereHas() across connections.
        $matchingCityIds = $search === '' ? [] : HrCity::query()
            ->where('name_en', 'like', '%'.$search.'%')
            ->orWhere('name_ar', 'like', '%'.$search.'%')
            ->pluck('id')
            ->all();

        return Project::query()
            ->select([
                'id',
                'name',
                'status',
                'start_date',
                'end_date',
                'target_beneficiaries',
                'target_men',
                'target_women',
                'target_boys',
                'target_girls',
                'city_id',
                'program_id',
                'user_id',
                'updated_at',
            ])
            ->with([
                'city:id,name_en,name_ar',
                'program:id,name',
                'user:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
            ])
            ->withCount(['risks', 'milestones', 'planIndicators', 'proposals'])
            ->when($search !== '', function ($query) use ($search, $matchingCityIds): void {
                $query->where(function ($query) use ($search, $matchingCityIds): void {
                    $query
                        ->where('name', 'like', '%'.$search.'%')
                        ->orWhereIn('city_id', $matchingCityIds)
                        ->orWhereHas('program', fn ($query) => $query->where('name', 'like', '%'.$search.'%'))
                        ->orWhereIn('user_id', HrEmployee::idsMatchingName($search));
                });
            })
            ->when($this->city !== '', fn ($query) => $query->where('city_id', (int) $this->city))
            ->when($this->program !== '', fn ($query) => $query->where('program_id', (int) $this->program))
            ->when($this->strategy !== '', fn ($query) => $query->whereHas('program', fn ($query) => $query->where('strategy_id', (int) $this->strategy)))
            ->when($this->owner !== '', fn ($query) => $query->where('user_id', (int) $this->owner))
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(15);
    }

    /** @return Collection<int, HrCity> */
    #[Computed]
    public function cityOptions(): Collection
    {
        return HrCity::query()->select(['id', 'name_en', 'name_ar'])->orderBy(HrCity::nameColumn())->orderBy('id')->get();
    }

    /** @return Collection<int, Program> */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /** @return Collection<int, HrEmployee> */
    #[Computed]
    public function userOptions(): Collection
    {
        return HrEmployee::pickerOptions();
    }

    public function openCreateModal(): void
    {
        $this->resetProjectForm();
        $this->showProjectModal = true;
    }

    public function openEditModal(int $projectId): void
    {
        $project = Project::query()
            ->select([
                'id',
                'name',
                'description',
                'status',
                'start_date',
                'end_date',
                'target_beneficiaries',
                'target_men',
                'target_women',
                'target_boys',
                'target_girls',
                'city_id',
                'program_id',
                'user_id',
            ])
            ->findOrFail($projectId);

        $this->resetValidation();
        $this->editingProjectId = $project->id;
        $this->name = $project->name;
        $this->description = $project->description ?? '';
        $this->projectStatus = $project->status->value;
        $this->startDate = $project->start_date?->toDateString() ?? '';
        $this->endDate = $project->end_date?->toDateString() ?? '';
        $this->targetBeneficiaries = self::formInteger($project->target_beneficiaries);
        $this->targetMen = self::formInteger($project->target_men);
        $this->targetWomen = self::formInteger($project->target_women);
        $this->targetBoys = self::formInteger($project->target_boys);
        $this->targetGirls = self::formInteger($project->target_girls);
        $this->cityId = (string) $project->city_id;
        $this->programId = (string) $project->program_id;
        $this->userId = (string) $project->user_id;
        $this->showProjectModal = true;
    }

    public function saveProject(): void
    {
        $this->name = trim($this->name);
        $this->description = trim($this->description);

        $validated = $this->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'projectStatus' => ['required', Rule::enum(ProjectStatus::class)],
            'startDate' => ['nullable', 'date'],
            'endDate' => [
                'nullable',
                'date',
                Rule::when($this->startDate !== '', ['after_or_equal:startDate']),
            ],
            'targetBeneficiaries' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'targetMen' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'targetWomen' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'targetBoys' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'targetGirls' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'cityId' => ['required', 'integer', Rule::exists(HrCity::class, 'id')],
            'programId' => ['required', 'integer', Rule::exists(Program::class, 'id')],
            'userId' => ['required', 'integer', Rule::exists(HrEmployee::class, 'id')],
        ], [
            'name.required' => __('projects.enter_a_project_name'),
            'cityId.required' => __('projects.select_a_city_2'),
            'cityId.exists' => __('projects.the_selected_city_is_no_longer_available'),
            'programId.required' => __('projects.select_a_program_2'),
            'programId.exists' => __('projects.the_selected_program_is_no_longer_available'),
            'userId.required' => __('projects.select_a_project_owner'),
            'userId.exists' => __('projects.the_selected_owner_is_no_longer_available'),
            'endDate.after_or_equal' => __('projects.the_end_date_must_be_on_or_after_the_start_date'),
        ]);

        $project = $this->editingProjectId === null
            ? new Project
            : Project::query()->findOrFail($this->editingProjectId);

        $project->fill([
            'name' => $validated['name'],
            'description' => $validated['description'] === '' ? null : $validated['description'],
            'status' => ProjectStatus::from($validated['projectStatus']),
            'start_date' => self::nullableString($validated['startDate']),
            'end_date' => self::nullableString($validated['endDate']),
            'target_beneficiaries' => self::nullableInteger($validated['targetBeneficiaries']),
            'target_men' => self::nullableInteger($validated['targetMen']),
            'target_women' => self::nullableInteger($validated['targetWomen']),
            'target_boys' => self::nullableInteger($validated['targetBoys']),
            'target_girls' => self::nullableInteger($validated['targetGirls']),
            'city_id' => (int) $validated['cityId'],
            'program_id' => (int) $validated['programId'],
            'user_id' => (int) $validated['userId'],
        ])->save();

        $wasRecentlyCreated = $project->wasRecentlyCreated;

        $this->showProjectModal = false;
        $this->resetProjectForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $wasRecentlyCreated ? __('projects.project_created') : __('projects.project_updated'),
        );
    }

    public function closeProjectModal(): void
    {
        $this->showProjectModal = false;
        $this->resetProjectForm();
    }

    protected function resetProjectForm(): void
    {
        $this->resetValidation();
        $this->editingProjectId = null;
        $this->name = '';
        $this->description = '';
        $this->projectStatus = ProjectStatus::Draft->value;
        $this->startDate = '';
        $this->endDate = '';
        $this->targetBeneficiaries = '';
        $this->targetMen = '';
        $this->targetWomen = '';
        $this->targetBoys = '';
        $this->targetGirls = '';
        $this->cityId = '';
        $this->programId = '';
        $this->userId = '';
    }

    protected static function nullableString(mixed $value): ?string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    protected static function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    protected static function formInteger(?int $value): string
    {
        return $value === null ? '' : (string) $value;
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $city !== '' || $program !== '' || $owner !== '' || $status !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="project-directory-heading">
    <x-ui.page-header
        heading-id="project-directory-heading"
        :title="__('common.projects')"
        :count="$this->projects->total()"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('projects.search_projects')" :placeholder="__('projects.project_city_program_or_owner')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.header-filter-select :label="__('common.city')" wire:model.live="city" :value="$city">
                <flux:select.option value="">{{ __('projects.all_cities') }}</flux:select.option>
                @foreach ($this->cityOptions as $cityOption)
                    <flux:select.option :value="$cityOption->id" :wire:key="'city-filter-'.$cityOption->id">{{ $cityOption->name }} · {{ $cityOption->secondary_name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.program')" wire:model.live="program" :value="$program">
                <flux:select.option value="">{{ __('common.all_programs') }}</flux:select.option>
                @foreach ($this->programOptions as $programOption)
                    <flux:select.option :value="$programOption->id" :wire:key="'program-filter-'.$programOption->id">{{ $programOption->name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('projects.owner')" wire:model.live="owner" :value="$owner">
                <flux:select.option value="">{{ __('projects.all_owners') }}</flux:select.option>
                @foreach ($this->userOptions as $userOption)
                    <flux:select.option :value="$userOption->id" :wire:key="'owner-filter-'.$userOption->id">{{ $userOption->name }} · {{ $userOption->secondary_name }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>

            <x-ui.header-filter-select :label="__('common.status')" wire:model.live="status" :value="$status">
                <flux:select.option value="">{{ __('common.all_statuses') }}</flux:select.option>
                @foreach (ProjectStatus::cases() as $statusOption)
                    <flux:select.option :value="$statusOption->value" :wire:key="'status-filter-'.$statusOption->value">{{ $statusOption->label() }}</flux:select.option>
                @endforeach
            </x-ui.header-filter-select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-project-button">
                {{ __('projects.add_project') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('projects.count_matching_project', $this->projects->total(), ['count' => $this->projects->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! hover:bg-secondary-soft! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('common.reset_filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->projects->isEmpty())
            <x-ui.empty-state
                icon="rectangle-stack"
                :heading="$filtersAreActive ? __('projects.no_matching_projects') : __('common.no_projects_yet')"
                :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('projects.add_the_first_project_and_place_it_in_a_city_and_program')"
            >
                <x-slot:action>
                    @if ($filtersAreActive)
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('common.reset_filters') }}</x-ui.button>
                    @else
                        <x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateModal">{{ __('projects.add_project') }}</x-ui.button>
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->projects as $project)
                    <article class="grid gap-3 p-3.5 @3xl/directory:grid-cols-[minmax(0,1.4fr)_minmax(12rem,0.8fr)_minmax(11rem,0.7fr)_auto] @3xl/directory:items-center" wire:key="project-{{ $project->id }}">
                        <div class="min-w-0">
                            <div class="flex flex-wrap items-center gap-2">
                                <a href="{{ route('projects.show', $project) }}" wire:navigate class="text-sm font-semibold hover:underline">{{ $project->name }}</a>
                                <x-ui.enum-badge :case="$project->status" />
                            </div>
                            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ $project->city?->name ?? __('common.unknown_city') }} · {{ $project->program->name }}
                            </p>
                            <p class="mt-1 text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                {{ $project->start_date?->translatedFormat(__('formats.date')) ?? '—' }} → {{ $project->end_date?->translatedFormat(__('formats.date')) ?? '—' }}
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs font-medium text-secondary-medium dark:text-secondary-soft">
                            <span>{{ __('projects.headline_count', ['count' => $project->target_beneficiaries === null ? '—' : number_format($project->target_beneficiaries)]) }}</span>
                            <span>{{ __('projects.breakdown_count', ['count' => $project->targetDemographicTotal() === null ? '—' : number_format($project->targetDemographicTotal())]) }}</span>
                            <span>{{ trans_choice('common.count_risk', $project->risks_count, ['count' => $project->risks_count]) }}</span>
                            <span>{{ trans_choice('common.count_milestone', $project->milestones_count, ['count' => $project->milestones_count]) }}</span>
                            <span>{{ trans_choice('common.count_target', $project->plan_indicators_count, ['count' => $project->plan_indicators_count]) }}</span>
                            <span>{{ trans_choice('projects.count_proposal', $project->proposals_count, ['count' => $project->proposals_count]) }}</span>
                        </div>

                        <x-ui.employee-chip :employee="$project->user" />

                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! shrink-0 touch-manipulation @3xl/directory:size-8!" wire:click="openEditModal({{ $project->id }})" :aria-label="__('common.edit_name', ['name' => $project->name])" />
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->projects" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#project-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showProjectModal" class="w-full max-w-4xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        <form wire:submit="saveProject" class="flex flex-col gap-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingProjectId === null ? __('projects.add_project') : __('projects.edit_project') }}</flux:heading>
                <flux:subheading>{{ __('projects.define_delivery_ownership_lifecycle_schedule_and_beneficiary') }}</flux:subheading>
            </div>

            <div class="grid gap-4 @2xl/directory:grid-cols-2">
                <x-ui.input wire:model="name" :label="__('projects.project_name')" type="text" maxlength="255" required autofocus />

                <x-ui.select wire:model="projectStatus" :label="__('common.status')" required>
                    @foreach (ProjectStatus::cases() as $statusOption)
                        <flux:select.option :value="$statusOption->value" :wire:key="'project-status-'.$statusOption->value">{{ $statusOption->label() }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <flux:textarea wire:model="description" :label="__('common.description')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid gap-4 @2xl/directory:grid-cols-3">
                <x-ui.select wire:model="cityId" :label="__('common.city')" required>
                    <flux:select.option value="">{{ __('projects.select_a_city') }}</flux:select.option>
                    @foreach ($this->cityOptions as $cityOption)
                        <flux:select.option :value="$cityOption->id" :wire:key="'city-option-'.$cityOption->id">{{ $cityOption->name }} · {{ $cityOption->secondary_name }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="programId" :label="__('common.program')" required>
                    <flux:select.option value="">{{ __('projects.select_a_program') }}</flux:select.option>
                    @foreach ($this->programOptions as $programOption)
                        <flux:select.option :value="$programOption->id" :wire:key="'program-option-'.$programOption->id">{{ $programOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="userId" :label="__('projects.owner')" required>
                    <flux:select.option value="">{{ __('projects.select_an_owner') }}</flux:select.option>
                    @foreach ($this->userOptions as $userOption)
                        <flux:select.option :value="$userOption->id" :wire:key="'owner-option-'.$userOption->id">{{ $userOption->name }} · {{ $userOption->secondary_name }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-4 @2xl/directory:grid-cols-2">
                <x-ui.input wire:model="startDate" :label="__('common.start_date')" type="date" />
                <x-ui.input wire:model="endDate" :label="__('projects.end_date')" type="date" />
            </div>

            <fieldset class="flex flex-col gap-3 rounded-lg border border-secondary-soft p-4 dark:border-secondary-medium">
                <div>
                    <legend class="text-sm font-semibold">{{ __('projects.beneficiary_targets') }}</legend>
                    <p class="text-xs text-secondary-medium dark:text-secondary-soft">{{ __('projects.the_headline_commitment_and_demographic_breakdown_are_record') }}</p>
                </div>

                <div class="grid gap-4 @2xl/directory:grid-cols-5">
                    <x-ui.input wire:model="targetBeneficiaries" :label="__('projects.headline_total')" type="number" min="0" step="1" />
                    <x-ui.input wire:model="targetMen" :label="__('projects.men')" type="number" min="0" step="1" />
                    <x-ui.input wire:model="targetWomen" :label="__('projects.women')" type="number" min="0" step="1" />
                    <x-ui.input wire:model="targetBoys" :label="__('projects.boys')" type="number" min="0" step="1" />
                    <x-ui.input wire:model="targetGirls" :label="__('projects.girls')" type="number" min="0" step="1" />
                </div>
            </fieldset>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closeProjectModal">{{ __('common.cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" data-test="save-project-button">
                    {{ $editingProjectId === null ? __('projects.create_project') : __('common.save_changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>
</section>
