<?php

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\RagStatus;
use App\Livewire\Concerns\Projects\ArchivesProjects;
use App\Livewire\Concerns\Projects\FiltersProjectDirectory;
use App\Livewire\Concerns\Projects\ManagesProjectForm;
use App\Livewire\Concerns\Projects\PresentsProjectDirectory;
use App\Livewire\Concerns\Projects\SummarisesProjectFunding;
use App\Livewire\Concerns\Projects\UnblocksProjects;
use App\Models\HrEmployee;
use App\Models\Program;
use App\Models\Project;
use App\Notifications\UnreadSubjects;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Layout('layouts::desk'), Title('titles.projects')] class extends Component {
    use ArchivesProjects;
    use FiltersProjectDirectory;
    use ManagesProjectForm;
    use PresentsProjectDirectory;
    use SummarisesProjectFunding;
    use UnblocksProjects;
    use WithPagination;

    private const LIST_PAGE_SIZE = 20;

    private const TIMELINE_PAGE_SIZE = 15;

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
    public string $sector = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: 'list')]
    public string $view = 'list';

    #[Url(except: 'name')]
    public string $sort = 'name';

    #[Url(except: '')]
    public string $lens = '';

    /**
     * Records with unread notifications for me, for the row dots.
     *
     * @return list<int>
     */
    #[Computed]
    public function unreadIds(): array
    {
        return app(UnreadSubjects::class)->ids(auth()->user(), 'project');
    }

    /** @return LengthAwarePaginator<int, Project> */
    #[Computed]
    public function projects(): LengthAwarePaginator
    {
        $query = Project::query()
            ->select([
                'id',
                'slug',
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
                'program:id,strategy_id',
                'program.strategy:id,name',
                'user:id,first_name_en,last_name_en,first_name_ar,last_name_ar',
                'ownerAssignment',
                'proposals:proposals.id,proposals.status',
            ])
            // Only the list view's preview shows the sectors.
            ->when($this->view === 'list', fn (Builder $query) => $query->with([
                'sectors' => fn ($query) => $query->select(['sectors.id', 'sectors.name_ar', 'sectors.name_en', 'sectors.is_active'])->ordered(),
            ]))
            ->when($this->view === 'timeline', fn (Builder $query) => $query->with([
                'milestones' => fn ($query) => $query
                    ->select(['id', 'project_id', 'name', 'planned_start_date', 'planned_end_date', 'actual_end_date', 'status'])
                    ->where('status', '!=', MilestoneStatus::Cancelled->value)
                    ->reorder()
                    ->orderBy('planned_start_date'),
            ]))
            ->withCount([
                'risks',
                'milestones',
                'planIndicators',
                'proposals',
                'indicators',
                'media' => fn ($query) => $query->whereNotIn('collection', $this->hiddenCollections()),
                'risks as materialised_risks_count' => fn ($query) => $query->where('happened', true),
                'milestones as reached_milestones_count' => fn ($query) => $query->where('status', MilestoneStatus::Reached->value),
            ])
            ->withSum(['milestones as reached_weight' => fn ($query) => $query->where('status', MilestoneStatus::Reached->value)], 'weight_percentage')
            ->withSum(['milestones as planned_weight' => fn ($query) => $query->where('status', '!=', MilestoneStatus::Cancelled->value)], 'weight_percentage');

        return $this->applySort($this->applyFilters($query))
            ->paginate($this->view === 'list' ? self::LIST_PAGE_SIZE : self::TIMELINE_PAGE_SIZE);
    }

    /** @return Collection<int, Program> */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::options();
    }

    /** @return Collection<int, HrEmployee> */
    #[Computed]
    public function userOptions(): Collection
    {
        return HrEmployee::pickerOptions();
    }

    /** Whether the list behind the "projects without a center" warning is open. */
    public bool $showProjectsWithoutCenter = false;

    /**
     * How many projects are not yet mapped to an HR center (D-30c), counted
     * only for those who manage the portfolio; nobody else pays for the query.
     */
    #[Computed]
    public function projectsWithoutCenterCount(): int
    {
        if (! (auth()->user()?->can('projects.delete') ?? false)) {
            return 0;
        }

        return Project::query()->whereNull('center_id')->count();
    }

    /**
     * The unmapped projects themselves, loaded only once the warning is opened.
     *
     * @return Collection<int, Project>
     */
    #[Computed]
    public function projectsWithoutCenter(): Collection
    {
        if (! $this->showProjectsWithoutCenter || $this->projectsWithoutCenterCount === 0) {
            return new Collection;
        }

        return Project::query()
            ->whereNull('center_id')
            ->orderBy('name')
            ->get(['id', 'slug', 'name']);
    }
};
?>

