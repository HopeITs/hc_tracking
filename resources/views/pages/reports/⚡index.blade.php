<?php

use App\Enums\ReportStatus;
use App\Models\PlanIndicator;
use App\Models\Report;
use App\Models\User;
use Flux\Flux;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Reports')] class extends Component {
    use WithPagination;

    #[Url(except: '')]
    public string $search = '';

    #[Url(except: '')]
    public string $status = '';

    #[Url(except: '')]
    public string $seenByMeal = '';

    #[Url(except: '')]
    public string $author = '';

    public bool $showReportModal = false;
    public ?int $editingReportId = null;

    public string $reportTargetId = '';
    public string $plannedDate = '';
    public string $periodStart = '';
    public string $periodEnd = '';
    public string $value = '';
    public string $narrativeNotes = '';
    public string $executiveSummary = '';
    public string $keyAchievements = '';
    public string $challenges = '';
    public string $lessonsLearned = '';
    public string $nextPeriodPlans = '';
    public string $submitDate = '';

    public function updatedSearch(): void
    {
        $this->resetPage();
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedSeenByMeal(): void
    {
        $this->resetPage();
    }

    public function updatedAuthor(): void
    {
        $this->resetPage();
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->status = '';
        $this->seenByMeal = '';
        $this->author = '';
        $this->resetPage();
    }

    /** @return LengthAwarePaginator<int, Report> */
    #[Computed]
    public function reports(): LengthAwarePaginator
    {
        $search = trim($this->search);

        return Report::query()
            ->select(['id', 'plan_indicator_id', 'user_id', 'status', 'planned_date', 'value', 'submit_date', 'is_seen_by_meal'])
            ->with([
                'planIndicator:id,indicator_id,target,period,source_type,source_id,project_id',
                'planIndicator.indicator:id,name,measure',
                'planIndicator.project:id,name',
                'user:id,name,email,role',
            ])
            ->withCount('files')
            ->when($search !== '', function ($query) use ($search): void {
                $query->where(function ($query) use ($search): void {
                    $query
                        ->where('narrative_report_notes', 'like', '%'.$search.'%')
                        ->orWhereHas(
                            'planIndicator.indicator',
                            fn ($query) => $query->where('name', 'like', '%'.$search.'%')
                        );
                });
            })
            ->when($this->status !== '', fn ($query) => $query->where('status', $this->status))
            ->when($this->seenByMeal === 'seen', fn ($query) => $query->where('is_seen_by_meal', true))
            ->when($this->seenByMeal === 'unseen', fn ($query) => $query->where('is_seen_by_meal', false))
            ->when($this->author !== '', fn ($query) => $query->where('user_id', (int) $this->author))
            ->orderByDesc('planned_date')
            ->orderByDesc('id')
            ->paginate(15);
    }

    /** @return Collection<int, PlanIndicator> */
    #[Computed]
    public function targetOptions(): Collection
    {
        return PlanIndicator::query()
            ->select(['id', 'indicator_id', 'target', 'period'])
            ->with('indicator:id,name')
            ->orderByDesc('id')
            ->get();
    }

    /** @return Collection<int, User> */
    #[Computed]
    public function authorOptions(): Collection
    {
        return User::query()->select(['id', 'name', 'email', 'role'])->orderBy('name')->orderBy('id')->get();
    }

    public function openCreateModal(): void
    {
        $this->resetReportForm();
        $this->showReportModal = true;
    }

    public function openEditModal(int $reportId): void
    {
        $report = Report::query()
            ->select([
                'id', 'status', 'plan_indicator_id', 'planned_date', 'period_start', 'period_end', 'value',
                'narrative_report_notes', 'executive_summary', 'key_achievements', 'challenges',
                'lessons_learned', 'next_period_plans', 'submit_date',
            ])
            ->findOrFail($reportId);

        if (! $report->isEditable()) {
            Flux::toast(
                variant: 'danger',
                text: __('Only draft reports can be edited. Return it to draft on the report page first.'),
            );

            return;
        }

        $this->resetValidation();
        $this->editingReportId = $report->id;
        $this->reportTargetId = (string) $report->plan_indicator_id;
        $this->plannedDate = $report->planned_date?->toDateString() ?? '';
        $this->periodStart = $report->period_start?->toDateString() ?? '';
        $this->periodEnd = $report->period_end?->toDateString() ?? '';
        $this->value = (string) $report->value;
        $this->narrativeNotes = $report->narrative_report_notes ?? '';
        $this->executiveSummary = $report->executive_summary ?? '';
        $this->keyAchievements = $report->key_achievements ?? '';
        $this->challenges = $report->challenges ?? '';
        $this->lessonsLearned = $report->lessons_learned ?? '';
        $this->nextPeriodPlans = $report->next_period_plans ?? '';
        $this->submitDate = $report->submit_date?->toDateString() ?? '';
        $this->showReportModal = true;
    }

    public function saveReport(): void
    {
        $this->narrativeNotes = trim($this->narrativeNotes);
        $this->executiveSummary = trim($this->executiveSummary);
        $this->keyAchievements = trim($this->keyAchievements);
        $this->challenges = trim($this->challenges);
        $this->lessonsLearned = trim($this->lessonsLearned);
        $this->nextPeriodPlans = trim($this->nextPeriodPlans);

        $isCreate = $this->editingReportId === null;

        $report = $isCreate
            ? new Report
            : Report::query()->select(['id', 'status', 'user_id'])->findOrFail($this->editingReportId);

        if (! $isCreate && ! $report->isEditable()) {
            $this->showReportModal = false;
            $this->resetReportForm();

            Flux::toast(
                variant: 'danger',
                text: __('Only draft reports can be edited. Return it to draft on the report page first.'),
            );

            return;
        }

        // periodEnd's after_or_equal comparison is only meaningful once
        // periodStart is actually set — an empty periodStart cannot be parsed
        // as a date, so the rule is added only when there is something to
        // compare against.
        $periodEndRules = ['nullable', 'date'];

        if ($this->periodStart !== '') {
            $periodEndRules[] = 'after_or_equal:periodStart';
        }

        $validated = $this->validate([
            'reportTargetId' => ['required', 'integer', Rule::exists(PlanIndicator::class, 'id')],
            'plannedDate' => ['required', 'date'],
            'periodStart' => ['nullable', 'date'],
            'periodEnd' => $periodEndRules,
            'value' => ['required', 'numeric', 'min:0', 'max:9999999999.99'],
            'narrativeNotes' => ['nullable', 'string'],
            'executiveSummary' => ['nullable', 'string'],
            'keyAchievements' => ['nullable', 'string'],
            'challenges' => ['nullable', 'string'],
            'lessonsLearned' => ['nullable', 'string'],
            'nextPeriodPlans' => ['nullable', 'string'],
            'submitDate' => ['nullable', 'date'],
        ], [
            'reportTargetId.required' => __('Select the target this reports against.'),
            'plannedDate.required' => __('Set the period this figure covers.'),
            'value.required' => __('Enter the reported value.'),
            'value.min' => __('A reported value cannot be negative.'),
            'value.max' => __('The reported value is too large to record.'),
            'periodEnd.after_or_equal' => __('The narrative window end cannot be before its start.'),
        ]);

        $attributes = [
            'plan_indicator_id' => (int) $validated['reportTargetId'],
            'planned_date' => $validated['plannedDate'],
            'period_start' => $validated['periodStart'] === '' ? null : $validated['periodStart'],
            'period_end' => $validated['periodEnd'] === '' ? null : $validated['periodEnd'],
            'value' => $validated['value'],
            'narrative_report_notes' => $validated['narrativeNotes'] === '' ? null : $validated['narrativeNotes'],
            'executive_summary' => $validated['executiveSummary'] === '' ? null : $validated['executiveSummary'],
            'key_achievements' => $validated['keyAchievements'] === '' ? null : $validated['keyAchievements'],
            'challenges' => $validated['challenges'] === '' ? null : $validated['challenges'],
            'lessons_learned' => $validated['lessonsLearned'] === '' ? null : $validated['lessonsLearned'],
            'next_period_plans' => $validated['nextPeriodPlans'] === '' ? null : $validated['nextPeriodPlans'],
            'submit_date' => $validated['submitDate'] === '' ? null : $validated['submitDate'],
        ];

        // Authorship is set once. reports.user_id RESTRICTs on delete because
        // who reported a figure is part of the record.
        // status is deliberately absent here: it is written only by the
        // explicit workflow actions on the detail page (submitForReview,
        // approve, returnToDraft), never from this form.
        if ($isCreate) {
            $attributes['user_id'] = (int) auth()->id();
        }

        $report->fill($attributes)->save();

        $this->showReportModal = false;
        $this->resetReportForm();
        $this->resetPage();

        Flux::toast(
            variant: 'success',
            text: $isCreate ? __('Report recorded.') : __('Report updated.'),
        );
    }

    public function closeReportModal(): void
    {
        $this->showReportModal = false;
        $this->resetReportForm();
    }

    protected function resetReportForm(): void
    {
        $this->resetValidation();
        $this->editingReportId = null;
        $this->reportTargetId = '';
        $this->plannedDate = '';
        $this->periodStart = '';
        $this->periodEnd = '';
        $this->value = '';
        $this->narrativeNotes = '';
        $this->executiveSummary = '';
        $this->keyAchievements = '';
        $this->challenges = '';
        $this->lessonsLearned = '';
        $this->nextPeriodPlans = '';
        $this->submitDate = '';
    }
};
?>

