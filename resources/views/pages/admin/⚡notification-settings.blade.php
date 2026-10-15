<?php

use App\Actions\Proposals\SaveDefaultReviewerSlots;
use App\Enums\EventImportance;
use App\Enums\OversightMode;
use App\Enums\ReviewerSlot;
use App\Models\HrPosition;
use App\Models\Role;
use App\Notifications\Alerts\NotificationSettings;
use App\Notifications\EventCatalog;
use App\Support\Slots;
use Carbon\CarbonImmutable;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Lang;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('titles.notification_rules')] class extends Component {
    /**
     * One row per EventCatalog key, in catalog order (the Event Notifications section).
     *
     * @var list<array{key: string, enabled: bool, importance: string, oversight_roles: list<string>}>
     */
    public array $events = [];

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

    /** @var array<string, string> slot value => HR position id ('' for none) */
    public array $reviewerPositions = [];

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

    public function mount(NotificationSettings $settings, EventCatalog $catalog): void
    {
        $this->fill($this->toForm($settings->all()));
        $this->events = $this->eventRows($catalog);
        $this->fillReviewerPositions();
    }

    /**
     * Store the HR position behind each default proposal reviewer slot
     * (contracts.md §9). A position, never a person.
     */
    public function saveDefaultReviewers(): void
    {
        Gate::authorize('notification-settings.manage');

        $this->validate(
            collect(ReviewerSlot::defaultSlots())->mapWithKeys(fn (ReviewerSlot $slot): array => ["reviewerPositions.{$slot->value}" => ['nullable', 'integer']])->all(),
            collect(ReviewerSlot::defaultSlots())->mapWithKeys(fn (ReviewerSlot $slot): array => ["reviewerPositions.{$slot->value}.integer" => __('notifications.admin.default_reviewers.unknown_position')])->all(),
        );

        $positions = collect(ReviewerSlot::defaultSlots())
            ->mapWithKeys(fn (ReviewerSlot $slot): array => [$slot->value => filled($this->reviewerPositions[$slot->value] ?? null) ? (int) $this->reviewerPositions[$slot->value] : null])
            ->all();

        try {
            app(SaveDefaultReviewerSlots::class)->handle($positions, auth()->user());
        } catch (ValidationException $exception) {
            foreach ($exception->errors() as $key => $messages) {
                foreach ($messages as $message) {
                    $this->addError($key, $message);
                }
            }

            return;
        }

        $this->fillReviewerPositions();

        Flux::toast(variant: 'success', text: __('notifications.admin.default_reviewers.saved'));
    }

    /**
     * Active HR positions for the slot pickers.
     *
     * @return list<array{value: string, label: string}>
     */
    protected function positionOptions(): array
    {
        return HrPosition::pickerOptions();
    }

    private function fillReviewerPositions(): void
    {
        $this->reviewerPositions = array_map(fn (?int $id): string => $id === null ? '' : (string) $id, Slots::positionIds());
    }

    public function save(NotificationSettings $settings): void
    {
        Gate::authorize('notification-settings.manage');

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
            'events' => ['array'],
            'events.*.key' => ['required', 'string', 'distinct', Rule::in(EventCatalog::keys())],
            'events.*.enabled' => ['required', 'boolean'],
            'events.*.importance' => ['required', Rule::enum(EventImportance::class)],
            'events.*.oversight_roles' => ['array'],
            'events.*.oversight_roles.*' => ['string', Rule::notIn([EventCatalog::SuperAdminRole]), Rule::exists('roles', 'name')],
        ], [
            'events.*.key.in' => __('notifications.settings.events.unknown_event'),
            'events.*.oversight_roles.*.not_in' => __('notifications.settings.events.super_admin_forbidden'),
            'events.*.oversight_roles.*.exists' => __('notifications.settings.events.unknown_role'),
        ]);

        $stages = collect(explode(',', $validated['dueSoonDays']))->map(fn (string $days): int => (int) trim($days))->filter()->unique()->sortDesc()->values()->all();
        $days = collect($validated['workingDays'])->map(fn (mixed $day): int => (int) $day)->unique()->sort()->values()->all();

        $values = ['working_days' => $days, 'due_soon_days' => $stages, 'event_overrides' => $this->overridesFrom($validated['events'] ?? [])];

        foreach (self::Keys as $property => $key) {
            $values[$key] = $validated[$property];
        }

        $settings->save($values);

        $this->fill($this->toForm($values));
        $this->events = $this->eventRows(new EventCatalog($settings));
        unset($this->staleOverrides);
        $this->dispatch('notification-rules-saved');

        Flux::toast(variant: 'success', text: __('notifications.admin.saved'));
    }

    /**
     * Clears the error messages left by a failed save once the draft is discarded.
     */
    public function forgetErrors(): void
    {
        $this->resetValidation();
    }

    /**
     * The shipped defaults, keyed by form property.
     *
     * @return array<string, mixed>
     */
    protected function defaultRules(): array
    {
        return $this->toForm(config('notifications.defaults'));
    }

    /**
     * Everything the page script needs: defaults, translated templates,
     * pre-resolved plural forms (Arabic needs real plural ranges) and weekday names.
     *
     * @return array{defaults: array<string, mixed>, eventDefaults: list<array{key: string, enabled: bool, importance: string, oversight_roles: list<string>}>, strings: array<string, string>, plurals: array<string, array<1|2|3|11, string>>, weekdays: list<string>}
     */
    protected function clientConfig(): array
    {
        $plurals = fn (string $key): array => collect([1, 2, 3, 11])->mapWithKeys(fn (int $count): array => [$count => trans_choice($key, $count, ['count' => ':count'])])->all();

        return [
            'defaults' => $this->defaultRules(),
            'eventDefaults' => $this->defaultEventRows(),
            'strings' => [
                'due' => __('notifications.admin.flow_due'),
                'manager' => __('notifications.admin.flow_manager'),
                'top' => __('notifications.admin.flow_top'),
                'weeklyDigestHint' => __('notifications.admin.weekly_digest_hint'),
                'leaveConfirm' => __('notifications.admin.leave_confirm'),
            ],
            'plurals' => [
                'workingDays' => $plurals('notifications.admin.working_days_unit'),
                'unsaved' => $plurals('notifications.admin.unsaved_count'),
                'before' => $plurals('notifications.admin.flow_before'),
                'remind' => $plurals('notifications.admin.flow_remind'),
            ],
            'weekdays' => $this->weekdayNames('l'),
        ];
    }

    /**
     * ISO weekday names (Monday first) in the current language.
     *
     * @return list<string>
     */
    protected function weekdayNames(string $format): array
    {
        $monday = CarbonImmutable::now()->startOfWeek(CarbonImmutable::MONDAY);

        return array_map(fn (int $offset): string => $monday->addDays($offset)->translatedFormat($format), range(0, 6));
    }

    /**
     * Roles an admin may add as oversight: every role but Super Admin (D-20a).
     *
     * @return list<string>
     */
    #[Computed]
    public function oversightRoleOptions(): array
    {
        return array_values(Role::query()
            ->where('name', '!=', EventCatalog::SuperAdminRole)
            ->orderBy('name')
            ->pluck('name')
            ->map(fn (mixed $name): string => (string) $name)
            ->all());
    }

    /**
     * Stored overrides the page can no longer apply: keys that left the
     * catalog and roles that no longer exist (or Super Admin). They are left
     * out of the form, so the next save drops them.
     *
     * @return array{keys: list<string>, roles: list<string>}
     */
    #[Computed]
    public function staleOverrides(): array
    {
        $stored = app(NotificationSettings::class)->get('event_overrides');
        $stored = is_array($stored) ? $stored : [];
        $known = array_intersect_key($stored, EventCatalog::all());
        $roles = collect($known)
            ->flatMap(fn (mixed $override): array => is_array($override) && is_array($override['oversight_roles'] ?? null) ? $override['oversight_roles'] : [])
            ->filter(fn (mixed $role): bool => is_string($role) && $role !== '')
            ->unique()
            ->diff($this->oversightRoleOptions)
            ->sort();

        return [
            'keys' => array_values(array_map('strval', array_diff(array_keys($stored), EventCatalog::keys()))),
            'roles' => array_values($roles->all()),
        ];
    }

    /**
     * The section's rows grouped by module, in catalog order.
     *
     * @return list<array{module: string, label: string, rows: list<array{index: int, key: string, title: string, critical: bool, scheduled: bool, oversight: bool}>}>
     */
    protected function eventGroups(): array
    {
        $groups = [];

        foreach (array_keys(EventCatalog::all()) as $index => $key) {
            $definition = EventCatalog::all()[$key];
            $module = $definition['module'];

            $groups[$module] ??= [
                'module' => $module,
                'label' => Lang::has("notifications.modules.{$module}") ? __("notifications.modules.{$module}") : __("notifications.settings.events.modules.{$module}"),
                'rows' => [],
            ];

            $groups[$module]['rows'][] = [
                'index' => $index,
                'key' => $key,
                'title' => __("notifications.events.{$key}.title"),
                'critical' => $definition['critical'],
                'scheduled' => $definition['direct'] === 'scanner',
                'oversight' => $definition['oversight'] !== OversightMode::None,
            ];
        }

        return array_values($groups);
    }

    /**
     * The form rows: the catalog merged with the stored overrides, minus roles that are gone.
     *
     * @return list<array{key: string, enabled: bool, importance: string, oversight_roles: list<string>}>
     */
    private function eventRows(EventCatalog $catalog): array
    {
        $options = $this->oversightRoleOptions();

        return array_map(function (string $key) use ($catalog, $options): array {
            $effective = $catalog->effective($key);

            return [
                'key' => $key,
                'enabled' => $effective['enabled'] ?? true,
                'importance' => ($effective['importance'] ?? EventImportance::Immediate)->value,
                'oversight_roles' => array_values(array_intersect($effective['oversight_roles'] ?? [], $options)),
            ];
        }, EventCatalog::keys());
    }

    /**
     * The catalog defaults as form rows (the page script's "use default").
     *
     * @return list<array{key: string, enabled: bool, importance: string, oversight_roles: list<string>}>
     */
    private function defaultEventRows(): array
    {
        return array_map(fn (string $key): array => [
            'key' => $key,
            'enabled' => true,
            'importance' => EventCatalog::all()[$key]['importance']->value,
            'oversight_roles' => [],
        ], EventCatalog::keys());
    }

    /**
     * Only what differs from the catalog. Scheduled (scanner) events keep their
     * importance, and events without oversight take no extra roles.
     *
     * @param  array<int, array{key: string, enabled: mixed, importance: string, oversight_roles?: list<string>}>  $rows
     * @return array<string, array{enabled?: false, importance?: string, oversight_roles?: list<string>}>
     */
    private function overridesFrom(array $rows): array
    {
        $catalog = EventCatalog::all();
        $overrides = [];

        foreach ($rows as $row) {
            $definition = $catalog[$row['key']];
            $override = [];

            if (! filter_var($row['enabled'], FILTER_VALIDATE_BOOLEAN)) {
                $override['enabled'] = false;
            }

            if ($definition['direct'] !== 'scanner' && $row['importance'] !== $definition['importance']->value) {
                $override['importance'] = $row['importance'];
            }

            $roles = array_values(array_unique($row['oversight_roles'] ?? []));
            sort($roles);

            if ($definition['oversight'] !== OversightMode::None && $roles !== []) {
                $override['oversight_roles'] = $roles;
            }

            if ($override !== []) {
                $overrides[$row['key']] = $override;
            }
        }

        return $overrides;
    }

    /**
     * Settings values (by settings key) mapped onto the form properties.
     *
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    private function toForm(array $values): array
    {
        $form = [
            'workingDays' => array_map('intval', $values['working_days']),
            'dueSoonDays' => implode(', ', $values['due_soon_days']),
        ];

        foreach (self::Keys as $property => $key) {
            $form[$property] = $values[$key];
        }

        return $form;
    }
}; ?>

<section
    class="rules-page @container/rules flex min-h-full w-full flex-1 flex-col bg-white text-secondary-dark dark:bg-surface-0 dark:text-ink"
    aria-labelledby="notification-rules-heading"
    x-data="notificationRules(@js($this->clientConfig()))"
>
    <x-ui.page-header heading-id="notification-rules-heading" :title="__('notifications.admin.title')" />

    <form x-on:submit.prevent="save()" class="flex flex-1 flex-col pt-8" novalidate>
        <div class="grid items-start gap-x-16 gap-y-12 @5xl/rules:grid-cols-2">
            <div class="grid gap-12">
                @include('pages.admin._rules-week')

                <section id="rules-deadlines" aria-labelledby="rules-deadlines-heading" class="rules-group">
                    <h2 id="rules-deadlines-heading" class="rules-group-title">{{ __('notifications.admin.sections.deadlines') }}</h2>
                    <p class="rules-group-hint">{{ __('notifications.admin.deadlines_hint') }}</p>

                    <ol class="rules-flow" aria-label="{{ __('notifications.admin.flow_label') }}">
                        <template x-for="step in flow" :key="step.key">
                            <li class="rules-flow-step" x-bind:data-kind="step.kind">
                                <span class="rules-flow-mark" aria-hidden="true"></span>
                                <span x-text="step.label"></span>
                            </li>
                        </template>
                    </ol>

                    <div class="rules-rows">
                        @include('pages.admin._rules-warnings')
                        @include('pages.admin._rules-row', ['property' => 'overdueRepeat', 'label' => __('notifications.admin.remind_every'), 'hint' => __('notifications.admin.remind_every_hint')])
                        @include('pages.admin._rules-row', ['property' => 'escalateManagerAfter', 'label' => __('notifications.admin.manager_after'), 'hint' => __('notifications.admin.manager_after_hint')])
                        @include('pages.admin._rules-row', ['property' => 'escalateTopAfter', 'label' => __('notifications.admin.top_after'), 'hint' => __('notifications.admin.top_after_hint')])
                        @include('pages.admin._rules-row', ['property' => 'escalationCatchUp', 'label' => __('notifications.admin.escalation_window'), 'hint' => __('notifications.admin.escalation_window_hint')])
                    </div>
                </section>
            </div>

            <div class="grid gap-12">
                <section id="rules-stalled" aria-labelledby="rules-stalled-heading" class="rules-group">
                    <h2 id="rules-stalled-heading" class="rules-group-title">{{ __('notifications.admin.sections.stalled') }}</h2>
                    <p class="rules-group-hint">{{ __('notifications.admin.stalled_hint') }}</p>

                    <div class="rules-rows">
                        @include('pages.admin._rules-row', ['property' => 'stalledTaskInProgress', 'label' => __('notifications.admin.stalled_task_in_progress')])
                        @include('pages.admin._rules-row', ['property' => 'stalledTaskUnderReview', 'label' => __('notifications.admin.stalled_task_under_review')])
                        @include('pages.admin._rules-row', ['property' => 'stalledReportDraft', 'label' => __('notifications.admin.stalled_report_draft')])
                        @include('pages.admin._rules-row', ['property' => 'stalledReportReviewed', 'label' => __('notifications.admin.stalled_report_reviewed')])
                        @include('pages.admin._rules-row', ['property' => 'stalledProposal', 'label' => __('notifications.admin.stalled_proposal')])
                    </div>
                </section>

                <section id="rules-digests" aria-labelledby="rules-digests-heading" class="rules-group">
                    <h2 id="rules-digests-heading" class="rules-group-title">{{ __('notifications.admin.sections.digests') }}</h2>

                    <div class="rules-rows">
                        @include('pages.admin._rules-row', ['property' => 'offTrackPace', 'label' => __('notifications.admin.off_track'), 'hint' => __('notifications.admin.off_track_hint'), 'unit' => '%'])
                        @include('pages.admin._rules-row', ['property' => 'dailyBriefTime', 'label' => __('notifications.admin.daily_brief'), 'hint' => __('notifications.admin.daily_brief_hint'), 'type' => 'time'])
                        @include('pages.admin._rules-row', ['property' => 'weeklyDigestTime', 'label' => __('notifications.admin.weekly_digest'), 'liveHint' => 'weeklyDigestHint', 'type' => 'time'])
                    </div>
                </section>
            </div>
        </div>

        @include('pages.admin._rules-events')

        <div
            class="rules-savebar sticky bottom-4 z-30 mt-10 flex flex-wrap items-center justify-between gap-3 rounded-2xl bg-secondary-dark px-5 py-3 text-white shadow-lg dark:bg-surface-3 dark:shadow-raised dark:text-ink"
            x-show="dirtyCount > 0"
            x-cloak
            x-transition:enter="rules-savebar-enter"
            x-transition:enter-start="rules-savebar-from"
            x-transition:leave="rules-savebar-leave"
            x-transition:leave-end="rules-savebar-from"
            role="region"
            aria-label="{{ __('notifications.admin.unsaved_region') }}"
            data-rules-savebar
        >
            <p class="flex items-center gap-3 text-sm font-medium" aria-live="polite">
                <span class="rules-savebar-pulse" aria-hidden="true"></span>
                <span x-text="dirtyLabel"></span>
            </p>
            <div class="flex items-center gap-2">
                <button type="button" x-on:click="discard()" class="min-h-10 rounded-full px-4 text-sm font-medium text-white/80 transition-colors hover:bg-white/10 hover:text-white dark:text-ink-muted dark:hover:bg-white/[0.06] dark:hover:text-ink">
                    {{ __('notifications.admin.discard') }}
                </button>
                <button type="submit" class="inline-flex min-h-10 items-center gap-2 rounded-full bg-primary dark:bg-brand dark:hover:bg-brand-hover px-5 text-sm font-bold text-white transition-transform active:scale-[0.97] disabled:opacity-70" x-bind:disabled="saving">
                    <flux:icon.check variant="micro" x-show="!saving" />
                    <flux:icon.loading variant="micro" x-show="saving" x-cloak />
                    {{ __('notifications.admin.save') }}
                </button>
            </div>
        </div>
    </form>

    @include('pages.admin._default-reviewers')
</section>
