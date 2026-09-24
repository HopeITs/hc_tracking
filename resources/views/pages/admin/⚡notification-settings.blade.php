<?php

use App\Notifications\Alerts\NotificationSettings;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.notification_rules')] class extends Component {
    /** @var list<int> ISO weekdays */
    public array $workingDays = [];

    public string $dueSoonDays = '';

    public int $overdueRepeat = 3;

    public int $escalateManagerAfter = 5;

    public int $escalateTopAfter = 10;

    public int $escalationCatchUp = 3;

    public int $stalledTaskInProgress = 7;

    public int $stalledTaskUnderReview = 3;

    public int $stalledReportDraft = 7;

    public int $stalledReportReviewed = 5;

    public int $stalledProposal = 30;

    public int $offTrackPace = 80;

    public string $dailyBriefTime = '07:30';

    public string $weeklyDigestTime = '08:00';

    /**
     * Form property => settings key.
     */
    private const Keys = [
        'overdueRepeat' => 'overdue_repeat_days',
        'escalateManagerAfter' => 'escalate_manager_after_days',
        'escalateTopAfter' => 'escalate_top_after_days',
        'escalationCatchUp' => 'escalation_catch_up_days',
        'stalledTaskInProgress' => 'stalled_task_in_progress_days',
        'stalledTaskUnderReview' => 'stalled_task_under_review_days',
        'stalledReportDraft' => 'stalled_report_draft_days',
        'stalledReportReviewed' => 'stalled_report_reviewed_days',
        'stalledProposal' => 'stalled_proposal_days',
        'offTrackPace' => 'off_track_pace_percent',
        'dailyBriefTime' => 'daily_brief_time',
        'weeklyDigestTime' => 'weekly_digest_time',
    ];

    public function mount(NotificationSettings $settings): void
    {
        $values = $settings->all();

        $this->workingDays = array_map('intval', $values['working_days']);
        $this->dueSoonDays = implode(', ', $values['due_soon_days']);

        foreach (self::Keys as $property => $key) {
            $this->{$property} = $values[$key];
        }
    }

    public function save(NotificationSettings $settings): void
    {
        Gate::authorize('manage-notification-settings');

        $validated = $this->validate([
            'workingDays' => ['required', 'array', 'min:1', function (string $attribute, mixed $value, Closure $fail): void {
                foreach ((array) $value as $weekday) {
                    if (! is_numeric($weekday) || (int) $weekday < 1 || (int) $weekday > 7) {
                        $fail(__('notifications.admin.bad_weekday'));

                        return;
                    }
                }
            }],
            'dueSoonDays' => ['required', 'regex:/^\s*\d{1,2}(\s*,\s*\d{1,2})*\s*$/'],
            'overdueRepeat' => ['required', 'integer', 'between:1,30'],
            'escalateManagerAfter' => ['required', 'integer', 'between:1,60'],
            'escalateTopAfter' => ['required', 'integer', 'gt:escalateManagerAfter', 'max:90'],
            'escalationCatchUp' => ['required', 'integer', 'between:1,30'],
            'stalledTaskInProgress' => ['required', 'integer', 'between:1,365'],
            'stalledTaskUnderReview' => ['required', 'integer', 'between:1,365'],
            'stalledReportDraft' => ['required', 'integer', 'between:1,365'],
            'stalledReportReviewed' => ['required', 'integer', 'between:1,365'],
            'stalledProposal' => ['required', 'integer', 'between:1,365'],
            'offTrackPace' => ['required', 'integer', 'between:1,100'],
            'dailyBriefTime' => ['required', 'date_format:H:i'],
            'weeklyDigestTime' => ['required', 'date_format:H:i'],
        ]);

        $stages = collect(explode(',', $validated['dueSoonDays']))->map(fn (string $days): int => (int) trim($days))->filter()->unique()->sortDesc()->values()->all();
        $days = array_values(array_unique(array_map('intval', $validated['workingDays'])));

        $values = ['working_days' => $days, 'due_soon_days' => $stages];

        foreach (self::Keys as $property => $key) {
            $values[$key] = $validated[$property];
        }

        $settings->save($values);

        Flux::toast(variant: 'success', text: __('notifications.admin.saved'));
    }
}; ?>

