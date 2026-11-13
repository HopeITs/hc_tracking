{{-- Part 1, step 2: what was done per batch this month next to the plan for the whole batch, and the supporting files. --}}
@php
    $rows = $this->rows;
    $monthName = $this->monthStart->translatedFormat('F');
    $files = $record?->files ?? collect();
@endphp

<div class="ap-grp" data-update-numbers>
    <h3 class="ap-h2">2. {{ __('annual_plans.up.numbers_title') }}</h3>
    @unless ($readOnly)<p class="ap-sm ap-muted">{{ __('annual_plans.up.numbers_help', ['month' => $monthName]) }}</p>@endunless
    @if ($rows !== [])
        <div class="ap-tw">
            <table class="ap-t">
                <thead><tr><th>{{ __('annual_plans.up.col_batch') }}</th><th>{{ __('annual_plans.up.col_planned_batch') }}</th><th>{{ __('annual_plans.up.col_done', ['month' => $monthName]) }}</th><th>{{ __('annual_plans.wf.hours_each') }}</th></tr></thead>
                <tbody>
                    @foreach ($rows as $row)
                        @php $item = $row['item']; $batch = $row['batch']; @endphp
                        <tr wire:key="num-{{ $batch->id }}">
                            <td><b dir="auto">{{ $item->activity?->statement }}</b><span class="ap-xs ap-faint" style="display: block">{{ $item->is_single_week ? __('annual_plans.wf.week_n', ['n' => $row['index'] + 1]) : __('annual_plans.wf.batch_n', ['n' => $row['index'] + 1]) }} · {{ $batch->start_week->translatedFormat('j M') }}@unless ($item->is_single_week) – {{ $batch->end_week->translatedFormat('j M') }}@endunless</span></td>
                            <td class="ap-num">{{ $item->counted((int) $batch->planned_count) }}</td>
                            <td><input class="ap-inp is-n {{ $this->isMissing('number:'.$batch->id) ? 'is-err' : '' }}" type="number" min="0" wire:model.blur="draft.numbers.{{ $batch->id }}" @disabled($readOnly) aria-label="{{ __('annual_plans.up.col_done', ['month' => $monthName]) }}" data-test="done-count"></td>
                            <td class="ap-num">{{ $item->unit->needsHours() && $batch->hours_each !== null ? rtrim(rtrim((string) $batch->hours_each, '0'), '.').' h' : '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif

    <div class="ap-grp">
        <span class="ap-lbl">{{ __('annual_plans.up.files_title') }}</span>
        @unless ($readOnly)<p class="ap-xs ap-faint">{{ __('annual_plans.up.files_help') }}</p>@endunless
        <div class="ap-files">
            @forelse ($files as $file)
                <div class="ap-file" wire:key="file-{{ $file->id }}">📎 <span class="ap-sp" dir="auto">{{ $file->original_name }}</span>
                    <a class="dk-btn dk-ghost" href="{{ route('plan-updates.files.download', $file) }}" target="_blank" rel="noopener" data-test="open-plan-update-file">{{ __('annual_plans.up.open_file') }}</a>
                    @unless ($readOnly)<button type="button" class="dk-btn dk-ghost" wire:click="removeFile({{ $file->id }})">{{ __('annual_plans.wf.remove') }}</button>@endunless
                </div>
            @empty
                @if ($readOnly)<span class="ap-sm ap-faint">{{ __('annual_plans.up.no_files') }}</span>@endif
            @endforelse
        </div>
        @unless ($readOnly)
            <label class="ap-up {{ $this->isMissing('files') ? 'is-err' : '' }}" style="align-self: flex-start">+ {{ __('annual_plans.up.add_files') }}<input type="file" multiple wire:model="newFiles" aria-label="{{ __('annual_plans.up.add_files') }}" data-test="add-files"></label>
            <span wire:loading wire:target="newFiles" class="ap-xs ap-faint">{{ __('common.updating') }}</span>
            @error('file') <p class="ap-err">{{ $message }}</p> @enderror
            @if ($this->isMissing('files'))<p class="ap-err">{{ __('annual_plans.up.add_one_file') }}</p>@endif
        @endunless
    </div>
</div>
