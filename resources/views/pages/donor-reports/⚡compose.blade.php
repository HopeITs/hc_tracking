<?php

use App\Actions\DonorReports\ComposeDonorReport;
use App\Actions\DonorReports\SaveDonorReportSnapshot;
use App\Enums\DonorReportType;
use App\Enums\ProposalStatus;
use App\Enums\ReportAudience;
use App\Enums\ReportingPeriod;
use App\Models\DonorProposal;
use App\Models\Proposal;
use App\Models\User;
use App\Support\DonorReportResponsibles;
use Flux\Flux;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/*
| Donor Report Builder (contracts §14.3, D-32): proposal → period → preview
| → save. The preview re-reads the validated figures on every render from
| the locked parameters it was asked for, so no snapshot (and no amount)
| travels in the component state. Finance shows only for
| funding-agreements.view holders; Save needs a recorded agreement.
*/
new #[Layout('layouts::desk'), Title('titles.donor_report_builder')] class extends Component {
    #[Url(as: 'proposal', except: '')]
    public string $proposalId = '';

    public string $periodType = 'quarterly';

    public string $startMonth = '';

    public string $endMonth = '';

    /** @var array{proposalId: int, periodType: string, startMonth: string, endMonth: ?string}|null */
    #[Locked]
    public ?array $previewed = null;

    public string $type = 'narrative';

    public string $audience = 'donor';

    public string $dueOn = '';

    public string $responsibleEmployeeId = '';

    public string $notes = '';

    public string $title = '';

    public function mount(): void
    {
        $this->defaultStartMonth();
    }

    /**
     * Donor-approved proposals of a real donor (never self-funded, D-27a)
     * that the user may see.
     *
     * @return Collection<int, Proposal>
     */
    #[Computed]
    public function proposals(): Collection
    {
        if (! auth()->user()->can('proposals.view')) {
            return new Collection;
        }

        $approved = array_values(array_map(
            fn (ProposalStatus $status): string => $status->value,
            array_filter(ProposalStatus::cases(), fn (ProposalStatus $status): bool => $status->isApprovedByDonor()),
        ));

        return Proposal::query()
            ->whereIn('status', $approved)
            ->whereHas('donor', fn (Builder $query) => $query->where('is_internal', false))
            ->orderBy('name')
            ->get(['id', 'name', 'internal_project_number', 'donor_id', 'status', 'actual_date_begin', 'actual_date_end']);
    }

    #[Computed]
    public function selectedProposal(): ?Proposal
    {
        return $this->proposalId === '' ? null : $this->proposals->firstWhere('id', (int) $this->proposalId);
    }

    /**
     * The composed report for the parameters last previewed, or null.
     *
     * @return array<string, mixed>|null
     */
    #[Computed]
    public function preview(): ?array
    {
        if ($this->previewed === null) {
            return null;
        }

        /** @var User $user */
        $user = auth()->user();

        try {
            return app(ComposeDonorReport::class)->handle(
                $this->previewed['proposalId'],
                ReportingPeriod::from($this->previewed['periodType']),
                $this->previewed['startMonth'],
                $this->previewed['endMonth'],
                $user,
            );
        } catch (ValidationException) {
            return null;
        }
    }

    #[Computed]
    public function previewHasAgreement(): bool
    {
        return $this->previewed !== null && DonorProposal::query()->where('proposal_id', $this->previewed['proposalId'])->exists();
    }

    /**
     * Decided on the server: the preview's finance block only for money holders.
     */
    #[Computed]
    public function showFinance(): bool
    {
        return auth()->user()->can('funding-agreements.view');
    }

    public function updatedProposalId(): void
    {
        $this->previewed = null;
        unset($this->selectedProposal);
        $this->defaultStartMonth();
    }

    public function compose(): void
    {
        Gate::authorize('reports.aggregate');

        $this->validate([
            'proposalId' => ['required', 'integer'],
            'periodType' => ['required', Rule::enum(ReportingPeriod::class)],
            'startMonth' => ['required', 'date_format:Y-m'],
            'endMonth' => ['nullable', 'required_if:periodType,'.ReportingPeriod::Custom->value, 'date_format:Y-m', 'after_or_equal:startMonth'],
        ], [
            'proposalId.required' => __('donor_reports.proposal_required'),
        ]);

        $parameters = [
            'proposalId' => (int) $this->proposalId,
            'periodType' => $this->periodType,
            'startMonth' => $this->startMonth,
            'endMonth' => $this->periodType === ReportingPeriod::Custom->value ? $this->endMonth : null,
        ];

        /** @var User $user */
        $user = auth()->user();

        // Run once here so a refused proposal or period shows its message next to the field.
        app(ComposeDonorReport::class)->handle($parameters['proposalId'], ReportingPeriod::from($parameters['periodType']), $parameters['startMonth'], $parameters['endMonth'], $user);

        $this->previewed = $parameters;
        unset($this->preview, $this->previewHasAgreement);

        if ($this->dueOn === '') {
            $this->dueOn = now()->addMonth()->toDateString();
        }
    }

    public function save(): void
    {
        Gate::authorize('reports.aggregate');

        if ($this->previewed === null) {
            return;
        }

        /** @var User $user */
        $user = auth()->user();
        $proposal = Proposal::query()->findOrFail($this->previewed['proposalId']);

        $report = app(SaveDonorReportSnapshot::class)->handle($proposal, [
            'period_type' => $this->previewed['periodType'],
            'start_month' => $this->previewed['startMonth'],
            'end_month' => $this->previewed['endMonth'],
            'type' => $this->type,
            'audience' => $this->audience,
            'due_on' => $this->dueOn,
            'responsible_employee_id' => $this->responsibleEmployeeId === '' ? null : (int) $this->responsibleEmployeeId,
            'notes' => $this->notes,
            'title' => trim($this->title),
        ], $user);

        Flux::toast(variant: 'success', text: __('donor_reports.saved_toast', ['version' => $report->version]));

        $this->redirectRoute('donor-reports.show', $report, navigate: true);
    }

    private function defaultStartMonth(): void
    {
        $this->startMonth = $this->selectedProposal?->actual_date_begin?->format('Y-m') ?? '';
        $this->endMonth = '';
    }
};
?>

