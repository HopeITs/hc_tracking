{{-- Activities this month on ⚡show.blade.php (contracts §13, D-49b, D-51): one row per activity, its status as chips (only the statuses its kind allows), the input that status needs, a note and evidence. --}}
@use('App\Enums\ActivityStatus')

@php
    $activities = $this->activities;
    $fillsNarrative = $this->fillsNarrative();
    $mutedText = 'text-secondary-medium dark:text-ink-muted';
    $field = 'w-full rounded-md border border-secondary-light bg-white px-2 py-1.5 text-sm text-secondary-dark outline-none focus:border-secondary-medium focus:ring-2 focus:ring-secondary-light dark:border-field-line dark:bg-surface-2 dark:text-ink dark:focus:border-ink-muted';
    $chip = 'inline-flex cursor-pointer items-center gap-1 rounded-full border border-secondary-light px-3 py-1 text-xs font-semibold text-secondary-dark hover:bg-secondary-soft has-checked:border-transparent has-checked:bg-secondary-dark has-checked:text-white has-focus-visible:ring-2 has-focus-visible:ring-secondary-light dark:border-line-strong dark:text-ink dark:hover:bg-white/[0.06] dark:has-checked:bg-ink dark:has-checked:text-surface-0';
    $reasonStatuses = ['not_started', 'suspended', 'cancelled'];
    $dateFields = ['started' => 'started_on', 'completed' => 'completed_on', 'conducted' => 'executed_on'];
    $statusTone = fn (?ActivityStatus $status): string => match ($status) {
        ActivityStatus::Completed, ActivityStatus::Conducted => 'done',
        ActivityStatus::Started, ActivityStatus::Ongoing => 'going',
        ActivityStatus::Suspended, ActivityStatus::Cancelled, ActivityStatus::NotStarted => 'stopped',
        null => 'none',
    };
    $statusIcon = ['done' => 'check', 'going' => 'arrow-path', 'stopped' => 'pause', 'none' => 'minus'];
    $longDate = fn (?string $date): ?string => $date === null ? null : \Carbon\CarbonImmutable::parse($date)->translatedFormat(__('formats.date'));
    $preferred = [ActivityStatus::Started, ActivityStatus::Ongoing, ActivityStatus::Completed, ActivityStatus::Conducted, ActivityStatus::NotStarted, ActivityStatus::Suspended, ActivityStatus::Cancelled];
@endphp

