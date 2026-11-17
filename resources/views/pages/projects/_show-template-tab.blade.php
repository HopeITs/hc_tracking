{{-- The "Report template" tab (T33 slice 3): lines grouped by proposal, editable and confirmable by MEAL only; then the narrative questions panel (Y-07). --}}
@php
    $templateItems = $this->reportTemplateItems;
    $canManageTemplate = $this->canManageReportTemplate;
    $selectedCount = count($selectedTemplateLineIds);
@endphp

<div class="flex flex-col gap-4" data-report-template>
    @if ($templateItems !== null)
        <x-ui.panel :title="__('report_templates.tab.title')" :subtitle="__('report_templates.tab.subtitle')" :count="$templateItems['template']['lineCount']">
            <x-slot:toolbar>
                <div class="flex flex-wrap items-center gap-2">
                    <span class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('report_templates.tab.template_status') }}</span>
                    <x-ui.enum-badge :case="$templateItems['template']['status']" data-test="template-status" />
                    @if ($canManageTemplate && $templateItems['template']['confirmableCount'] > 0)
                        <x-ui.button tone="ghost" size="sm" icon="check-circle" wire:click="selectAllPendingTemplateLines" wire:target="selectAllPendingTemplateLines" wire:loading.attr="disabled" data-test="template-select-all">{{ __('report_templates.tab.select_all') }}</x-ui.button>
                        <span class="type-meta tabular-nums text-secondary-medium dark:text-ink-muted" aria-live="polite">{{ trans_choice('report_templates.tab.selected_count', $selectedCount, ['count' => $selectedCount]) }}</span>
                        <x-ui.button tone="primary" size="sm" icon="check" wire:click="askConfirmReportTemplate" wire:target="askConfirmReportTemplate" wire:loading.attr="disabled" :disabled="$selectedCount === 0" data-test="template-confirm-button">{{ __('report_templates.tab.confirm_selected') }}</x-ui.button>
                    @endif
                </div>
            </x-slot:toolbar>
            @if ($templateItems['template']['proposals'] !== [])
                <p class="mb-3 flex flex-wrap items-baseline gap-x-2 gap-y-1 text-sm text-secondary-medium dark:text-ink-muted" data-test="template-proposals">
                    <span class="font-semibold">{{ __('report_templates.tab.proposals') }}</span>
                    @foreach ($templateItems['template']['proposals'] as $proposal)
                        <span dir="auto">{{ $proposal['name'] }}</span>
                    @endforeach
                </p>
            @endif

            @if ($this->awaitingMonthlyReports)
                <p role="status" class="px-4 py-2.5 text-sm text-secondary-medium dark:text-ink-muted" data-monthly-reports-preparing>
                    {{ __('report_templates.tab.preparing_monthly_reports') }}
                </p>
            @endif

            @unless ($canManageTemplate)
                <p class="flex items-start gap-2 border-b border-secondary-soft px-4 py-2.5 text-sm text-secondary-medium dark:border-line dark:text-ink-muted" data-test="template-read-only">
                    <flux:icon name="lock-closed" variant="micro" class="mt-0.5 size-3.5 shrink-0" aria-hidden="true" />
                    <span>{{ __('report_templates.tab.read_only') }}</span>
                </p>
            @endunless

            @if ($templateItems['groups'] === [])
                <x-ui.empty-state icon="clipboard-document-list" :heading="__('report_templates.tab.empty_heading')" :body="__('report_templates.tab.empty_body')" />
            @else
                <div class="flex flex-col gap-5 p-3.5 @6xl/detail:p-5">
                    @foreach ($templateItems['groups'] as $group)
                        <section class="flex flex-col gap-2" wire:key="template-group-{{ $group['key'] }}" aria-labelledby="template-group-{{ $group['key'] }}">
                            <h3 id="template-group-{{ $group['key'] }}" class="flex flex-wrap items-baseline gap-2 text-sm font-bold text-secondary-dark dark:text-ink">
                                <span dir="auto">{{ $group['title'] }}</span>
                                @if ($group['code'] !== null)
                                    <span class="type-meta font-semibold text-secondary-medium dark:text-ink-muted" dir="ltr">{{ $group['code'] }}</span>
                                @endif
                            </h3>

                            @if ($group['lines'] !== [])
                                <x-ui.data-table :caption="$group['title']">
                                    <x-slot:head>
                                        @if ($canManageTemplate)
                                            <th class="w-10!"><span class="sr-only">{{ __('report_templates.tab.col_select') }}</span></th>
                                        @endif
                                        <th>{{ __('report_templates.tab.col_indicator') }}</th>
                                        <th class="text-end!">{{ __('report_templates.tab.col_target') }}</th>
                                        <th>{{ __('report_templates.tab.col_schedule') }}</th>
                                        <th>{{ __('report_templates.tab.col_entered_by') }}</th>
                                        <th>{{ __('report_templates.tab.col_evidence') }}</th>
                                        <th>{{ __('report_templates.tab.col_window') }}</th>
                                        <th>{{ __('report_templates.tab.col_status') }}</th>
                                        @if ($canManageTemplate)
                                            <th class="w-24!"><span class="sr-only">{{ __('report_templates.tab.col_actions') }}</span></th>
                                        @endif
                                    </x-slot:head>

                                    @foreach ($group['lines'] as $line)
                                        <tr wire:key="template-line-{{ $line['id'] }}" data-template-line="{{ $line['id'] }}">
                                            @if ($canManageTemplate)
                                                <x-ui.data-table.cell :label="__('report_templates.tab.col_select')">
                                                    @if ($line['confirmable'])
                                                        <input
                                                            type="checkbox"
                                                            value="{{ $line['id'] }}"
                                                            wire:model.live="selectedTemplateLineIds"
                                                            data-template-select="{{ $line['id'] }}"
                                                            aria-label="{{ __('report_templates.tab.select_line', ['name' => $line['name']]) }}"
                                                            class="size-4 rounded border-secondary-light accent-primary focus-visible:outline-2 focus-visible:outline-primary dark:border-line-strong"
                                                        />
                                                    @endif
                                                </x-ui.data-table.cell>
                                            @endif
                                            <x-ui.data-table.cell :label="__('report_templates.tab.col_indicator')">
                                                <span class="block font-semibold" dir="auto">{{ $line['name'] }}</span>
                                                @if ($line['code'] !== null)
                                                    <span class="type-meta block text-secondary-medium dark:text-ink-muted" dir="ltr">{{ $line['code'] }}</span>
                                                @endif
                                                @if ($line['unlinked'] || $line['needsMonths'] || $line['missingFixedDenominator'])
                                                    <span class="mt-1 flex flex-wrap gap-1">
                                                        @if ($line['unlinked'])
                                                            <span class="inline-flex items-center gap-1 rounded-full bg-status-critical-soft px-2 py-0.5 text-[11px] font-bold text-status-critical" data-template-flag="unlinked-{{ $line['id'] }}">
                                                                <flux:icon name="link-slash" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
                                                                {{ __('report_templates.link_library_first') }}
                                                            </span>
                                                        @endif
                                                        @if ($line['needsMonths'])
                                                            <span class="inline-flex items-center gap-1 rounded-full bg-status-warn-soft px-2 py-0.5 text-[11px] font-bold text-status-warn" data-template-flag="months-{{ $line['id'] }}">
                                                                <flux:icon name="calendar" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
                                                                {{ __('report_templates.choose_measurement_months') }}
                                                            </span>
                                                        @endif
                                                        @if ($line['missingFixedDenominator'])
                                                            <span class="inline-flex items-center gap-1 rounded-full bg-status-warn-soft px-2 py-0.5 text-[11px] font-bold text-status-warn" data-template-flag="denominator-{{ $line['id'] }}">
                                                                <flux:icon name="exclamation-circle" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
                                                                {{ __('report_templates.missing_fixed_denominator') }}
                                                            </span>
                                                        @endif
                                                    </span>
                                                @endif
                                            </x-ui.data-table.cell>
                                            <x-ui.data-table.cell :label="__('report_templates.tab.col_target')" numeric>
                                                <span dir="ltr">{{ $line['target'] ?? '—' }}</span>
                                            </x-ui.data-table.cell>
                                            <x-ui.data-table.cell :label="__('report_templates.tab.col_schedule')">{{ $line['scheduleLabel'] }}</x-ui.data-table.cell>
                                            <x-ui.data-table.cell :label="__('report_templates.tab.col_entered_by')">{{ $line['enteredBy']->label() }}</x-ui.data-table.cell>
                                            <x-ui.data-table.cell :label="__('report_templates.tab.col_evidence')">
                                                @if ($line['evidence'] === [])
                                                    <span class="text-secondary-medium dark:text-ink-muted">{{ __('report_templates.tab.no_evidence') }}</span>
                                                @else
                                                    {{ implode(__('report_templates.tab.list_separator'), $line['evidence']) }}
                                                @endif
                                            </x-ui.data-table.cell>
                                            <x-ui.data-table.cell :label="__('report_templates.tab.col_window')">
                                                <span class="whitespace-nowrap">{{ $line['window'] }}</span>
                                            </x-ui.data-table.cell>
                                            <x-ui.data-table.cell :label="__('report_templates.tab.col_status')">
                                                <x-ui.enum-badge :case="$line['status']" />
                                            </x-ui.data-table.cell>
                                            @if ($canManageTemplate)
                                                <x-ui.data-table.cell>
                                                    <span class="flex items-center justify-end gap-1">
                                                        @if ($line['status'] !== \App\Enums\ReportTemplateLineStatus::Retired)
                                                            <x-ui.button
                                                                tone="ghost"
                                                                size="sm"
                                                                icon="pencil-square"
                                                                wire:click="openTemplateLineModal({{ $line['id'] }})"
                                                                wire:target="openTemplateLineModal({{ $line['id'] }})"
                                                                wire:loading.attr="disabled"
                                                                :aria-label="__('report_templates.tab.edit_line', ['name' => $line['name']])"
                                                                data-test="template-edit-line-{{ $line['id'] }}"
                                                            />
                                                        @endif
                                                        @if ($line['status'] === \App\Enums\ReportTemplateLineStatus::Confirmed)
                                                            <x-ui.button
                                                                tone="ghost"
                                                                size="sm"
                                                                icon="stop-circle"
                                                                wire:click="askRetireTemplateLine({{ $line['id'] }})"
                                                                wire:target="askRetireTemplateLine({{ $line['id'] }})"
                                                                wire:loading.attr="disabled"
                                                                :aria-label="__('report_templates.tab.retire_line', ['name' => $line['name']])"
                                                                data-test="template-retire-line-{{ $line['id'] }}"
                                                            />
                                                        @endif
                                                    </span>
                                                </x-ui.data-table.cell>
                                            @endif
                                        </tr>
                                    @endforeach
                                </x-ui.data-table>
                            @endif

                            @if ($group['unlinkedTargets'] !== [])
                                <div class="rounded-xl border border-dashed border-secondary-light p-3.5 dark:border-line-strong" data-template-unlinked-target>
                                    <p class="text-sm font-semibold text-secondary-dark dark:text-ink">{{ __('report_templates.tab.unlinked_heading') }}</p>
                                    <p class="type-meta text-secondary-medium dark:text-ink-muted">{{ __('report_templates.tab.unlinked_body') }}</p>
                                    <ul class="mt-2 flex flex-col gap-1.5">
                                        @foreach ($group['unlinkedTargets'] as $target)
                                            <li class="flex flex-wrap items-center gap-2 text-sm" wire:key="template-unlinked-{{ $target['id'] }}">
                                                <span dir="auto">{{ $target['name'] }}</span>
                                                @if ($target['code'] !== null)
                                                    <span class="type-meta text-secondary-medium dark:text-ink-muted" dir="ltr">{{ $target['code'] }}</span>
                                                @endif
                                                <span class="inline-flex items-center gap-1 rounded-full bg-status-critical-soft px-2 py-0.5 text-[11px] font-bold text-status-critical">
                                                    <flux:icon name="link-slash" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
                                                    {{ __('report_templates.link_library_first') }}
                                                </span>
                                            </li>
                                        @endforeach
                                    </ul>
                                </div>
                            @endif
                        </section>
                    @endforeach
                </div>
            @endif
        </x-ui.panel>

        @php
            $calendar = $this->templateCalendar;
            $calendarHasActivities = collect($calendar['groups'])->contains(fn (array $group): bool => $group['rows'] !== []);
            $calendarEditUrl = collect($calendar['groups'])->pluck('editUrl')->filter()->first();
            $calendarScope = $this->reportTemplate->city_wide ? 'adjusted_field' : 'adjusted_center';
        @endphp

        <x-ui.panel :title="__('report_templates.calendar.title')" :subtitle="__('report_templates.calendar.subtitle')" data-activity-calendar>
            @if (! $calendarHasActivities)
                <p class="px-4 py-3 text-sm text-secondary-medium dark:text-ink-muted" data-calendar-empty>
                    {{ __('report_templates.calendar.empty') }}
                    @if ($calendarEditUrl !== null)
                        <a href="{{ $calendarEditUrl }}" wire:navigate class="font-semibold text-secondary-dark underline underline-offset-2 dark:text-ink" data-calendar-edit-link>{{ __('report_templates.calendar.edit_link') }}</a>
                    @endif
                </p>
            @else
                <p class="border-b border-secondary-soft px-4 py-2.5 text-sm text-secondary-medium dark:border-line dark:text-ink-muted">
                    {{ __('report_templates.calendar.where_edited') }}
                    @if ($calendarEditUrl !== null && ! $calendar['multiple'])
                        <a href="{{ $calendarEditUrl }}" wire:navigate class="font-semibold text-secondary-dark underline underline-offset-2 dark:text-ink" data-calendar-edit-link>{{ __('report_templates.calendar.edit_link') }}</a>
                    @endif
                </p>

                <div class="overflow-x-auto" tabindex="0" role="region" aria-label="{{ __('report_templates.calendar.title') }}">
                    <table class="w-max min-w-full border-collapse text-start text-sm">
                        <caption class="sr-only">{{ __('report_templates.calendar.title') }}</caption>
                        <thead class="bg-board-head">
                            <tr>
                                <th scope="col" class="type-label sticky start-0 z-10 min-w-56 bg-board-head px-3.5 py-2.5 text-start text-secondary-medium dark:text-ink-faint">{{ __('report_templates.calendar.col_activity') }}</th>
                                @foreach ($calendar['months'] as $month)
                                    <th scope="col" class="type-label px-2 py-2.5 text-center whitespace-nowrap text-secondary-medium dark:text-ink-faint" dir="ltr">{{ $month['label'] }}</th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-board-line">
                            @foreach ($calendar['groups'] as $group)
                                @if ($calendar['multiple'])
                                    <tr data-calendar-group="{{ $group['proposal']->id }}">
                                        <th scope="colgroup" colspan="{{ count($calendar['months']) + 1 }}" class="sticky start-0 bg-secondary-soft px-3.5 py-2 text-start text-sm font-bold text-secondary-dark dark:bg-surface-2 dark:text-ink">
                                            <span dir="auto">{{ $group['proposal']->name }}</span>
                                            @if ($group['editUrl'] !== null)
                                                <a href="{{ $group['editUrl'] }}" wire:navigate class="ms-3 text-xs font-semibold underline underline-offset-2" data-calendar-edit-link>{{ __('report_templates.calendar.edit_link') }}</a>
                                            @endif
                                        </th>
                                    </tr>
                                @endif
                                @foreach ($group['rows'] as $row)
                                    <tr wire:key="calendar-row-{{ $row['id'] }}" data-calendar-row="{{ $row['id'] }}">
                                        <th scope="row" class="sticky start-0 z-10 min-w-56 bg-white px-3.5 py-2 text-start font-semibold text-secondary-dark dark:bg-surface-1 dark:text-ink">
                                            <span dir="auto">{{ $row['statement'] }}</span>
                                            @if ($row['adjusted'])
                                                <span class="mt-1 flex">
                                                    <span class="inline-flex items-center gap-1 rounded-full bg-status-warn-soft px-2 py-0.5 text-[11px] font-bold text-status-warn" data-calendar-adjusted="{{ $row['id'] }}">
                                                        <flux:icon name="adjustments-horizontal" variant="micro" class="size-3 shrink-0" aria-hidden="true" />
                                                        {{ __('report_templates.calendar.'.$calendarScope) }}
                                                    </span>
                                                </span>
                                            @endif
                                        </th>
                                        @foreach ($calendar['months'] as $month)
                                            @php
                                                $cell = $row['cells'][$month['key']] ?? null;
                                            @endphp
                                            <td class="px-2 py-2 text-center">
                                                @if ($cell !== null)
                                                    <span class="inline-flex min-w-8 flex-col items-center gap-0.5 rounded-md bg-secondary-soft px-1.5 py-1 text-secondary-dark dark:bg-surface-2 dark:text-ink" data-calendar-cell="{{ $row['id'] }}-{{ $month['key'] }}">
                                                        <flux:icon name="check" variant="micro" class="size-3.5" aria-hidden="true" />
                                                        @if ($cell['weeks'] !== null)
                                                            <span class="type-meta tabular-nums" dir="ltr" aria-hidden="true">{{ \App\Support\ReportTemplateReader::weeksText($cell['weeks']) }}</span>
                                                        @endif
                                                        <span class="sr-only">{{ $cell['label'] }}</span>
                                                    </span>
                                                @endif
                                            </td>
                                        @endforeach
                                    </tr>
                                @endforeach
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </x-ui.panel>

        @include('pages.projects._show-template-questions')
    @endif
</div>