@php
    $muted = 'text-secondary-medium dark:text-ink-muted';
    $panel = 'rounded-xl border border-secondary-soft p-4 sm:p-5 dark:border-transparent dark:bg-surface-1 dark:shadow-raised';
    $preview = $this->preview;
    $proposal = $this->selectedProposal;
    $slotHint = DonorReportResponsibles::slotFor(DonorReportType::tryFrom($type) ?? DonorReportType::Narrative);
@endphp

<x-desk.record-sheet register="pages::reporting-duties.index" :close-url="route('reporting-duties.index')" :title="__('titles.donor_report_builder')">
<section class="@container flex min-h-full w-full flex-1 flex-col gap-8 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" data-donor-report-builder>
    <x-ui.page-header heading-id="donor-report-builder-heading" :title="__('titles.donor_report_builder')" />

    {{-- 1. Proposal and period. --}}
    <form wire:submit="compose" class="{{ $panel }} grid grid-cols-1 gap-4" data-tour="donor-report-builder-period">
        <h2 class="text-base font-bold">{{ __('donor_reports.step_period') }}</h2>

        @if ($this->proposals->isEmpty())
            <p class="text-sm {{ $muted }}" data-no-eligible-proposals>{{ __('donor_reports.no_eligible_proposals') }}</p>
        @else
            <div class="grid gap-4 md:grid-cols-2">
                <x-ui.form-select wire:model.live="proposalId" :label="__('donor_reports.proposal')" :placeholder="__('donor_reports.choose_proposal')" required searchable>
                    @foreach ($this->proposals as $option)
                        <option value="{{ $option->id }}">{{ $option->name }} · {{ $option->internal_project_number }}</option>
                    @endforeach
                </x-ui.form-select>

                <x-ui.form-segmented
                    wire:model.live="periodType"
                    :label="__('donor_reports.period_type')"
                    required
                    :options="collect(ReportingPeriod::cases())->map(fn (ReportingPeriod $case): array => ['value' => $case->value, 'label' => $case->label()])->all()"
                />

                <x-ui.form-input wire:model="startMonth" type="month" :label="__('donor_reports.start_month')" required
                    :help="$proposal?->actual_date_begin !== null ? __('donor_reports.grant_window', ['start' => $proposal->actual_date_begin->format('Y-m'), 'end' => $proposal->actual_date_end?->format('Y-m') ?? '—']) : null" />

                @if ($periodType === ReportingPeriod::Custom->value)
                    <x-ui.form-input wire:model="endMonth" type="month" :label="__('donor_reports.end_month')" required />
                @endif
            </div>
            <flux:error name="proposalId" />

            <div>
                <x-ui.button type="submit" tone="primary" icon="eye" wire:loading.attr="disabled" wire:target="compose" data-test="compose-preview">
                    <span wire:loading.remove wire:target="compose">{{ __('donor_reports.preview') }}</span>
                    <span wire:loading wire:target="compose">{{ __('donor_reports.composing') }}</span>
                </x-ui.button>
            </div>
        @endif
    </form>

    @if ($preview !== null)
        {{-- 2. Preview, read-only. --}}
        <x-donor-reports.snapshot :snapshot="$preview" :show-finance="$this->showFinance" />

        {{-- 3. Save as a frozen snapshot. --}}
        <form wire:submit="save" class="{{ $panel }} grid grid-cols-1 gap-4" data-tour="donor-report-builder-save" data-save-form>
            <h2 class="text-base font-bold">{{ __('donor_reports.step_save') }}</h2>
            <p class="text-sm {{ $muted }}">{{ __('donor_reports.save_help') }}</p>

            @unless ($this->previewHasAgreement)
                <p class="rounded-lg bg-status-warn-soft px-3 py-2 text-sm font-medium text-status-warn" role="status" data-no-agreement>{{ __('donor_reports.agreement_required') }}</p>
            @endunless

            <div class="grid gap-4 md:grid-cols-2">
                <x-ui.form-segmented wire:model.live="type" :label="__('common.type')" required
                    :options="collect(DonorReportType::cases())->map(fn (DonorReportType $case): array => ['value' => $case->value, 'label' => $case->label()])->all()" />
                <x-ui.form-segmented wire:model="audience" :label="__('donor_reports.audience')" required
                    :options="collect(ReportAudience::cases())->map(fn (ReportAudience $case): array => ['value' => $case->value, 'label' => $case->label()])->all()" />
                <x-ui.form-input wire:model="dueOn" type="date" :label="__('funding_agreements.due')" required :help="__('donor_reports.due_help')" />
                <x-ui.form-select wire:model="responsibleEmployeeId" :label="__('donor_reports.responsible')" :placeholder="__('donor_reports.default_responsible.'.$slotHint)" employees
                    :help="__('donor_reports.responsible_help')" />
                <x-ui.form-input wire:model="title" :label="__('donor_reports.title')" maxlength="255" :help="__('donor_reports.title_help')" class="md:col-span-2" />
                <x-ui.form-textarea wire:model="notes" :label="__('donor_reports.notes')" maxlength="2000" rows="3" class="md:col-span-2" />
            </div>
            <flux:error name="proposalId" />

            <div>
                <x-ui.button type="submit" tone="primary" icon="check" :disabled="! $this->previewHasAgreement" wire:loading.attr="disabled" wire:target="save" data-test="save-donor-report">{{ __('donor_reports.save') }}</x-ui.button>
            </div>
        </form>
    @endif
</section>
</x-desk.record-sheet>
