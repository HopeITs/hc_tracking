<?php

use App\Enums\ProjectStatus;
use App\Enums\ProposalPhase;
use App\Livewire\Concerns\BuildsPortfolioGantt;
use App\Models\Program;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * The department-wide Gantt, in three lenses on the same portfolio:
 *
 * - portfolio: project groups (program, status or owner) → project →
 *   milestones, with every project's links, float and critical path;
 * - connected: the portfolio with its money — the proposals that fund those
 *   projects, their transfers and donor reports, and the relations between
 *   them, so selecting any bar traces its whole route;
 * - pipeline: every proposal through its lifecycle with the projects it funds.
 *
 * The connected lens shows proposals and the pipeline lens is built from them,
 * so both need proposals.view: a viewer without it is limited to the portfolio
 * lens, the pipeline option is hidden and its URL value is ignored.
 *
 * Read-only by design — schedules are edited on each project's Plan tab,
 * where links, baselines and undo live.
 */
new #[Title('titles.timeline')] class extends Component {
    use BuildsPortfolioGantt;

    /** The lenses the page offers, in tab order. */
    public const VIEWS = ['portfolio', 'connected', 'pipeline'];

    #[Url(except: 'portfolio')]
    public string $view = 'portfolio';

    #[Url(except: 'program')]
    public string $group = 'program';

    #[Url(except: '')]
    public string $program = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $phase = '';

    public function mount(): void
    {
        $this->updatedView();
        $this->updatedGroup();
    }

    /**
     * The lens actually drawn: the requested one, unless the viewer may not
     * open it.
     */
    protected function activeView(): string
    {
        if ($this->view === 'pipeline' && ! $this->canViewProposals()) {
            return 'portfolio';
        }

        return $this->view;
    }

    /**
     * Whether the viewer may see proposals, which the pipeline lens is made of.
     */
    protected function canViewProposals(): bool
    {
        return auth()->user()?->can('proposals.view') ?? false;
    }

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function payload(): array
    {
        $programId = ctype_digit($this->program) ? (int) $this->program : null;
        $status = ProjectStatus::tryFrom($this->status)?->value;

        return match ($this->activeView()) {
            'pipeline' => $this->pipelineGanttPayload(ProposalPhase::tryFrom($this->phase)?->value),
            'connected' => $this->connectedGanttPayload($programId, $status, $this->group),
            default => $this->portfolioGanttPayload($programId, $status, $this->group),
        };
    }

    /**
     * @return Collection<int, Program>
     */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::options();
    }

    /**
     * Rows that stand for a real record, for the header count.
     */
    #[Computed]
    public function recordCount(): int
    {
        $kind = $this->activeView() === 'pipeline' ? 'proposal' : 'project';

        return collect($this->payload['rows'])->where('kind', $kind)->count();
    }

    /**
     * The group-by choices the chart toolbar offers, or none for the pipeline
     * (it is always grouped by phase).
     *
     * @return array<string, string>
     */
    #[Computed]
    public function groupOptions(): array
    {
        if ($this->activeView() === 'pipeline') {
            return [];
        }

        return [
            'program' => __('gantt.group_by_options.program'),
            'status' => __('gantt.group_by_options.status'),
            'owner' => __('gantt.group_by_options.owner'),
        ];
    }

    public function updatedView(): void
    {
        if (! in_array($this->view, self::VIEWS, true) || $this->activeView() !== $this->view) {
            $this->view = 'portfolio';
        }
    }

    public function updatedGroup(): void
    {
        if (! in_array($this->group, self::TIMELINE_GROUPINGS, true)) {
            $this->group = 'program';
        }
    }
};
?>

@php
    $activeView = $this->activeView();
    $views = [
        'portfolio' => ['key' => 'portfolio', 'label' => __('gantt.portfolio'), 'icon' => 'briefcase', 'subtitle' => __('gantt.portfolio_subtitle')],
        'connected' => ['key' => 'connected', 'label' => __('gantt.connected'), 'icon' => 'arrows-right-left', 'subtitle' => __('gantt.connected_subtitle')],
        'pipeline' => ['key' => 'pipeline', 'label' => __('gantt.pipeline'), 'icon' => 'funnel', 'subtitle' => __('gantt.pipeline_subtitle')],
    ];

    if (! $this->canViewProposals()) {
        unset($views['pipeline']);
    }
@endphp

<section class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-5 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" aria-labelledby="timeline-heading">
    <x-ui.page-header
        heading-id="timeline-heading"
        :title="__('titles.timeline')"
        :count="$this->recordCount"
    >
        <x-slot:filters>
            @if ($activeView === 'pipeline')
                <x-ui.header-filter-select :label="__('gantt.phase')" wire:model.live="phase" :value="$phase">
                    <option value="">{{ __('gantt.all_statuses') }}</option>
                    @foreach (ProposalPhase::cases() as $phaseOption)
                        <option value="{{ $phaseOption->value }}">{{ $phaseOption->label() }}</option>
                    @endforeach
                </x-ui.header-filter-select>
            @else
                <x-ui.header-filter-select :label="__('common.program')" wire:model.live="program" :value="$program">
                    <option value="">{{ __('gantt.all_programs') }}</option>
                    @foreach ($this->programOptions as $programOption)
                        <option value="{{ $programOption->id }}">{{ $programOption->name }}</option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('common.status')" wire:model.live="status" :value="$status">
                    <option value="">{{ __('gantt.all_statuses') }}</option>
                    @foreach (ProjectStatus::cases() as $statusOption)
                        <option value="{{ $statusOption->value }}">{{ $statusOption->label() }}</option>
                    @endforeach
                </x-ui.header-filter-select>
            @endif
        </x-slot:filters>
    </x-ui.page-header>

    <div class="relative rounded-xl border border-secondary-soft bg-white shadow-card dark:border-transparent dark:bg-surface-1 dark:shadow-raised" data-timeline-board>
        {{-- The three lenses on the same portfolio --}}
        <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-b border-board-line px-4 py-3">
            <x-ui.view-toggle wire-model="view" :active="$activeView" :options="array_values($views)" :label="__('gantt.view')" class="max-w-full overflow-x-auto [&>button]:shrink-0 [&>button]:whitespace-nowrap" />
            <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ $views[$activeView]['subtitle'] }}</p>
        </div>

        <div wire:loading.delay class="pointer-events-none absolute inset-x-4 top-0 h-0.5 animate-pulse rounded-full bg-status-active" aria-hidden="true"></div>

        {{-- Keyed by the view, grouping and filters so the chart starts fresh on every change. --}}
        <div wire:key="timeline-gantt-{{ $activeView }}-{{ $group }}-{{ $program }}-{{ $status }}-{{ $phase }}" wire:loading.delay.class="opacity-60" class="transition-opacity">
            @if ($this->payload['rows'] === [])
                <div class="p-6">
                    <x-ui.empty-state icon="chart-bar" :heading="__('gantt.nothing_to_plan')" :body="__('gantt.nothing_matches')" />
                </div>
            @else
                <x-ui.gantt
                    :payload="$this->payload"
                    :scope="'timeline-'.$activeView"
                    explorer
                    :group-by="$activeView === 'pipeline' ? null : $group"
                    :group-options="$this->groupOptions"
                    height="h-[min(74vh,52rem)]"
                    wire:ignore
                />
            @endif
        </div>
    </div>
</section>
