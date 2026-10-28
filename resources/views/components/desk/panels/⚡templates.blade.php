<?php

use App\Enums\ProjectStatus;
use App\Enums\ReportTemplateStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\HrEmployee;
use App\Models\Project;
use App\Support\MealCoverage;
use Illuminate\Support\Collection;
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

        return Project::query()
            ->select(['id', 'slug', 'name', 'status', 'city_id', 'center_id', 'program_id', 'user_id'])
            ->whereIn('status', [ProjectStatus::Active, ProjectStatus::Approved])
            ->with(['reportTemplate' => fn ($query) => $query->select(['id', 'project_id', 'status'])->withCount('lines')])
            ->orderBy('name')
            ->get()
            ->map(fn (Project $project): array => [
                'project' => $project,
                'state' => match ($project->reportTemplate?->status) {
                    null => 'missing',
                    ReportTemplateStatus::Pending => 'pending',
                    default => 'approved',
                },
                'lines' => (int) ($project->reportTemplate?->lines_count ?? 0),
            ])
            ->sortBy(fn (array $row): int => array_search($row['state'], self::States, true))
            ->values();
    }

    /**
     * project id => responsible MEAL officer's name.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function officers(): array
    {
        $byProject = $this->rows->mapWithKeys(fn (array $row): array => [$row['project']->id => MealCoverage::responsibleFor($row['project'])]);
        $names = HrEmployee::query()->whereKey($byProject->filter()->unique()->values()->all())->get(HrEmployee::NameColumns)
            ->mapWithKeys(fn (HrEmployee $employee): array => [$employee->id => $employee->name]);

        return $byProject->map(fn (?int $employeeId): string => $employeeId === null ? '' : (string) $names->get($employeeId, ''))->all();
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

<x-desk.panel :facets="$pg['facets']" id="templates" :size="$size" :index="$index" :total="$total" :count="($counts['missing'] ?? 0) + ($counts['pending'] ?? 0) ?: null">
    @if ($rows->isNotEmpty())
        <p class="dk-pl-sum">{{ __('desk.templates_summary', ['missing' => $counts['missing'] ?? 0, 'pending' => $counts['pending'] ?? 0, 'approved' => $counts['approved'] ?? 0]) }}</p>
    @endif
    @if ($pg['total'] > 0)
        <ul class="dk-tp">
            @foreach ($pg['slice'] as $row)
                @php
                    $officer = $this->officers[$row['project']->id] ?? '';
                @endphp
                <li wire:key="tp-{{ $row['project']->id }}">
                    <span class="dk-tp-n" title="{{ $row['project']->name }}">{{ $row['project']->name }}</span>
                    <span @class(['dk-pill dk-tp-s', 'dk-bad' => $row['state'] === 'missing', 'dk-warn' => $row['state'] === 'pending', 'dk-good' => $row['state'] === 'approved'])>{{ __('desk.template_state.'.$row['state']) }}</span>
                    <span class="dk-tp-m dk-is">@if ($row['state'] !== 'missing'){{ trans_choice('desk.lines_count', $row['lines'], ['count' => $row['lines']]) }} · @endif{{ $officer !== '' ? $officer : __('desk.no_meal_officer') }}</span>
                    <a href="{{ route('projects.show', $row['project']) }}" wire:navigate @class(['dk-btn dk-sm dk-tp-a', 'dk-primary' => $row['state'] !== 'approved'])>{{ __('desk.template_action.'.$row['state']) }}</a>
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