<section class="@container/directory flex min-h-full w-full flex-1 flex-col gap-8 bg-white text-secondary-dark dark:bg-secondary-dark dark:text-white" aria-labelledby="notification-rules-heading">
    <x-ui.page-header heading-id="notification-rules-heading" :title="__('notifications.admin.title')">
        <x-slot:actions>
            <x-ui.button tone="primary" icon="check" wire:click="save">{{ __('notifications.admin.save') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.page-header>

    <form wire:submit="save" class="grid max-w-4xl gap-10">
        <fieldset class="grid gap-3">
            <legend class="type-heading">{{ __('notifications.admin.working_days') }}</legend>
            <p class="text-sm text-secondary-medium dark:text-secondary-soft">{{ __('notifications.admin.working_days_help') }}</p>
            <div class="flex flex-wrap gap-2">
                @foreach (range(1, 7) as $weekday)
                    <label class="inline-flex min-h-10 items-center gap-2 rounded-full border border-secondary-soft px-3 text-sm has-checked:border-primary has-checked:bg-primary/5 dark:border-secondary-medium">
                        <input type="checkbox" wire:model="workingDays" value="{{ $weekday }}" class="size-4 accent-primary" />
                        {{ \Carbon\CarbonImmutable::now()->startOfWeek(\Carbon\CarbonImmutable::MONDAY)->addDays($weekday - 1)->translatedFormat('l') }}
                    </label>
                @endforeach
            </div>
            @error('workingDays') <p class="text-sm text-status-critical">{{ $message }}</p> @enderror
        </fieldset>

        <fieldset class="grid items-start gap-4 @3xl/directory:grid-cols-2">
            <legend class="type-heading">{{ __('notifications.admin.deadlines') }}</legend>
            <x-ui.input :label="__('notifications.admin.due_soon_days')" description:trailing="{{ __('notifications.admin.due_soon_days_help') }}" wire:model="dueSoonDays" />
            <x-ui.input type="number" min="1" :label="__('notifications.admin.overdue_repeat')" wire:model="overdueRepeat" />
            <x-ui.input type="number" min="1" :label="__('notifications.admin.escalate_manager')" wire:model="escalateManagerAfter" />
            <x-ui.input type="number" min="1" :label="__('notifications.admin.escalate_top')" wire:model="escalateTopAfter" />
            <x-ui.input type="number" min="1" max="30" :label="__('notifications.admin.escalation_catch_up')" description:trailing="{{ __('notifications.admin.escalation_catch_up_help') }}" wire:model="escalationCatchUp" />
        </fieldset>

        <fieldset class="grid items-start gap-4 @3xl/directory:grid-cols-2">
            <legend class="type-heading">{{ __('notifications.admin.stalled') }}</legend>
            <x-ui.input type="number" min="1" :label="__('notifications.admin.stalled_task_in_progress')" wire:model="stalledTaskInProgress" />
            <x-ui.input type="number" min="1" :label="__('notifications.admin.stalled_task_under_review')" wire:model="stalledTaskUnderReview" />
            <x-ui.input type="number" min="1" :label="__('notifications.admin.stalled_report_draft')" wire:model="stalledReportDraft" />
            <x-ui.input type="number" min="1" :label="__('notifications.admin.stalled_report_reviewed')" wire:model="stalledReportReviewed" />
            <x-ui.input type="number" min="1" :label="__('notifications.admin.stalled_proposal')" wire:model="stalledProposal" />
        </fieldset>

        <fieldset class="grid items-start gap-4 @3xl/directory:grid-cols-2">
            <legend class="type-heading">{{ __('notifications.admin.indicators_and_digests') }}</legend>
            <x-ui.input type="number" min="1" max="100" :label="__('notifications.admin.off_track_pace')" wire:model="offTrackPace" />
            <x-ui.input type="time" :label="__('notifications.admin.daily_brief_time')" wire:model="dailyBriefTime" />
            <x-ui.input type="time" :label="__('notifications.admin.weekly_digest_time')" wire:model="weeklyDigestTime" />
        </fieldset>
    </form>
</section>