@php
    $filtersAreActive = $search !== '' || $status !== '' || $seenByMeal !== '' || $author !== '';
@endphp

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-4 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="report-directory-heading">
    <x-ui.page-header
        heading-id="report-directory-heading"
        :title="__('Reports')"
        :count="$this->reports->total()"
        :description="__('Reported figures against committed targets, newest period first.')"
    >
        <x-slot:filters>
            <x-ui.input type="search" icon="magnifying-glass" :label="__('Search reports')" :placeholder="__('Indicator or narrative')" wire:model.live.debounce.300ms="search" clearable />

            <x-ui.select :label="__('Status')" wire:model.live="status">
                <flux:select.option value="">{{ __('All reports') }}</flux:select.option>
                @foreach (ReportStatus::cases() as $statusCase)
                    <flux:select.option :value="$statusCase->value" :wire:key="'rpt-status-'.$statusCase->value">{{ Str::title($statusCase->value) }}</flux:select.option>
                @endforeach
            </x-ui.select>

            <x-ui.select :label="__('MEAL review')" wire:model.live="seenByMeal">
                <flux:select.option value="">{{ __('Any') }}</flux:select.option>
                <flux:select.option value="seen">{{ __('Seen by MEAL') }}</flux:select.option>
                <flux:select.option value="unseen">{{ __('Not yet seen') }}</flux:select.option>
            </x-ui.select>

            <x-ui.select :label="__('Reported by')" wire:model.live="author">
                <flux:select.option value="">{{ __('Anyone') }}</flux:select.option>
                @foreach ($this->authorOptions as $authorOption)
                    <flux:select.option :value="$authorOption->id" :wire:key="'rpt-author-'.$authorOption->id">{{ $authorOption->name }}</flux:select.option>
                @endforeach
            </x-ui.select>
        </x-slot:filters>

        <x-slot:actions>
            <x-ui.button tone="primary" icon="plus" class="min-h-11 self-end touch-manipulation @sm/directory:min-h-10" wire:click="openCreateModal" data-test="create-report-button">
                {{ __('Record report') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <x-ui.panel :subtitle="trans_choice('{1} :count matching report|[2,*] :count matching reports', $this->reports->total(), ['count' => $this->reports->total()])">
        <x-slot:toolbar>
            @if ($filtersAreActive)
                <flux:button size="sm" variant="ghost" class="text-secondary-medium! dark:text-secondary-soft!" wire:click="clearFilters">{{ __('Reset filters') }}</flux:button>
            @endif
        </x-slot:toolbar>

        @if ($this->reports->isEmpty())
            <x-ui.empty-state
                icon="document-chart-bar"
                :heading="$filtersAreActive ? __('No matching reports') : __('No reports yet')"
                :body="$filtersAreActive ? __('Change the search or filters and try again.') : __('Record the first figure against a target.')"
            />
        @else
            <div class="divide-y divide-secondary-soft dark:divide-secondary-medium">
                @foreach ($this->reports as $report)
                    <article class="flex flex-wrap items-center gap-3 p-3.5" wire:key="report-{{ $report->id }}">
                        <div class="min-w-0 me-auto">
                            <a href="{{ route('reports.show', $report) }}" wire:navigate class="text-sm font-semibold hover:underline">
                                {{ $report->planIndicator->indicator->name }}
                            </a>
                            <p class="truncate text-xs text-secondary-medium dark:text-secondary-soft">
                                {{ $report->planned_date?->format('M j, Y') }}
                                · {{ $report->planIndicator->project?->name ?? __('Organisation-wide') }}
                                · {{ trans_choice(':count file|:count files', $report->files_count, ['count' => $report->files_count]) }}
                            </p>
                        </div>

                        <div class="text-right">
                            <p class="text-sm font-semibold tabular-nums">{{ number_format((float) $report->value, 2) }}</p>
                            <p class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft">
                                {{ __('of :target', ['target' => number_format((float) $report->planIndicator->target, 2)]) }}
                            </p>
                        </div>

                        <div class="flex flex-wrap items-center gap-1.5">
                            <x-ui.enum-badge :case="$report->status" />

                            @if ($report->is_seen_by_meal)
                                <span class="rounded-md border border-secondary-light px-2 py-0.5 text-[11px] font-semibold text-secondary-medium dark:text-secondary-soft">{{ __('MEAL') }}</span>
                            @endif
                        </div>

                        <x-ui.user-chip :user="$report->user" compact class="w-40" />

                        {{-- No delete: a measurement is the record. --}}
                        @if ($report->isEditable())
                            <x-ui.button tone="ghost" size="sm" icon="pencil-square" class="size-9!" wire:click="openEditModal({{ $report->id }})" :aria-label="__('Edit report')" />
                        @else
                            <flux:tooltip :content="__('Only draft reports can be edited.')">
                                <span class="inline-flex size-9 cursor-not-allowed items-center justify-center text-secondary-light" aria-disabled="true">
                                    <flux:icon.lock-closed class="size-4" />
                                </span>
                            </flux:tooltip>
                        @endif
                    </article>
                @endforeach
            </div>
        @endif

        <x-slot:footer>
            <flux:pagination :paginator="$this->reports" class="text-secondary-medium! dark:text-secondary-soft!" scroll-to="#report-directory-heading" />
        </x-slot:footer>
    </x-ui.panel>

    <flux:modal wire:model="showReportModal" class="w-full max-w-2xl border border-secondary-light! bg-white! text-secondary-dark! shadow-none! ring-0! dark:border-secondary-light! dark:bg-secondary-dark! dark:text-white!">
        @if ($showReportModal)
        <form wire:submit="saveReport" class="space-y-5">
            <div class="border-b border-secondary-soft pb-4 dark:border-secondary-medium">
                <flux:heading size="lg">{{ $editingReportId === null ? __('Record report') : __('Edit report') }}</flux:heading>
                <flux:subheading>{{ __('Status and MEAL review are recorded on the report page, not here.') }}</flux:subheading>
            </div>

            <x-ui.select wire:model="reportTargetId" :label="__('Target')" required>
                <flux:select.option value="">{{ __('Select a target') }}</flux:select.option>
                @foreach ($this->targetOptions as $targetOption)
                    <flux:select.option :value="$targetOption->id" :wire:key="'rpt-target-'.$targetOption->id">
                        {{ $targetOption->indicator->name }} — {{ number_format((float) $targetOption->target, 2) }}
                    </flux:select.option>
                @endforeach
            </x-ui.select>

            <div class="grid gap-4 sm:grid-cols-3">
                <x-ui.input wire:model="plannedDate" :label="__('Period covered')" type="date" required />
                <x-ui.input wire:model="value" :label="__('Reported value')" type="number" step="0.01" required />
                <x-ui.input wire:model="submitDate" :label="__('Submitted on')" type="date" />
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <x-ui.input wire:model="periodStart" :label="__('Narrative window start')" type="date" />
                <x-ui.input wire:model="periodEnd" :label="__('Narrative window end')" type="date" />
            </div>

            <flux:textarea wire:model="narrativeNotes" :label="__('Narrative notes')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <flux:textarea wire:model="executiveSummary" :label="__('Executive summary')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />
            <flux:textarea wire:model="keyAchievements" :label="__('Key achievements')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />
            <flux:textarea wire:model="challenges" :label="__('Challenges')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />
            <flux:textarea wire:model="lessonsLearned" :label="__('Lessons learned')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />
            <flux:textarea wire:model="nextPeriodPlans" :label="__('Next period plans')" rows="3" class="border-secondary-light! bg-white! text-secondary-dark! dark:bg-secondary-medium! dark:text-white!" />

            <div class="grid grid-cols-2 gap-2 border-t border-secondary-soft pt-4 sm:flex sm:justify-end dark:border-secondary-medium">
                <x-ui.button type="button" class="min-h-11 w-full sm:min-h-10 sm:w-auto" wire:click="closeReportModal">{{ __('Cancel') }}</x-ui.button>
                <x-ui.button type="submit" tone="primary" class="min-h-11 w-full sm:min-h-10 sm:w-auto" data-test="save-report-button">
                    {{ $editingReportId === null ? __('Record report') : __('Save changes') }}
                </x-ui.button>
            </div>
        </form>
        @endif
    </flux:modal>
</section>
