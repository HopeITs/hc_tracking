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
 * The department-wide Gantt: every project and its milestones (portfolio), or
 * every proposal through its lifecycle with the projects it funds (pipeline).
 *
 * Read-only by design — schedules are edited on each project's Plan tab,
 * where links, baselines and undo live.
 */
new #[Title('titles.timeline')] class extends Component {
    use BuildsPortfolioGantt;

    #[Url(except: 'portfolio')]
    public string $view = 'portfolio';

    #[Url(except: '')]
    public string $program = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $phase = '';

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function payload(): array
    {
        if ($this->view === 'pipeline') {
            return $this->pipelineGanttPayload(ProposalPhase::tryFrom($this->phase)?->value);
        }

        $programId = ctype_digit($this->program) ? (int) $this->program : null;

        return $this->portfolioGanttPayload($programId, ProjectStatus::tryFrom($this->status)?->value);
    }

    /**
     * @return Collection<int, Program>
     */
    #[Computed]
    public function programOptions(): Collection
    {
        return Program::query()->select(['id', 'name'])->orderBy('name')->orderBy('id')->get();
    }

    /**
     * Rows that stand for a real record, for the header count.
     */
    #[Computed]
    public function recordCount(): int
    {
        $kind = $this->view === 'pipeline' ? 'proposal' : 'project';

        return collect($this->payload['rows'])->where('kind', $kind)->count();
    }

    public function updatedView(): void
    {
        if (! in_array($this->view, ['portfolio', 'pipeline'], true)) {
            $this->view = 'portfolio';
        }
    }
};
?>

<section class="admin-directory @container/directory flex min-h-full w-full flex-1 flex-col gap-5 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="timeline-heading">
    <x-ui.page-header
        heading-id="timeline-heading"
        :title="__('titles.timeline')"
        :count="$this->recordCount"
    >
        <x-slot:filters>
            <x-ui.header-filter-select :label="__('gantt.view')" wire:model.live="view" :value="$view === 'pipeline' ? 'pipeline' : 'portfolio'">
                <flux:select.option value="portfolio">{{ __('gantt.portfolio') }}</flux:select.option>
                <flux:select.option value="pipeline">{{ __('gantt.pipeline') }}</flux:select.option>
            </x-ui.header-filter-select>

            @if ($view === 'pipeline')
                <x-ui.header-filter-select :label="__('gantt.phase')" wire:model.live="phase" :value="$phase">
                    <flux:select.option value="">{{ __('gantt.all_statuses') }}</flux:select.option>
                    @foreach (ProposalPhase::cases() as $phaseOption)
                        <flux:select.option :value="$phaseOption->value" :wire:key="'timeline-phase-'.$phaseOption->value">{{ $phaseOption->label() }}</flux:select.option>
                    @endforeach
                </x-ui.header-filter-select>
            @else
                <x-ui.header-filter-select :label="__('common.program')" wire:model.live="program" :value="$program">
                    <flux:select.option value="">{{ __('gantt.all_programs') }}</flux:select.option>
                    @foreach ($this->programOptions as $programOption)
                        <flux:select.option :value="$programOption->id" :wire:key="'timeline-program-'.$programOption->id">{{ $programOption->name }}</flux:select.option>
                    @endforeach
                </x-ui.header-filter-select>

                <x-ui.header-filter-select :label="__('common.status')" wire:model.live="status" :value="$status">
                    <flux:select.option value="">{{ __('gantt.all_statuses') }}</flux:select.option>
                    @foreach (ProjectStatus::cases() as $statusOption)
                        <flux:select.option :value="$statusOption->value" :wire:key="'timeline-status-'.$statusOption->value">{{ $statusOption->label() }}</flux:select.option>
                    @endforeach
                </x-ui.header-filter-select>
            @endif
        </x-slot:filters>
    </x-ui.page-header>

    <x-ui.panel
        :title="$view === 'pipeline' ? __('gantt.pipeline') : __('gantt.portfolio')"
        :subtitle="$view === 'pipeline' ? __('gantt.pipeline_subtitle') : __('gantt.portfolio_subtitle')"
    >
        {{-- Keyed by the filters so the chart starts fresh on every change. --}}
        <div wire:key="timeline-gantt-{{ $view }}-{{ $program }}-{{ $status }}-{{ $phase }}">
            @if ($this->payload['rows'] === [])
                <x-ui.empty-state icon="chart-bar" :heading="__('gantt.nothing_to_plan')" :body="__('gantt.nothing_matches')" />
            @else
                <x-ui.gantt :payload="$this->payload" :scope="'timeline-'.$view" wire:ignore />
            @endif
        </div>
    </x-ui.panel>
</section>
