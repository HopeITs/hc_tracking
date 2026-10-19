@props([
    'open' => false,
    'needsFile' => true,
    'fileCount' => 0,
])

{{--
    The "Mark as submitted" dialog of a donor report (SubmitsDonorReport):
    the submission date and the delivered file. A donor-facing report needs
    a file unless one is already attached (D-21).
--}}
<x-ui.modal
    wire:model="showSubmitModal"
    size="md"
    :title="__('donor_reports.mark_submitted')"
    :description="__('donor_reports.submit_consequence')"
    submit="submitReport"
    close="closeSubmitModal"
    required-note
>
    @if ($open)
        <div class="grid gap-y-4" data-test="donor-report-submit-form">
            <flux:error name="report" />

            <x-ui.form-input wire:model="submittedOn" :label="__('donor_reports.submitted_on')" type="date" :max="now()->toDateString()" required />

            <x-ui.form-file
                wire:model="submissionFile"
                :label="__('donor_reports.report_file')"
                :accept="collect(\App\Enums\FileType::values())->map(fn ($extension) => '.'.$extension)->implode(',')"
                :types="\Illuminate\Support\Str::upper(implode(', ', \App\Enums\FileType::values()))"
                max-size="5 MB"
                :required="$needsFile && $fileCount === 0"
                :help="match (true) {
                    $fileCount > 0 => trans_choice('donor_reports.files_already_attached', $fileCount, ['count' => $fileCount]),
                    $needsFile => __('donor_reports.file_required_help'),
                    default => __('donor_reports.file_optional_help'),
                }"
            />

            <flux:error name="submissionFiles" />
        </div>
    @endif

    <x-slot:actions>
        <x-ui.button type="submit" tone="primary" icon="check" wire:loading.attr="disabled" wire:target="submissionFile,submitReport" data-test="confirm-submit-donor-report">
            {{ __('donor_reports.mark_submitted') }}
        </x-ui.button>
    </x-slot:actions>
</x-ui.modal>
