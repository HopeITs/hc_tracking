<?php

use App\Enums\ProjectStatus;
use App\Enums\ReportTemplateStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\HrEmployee;
use App\Models\ProposalLocation;
use App\Support\Locations\MonthlyReportsAtRows;
use App\Models\ReportTemplate;
use App\Support\MealCoverage;
use App\Support\Reporting\PlaceBridge;
use App\Support\ReportTemplatePrefiller;
use Flux\Flux;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Report templates status, for MEAL and managers (report-templates.manage):
 * every running project with where its monthly-report template stands —
 * missing (needs creating), pending (waiting for MEAL to confirm) or
 * approved — its responsible MEAL officer and a link to the project, where
 * templates are made (there is no template page of its own). Missing first,
 * then pending, then approved.
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * The order and words of the three states.
     */
    public const array States = ['missing', 'pending', 'approved'];

    /**
     * @return Collection<int, array{project: Project, state: string, lines: int}>
     */
    #[Computed]
    public function rows(): Collection
    {
        if (! auth()->user()->can('report-templates.manage')) {
            return new Collection;
        }

        // Y-16 (D-93): one line per **place** waiting for its report template,
        // which is where a template's place points (D-75).
        $projects = ProposalLocation::query()
            ->select(['id', 'proposal_id', 'project_id', 'slug', 'status', 'city_id', 'city_wide', 'program_id', 'responsible_employee_id'])
            ->whereIn('proposal_locations.status', [ProjectStatus::Active, ProjectStatus::Approved])
            ->with(['city:id,name_en,name_ar', 'program:id', 'centers', 'proposal:id,name'])
            ->get()
            ->sortBy(fn (ProposalLocation $row): string => $row->headline())
            ->values();
        $templateIds = app(MonthlyReportsAtRows::class)->templateIdsByRow($projects);
        $templates = ReportTemplate::query()
            ->select(['id', 'status'])
            ->whereKey(array_merge(...array_values($templateIds)))
            ->withCount('lines')
            ->get()
            ->keyBy('id');

        return $projects
            ->map(function (ProposalLocation $project) use ($templateIds, $templates): array {
                $template = $templates->get($templateIds[$project->id][0] ?? 0);

                return [
                    'project' => $project,
                    'state' => match ($template?->status) {
                        null => 'missing',
                        ReportTemplateStatus::Pending => 'pending',
                        default => 'approved',
                    },
                    'lines' => (int) ($template?->lines_count ?? 0),
                ];
            })
            ->sortBy(fn (array $row): int => array_search($row['state'], self::States, true))
            ->values();
    }

    /**
     * row id => responsible MEAL officer's name (Y-16, D-93).
     *
     * @return array<int, string>
     */
    #[Computed]
    public function officers(): array
    {
        $byProject = $this->rows->mapWithKeys(fn (array $row): array => [
            $row['project']->id => MealCoverage::responsibleForLocation($row['project']),
        ]);
        $names = HrEmployee::query()->whereKey($byProject->flatten()->unique()->values()->all())
            ->get(HrEmployee::NameColumns)->pluck('name', 'id');

        return $byProject->map(fn (array $ids): string => $names->only($ids)->implode(', '))->all();
    }

    /**
     * "Create" on a place with no template: pre-fill it now from the
     * place's donor-approved proposal targets and its HC.* annual-plan
     * targets (ReportTemplatePrefiller, the same writer the donor approval
     * runs), then open the place's Report template tab. A place with no
     * target to plan gets no template, and the toast says so.
     *
     * The pre-filler still writes against the project the place carries
     * (Y-17 moves it), reached through `project_id`.
     */
    public function createTemplate(int $locationId, ReportTemplatePrefiller $prefiller, PlaceBridge $places): void
    {
        Gate::authorize('report-templates.manage');

        $row = $this->rows->first(fn (array $row): bool => $row['project']->id === $locationId);

        if ($row === null) {
            return;
        }

        $project = $row['project'];
        $prefiller->prefillProject((int) $project->project_id);

        if ($places->templateIdsOfProject((int) $project->project_id) === []) {
            Flux::toast(variant: 'warning', text: __('desk.template_nothing_to_plan', ['project' => $project->headline()]));

            return;
        }

        unset($this->rows);
        $this->redirectRoute('projects.show', ['project' => $project, 'tab' => 'template'], navigate: true);
    }

    /**
     * @return array<string, string>
     */
    protected function facetsOf(mixed $row): array
    {
        return ['status' => __('desk.template_state.'.$row['state'])];
    }
};
?>

@php
    $rows = $this->rows;
    $counts = $rows->countBy('state');
    $pg = $this->paginate($rows, self::PerPage);
@endphp

<x-desk.panel :empty="$rows->isEmpty()" :facets="$pg['facets']" id="templates" register="report-templates.index" :size="$size" :cols="$gridCols" :height="$gridRows" :index="$index" :total="$total" :count="($counts['missing'] ?? 0) + ($counts['pending'] ?? 0) ?: null">
    @if ($rows->isNotEmpty())
        <p class="dk-pl-sum">{{ __('desk.templates_summary', ['missing' => $counts['missing'] ?? 0, 'pending' => $counts['pending'] ?? 0, 'approved' => $counts['approved'] ?? 0]) }}</p>
    @endif
    @if ($pg['total'] > 0)
        <ul class="dk-tp" {{ $this->fitAttributes($pg) }}>
            @foreach ($pg['slice'] as $row)
                @php
                    $officer = $this->officers[$row['project']->id] ?? '';
                @endphp
                <li wire:key="tp-{{ $row['project']->id }}">
                    <span class="dk-tp-n" title="{{ $row['project']->headline() }} · {{ $row['project']->label() }}">{{ $row['project']->headline() }}</span>
                    <span @class(['dk-pill dk-tp-s', 'dk-bad' => $row['state'] === 'missing', 'dk-warn' => $row['state'] === 'pending', 'dk-good' => $row['state'] === 'approved'])>{{ __('desk.template_state.'.$row['state']) }}</span>
                    <span class="dk-tp-m dk-is">@if ($row['state'] !== 'missing'){{ trans_choice('desk.lines_count', $row['lines'], ['count' => $row['lines']]) }} · @endif{{ $officer !== '' ? $officer : __('desk.no_meal_officer') }}</span>
                    @if ($row['state'] === 'missing')
                        <button type="button" class="dk-btn dk-sm dk-primary dk-tp-a" wire:click="createTemplate({{ $row['project']->id }})" wire:loading.attr="disabled" wire:target="createTemplate({{ $row['project']->id }})" data-template-create="{{ $row['project']->id }}">{{ __('desk.template_action.missing') }}</button>
                    @else
                        <a href="{{ route('projects.show', ['project' => $row['project'], 'tab' => 'template']) }}" wire:navigate @class(['dk-btn dk-sm dk-tp-a', 'dk-primary' => $row['state'] !== 'approved'])>{{ __('desk.template_action.'.$row['state']) }}</a>
                    @endif
                </li>
            @endforeach
        </ul>
    @else
        <p class="dk-pempty">{{ __('desk.panel.templates.empty') }}</p>
    @endif

    <x-slot:foot>
        <x-desk.pager :pg="$pg" />
    </x-slot:foot>
</x-desk.panel>