@php
    /* The class block above already imports every symbol this view uses; a
       single-file page compiles both halves into one PHP file, so repeating
       the `use` statements here would be a redeclaration. */
    $filtersAreActive = $search !== '' || $city !== '' || $program !== '' || $owner !== '' || $sector !== '' || $status !== '' || $lens !== '';
    $today = CarbonImmutable::today();

    $statusCounts = $this->statusCounts;
    $triageCounts = $this->triageCounts;
    $fundingByProject = $this->fundingByProject;
    $canViewMoney = $this->canViewMoney();
    $projects = $this->projects;

    $loadingTargets = 'search, city, program, owner, sector, status, lens, sort, view, toggleStatus, toggleLens, clearFilters, gotoPage, nextPage, previousPage';

    /* Two ways to read the portfolio: a scannable list, and the same projects on a calendar. */
    $viewOptions = [
        'list' => ['icon' => 'table', 'label' => __('projects.view_table'), 'test' => 'project-view-list'],
        'timeline' => ['icon' => 'chart', 'label' => __('projects.view_timeline'), 'test' => 'project-view-timeline'],
    ];

    /* The filters that live behind the one Filters button, as removable chips once set. */
    $activeFilterChips = array_filter([
        'city' => $city !== '' ? $this->cityOptions->firstWhere('id', (int) $city)?->name : null,
        'program' => $program !== '' ? $this->programOptions->firstWhere('id', (int) $program)?->name : null,
        'owner' => $owner !== '' ? (HrEmployee::find((int) $owner)?->name ?? '#'.$owner) : null,
        'sector' => $sector !== '' ? $this->sectorOptions->firstWhere('id', (int) $sector)?->localizedName() : null,
    ], fn (?string $label): bool => $label !== null);
    $activeFilterLabels = [
        'city' => __('common.city'),
        'program' => __('common.program'),
        'owner' => __('projects.owner'),
        'sector' => __('sectors.sectors'),
    ];

    $sortOptions = [
        'name' => __('projects.sort_name'),
        'start_date' => __('projects.sort_start_date'),
        'end_date' => __('projects.sort_end_date'),
        'updated_at' => __('projects.sort_recently_updated'),
    ];

    $lensTiles = [
        'unfunded' => ['label' => __('projects.lens_unfunded'), 'hint' => __('projects.lens_unfunded_hint')],
        'overdue' => ['label' => __('projects.lens_overdue'), 'hint' => __('projects.lens_overdue_hint')],
        'slipping' => ['label' => __('projects.lens_slipping'), 'hint' => __('projects.lens_slipping_hint')],
        'risks' => ['label' => __('projects.lens_risks'), 'hint' => __('projects.lens_risks_hint')],
    ];

    /* How much of a project's own window has passed, as a percentage. */
    $elapsedOf = function (Project $project) use ($today): ?float {
        if ($project->start_date === null || $project->end_date === null || $project->end_date->lt($project->start_date)) {
            return null;
        }

        $span = max(1, $project->end_date->getTimestamp() - $project->start_date->getTimestamp());

        return min(100, max(0, ($today->getTimestamp() - $project->start_date->getTimestamp()) / $span * 100));
    };

    /* The schedule verdict, rated with RagStatus so this page and the program
       timeline can never disagree about what "slipping" means. Only two
       appearances exist — needs attention (primary) and calm (grey) — so the
       words carry the meaning and the colour only raises a hand. */
    $scheduleOf = function (Project $project) use ($today, $elapsedOf): array {
        $progress = $this->progressFor($project);
        $elapsed = $elapsedOf($project);
        $isFinished = in_array($project->status, [ProjectStatus::Closed, ProjectStatus::Archived], true);

        return match (true) {
            $project->start_date === null || $project->end_date === null => ['label' => __('projects.unscheduled'), 'icon' => 'calendar', 'alert' => false, 'tone' => 'neutral'],
            $isFinished => ['label' => $project->status->label(), 'icon' => 'archive-box', 'alert' => false, 'tone' => 'neutral'],
            $today->gt($project->end_date) && ($progress ?? 0) < 100 => ['label' => __('programs.timeline_past_end'), 'icon' => 'exclamation-circle', 'alert' => true, 'tone' => 'critical'],
            $progress === null => ['label' => __('programs.timeline_unmeasured'), 'icon' => 'minus-circle', 'alert' => false, 'tone' => 'neutral'],
            $progress >= 100 => ['label' => __('programs.timeline_complete'), 'icon' => 'check-circle', 'alert' => false, 'tone' => 'good'],
            $today->lt($project->start_date) => ['label' => __('programs.timeline_not_started'), 'icon' => 'clock', 'alert' => false, 'tone' => 'neutral'],
            default => match (RagStatus::rate($progress, (float) $elapsed)) {
                RagStatus::Green => ['label' => __('programs.timeline_on_track'), 'icon' => 'check-circle', 'alert' => false, 'tone' => 'good'],
                RagStatus::Amber => ['label' => __('programs.timeline_slipping'), 'icon' => 'exclamation-triangle', 'alert' => true, 'tone' => 'warn'],
                RagStatus::Red => ['label' => __('programs.timeline_behind'), 'icon' => 'exclamation-circle', 'alert' => true, 'tone' => 'critical'],
            },
        };
    };

    /* Plural forms with the count left as a marker, so the modal's Alpine
       hint can pick the right Arabic range without a round trip. */
    $durationForms = [
        1 => trans_choice('projects.duration_days', 1, ['count' => '__N__']),
        2 => trans_choice('projects.duration_days', 2, ['count' => '__N__']),
        3 => trans_choice('projects.duration_days', 3, ['count' => '__N__']),
        11 => trans_choice('projects.duration_days', 11, ['count' => '__N__']),
    ];

    /* Month names in the reader's language (Syrian names in Arabic), so the
       project modal's live duration range matches every other date here. */
    $monthNames = collect(range(1, 12))
        ->map(fn (int $month): string => CarbonImmutable::create(2000, $month, 1)->translatedFormat(__('formats.month_short')))
        ->all();
