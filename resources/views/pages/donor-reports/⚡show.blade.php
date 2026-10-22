<?php

use App\Actions\DonorReports\DeleteDonorReportFile;
use App\Actions\DonorReports\RegenerateDonorReport;
use App\Actions\DonorReports\SaveDonorReportFile;
use App\Enums\FileType;
use App\Livewire\Concerns\DonorReports\SubmitsDonorReport;
use App\Models\DonorReport;
use App\Models\HrEmployee;
use App\Models\Media;
use App\Models\User;
use App\Rules\SecureUpload;
use App\Support\DonorReportResponsibles;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;

/*
| One saved donor report (contracts §14.3): the frozen snapshot, its
| versions, regenerate (reports.aggregate, not submitted), the delivered
| files and the submission. Rendered from the snapshot alone; finance only
| for funding-agreements.view holders. The route checks the view policy.
*/
new #[Layout('layouts::desk'), Title('titles.donor_report')] class extends Component {
    use SubmitsDonorReport;
    use WithFileUploads;

    #[Locked]
    public int $reportId;

    /** @var TemporaryUploadedFile|null */
    public $newFile = null;

    public bool $showRegenerateModal = false;

    public bool $showFileDeleteModal = false;

    #[Locked]
    public ?int $deletingFileId = null;

    public function mount(DonorReport $donorReport): void
    {
        $this->reportId = $donorReport->id;
    }

    #[Computed]
    public function report(): DonorReport
    {
        return DonorReport::query()->with('donorProposal:id,slug,proposal_id,donor_id')->findOrFail($this->reportId);
    }

    /**
     * The delivered files (`report_submission`).
     *
     * @return Collection<int, Media>
     */
    #[Computed]
    public function files(): Collection
    {
        return $this->report->media()->orderBy('id')->get(['id', 'mediable_type', 'mediable_id', 'collection', 'original_name', 'type', 'size', 'uploaded_at']);
    }

    /**
     * Every version saved for the same agreement, type and period.
     *
     * @return Collection<int, DonorReport>
     */
    #[Computed]
    public function versions(): Collection
    {
        $report = $this->report;

        if (! $report->isComposed()) {
            return new Collection;
        }

        return DonorReport::query()
            ->where('donor_proposal_id', $report->donor_proposal_id)
            ->where('type', $report->type->value)
            ->whereDate('period_start', $report->period_start->toDateString())
            ->whereDate('period_end', $report->period_end->toDateString())
            ->orderByDesc('version')
            ->get(['id', 'version', 'submitted_on', 'created_at']);
    }

    /**
     * @return array{employee: HrEmployee|null, user_id: int|null, reachable: bool}|null
     */
    #[Computed]
    public function responsible(): ?array
    {
        $employeeId = $this->report->responsible_employee_id;

        return $employeeId === null ? null : (DonorReportResponsibles::resolve([$employeeId])[$employeeId] ?? null);
    }

    /**
     * Decided on the server: the snapshot's finance block is shown only to
     * funding-agreements.view holders.
     */
    #[Computed]
    public function showFinance(): bool
    {
        return auth()->user()->can('funding-agreements.view');
    }

    public function openRegenerate(): void
    {
        Gate::authorize('regenerate', $this->report);

        $this->showRegenerateModal = true;
    }

    public function regenerate(): void
    {
        Gate::authorize('regenerate', $this->report);

        /** @var User $user */
        $user = auth()->user();
        $next = app(RegenerateDonorReport::class)->handle($this->report, $user);

        $this->showRegenerateModal = false;

        Flux::toast(variant: 'success', text: __('donor_reports.regenerated_toast', ['version' => $next->version]));

        $this->redirectRoute('donor-reports.show', $next, navigate: true);
    }

    public function uploadFile(): void
    {
        Gate::authorize('uploadFile', $this->report);

        $allowedTypes = implode(',', FileType::values());

        $this->validate([
            'newFile' => ['required', 'bail', 'file', 'max:5120', 'mimes:'.$allowedTypes, 'extensions:'.$allowedTypes, new SecureUpload],
        ]);

        /** @var User $user */
        $user = auth()->user();

        app(SaveDonorReportFile::class)->handle($this->report, $this->newFile, $user);

        $this->newFile = null;
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('donor_reports.file_added_toast'));
    }

    public function confirmFileDelete(int $mediaId): void
    {
        Gate::authorize('deleteFile', $this->report);

        $this->deletingFileId = $this->report->media()->whereKey($mediaId)->firstOrFail()->id;
        $this->showFileDeleteModal = true;
    }

    public function deleteFile(): void
    {
        Gate::authorize('deleteFile', $this->report);

        if ($this->deletingFileId !== null) {
            app(DeleteDonorReportFile::class)->handle($this->report, $this->deletingFileId);
        }

        $this->closeFileDeleteModal();
        $this->forgetReadings();

        Flux::toast(variant: 'success', text: __('donor_reports.file_removed_toast'));
    }

    public function closeFileDeleteModal(): void
    {
        $this->showFileDeleteModal = false;
        $this->deletingFileId = null;
    }

    protected function submittableReport(int $reportId): DonorReport
    {
        abort_unless($reportId === $this->reportId, 404);

        return $this->report;
    }

    protected function donorReportSubmitted(): void
    {
        $this->forgetReadings();
    }

    private function forgetReadings(): void
    {
        unset($this->report, $this->files, $this->versions);
    }
};
?>

