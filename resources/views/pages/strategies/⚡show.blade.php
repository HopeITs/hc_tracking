<?php

use App\Models\Program;
use App\Models\Strategy;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Strategy')] class extends Component {
    public int $strategyId;

    public function mount(Strategy $strategy): void
    {
        $this->strategyId = $strategy->id;
    }

    #[Computed]
    public function strategy(): Strategy
    {
        return Strategy::query()
            ->select(['id', 'name', 'start_year', 'end_year', 'updated_at'])
            ->withCount('programs')
            ->findOrFail($this->strategyId);
    }

    /** @return Collection<int, Program> */
    #[Computed]
    public function programs(): Collection
    {
        return Program::query()
            ->select(['id', 'name', 'strategy_id', 'manager_id'])
            ->where('strategy_id', $this->strategyId)
            ->with(['manager:id,first_name_en,last_name_en,first_name_ar,last_name_ar'])
            ->withCount(['projects', 'yearlyPlans'])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }
};
?>

<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white">
    <x-ui.detail-header
        :breadcrumbs="[
            ['label' => __('Strategies'), 'url' => route('strategies.index')],
            ['label' => $this->strategy->name],
        ]"
        :title="$this->strategy->name"
        :subtitle="__('Strategic period :start to :end', ['start' => $this->strategy->start_year, 'end' => $this->strategy->end_year])"
    >
        <x-slot:meta>
            <span>{{ trans_choice(':count program|:count programs', $this->strategy->programs_count, ['count' => $this->strategy->programs_count]) }}</span>
            <span>{{ __('Updated :date', ['date' => $this->strategy->updated_at?->format('M j, Y')]) }}</span>
        </x-slot:meta>
    </x-ui.detail-header>

    <div class="grid gap-3 @2xl/detail:grid-cols-3">
        <x-ui.stat-tile :label="__('Period start')" :value="$this->strategy->start_year" />
        <x-ui.stat-tile :label="__('Period end')" :value="$this->strategy->end_year" />
        <x-ui.stat-tile :label="__('Programs')" :value="$this->strategy->programs_count" :unit="__('total')" />
    </div>

    <x-ui.panel :title="__('Programs')" :count="$this->programs->count()" :subtitle="__('Delivery programs carried by this strategy.')">
        @if ($this->programs->isEmpty())
            <x-ui.empty-state
                icon="briefcase"
                :heading="__('No programs yet')"
                :body="__('Programs linked to this strategy will appear here.')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->programs as $program)
                    <article class="flex flex-col gap-3 p-3.5 @3xl/detail:flex-row @3xl/detail:items-center" wire:key="strategy-program-{{ $program->id }}">
                        <div class="min-w-0 @3xl/detail:me-auto">
                            @if (Route::has('programs.show'))
                                <a href="{{ route('programs.show', $program) }}" wire:navigate class="truncate text-sm font-semibold text-secondary-dark hover:underline dark:text-white">
                                    {{ $program->name }}
                                </a>
                            @else
                                <p class="truncate text-sm font-semibold text-secondary-dark dark:text-white">{{ $program->name }}</p>
                            @endif
                            <p class="text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ trans_choice(':count project|:count projects', $program->projects_count, ['count' => $program->projects_count]) }}
                                ·
                                {{ trans_choice(':count annual plan|:count annual plans', $program->yearly_plans_count, ['count' => $program->yearly_plans_count]) }}
                            </p>
                        </div>
                        <x-ui.employee-chip :employee="$program->manager" class="@3xl/detail:w-64" />
                    </article>
                @endforeach
            </div>
        @endif
    </x-ui.panel>
</section>