@endphp

<x-desk.register
    class="admin-directory @container/directory"
    heading-id="project-directory-heading"
    :title="__('common.projects')"
    :count="$projects->total()"
    :view="$view"
    :views="$viewOptions"
>
    <x-slot:actions>
        @can('create', App\Models\Project::class)
            <button type="button" class="dk-btn dk-primary" wire:click="openCreateModal" data-test="create-project-button" data-quick-create><x-desk.icon name="plus" />{{ __('projects.add_project') }}</button>
        @endcan
    </x-slot:actions>
    <x-slot:search>
        <x-desk.register-search :label="__('projects.search_projects')" :placeholder="__('projects.project_city_program_or_owner')" wire:model.live.debounce.300ms="search" />
    </x-slot:search>
    <x-slot:figures>
        <span><b>{{ number_format($this->committedBeneficiaries) }}</b>{{ __('projects.tile_beneficiaries') }}</span>
        @if ($canViewMoney)
            @foreach ($this->committedFunding as $fundingCurrency => $fundingTotal)
                <span wire:key="committed-{{ $fundingCurrency }}"><b><x-ui.money :amount="$fundingTotal" :currency="$fundingCurrency" /></b>{{ __('projects.tile_committed_funding') }}</span>
            @endforeach
        @endif
    </x-slot:figures>
    <x-slot:filters>
        {{-- Every "narrow it down" choice sits behind one button; what is set shows as chips below. --}}
        <div class="dk-pmenu" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
            <button type="button" class="dk-btn" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="dialog" data-test="project-filters-button">
                <x-desk.icon name="grid" />{{ __('projects.filters') }}
                @if ($activeFilterChips !== [])
                    <span class="dk-pc dk-num">{{ count($activeFilterChips) }}</span>
                @endif
            </button>

            <div class="dk-pop dk-pjf-pop" role="dialog" aria-label="{{ __('projects.filters') }}" x-show="open" x-cloak x-transition.opacity.duration.120ms>
                <x-ui.header-filter-select :label="__('common.city')" wire:model.live="city" :value="$city">
                    <option value="">{{ __('projects.all_cities') }}</option>
                    @foreach ($this->cityOptions as $cityOption)
                        <option value="{{ $cityOption->id }}">{{ $cityOption->name }} · {{ $cityOption->secondary_name }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('common.program')" wire:model.live="program" :value="$program">
                    <option value="">{{ __('common.all_programs') }}</option>
                    @foreach ($this->programOptions as $programOption)
                        <option value="{{ $programOption->id }}">{{ $programOption->name }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('projects.owner')" wire:model.live="owner" :value="$owner" employees>
                    <option value="">{{ __('projects.all_owners') }}</option>
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('sectors.sectors')" wire:model.live="sector" :value="$sector">
                    <option value="">{{ __('sectors.all_sectors') }}</option>
                    @foreach ($this->sectorOptions as $sectorOption)
                        <option value="{{ $sectorOption->id }}">{{ $sectorOption->localizedName() }}@unless ($sectorOption->is_active) · {{ __('sectors.inactive') }}@endunless</option>
                    @endforeach
                </x-ui.header-filter-select>
            </div>
        </div>

        <x-ui.header-filter-select :label="__('projects.sort_by')" wire:model.live="sort" :value="$sort">
            @foreach ($sortOptions as $sortValue => $sortLabel)
                <option value="{{ $sortValue }}">{{ $sortLabel }}</option>
            @endforeach
        </x-ui.header-filter-select>

        @if ($filtersAreActive)
            <button type="button" class="dk-btn dk-ghost dk-sm" wire:click="clearFilters"><x-desk.icon name="x" />{{ __('desk.register.clear') }}</button>
        @endif
    </x-slot:filters>


    {{-- A housekeeping note for portfolio managers, kept to one line until opened. --}}
    @if ($this->projectsWithoutCenterCount > 0)
        <div class="dk-pjnote" data-test="projects-without-center">
            <x-desk.icon name="info" />
            <p>
                <strong>{{ __('projects.projects_without_center') }} ({{ $this->projectsWithoutCenterCount }})</strong>
                <span class="dk-pjnote-hint">{{ __('projects.projects_without_center_hint') }}</span>
                <button type="button" wire:click="$toggle('showProjectsWithoutCenter')" aria-expanded="{{ $showProjectsWithoutCenter ? 'true' : 'false' }}">
                    {{ $showProjectsWithoutCenter ? __('projects.hide_projects_without_center') : __('projects.show_projects_without_center') }}
                </button>
                @if ($showProjectsWithoutCenter)
                    <span class="dk-pjnote-list">
                        @foreach ($this->projectsWithoutCenter as $unmappedProject)
                            <a href="{{ route('projects.show', $unmappedProject) }}" wire:navigate>{{ $unmappedProject->name }}</a>
                        @endforeach
                    </span>
                @endif
            </p>
        </div>
    @endif

    @include('pages.projects._index-summary')

    @if ($projects->isEmpty())
        <x-ui.panel>
            <x-ui.empty-state
                icon="rectangle-stack"
                :heading="$filtersAreActive ? __('projects.no_matching_projects') : __('common.no_projects_yet')"
                :body="$filtersAreActive ? __('common.change_the_search_or_filters_and_try_again') : __('projects.add_the_first_project_and_place_it_in_a_city_and_program')"
            >
                <x-slot:action>
                    @if ($filtersAreActive)
                        <x-ui.button size="sm" wire:click="clearFilters">{{ __('common.reset_filters') }}</x-ui.button>
                    @else
                        @can('create', App\Models\Project::class)<x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateModal">{{ __('projects.add_project') }}</x-ui.button>@endcan
                    @endif
                </x-slot:action>
            </x-ui.empty-state>
        </x-ui.panel>
    @else
        <div class="flex flex-col gap-4 transition-opacity motion-reduce:transition-none" wire:loading.class="opacity-60" wire:target="{{ $loadingTargets }}">
            @if ($view === 'timeline')
                @include('pages.projects._index-timeline')
            @else
                @include('pages.projects._index-list')
            @endif

            @if ($projects->hasPages())
                <flux:pagination :paginator="$projects" class="text-secondary-medium! dark:text-ink-muted!" scroll-to="#project-directory-heading" />
            @endif
        </div>
    @endif

    @include('pages.projects._index-project-modal')

    <x-ui.modal
        wire:model="showUnblockModal"
        size="md"
        :title="__('projects.unblock_title')"
        submit="unblockProject"
        close="closeUnblockModal"
    >
        @if ($showUnblockModal)
            <p class="mb-3 text-sm text-secondary-medium dark:text-ink-muted">{{ __('projects.unblock_help') }}</p>
            <x-ui.form-textarea
                wire:model="unblockNote"
                :label="__('projects.assignment_note_optional')"
                rows="4"
                data-test="unblock-note"
            />
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="unblock-submit-button">{{ __('projects.unblock') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    {{-- Archiving is reversible, so the dialog explains where the work goes, not what is lost. --}}
    <x-ui.confirm-modal
        wire:model="showArchiveModal"
        close="cancelArchive"
        tone="neutral"
        :title="__('projects.archive_this_project')"
        :message="$this->archivingProject !== null ? __('projects.archiving_keeps_everything', ['name' => $this->archivingProject['project']->name]) : null"
        confirm="archiveProject"
        :confirm-label="__('projects.archive_project')"
        confirm-test="confirm-archive-project"
    >
        @if ($this->archivingProject !== null && $this->archivingProject['carries'] !== [])
            <div class="rounded-lg border border-board-line bg-board-head p-3">
                <p class="type-label text-secondary-medium dark:text-ink-muted">{{ __('projects.kept_with_the_project') }}</p>
                <ul class="mt-1.5 flex flex-col gap-0.5">
                    @foreach ($this->archivingProject['carries'] as $carriedLabel => $carriedCount)
                        <li class="text-sm tabular-nums text-secondary-dark dark:text-ink" wire:key="carried-{{ $loop->index }}">{{ $carriedCount }} {{ $carriedLabel }}</li>
                    @endforeach
                </ul>
            </div>
        @endif
    </x-ui.confirm-modal>
</x-desk.register>
