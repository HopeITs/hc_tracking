<?php

use App\Enums\ReportTemplateStatus;
use App\Livewire\Concerns\Desk\IsDeskPanel;
use App\Models\HrEmployee;
use App\Models\ReportTemplate;
use App\Support\MealCoverage;
use Illuminate\Support\Collection;
use Livewire\Attributes\Computed;
use Livewire\Component;

/**
 * Report templates: monthly-report templates waiting for MEAL first, then
 * the confirmed ones, each with its line count and responsible MEAL
 * officer. There is no template review page yet (stage 4 adds the sheet),
 * so a waiting template shows its state instead of a Review button.
 */
new class extends Component {
    use IsDeskPanel;

    /**
     * @return Collection<int, ReportTemplate>
     */
    #[Computed]
    public function rows(): Collection
    {
        if (! auth()->user()->can('report-templates.manage')) {
            return new Collection;
        }

        return ReportTemplate::query()
            ->with('project')
            ->withCount('lines')
            ->orderByRaw('status = ? desc', [ReportTemplateStatus::Pending->value])
            ->latest('id')
            ->get();
    }

    /**
     * template id => responsible MEAL officer's name.
     *
     * @return array<int, string>
     */
    #[Computed]
    public function officers(): array
    {
        $byTemplate = $this->rows->mapWithKeys(fn (ReportTemplate $template): array => [$template->id => $template->project === null ? null : MealCoverage::responsibleFor($template->project)]);
        $names = HrEmployee::query()->whereKey($byTemplate->filter()->unique()->values()->all())->get(HrEmployee::NameColumns)
            ->mapWithKeys(fn (HrEmployee $employee): array => [$employee->id => $employee->name]);

        return $byTemplate->map(fn (?int $employeeId): string => $employeeId === null ? '' : (string) $names->get($employeeId, ''))->all();
    }
};
?>

@php
    $rows = $this->rows;
    $pending = $rows->where('status', ReportTemplateStatus::Pending)->count();
    $pg = $this->paginate($rows, self::PerPage);
@endphp

<x-desk.panel :facets="$pg['facets']" id="templates" :size="$size" :index="$index" :total="$total" :count="$pending > 0 ? $pending : null">
    @if ($pg['total'] > 0)
        <ul class="dk-plist">
            @foreach ($pg['slice'] as $template)
                <li wire:key="tp-{{ $template->id }}">
                    <div>
                        <strong>{{ $template->project?->name ?? '—' }}</strong>
                        <span class="dk-is">{{ trans_choice('desk.lines_count', $template->lines_count, ['count' => $template->lines_count]) }}@if ($this->officers[$template->id] !== '') · {{ $this->officers[$template->id] }}@endif</span>
                    </div>
                    @if ($template->status === ReportTemplateStatus::Pending)
                        <span class="dk-pill dk-warn">{{ $template->status->label() }}</span>
                    @else
                        <span class="dk-pill dk-good">{{ $template->status->label() }}</span>
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