@php
    $report = $this->report;
    $snapshot = is_array($report->snapshot) ? $report->snapshot : null;
    $header = $snapshot['header'] ?? [];
    $muted = 'text-secondary-medium dark:text-ink-muted';
    $responsible = $this->responsible;
    $canSeeAgreement = auth()->user()->can('funding-agreements.view');
    $breadcrumbs = [
        auth()->user()->can('reports.aggregate')
            ? ['label' => __('navigation.donor_report_builder'), 'url' => route('donor-reports.compose')]
            : ['label' => __('navigation.reporting_duties'), 'url' => route('reporting-duties.index')],
        ['label' => $report->title],
    ];
@endphp

<x-desk.record-sheet register="pages::reporting-duties.index" :close-url="route('reporting-duties.index')" :title="$report->title">
<section class="@container/detail flex min-h-full w-full flex-1 flex-col gap-8 bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink" data-donor-report-page>
    <x-ui.detail-header :breadcrumbs="$breadcrumbs" :title="$report->title" title-class="text-3xl! font-extrabold! leading-none! tracking-tight!">
        <x-slot:meta>
            <span>{{ $report->type->label() }}</span>
            <span>{{ $report->audience->label() }}</span>
            @if ($report->isComposed())
                <span class="tabular-nums" dir="ltr">{{ $report->period_start->format('Y-m') }} – {{ $report->period_end->format('Y-m') }}</span>
                <span class="tabular-nums" data-report-version>{{ __('donor_reports.version_number', ['version' => $report->version]) }}</span>
            @endif
            <span>{{ __('donor_reports.due_on_date', ['date' => $report->due_on->translatedFormat(__('formats.date'))]) }}</span>
            @if (filled($header['donor_name'] ?? null))
                <span dir="auto">{{ $header['donor_name'] }}</span>
            @endif
            @if ($canSeeAgreement && $report->donorProposal !== null)
                <a href="{{ route('funding-agreements.show', $report->donorProposal) }}" wire:navigate class="hover:underline" data-agreement-link>{{ __('donor_reports.open_agreement') }}</a>
            @endif
        </x-slot:meta>

        <x-slot:actions>
            @if ($report->isSubmitted())
                <span class="inline-flex items-center gap-1.5 rounded-full bg-status-good-soft px-3 py-1 text-sm font-semibold text-status-good" data-report-submitted>
                    <flux:icon.check-circle variant="mini" class="size-4" />
                    {{ __('donor_reports.submitted_on_date', ['date' => $report->submitted_on->translatedFormat(__('formats.date'))]) }}
                </span>
            @elseif ($report->isOverdue())
                <span class="inline-flex items-center gap-1.5 rounded-full bg-status-critical-soft px-3 py-1 text-sm font-semibold text-status-critical">
                    <flux:icon.exclamation-triangle variant="mini" class="size-4" />
                    {{ __('donor_reports.overdue') }}
                </span>
            @endif
            @if ($report->isComposed())
                <x-ui.button icon="printer" :href="route('donor-reports.print', $report)" target="_blank" data-test="print-link">{{ __('donor_reports.print') }}</x-ui.button>
                <x-ui.button icon="arrow-down-tray" :href="route('donor-reports.export', $report)" data-test="export-link">{{ __('donor_reports.export_csv') }}</x-ui.button>
            @endif
            @can('regenerate', $report)
                <x-ui.button icon="arrow-path" wire:click="openRegenerate" data-test="regenerate-donor-report">{{ __('donor_reports.regenerate') }}</x-ui.button>
            @endcan
            @if (! $report->isSubmitted())
                @can('submit', $report)
                    <x-ui.button tone="primary" icon="check" wire:click="openSubmit({{ $report->id }})" data-test="submit-donor-report" data-tour="donor-report-submit">{{ __('donor_reports.mark_submitted') }}</x-ui.button>
                @endcan
            @endif
        </x-slot:actions>
    </x-ui.detail-header>

    @if ($report->isSubmitted())
        <p class="rounded-xl bg-secondary-soft/50 px-4 py-3 text-sm dark:bg-surface-1" data-read-only-note>{{ __('donor_reports.frozen_note') }}</p>
    @endif

    {{-- Who delivers it. --}}
    <section class="grid grid-cols-1 gap-4 md:grid-cols-[minmax(0,1fr)_minmax(0,2fr)]" data-tour="donor-report-delivery">
        <div class="rounded-xl border border-secondary-soft p-4 dark:border-transparent dark:bg-surface-1 dark:shadow-raised">
            <h2 class="text-sm font-semibold">{{ __('donor_reports.responsible') }}</h2>
            <x-ui.employee-chip :employee="$responsible['employee'] ?? null" class="mt-2" />
            @if ($responsible !== null && ! $responsible['reachable'])
                <p class="mt-2 text-xs text-status-critical" data-responsible-warning>{{ __('donor_reports.responsible_unreachable') }}</p>
            @endif
            @if (filled($report->notes))
                <h3 class="mt-4 text-sm font-semibold">{{ __('donor_reports.notes') }}</h3>
                <p class="mt-1 whitespace-pre-line text-sm" dir="auto">{{ $report->notes }}</p>
            @endif
        </div>

        <div class="rounded-xl border border-secondary-soft p-4 dark:border-transparent dark:bg-surface-1 dark:shadow-raised" data-delivered-files>
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h2 class="text-sm font-semibold">{{ __('donor_reports.delivered_files') }} <span class="tabular-nums {{ $muted }}">{{ $this->files->count() }}</span></h2>
                @if ($report->audience === \App\Enums\ReportAudience::Donor && ! $report->isSubmitted())
                    <span class="text-xs {{ $muted }}">{{ __('donor_reports.file_required_help') }}</span>
                @endif
            </div>
            @if ($this->files->isEmpty())
                <p class="mt-2 text-sm {{ $muted }}">{{ __('donor_reports.no_files_yet') }}</p>
            @else
                <ul class="mt-2 divide-y divide-secondary-soft text-sm dark:divide-line">
                    @foreach ($this->files as $file)
                        <li class="flex items-center justify-between gap-2 py-2" wire:key="donor-report-file-{{ $file->id }}" data-delivered-file="{{ $file->id }}">
                            <span class="min-w-0 truncate" dir="auto">{{ $file->original_name }}</span>
                            <span class="flex shrink-0 items-center gap-1">
                                @can('downloadFile', $report)
                                    <x-ui.button tone="ghost" size="sm" icon="arrow-down-tray" class="size-8!" :href="route('donor-reports.files.download', [$report, $file->id])" :aria-label="__('donor_reports.download_name', ['name' => $file->original_name])" />
                                @endcan
                                @if (! $report->isSubmitted())
                                    @can('deleteFile', $report)
                                        <x-ui.button tone="ghost" size="sm" icon="trash" class="size-8!" wire:click="confirmFileDelete({{ $file->id }})" :aria-label="__('donor_reports.remove_name', ['name' => $file->original_name])" />
                                    @endcan
                                @endif
                            </span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if (! $report->isSubmitted())
                @can('uploadFile', $report)
                    <form wire:submit="uploadFile" class="mt-3 grid gap-2">
                        <x-ui.form-file
                            wire:model="newFile"
                            :label="__('donor_reports.add_file')"
                            :accept="collect(FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',')"
                            :types="\Illuminate\Support\Str::upper(implode(', ', FileType::values()))"
                            max-size="5 MB"
                        />
                        <flux:error name="report" />
                        <div>
                            <x-ui.button type="submit" size="sm" icon="arrow-up-tray" wire:loading.attr="disabled" wire:target="newFile,uploadFile" data-test="upload-donor-report-file">{{ __('donor_reports.upload') }}</x-ui.button>
                        </div>
                    </form>
                @endcan
            @endif
        </div>
    </section>

    @if ($this->versions->count() > 1)
        <nav class="flex flex-wrap items-center gap-2 text-sm" aria-label="{{ __('donor_reports.versions') }}" data-versions>
            <span class="{{ $muted }}">{{ __('donor_reports.versions') }}</span>
            @foreach ($this->versions as $version)
                <a href="{{ route('donor-reports.show', $version) }}" wire:navigate @class([
                    'rounded-full border px-2.5 py-0.5 tabular-nums',
                    'border-primary bg-primary text-white' => $version->id === $report->id,
                    'border-secondary-soft dark:border-line' => $version->id !== $report->id,
                ]) @if ($version->id === $report->id) aria-current="page" @endif>
                    {{ __('donor_reports.version_number', ['version' => $version->version]) }}
                    @if ($version->submitted_on !== null)
                        · {{ __('donor_reports.submitted') }}
                    @endif
                </a>
            @endforeach
        </nav>
    @endif

    @if ($snapshot !== null)
        <x-donor-reports.snapshot :snapshot="$snapshot" :show-finance="$this->showFinance" />
    @else
        <x-ui.empty-state icon="document-text" :heading="__('donor_reports.hand_entered_title')" :body="__('donor_reports.hand_entered_help')" />
    @endif

    <x-ui.confirm-modal
        wire:model="showRegenerateModal"
        tone="neutral"
        :title="__('donor_reports.regenerate_title')"
        :message="__('donor_reports.regenerate_consequence', ['version' => $report->version + 1])"
        confirm="regenerate"
        :confirm-label="__('donor_reports.regenerate')"
        confirm-test="confirm-regenerate"
    >
        @if ($showRegenerateModal)
            <flux:error name="report" />
            <flux:error name="proposalId" />
        @endif
    </x-ui.confirm-modal>

    <x-ui.confirm-modal
        wire:model="showFileDeleteModal"
        close="closeFileDeleteModal"
        :title="__('donor_reports.remove_file_title')"
        :message="__('donor_reports.remove_file_consequence')"
        confirm="deleteFile"
        :confirm-label="__('donor_reports.remove_file')"
        confirm-test="confirm-remove-file"
    />

    <x-donor-reports.submit-modal :open="$showSubmitModal" :needs-file="$submissionNeedsFile" :file-count="$submissionFileCount" />
</section>
</x-desk.record-sheet>
