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

new #[Title('Projects')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $city = '';

    #[Url(except: '')]
    public string $program = '';

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
                'city:id,name_en',
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
        return HrCity::query()->select(['id', 'name_en', 'name_ar'])->orderBy('name_en')->orderBy('id')->get();
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
            'name.required' => __('Enter a project name.'),
            'cityId.required' => __('Select a city.'),
            'cityId.exists' => __('The selected city is no longer available.'),
            'programId.required' => __('Select a program.'),
            'programId.exists' => __('The selected program is no longer available.'),
            'userId.required' => __('Select a project owner.'),
            'userId.exists' => __('The selected owner is no longer available.'),
            'endDate.after_or_equal' => __('The end date must be on or after the start date.'),
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
            text: $wasRecentlyCreated ? __('Project created.') : __('Project updated.'),
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
        :title="__('Projects')"
        :count="$this->projects->total()"
        :description="__('Track delivery ownership, lifecycle, schedule, and beneficiary commitments.')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('Search projects')" :placeholder="__('Project, city, program, or owner')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('City')" wire:model.live="city">
                <flux:select.option value="">{{ __('All cities') }}</flux:select.option>
                @foreach ($this->cityOptions as $cityOption)
                    <flux:select.option :value="$cityOption->id" :wire:key="'city-filter-'.$cityOption->id">{{ $cityOption->name_en }} · {{ $cityOption->name_ar }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Program')" wire:model.live="program">
                <flux:select.option value="">{{ __('All programs') }}</flux:select.option>
                @foreach ($this->programOptions as $programOption)
                    <flux:select.option :value="$programOption->id" :wire:key="'program-filter-'.$programOption->id">{{ $programOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Owner')" wire:model.live="owner">
                <flux:select.option value="">{{ __('All owners') }}</flux:select.option>
                @foreach ($this->userOptions as $userOption)
                    <flux:select.option :value="$userOption->id" :wire:key="'owner-filter-'.$userOption->id">{{ $userOption->name_en }} · {{ $userOption->name_ar }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('Status')" wire:model.live="status">
                <flux:select.option value="">{{ __('All statuses') }}</flux:select.option>
                @foreach (ProjectStatus::cases() as $statusOption)
                    <flux:select.option :value="$statusOption->value" :wire:key="'status-filter-'.$statusOption->value">{{ str($statusOption->value)->headline() }}</flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-project-button">
                {{ __('Add project') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('{1} :count matching project|[2,*] :count matching projects', $this->projects->total(), ['count' => $this->projects->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! hover:bg-secondary-soft! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->projects->isEmpty())
            <x-ui.empty-state
                icon="rectangle-stack"
                :heading="$filtersAreActive ? __('No matching projects') : __('No projects yet')"
                :body="$filtersAreActive ? __('Change the search or filters and try again.') : __('Add the first project and place it in a city and program.')"
            >
                <x-slot:action>
                    @if ($filtersAreActive)
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('Reset filters') }}</x-ui.button>
                    @else
                        <x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateModal">{{ __('Add project') }}</x-ui.button>
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
                                {{ $project->city?->name_en ?? __('Unknown city') }} · {{ $project->program->name }}
                            </p>
                            <p class="mt-1 text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                {{ $project->start_date?->format('M j, Y') ?? '—' }} → {{ $project->end_date?->format('M j, Y') ?? '—' }}
                            </p>
                        </div>

                        <div class="grid grid-cols-2 gap-x-3 gap-y-1 text-xs font-medium text-secondary-medium dark:text-secondary-soft">
                            <span>{{ __('Headline: :count', ['count' => $project->target_beneficiaries === null ? '—' : number_format($project->target_beneficiaries)]) }}</span>
                            <span>{{ __('Breakdown: :count', ['count' => $project->targetDemographicTotal() === null ? '—' : number_format($project->targetDemographicTotal())]) }}</span>
                            <span>{{ trans_choice(':count risk|:count risks', $project->risks_count, ['count' => $project->risks_count]) }}</span>
                            <span>{{ trans_choice(':count milestone|:count milestones', $project->milestones_count, ['count' => $project->milestones_count]) }}</span>
                            <span>{{ trans_choice(':count target|:count targets', $project->plan_indicators_count, ['count' => $project->plan_indicators_count]) }}</span>
                            <span>{{ trans_choice(':count proposal|:count proposals', $project->proposals_count, ['count' => $project->proposals_count]) }}</span>
                        </div>

                        <x-ui.employee-chip :employee="$project->user" />

                        <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-11! shrink-0 touch-manipulation @3xl/directory:size-8!" wire:click="openEditModal({{ $project->id }})" :aria-label="__('Edit :name', ['name' => $project->name])" />
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
                <flux:heading size="lg">{{ $editingProjectId === null ? __('Add project') : __('Edit project') }}</flux:heading>
                <flux:subheading>{{ __('Define delivery ownership, lifecycle, schedule, and beneficiary commitments.') }}</flux:subheading>
            </div>

            <div class="grid gap-4 @2xl/directory:grid-cols-2">
                <x-ui.input wire:model="name" :label="__('Project name')" type="text" maxlength="255" required autofocus />

                <x-ui.select wire:model="projectStatus" :label="__('Status')" required>
                    @foreach (ProjectStatus::cases() as $statusOption)
                        <flux:select.option :value="$statusOption->value" :wire:key="'project-status-'.$statusOption->value">{{ str($statusOption->value)->headline() }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <flux:textarea wire:model="description" :label="__('Description')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:border-secondary-light! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid gap-4 @2xl/directory:grid-cols-3">
                <x-ui.select wire:model="cityId" :label="__('City')" required>
                    <flux:select.option value="">{{ __('Select a city') }}</flux:select.option>
                    @foreach ($this->cityOptions as $cityOption)
                        <flux:select.option :value="$cityOption->id" :wire:key="'city-option-'.$cityOption->id">{{ $cityOption->name_en }} · {{ $cityOption->name_ar }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="programId" :label="__('Program')" required>
                    <flux:select.option value="">{{ __('Select a program') }}</flux:select.option>
                    @foreach ($this->programOptions as $programOption)
                        <flux:select.option :value="$programOption->id" :wire:key="'program-option-'.$programOption->id">{{ $programOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.select>

                <x-ui.select wire:model="userId" :label="__('Owner')" required>
                    <flux:select.option value="">{{ __('Select an owner') }}</flux:select.option>
                    @foreach ($this->userOptions as $userOption)
                        <flux:select.option :value="$userOption->id" :wire:key="'owner-option-'.$userOption->id">{{ $userOption->name_en }} · {{ $userOption->name_ar }}</flux:select.option>
                    @endforeach
                </x-ui.select>
            </div>

            <div class="grid gap-4 @2xl/directory:grid-cols-2">
                <x-ui.input wire:model="startDate" :label="__('Start date')" type="date" />
                <x-ui.input wire:model="endDate" :label="__('End date')" type="date" />
            </div>

            <fieldset class="flex flex-col gap-3 rounded-lg border border-secondary-soft p-4 dark:border-secondary-medium">
                <div>
                    <legend class="text-sm font-semibold">{{ __('Beneficiary targets') }}</legend>
                    <p class="text-xs text-secondary-medium dark:text-secondary-soft">{{ __('The headline commitment and demographic breakdown are recorded independently.') }}</p>
                </div>

                <div class="grid gap-4 @2xl/directory:grid-cols-5">
                    <x-ui.input wire:model="targetBeneficiaries" :label="__('Headline total')" type="number" min="0" step="1" />
                    <x-ui.input wire:model="targetMen" :label="__('Men')" type="number" min="0" step="1" />
                    <x-ui.input wire:model="targetWomen" :label="__('Women')" type="number" min="0" step="1" />
                    <x-ui.input wire:model="targetBoys" :label="__('Boys')" type="number" min="0" step="1" />
                    <x-ui.input wire:model="targetGirls" :label="__('Girls')" type="number" min="0" step="1" />
                </div>
            </fieldset>

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" wire:click="closeProjectModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full touch-manipulation sm:min-h-10 sm:w-auto" data-test="save-project-button">
                    {{ $editingProjectId === null ? __('Create project') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
    </flux:modal>
</section>