<section aria-labelledby="monthly-report-activities-heading" class="flex flex-col gap-3" data-monthly-report-activities>
    <div>
        <h2 id="monthly-report-activities-heading" class="mrp-h2">{{ __('monthly_reports.activities_this_month') }}@if ($activities !== []) <span class="mrp-count">{{ count($activities) }}</span>@endif</h2>
        @if ($fillsNarrative && $activities !== [])
            <p class="type-meta {{ $mutedText }}">{{ __('monthly_reports.activities_help') }}</p>
        @endif
    </div>

    @if ($activities === [])
        @include('pages.monthly-reports._show-empty-line', ['key' => 'activities', 'icon' => 'calendar-days', 'heading' => __('monthly_reports.no_activities'), 'body' => __('monthly_reports.no_activities_body')])
    @else
        {{-- Read only: a two-column grid of short cards; while filling, one stacked card with room for the inputs. --}}
        <ol class="{{ $fillsNarrative ? 'mrp-stack' : 'mrp-act-grid' }}" role="list">
            @foreach ($activities as $activity)
                @php
                    $key = 'activityEntries.'.$activity['id'];
                    $state = $activityEntries[$activity['id']] ?? [];
                    $chosen = (string) ($state['status'] ?? '');
                    $allowed = $activity['statuses'];
                    $primary = count($allowed) <= 4
                        ? $allowed
                        : array_slice(array_values(array_filter($preferred, fn (ActivityStatus $status): bool => in_array($status, $allowed, true))), 0, 3);
                    $more = array_values(array_filter($allowed, fn (ActivityStatus $status): bool => ! in_array($status, $primary, true)));
                    $chosenInMore = collect($more)->contains(fn (ActivityStatus $status): bool => $status->value === $chosen);
                    $noteOpen = filled($state['note'] ?? '') || $errors->has($key.'.note');
                @endphp

                @php($tone = $statusTone($activity['status']))
                <li class="mrp-act mrp-act-{{ $tone }}" wire:key="activity-{{ $activity['id'] }}" data-activity="{{ $activity['id'] }}">
                    @unless ($fillsNarrative)
                        <span class="mrp-act-mark" aria-hidden="true"><flux:icon :name="$statusIcon[$tone]" variant="micro" class="size-4" /></span>
                    @endunless
                    <div class="mrp-act-body">
                    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
                        <div class="min-w-0 flex-1 basis-64">
                            <p class="mrp-act-title" dir="auto">{{ $activity['statement'] }}</p>
                            @if ($activity['proposal'] !== null)
                                <p class="type-meta {{ $mutedText }}" dir="auto" data-activity-proposal="{{ $activity['id'] }}">{{ $activity['proposal'] }}</p>
                            @endif
                            <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-1 text-xs {{ $mutedText }}">
                                @if ($activity['planned'] !== null)
                                    <span class="inline-flex items-center gap-1">
                                        <flux:icon.calendar-days variant="micro" class="size-3" aria-hidden="true" />
                                        {{ $activity['planned'] }}
                                    </span>
                                @endif
                                @if ($activity['carried'] !== null)
                                    <span @class(['inline-flex items-center gap-1', 'font-semibold text-status-critical' => $activity['late']]) data-carried="{{ $activity['id'] }}">
                                        @if ($activity['late'])
                                            <flux:icon.exclamation-triangle variant="micro" class="size-3" aria-hidden="true" />
                                        @else
                                            <flux:icon.arrow-path variant="micro" class="size-3" aria-hidden="true" />
                                        @endif
                                        {{ $activity['carried'] }}
                                    </span>
                                @endif
                            </p>
                        </div>

                        @if ($fillsNarrative)
                            <div x-data="{ more: @js($chosenInMore) }" role="radiogroup" aria-label="{{ $activity['statement'] }} — {{ __('monthly_reports.activity_status') }}" class="flex flex-wrap items-center gap-1.5">
                                @foreach ($primary as $status)
                                    <label class="{{ $chip }}">
                                        <input type="radio" class="sr-only" wire:model.live="{{ $key }}.status" value="{{ $status->value }}" @checked($chosen === $status->value) data-activity-option="{{ $activity['id'] }}-{{ $status->value }}">
                                        {{ $status->label() }}
                                    </label>
                                @endforeach
                                @foreach ($more as $status)
                                    <label class="{{ $chip }}" x-show="more" @unless ($chosenInMore) style="display: none" @endunless>
                                        <input type="radio" class="sr-only" wire:model.live="{{ $key }}.status" value="{{ $status->value }}" @checked($chosen === $status->value) data-activity-option="{{ $activity['id'] }}-{{ $status->value }}">
                                        {{ $status->label() }}
                                    </label>
                                @endforeach
                                @if ($more !== [])
                                    <button type="button" x-show="! more" @if ($chosenInMore) style="display: none" @endif x-on:click="more = true" class="rounded-full px-2 py-1 text-xs font-semibold {{ $mutedText }} hover:text-secondary-dark focus-visible:outline-2 focus-visible:outline-secondary-medium dark:hover:text-ink">
                                        {{ __('monthly_reports.more_statuses') }}
                                    </button>
                                @endif
                            </div>
                        @elseif ($activity['status'] !== null)
                            <span class="mrp-pill mrp-pill-{{ $tone }}" data-activity-status="{{ $activity['status']->value }}">{{ $activity['status']->label() }}</span>
                        @else
                            <span class="text-sm {{ $mutedText }}">{{ __('monthly_reports.no_status_yet') }}</span>
                        @endif
                    </div>

                    @error($key.'.status')
                        <p class="text-xs text-status-critical">{{ $message }}</p>
                    @enderror

                    @if ($fillsNarrative)
                        @if (in_array($chosen, $reasonStatuses, true))
                            <label class="flex max-w-2xl flex-col gap-1 text-xs font-semibold">
                                {{ __('monthly_reports.activity_reason') }}
                                <textarea rows="2" dir="auto" wire:model.live.blur="{{ $key }}.reason" class="{{ $field }}"></textarea>
                                @error($key.'.reason')
                                    <span class="font-normal text-status-critical">{{ $message }}</span>
                                @enderror
                            </label>
                        @endif

                        @foreach (isset($dateFields[$chosen]) ? [$dateFields[$chosen]] : [] as $dateField)
                            <label class="flex w-48 flex-col gap-1 text-xs font-semibold">
                                {{ __('monthly_reports.'.$dateField) }}
                                <input type="date" dir="ltr" wire:model.live.blur="{{ $key }}.{{ $dateField }}" class="{{ $field }} tabular-nums" />
                                @error($key.'.'.$dateField)
                                    <span class="font-normal text-status-critical">{{ $message }}</span>
                                @enderror
                            </label>
                        @endforeach

                        <div x-data="{ note: @js($noteOpen) }" class="flex flex-wrap items-start gap-x-6 gap-y-2">
                            <button type="button" x-show="! note" @if ($noteOpen) style="display: none" @endif x-on:click="note = true; $nextTick(() => $refs.note.focus())" class="inline-flex items-center gap-1 text-xs font-semibold {{ $mutedText }} hover:text-secondary-dark focus-visible:outline-2 focus-visible:outline-secondary-medium dark:hover:text-ink">
                                <flux:icon.plus variant="micro" class="size-3.5" aria-hidden="true" />
                                {{ __('monthly_reports.add_note') }}
                            </button>
                            <label x-show="note" @unless ($noteOpen) style="display: none" @endunless class="flex w-full max-w-2xl flex-col gap-1 text-xs font-semibold">
                                {{ __('monthly_reports.activity_note') }}
                                <textarea x-ref="note" rows="2" dir="auto" wire:model.live.blur="{{ $key }}.note" class="{{ $field }}"></textarea>
                            </label>
                            @if ($activity['entry_id'] !== null)
                                <x-monthly-reports.files-cell :files="$activity['files']" :editable="true" :entry-id="$activity['entry_id']" :label="$activity['statement']" />
                            @endif
                        </div>
                        @error($key)
                            <p class="text-xs text-status-critical">{{ $message }}</p>
                        @enderror
                    @else
                        {{-- Read only: dates on one line, then the reason and note as plain text, never clickable. --}}
                        @php($shownDates = array_filter(array_map(fn (string $field): ?string => $activity[$field] === null ? null : __('monthly_reports.'.$field).' '.$longDate($activity[$field]), $dateFields)))
                        @if ($shownDates !== [])
                            <p class="mrp-act-meta tabular-nums">{{ implode(' · ', $shownDates) }}</p>
                        @endif
                        @foreach (array_filter(['reason' => $activity['reason'], 'note' => $activity['note']], 'filled') as $textKind => $text)
                            <p @class(['mrp-act-text', 'mrp-act-text-reason' => $textKind === 'reason'])>
                                <b>{{ __('monthly_reports.activity_'.$textKind) }}</b>
                                <span class="whitespace-pre-line" dir="auto">{{ $text }}</span>
                            </p>
                        @endforeach
                        @if ($activity['files'] !== [])
                            <x-monthly-reports.files-cell :files="$activity['files']" :entry-id="$activity['entry_id']" :label="$activity['statement']" />
                        @endif
                    @endif
                    </div>
                </li>
            @endforeach
        </ol>
    @endif
</section>
