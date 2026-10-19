<?php

use App\Livewire\Concerns\DonorReports\SubmitsDonorReport;
use App\Models\DonorReport;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/*
| My Reporting Duties (contracts §14.3): the donor reports this person is
| responsible for (responsible_employee_id = their employee id), with no
| money. Donor and proposal names are plain text; nothing here links to a
| money page, and no amount, currency or agreement file is ever read.
*/
new #[Title('titles.reporting_duties')] class extends Component {
    use SubmitsDonorReport;
    use WithFileUploads;

    #[Url(except: 'open')]
    public string $state = 'open';

    /**
     * My reports: title, type, audience, period, version, dates and the
     * donor and proposal names. Open ones first, soonest due first.
     *
     * @return EloquentCollection<int, DonorReport>
     */
    #[Computed]
    public function duties(): EloquentCollection
    {
        $employeeId = auth()->user()->employee_id;

        if ($employeeId === null) {
            return new EloquentCollection;
        }

        return $this->mine()
            ->join('donor_proposal', 'donor_proposal.id', '=', 'donor_reports.donor_proposal_id')
            ->join('proposals', 'proposals.id', '=', 'donor_proposal.proposal_id')
            ->join('donors', 'donors.id', '=', 'donor_proposal.donor_id')
            ->when($this->state !== 'all', fn (Builder $query) => $query->whereNull('donor_reports.submitted_on'))
            ->orderByRaw('donor_reports.submitted_on is not null')
            ->orderBy('donor_reports.due_on')
            ->orderBy('donor_reports.id')
            ->get([
                'donor_reports.id', 'donor_reports.title', 'donor_reports.type', 'donor_reports.audience', 'donor_reports.period_type',
                'donor_reports.period_start', 'donor_reports.period_end', 'donor_reports.version', 'donor_reports.due_on',
                'donor_reports.submitted_on', 'donor_reports.responsible_employee_id',
                'proposals.name as proposal_name', 'donors.name as donor_name',
            ]);
    }

    protected function submittableReport(int $reportId): DonorReport
    {
        return $this->mine()->findOrFail($reportId);
    }

    protected function donorReportSubmitted(): void
    {
        unset($this->duties);
    }

    /**
     * @return Builder<DonorReport>
     */
    private function mine(): Builder
    {
        return DonorReport::query()->where('donor_reports.responsible_employee_id', auth()->user()->employee_id ?? 0);
    }
};
?>

@php
    $muted = 'text-secondary-medium dark:text-ink-muted';
    $today = CarbonImmutable::today();
@endphp

<section class="@container flex min-h-full w-full flex-1 flex-col gap-8 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" data-reporting-duties>
    <x-ui.page-header heading-id="reporting-duties-heading" :title="__('titles.reporting_duties')" :count="$this->duties->count()">
        <x-slot:filters>
            <x-ui.header-filter-select :label="__('common.state')" wire:model.live="state" :value="$state === 'all' ? 'all' : 'open'">
                <option value="open">{{ __('reporting_duties.state_open') }}</option>
                <option value="all">{{ __('reporting_duties.state_all') }}</option>
            </x-ui.header-filter-select>
        </x-slot:filters>
    </x-ui.page-header>

    @if ($this->duties->isEmpty())
        <x-ui.empty-state
            icon="document-check"
            :heading="$state === 'all' ? __('reporting_duties.none') : __('reporting_duties.none_open')"
            :body="__('reporting_duties.empty_body')"
        />
    @else
        <x-ui.data-table :caption="__('titles.reporting_duties')" data-tour="reporting-duties-list">
            <x-slot:head>
                <th>{{ __('reporting_duties.report') }}</th>
                <th class="w-48!">{{ __('reporting_duties.donor_and_proposal') }}</th>
                <th class="w-36!">{{ __('donor_reports.period') }}</th>
                <th class="w-36!">{{ __('funding_agreements.due') }}</th>
                <th class="w-40!">{{ __('common.state') }}</th>
                <th class="w-44!"><span class="sr-only">{{ __('common.actions') }}</span></th>
            </x-slot:head>

            @foreach ($this->duties as $duty)
                @php
                    $dueOn = $duty->due_on;
                    $submittedOn = $duty->submitted_on;
                    $days = (int) $today->diffInDays($dueOn, false);
                    $overdue = $duty->isOverdue();
                @endphp
                <tr wire:key="duty-{{ $duty->id }}" data-duty="{{ $duty->id }}">
                    <td>
                        <span class="font-semibold" dir="auto">{{ $duty->title }}</span>
                        <span class="block text-xs {{ $muted }}">
                            {{ $duty->type->label() }} · {{ $duty->audience->label() }}
                            @if ($duty->isComposed())
                                · {{ __('donor_reports.version_number', ['version' => $duty->version]) }}
                            @endif
                        </span>
                    </td>
                    <td>
                        <span class="block" dir="auto">{{ $duty->donor_name }}</span>
                        <span class="block text-xs {{ $muted }}" dir="auto">{{ $duty->proposal_name }}</span>
                    </td>
                    <td class="tabular-nums" dir="ltr">
                        @if ($duty->isComposed())
                            {{ $duty->period_start->format('Y-m') }} – {{ $duty->period_end->format('Y-m') }}
                        @else
                            —
                        @endif
                    </td>
                    <td>{{ $dueOn->translatedFormat(__('formats.date')) }}</td>
                    <td data-duty-state>
                        @if ($submittedOn !== null)
                            <span class="inline-flex items-center gap-1 text-xs font-semibold text-status-good">
                                <flux:icon.check-circle variant="micro" class="size-4" />
                                {{ __('donor_reports.submitted_on_date', ['date' => $submittedOn->translatedFormat(__('formats.date'))]) }}
                            </span>
                        @elseif ($overdue)
                            <span class="inline-flex items-center gap-1 rounded-full bg-status-critical-soft px-2 py-0.5 text-xs font-semibold text-status-critical" data-duty-overdue>
                                <flux:icon.exclamation-triangle variant="micro" class="size-4" />
                                {{ trans_choice('funding_agreements.count_days_overdue', -$days, ['count' => -$days]) }}
                            </span>
                        @else
                            <span class="inline-flex items-center gap-1 rounded-full bg-status-warn-soft px-2 py-0.5 text-xs font-semibold text-status-warn" data-duty-due>
                                <flux:icon.clock variant="micro" class="size-4" />
                                {{ trans_choice('funding_agreements.due_in_days', $days, ['count' => $days]) }}
                            </span>
                        @endif
                    </td>
                    <td>
                        <span class="flex items-center justify-end gap-1">
                            <x-ui.button tone="ghost" size="sm" icon="eye" class="size-8!" :href="route('donor-reports.show', $duty)" wire:navigate :aria-label="__('common.view_name', ['name' => $duty->title])" />
                            @if ($submittedOn === null)
                                <x-ui.button size="sm" icon="check" class="min-h-8 touch-manipulation" wire:click="openSubmit({{ $duty->id }})" data-test="submit-duty-{{ $duty->id }}">{{ __('donor_reports.mark_submitted') }}</x-ui.button>
                            @endif
                        </span>
                    </td>
                </tr>
            @endforeach
        </x-ui.data-table>
    @endif

    <x-donor-reports.submit-modal :open="$showSubmitModal" :needs-file="$submissionNeedsFile" :file-count="$submissionFileCount" />
</section>
