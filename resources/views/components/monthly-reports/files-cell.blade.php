@props([
    'files' => [],
    'missing' => [],
    'editable' => false,
    'lineId' => null,
    'entryId' => null,
    'label' => '',
])

@php
    /*
     * The evidence of one monthly report line or activity entry (T17B slice
     * 2; D-42, contracts §13.1 item 4): its files with their type and the
     * protected download link, the required types still missing (lines
     * only), and Attach / delete when the viewer fills this line or entry.
     * Rows come prebuilt from ReadsMonthlyReport; this runs no query.
     */
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
@endphp

<div class="flex min-w-44 flex-col gap-1.5">
    @foreach ($files as $file)
        <div class="flex items-center gap-1.5" wire:key="file-{{ $file['id'] }}">
            <flux:icon.paper-clip variant="micro" class="size-3.5 shrink-0 {{ $mutedText }}" aria-hidden="true" />
            <a
                href="{{ $file['url'] }}"
                class="min-w-0 truncate text-xs font-medium underline hover:text-primary dark:hover:text-brand-text"
                title="{{ $file['name'] }}"
                aria-label="{{ __('monthly_reports.download_name', ['name' => $file['name']]) }}"
                dir="auto"
            >{{ $file['name'] }}</a>
            <span class="shrink-0 text-[11px] {{ $mutedText }}" dir="auto">{{ $file['type'] }}</span>
            @if ($editable)
                <button
                    type="button"
                    wire:click="confirmDeleteFile({{ $file['id'] }})"
                    class="ms-auto shrink-0 rounded p-0.5 {{ $mutedText }} hover:text-status-critical focus-visible:outline-2 focus-visible:outline-primary"
                    aria-label="{{ __('monthly_reports.delete_file_name', ['name' => $file['name']]) }}"
                >
                    <flux:icon.trash variant="micro" class="size-3.5" aria-hidden="true" />
                </button>
            @endif
        </div>
    @endforeach

    @if ($files === [] && $missing === [] && ! $editable)
        <span class="{{ $mutedText }}">—</span>
    @endif

    @if ($missing !== [])
        <p class="flex items-start gap-1 text-[11px] font-semibold text-status-warn" data-missing-evidence="{{ $lineId }}">
            <flux:icon.exclamation-triangle variant="micro" class="mt-px size-3 shrink-0" aria-hidden="true" />
            <span dir="auto">{{ __('monthly_reports.missing_evidence', ['types' => implode(app()->getLocale() === 'ar' ? '، ' : ', ', $missing)]) }}</span>
        </p>
    @endif

    @if ($editable)
        <button
            type="button"
            wire:click="openFileModal({{ $lineId === null ? 'null' : $lineId }}, {{ $entryId === null ? 'null' : $entryId }})"
            class="inline-flex w-fit items-center gap-1 rounded-md border border-secondary-light px-2 py-1 text-xs font-semibold text-secondary-dark hover:bg-secondary-soft focus-visible:outline-2 focus-visible:outline-primary dark:border-line-strong dark:text-ink dark:hover:bg-white/[0.06]"
            aria-label="{{ __('monthly_reports.attach_file_to', ['name' => $label]) }}"
        >
            <flux:icon.arrow-up-tray variant="micro" class="size-3.5" aria-hidden="true" />
            {{ __('monthly_reports.attach_file') }}
        </button>
    @endif
</div>
