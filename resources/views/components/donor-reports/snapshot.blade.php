@props([
    'snapshot',
    'showFinance' => false,
    'print' => false,
])

{{--
    One donor report as the aggregator built it (contracts §14.1, §14.3):
    the live preview on the builder, a saved snapshot, and the print view
    all render through here, from the array alone. Figures are validated
    only. The finance block renders only when the snapshot carries it AND
    the viewer holds funding-agreements.view ($showFinance, decided on the
    server).
--}}
@php
    $muted = 'text-secondary-medium dark:text-ink-muted';
    $ink = 'text-secondary-dark dark:text-ink';
    $hairline = 'border-secondary-soft dark:border-line';
    $panel = 'rounded-xl border border-secondary-soft bg-white p-4 sm:p-5 dark:border-transparent dark:bg-surface-1 dark:shadow-raised';
    $th = 'px-3 py-2 text-start text-xs font-semibold uppercase tracking-wide '.$muted;
    $td = 'px-3 py-2 align-top';
    $number = function (?float $value): string {
        if ($value === null) {
            return '—';
        }

        return number_format($value, fmod($value, 1.0) === 0.0 ? 0 : 1);
    };
    $percent = fn (?float $value): string => $value === null ? '—' : number_format($value, 1).'%';
    $centers = collect($snapshot['centers'] ?? [])->keyBy('project_id');
    $centerName = function (int $projectId) use ($centers): string {
        $center = $centers->get($projectId);

        if ($center === null) {
            return '#'.$projectId;
        }

        $hrCenter = $center['center_id'] === null ? null : \App\Models\HrCenter::remembered((int) $center['center_id']);

        return $hrCenter === null ? (string) $center['name'] : $hrCenter->name.' · '.$center['name'];
    };
    $figure = function (array $figure, string $measure) use ($number, $percent): string {
        if ($figure['numerator'] === null && $figure['denominator'] === null) {
            return $number($figure['value']);
        }

        return $measure === 'percentage' ? $percent($figure['value']) : $number($figure['value']);
    };
    $completeness = $snapshot['completeness'] ?? ['missing' => [], 'missing_denominator' => [], 'has_validated_data' => false];
    $header = $snapshot['header'] ?? [];
    $period = $snapshot['period'] ?? [];
    $indicators = $snapshot['indicators'] ?? [];
    $historical = $snapshot['historical'] ?? [];
    $disaggregated = collect([...$indicators, ...$historical])->filter(fn (array $row): bool => $row['disaggregation'] !== null);
    $finance = $showFinance && array_key_exists('finance', $snapshot) ? $snapshot['finance'] : null;
@endphp

<div {{ $attributes->class('grid min-w-0 grid-cols-1 gap-6') }} data-donor-report-snapshot>
    {{-- Completeness first: what the figures below leave out. --}}
    @if (! $completeness['has_validated_data'])
        <div class="rounded-xl border border-status-warn/40 bg-status-warn-soft p-4 text-sm {{ $ink }}" role="status" data-no-validated-data>
            <p class="flex items-center gap-2 font-semibold">
                <flux:icon.information-circle variant="mini" class="size-5 shrink-0" />
                {{ __('donor_reports.no_validated_data') }}
            </p>
        </div>
    @endif

    @if ($completeness['missing'] !== [] || $completeness['missing_denominator'] !== [])
        <div class="rounded-xl border border-status-critical/40 bg-status-critical-soft p-4 text-sm {{ $ink }}" role="alert" data-completeness-warning data-tour="donor-report-completeness">
            <p class="flex items-center gap-2 font-semibold text-status-critical">
                <flux:icon.exclamation-triangle variant="mini" class="size-5 shrink-0" />
                {{ __('donor_reports.incomplete_title') }}
            </p>
            <p class="mt-1 {{ $muted }}">{{ __('donor_reports.incomplete_help') }}</p>
            @if ($completeness['missing'] !== [])
                <ul class="mt-3 grid gap-1 sm:grid-cols-2">
                    @foreach ($completeness['missing'] as $gap)
                        <li class="flex flex-wrap items-baseline gap-x-2" data-missing-month="{{ $gap['project_id'] }}-{{ $gap['month'] }}">
                            <span class="font-medium" dir="auto">{{ $centerName((int) $gap['project_id']) }}</span>
                            <span dir="ltr" class="tabular-nums {{ $muted }}">{{ $gap['month'] }}</span>
                            <span class="text-xs font-semibold">{{ __('donor_reports.states.'.$gap['state']) }}</span>
                        </li>
                    @endforeach
                </ul>
            @endif
            @if ($completeness['missing_denominator'] !== [])
                <p class="mt-2 text-xs" data-missing-denominator>{{ trans_choice('donor_reports.missing_denominator', count($completeness['missing_denominator']), ['count' => count($completeness['missing_denominator'])]) }}</p>
            @endif
        </div>
    @endif

    {{-- Header from system data. --}}
    <section class="{{ $panel }}" aria-labelledby="snapshot-header" data-snapshot-header>
        <h2 id="snapshot-header" class="sr-only">{{ __('donor_reports.report_details') }}</h2>
        <dl class="grid gap-x-6 gap-y-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
            <div class="min-w-0">
                <dt class="text-xs {{ $muted }}">{{ __('donor_reports.proposal') }}</dt>
                <dd class="font-semibold {{ $ink }}" dir="auto">{{ $header['proposal_name'] ?? '—' }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs {{ $muted }}">{{ __('donor_reports.donor') }}</dt>
                <dd class="font-semibold {{ $ink }}" dir="auto">{{ $header['donor_name'] ?? '—' }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs {{ $muted }}">{{ __('donor_reports.period') }}</dt>
                <dd class="font-semibold tabular-nums {{ $ink }}" dir="ltr">{{ \Illuminate\Support\Str::substr($period['start'] ?? '', 0, 7) }} – {{ \Illuminate\Support\Str::substr($period['end'] ?? '', 0, 7) }}</dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs {{ $muted }}">{{ __('donor_reports.internal_number') }}</dt>
                <dd class="{{ $ink }}"><x-ui.project-number :number="$header['internal_number'] ?? null" /></dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs {{ $muted }}">{{ __('donor_reports.contract_number') }}</dt>
                <dd class="{{ $ink }}"><x-ui.project-number :number="$header['contract_number'] ?? null" /></dd>
            </div>
            <div class="min-w-0">
                <dt class="text-xs {{ $muted }}">{{ __('donor_reports.implementer') }}</dt>
                <dd class="{{ $ink }}" dir="auto">{{ $header['implementer'] ?? '—' }}</dd>
            </div>
            @if (filled($header['goal'] ?? null))
                <div class="min-w-0 sm:col-span-2 lg:col-span-3">
                    <dt class="text-xs {{ $muted }}">{{ __('donor_reports.goal') }}</dt>
                    <dd class="{{ $ink }}" dir="auto">{{ $header['goal'] }}</dd>
                </div>
            @endif
            @if ($centers->isNotEmpty())
                <div class="min-w-0 sm:col-span-2 lg:col-span-3">
                    <dt class="text-xs {{ $muted }}">{{ __('donor_reports.centers') }}</dt>
                    <dd class="flex flex-wrap gap-2 {{ $ink }}">
                        @foreach ($centers as $center)
                            <span class="rounded-full border px-2.5 py-0.5 text-xs {{ $hairline }}" dir="auto">
                                {{ $centerName((int) $center['project_id']) }}
                                @if ($center['beneficiaries'] !== null)
                                    <span class="tabular-nums {{ $muted }}">· {{ trans_choice('donor_reports.beneficiaries_count', $center['beneficiaries'], ['count' => number_format($center['beneficiaries'])]) }}</span>
                                @endif
                            </span>
                        @endforeach
                    </dd>
                </div>
            @endif
        </dl>
    </section>

    {{-- Indicators, each with its centers underneath. --}}
    @foreach (['indicators' => $indicators, 'historical' => $historical] as $section => $rows)
        @continue($section === 'historical' && $rows === [])
        <section class="{{ $panel }} break-inside-avoid-page" aria-labelledby="snapshot-{{ $section }}" data-snapshot-{{ $section }} @if ($section === 'indicators') data-tour="donor-report-indicators" @endif>
            <h2 id="snapshot-{{ $section }}" class="text-base font-bold {{ $ink }}">{{ __('donor_reports.sections.'.$section) }}</h2>
            @if ($section === 'historical')
                <p class="mt-1 text-xs {{ $muted }}">{{ __('donor_reports.historical_help') }}</p>
            @endif

            @if ($rows === [])
                <p class="mt-3 text-sm {{ $muted }}">{{ __('donor_reports.no_indicators') }}</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[34rem] sm:min-w-[44rem] text-sm {{ $ink }}">
                        <thead class="border-b {{ $hairline }}">
                            <tr>
                                <th scope="col" class="{{ $th }} sticky start-0 z-10 max-sm:w-44 max-sm:max-w-44 bg-white dark:bg-surface-1">{{ __('donor_reports.indicator') }}</th>
                                <th scope="col" class="{{ $th }} text-end!">{{ __('donor_reports.target') }}</th>
                                <th scope="col" class="{{ $th }} text-end!">{{ __('donor_reports.this_period') }}</th>
                                <th scope="col" class="{{ $th }} text-end!">{{ __('donor_reports.to_date') }}</th>
                                <th scope="col" class="{{ $th }} text-end!">{{ __('donor_reports.achievement') }}</th>
                            </tr>
                        </thead>
                        @foreach ($rows as $row)
                            <tbody class="border-b last:border-b-0 {{ $hairline }}" @unless ($print) x-data="{ open: false }" @endunless data-indicator-row="{{ $row['indicator_id'] }}">
                                <tr>
                                    <th scope="row" class="{{ $td }} sticky start-0 z-10 max-sm:w-44 max-sm:max-w-44 bg-white text-start font-normal dark:bg-surface-1">
                                        <div class="flex items-start gap-2">
                                            @unless ($print)
                                                <button type="button" class="mt-0.5 rounded {{ $muted }} focus-visible:outline-2" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-label="{{ __('donor_reports.toggle_centers', ['name' => $row['name']]) }}">
                                                    <flux:icon.chevron-right variant="micro" class="size-4 transition rtl:-scale-x-100" x-bind:class="open && 'rotate-90 rtl:-rotate-90'" />
                                                </button>
                                            @endunless
                                            <div class="min-w-0">
                                                <span class="block text-xs tabular-nums {{ $muted }}" dir="ltr">{{ $row['code'] }}</span>
                                                <span class="font-medium" dir="auto">{{ $row['name'] }}</span>
                                                @if ($row['shared'])
                                                    <sup class="text-xs font-semibold" title="{{ __('donor_reports.shared_footnote') }}" data-shared-marker>*</sup>
                                                @endif
                                                @if ($row['is_tracking_only'])
                                                    <span class="ms-1 text-xs {{ $muted }}">{{ __('donor_reports.tracking_only') }}</span>
                                                @endif
                                                @if ($row['is_cumulative'])
                                                    <span class="ms-1 text-xs {{ $muted }}">{{ __('donor_reports.cumulative') }}</span>
                                                @endif
                                                @if ($row['excluded'] > 0)
                                                    <span class="block text-xs {{ $muted }}">{{ trans_choice('donor_reports.excluded_lines', $row['excluded'], ['count' => $row['excluded']]) }}</span>
                                                @endif
                                            </div>
                                        </div>
                                    </th>
                                    <td class="{{ $td }} text-end tabular-nums" dir="ltr">{{ $row['measure'] === 'percentage' ? $percent($row['target']) : $number($row['target']) }}</td>
                                    <td class="{{ $td }} text-end tabular-nums" dir="ltr" data-period-value>
                                        {{ $figure($row['period'], $row['measure']) }}
                                        @if ($row['period']['denominator'] !== null)
                                            <span class="block text-xs {{ $muted }}">{{ $number($row['period']['numerator']) }} / {{ $number($row['period']['denominator']) }}</span>
                                        @endif
                                    </td>
                                    <td class="{{ $td }} text-end tabular-nums" dir="ltr" data-cumulative-value>
                                        {{ $figure($row['cumulative'], $row['measure']) }}
                                        @if ($row['cumulative']['denominator'] !== null)
                                            <span class="block text-xs {{ $muted }}">{{ $number($row['cumulative']['numerator']) }} / {{ $number($row['cumulative']['denominator']) }}</span>
                                        @endif
                                    </td>
                                    <td class="{{ $td }} text-end font-semibold tabular-nums" dir="ltr">{{ $row['is_tracking_only'] ? '—' : $percent($row['achievement']) }}</td>
                                </tr>
                                @foreach ($row['centers'] as $center)
                                    <tr class="bg-secondary-soft/30 text-xs dark:bg-surface-2/50" @unless ($print) x-show="open" x-cloak @endunless data-center-row="{{ $center['project_id'] }}">
                                        <th scope="row" class="{{ $td }} sticky start-0 z-10 max-sm:w-44 max-sm:max-w-44 bg-secondary-soft/30 ps-9 text-start font-normal dark:bg-surface-2/50" dir="auto">
                                            {{ $centerName((int) $center['project_id']) }}
                                            @if ($center['beneficiaries'] !== null && $row['basis'] === 'fixed')
                                                <span class="{{ $muted }}">· {{ trans_choice('donor_reports.beneficiaries_count', $center['beneficiaries'], ['count' => number_format($center['beneficiaries'])]) }}</span>
                                            @endif
                                        </th>
                                        <td class="{{ $td }} text-end tabular-nums" dir="ltr">{{ $row['measure'] === 'percentage' ? $percent($center['target']) : $number($center['target']) }}</td>
                                        <td class="{{ $td }} text-end tabular-nums" dir="ltr">{{ $figure($center['period'], $row['measure']) }}</td>
                                        <td class="{{ $td }} text-end tabular-nums" dir="ltr">{{ $figure($center['cumulative'], $row['measure']) }}</td>
                                        <td class="{{ $td }} text-end tabular-nums" dir="ltr">{{ $row['is_tracking_only'] ? '—' : $percent($center['achievement']) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        @endforeach
                    </table>
                </div>
                @if (collect($rows)->contains('shared', true))
                    <p class="mt-2 text-xs {{ $muted }}" data-shared-footnote>* {{ __('donor_reports.shared_footnote') }}</p>
                @endif
            @endif
        </section>
    @endforeach

    {{-- Disaggregation (D-50). --}}
    @if ($disaggregated->isNotEmpty())
        <section class="{{ $panel }} break-inside-avoid-page" aria-labelledby="snapshot-disaggregation" data-snapshot-disaggregation>
            <h2 id="snapshot-disaggregation" class="text-base font-bold {{ $ink }}">{{ __('donor_reports.sections.disaggregation') }}</h2>
            <div class="mt-3 overflow-x-auto">
                <table class="w-full min-w-[34rem] sm:min-w-[40rem] text-sm {{ $ink }}">
                    <thead class="border-b {{ $hairline }}">
                        <tr>
                            <th scope="col" class="{{ $th }} sticky start-0 z-10 max-sm:w-44 max-sm:max-w-44 bg-white dark:bg-surface-1">{{ __('donor_reports.indicator') }}</th>
                            @foreach (['male', 'male_disabled', 'female', 'female_disabled', 'total'] as $category)
                                <th scope="col" class="{{ $th }} text-end!">{{ __('donor_reports.categories.'.$category) }}</th>
                            @endforeach
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($disaggregated as $row)
                            @foreach ([['label' => $row['name'], 'figures' => $row['disaggregation']['total'], 'strong' => true], ...array_map(fn (array $center): array => ['label' => $centerName((int) $center['project_id']), 'figures' => $center, 'strong' => false], $row['disaggregation']['centers'])] as $line)
                                <tr class="border-b last:border-b-0 {{ $hairline }} {{ $line['strong'] ? '' : 'text-xs' }}">
                                    <th scope="row" class="{{ $td }} sticky start-0 z-10 max-sm:w-44 max-sm:max-w-44 bg-white text-start dark:bg-surface-1 {{ $line['strong'] ? 'font-medium' : 'ps-6 font-normal' }}" dir="auto">{{ $line['label'] }}</th>
                                    @foreach (['male', 'male_disabled', 'female', 'female_disabled', 'total'] as $category)
                                        @php
                                            $cell = $line['figures'][$category] ?? null;
                                        @endphp
                                        <td class="{{ $td }} text-end tabular-nums" dir="ltr">
                                            @if (is_array($cell) && array_key_exists('denominator', $cell))
                                                {{ $figure(['value' => $cell['value'] ?? null, 'numerator' => $cell['numerator'] ?? null, 'denominator' => $cell['denominator'] ?? null], $row['measure']) }}
                                            @elseif (is_array($cell))
                                                {{ $number($cell['value'] ?? null) }}
                                            @else
                                                {{ $number($cell === null ? null : (float) $cell) }}
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @endforeach
                        @endforeach
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- Activities at period end (D-51). --}}
    @if (($snapshot['activities'] ?? []) !== [])
        <section class="{{ $panel }} break-inside-avoid-page" aria-labelledby="snapshot-activities" data-snapshot-activities>
            <h2 id="snapshot-activities" class="text-base font-bold {{ $ink }}">{{ __('donor_reports.sections.activities') }}</h2>
            <ul class="mt-3 divide-y {{ $hairline }} text-sm {{ $ink }}">
                @foreach ($snapshot['activities'] as $activity)
                    <li class="py-2.5" data-activity="{{ $activity['result_id'] }}">
                        <p class="font-medium" dir="auto">{{ $activity['statement'] }}</p>
                        <ul class="mt-1 grid gap-1">
                            @foreach ($activity['centers'] as $center)
                                <li class="flex flex-wrap items-baseline gap-x-2 text-xs">
                                    <span dir="auto">{{ $centerName((int) $center['project_id']) }}</span>
                                    <span class="font-semibold">{{ $center['status'] === null ? __('donor_reports.no_status') : \App\Enums\ActivityStatus::from($center['status'])->label() }}</span>
                                    @if ($center['execution_months'] !== [])
                                        <span class="tabular-nums {{ $muted }}" dir="ltr">{{ implode(', ', $center['execution_months']) }}</span>
                                    @endif
                                </li>
                            @endforeach
                        </ul>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Narratives per center per month. --}}
    @if (($snapshot['narratives'] ?? []) !== [])
        <section class="{{ $panel }}" aria-labelledby="snapshot-narratives" data-snapshot-narratives>
            <h2 id="snapshot-narratives" class="text-base font-bold {{ $ink }}">{{ __('donor_reports.sections.narratives') }}</h2>
            @foreach (collect($snapshot['narratives'])->groupBy(fn (array $narrative): string => $narrative['project_id'].'|'.$narrative['month']) as $group)
                <div class="mt-4 break-inside-avoid-page">
                    <h3 class="text-sm font-semibold {{ $ink }}">
                        <span dir="auto">{{ $centerName((int) $group->first()['project_id']) }}</span>
                        <span class="ms-1 tabular-nums {{ $muted }}" dir="ltr">{{ $group->first()['month'] }}</span>
                    </h3>
                    <dl class="mt-2 grid gap-2 text-sm">
                        @foreach ($group as $narrative)
                            <div>
                                <dt class="text-xs {{ $muted }}" dir="auto">{{ $narrative['question'] }}</dt>
                                <dd class="whitespace-pre-line {{ $ink }}" dir="auto">{{ filled($narrative['answer']) ? $narrative['answer'] : '—' }}</dd>
                            </div>
                        @endforeach
                    </dl>
                </div>
            @endforeach
        </section>
    @endif

    {{-- Evidence: links to the monthly report files, never copies. --}}
    @if (($snapshot['files'] ?? []) !== [])
        <section class="{{ $panel }}" aria-labelledby="snapshot-files" data-snapshot-files>
            <h2 id="snapshot-files" class="text-base font-bold {{ $ink }}">{{ __('donor_reports.sections.files') }}</h2>
            @php
                $canLinkFiles = ! $print && \Illuminate\Support\Facades\Route::has('monthly-reports.files.download');
            @endphp
            <ul class="mt-3 grid gap-1.5 text-sm {{ $ink }}">
                @foreach ($snapshot['files'] as $file)
                    <li class="flex flex-wrap items-baseline gap-x-2" data-evidence-file="{{ $file['id'] }}">
                        @if ($canLinkFiles)
                            <a class="font-medium underline underline-offset-2" href="{{ route('monthly-reports.files.download', [tap(new \App\Models\MonthlyReport, fn ($report) => $report->id = (int) $file['monthly_report_id']), $file['id']]) }}" dir="auto">{{ $file['name'] }}</a>
                        @else
                            <span class="font-medium" dir="auto">{{ $file['name'] }}</span>
                        @endif
                        <span class="text-xs {{ $muted }}" dir="auto">{{ (app()->getLocale() === 'ar' ? $file['evidence_type_ar'] : ($file['evidence_type_en'] ?? $file['evidence_type_ar'])) }}</span>
                        <span class="text-xs {{ $muted }}" dir="auto">{{ $centerName((int) $file['project_id']) }} · <span dir="ltr" class="tabular-nums">{{ $file['month'] }}</span></span>
                    </li>
                @endforeach
            </ul>
        </section>
    @endif

    {{-- Finance: money holders only, visually apart. --}}
    @if ($finance !== null)
        <section class="rounded-xl border-2 border-dashed border-secondary-soft p-4 sm:p-5 dark:border-line break-inside-avoid-page" aria-labelledby="snapshot-finance" data-snapshot-finance data-tour="donor-report-finance">
            <h2 id="snapshot-finance" class="flex items-center gap-2 text-base font-bold {{ $ink }}">
                <flux:icon.banknotes variant="mini" class="size-5" />
                {{ __('donor_reports.sections.finance') }}
            </h2>
            @if ($finance === [])
                <p class="mt-2 text-sm {{ $muted }}">{{ __('donor_reports.no_transfers_in_period') }}</p>
            @else
                <div class="mt-3 overflow-x-auto">
                    <table class="w-full min-w-[32rem] text-sm {{ $ink }}">
                        <thead class="border-b {{ $hairline }}">
                            <tr>
                                <th scope="col" class="{{ $th }}">{{ __('donor_reports.currency') }}</th>
                                <th scope="col" class="{{ $th }} text-end!">{{ __('donor_reports.received') }}</th>
                                <th scope="col" class="{{ $th }} text-end!">{{ __('donor_reports.fees') }}</th>
                                <th scope="col" class="{{ $th }} text-end!">{{ __('donor_reports.net') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($finance as $line)
                                <tr class="border-b last:border-b-0 {{ $hairline }}" data-finance-currency="{{ $line['currency'] }}">
                                    <th scope="row" class="{{ $td }} text-start font-semibold" dir="ltr">{{ $line['currency'] }}</th>
                                    <td class="{{ $td }} text-end tabular-nums" dir="ltr">{{ number_format((float) $line['received'], 2) }}</td>
                                    <td class="{{ $td }} text-end tabular-nums" dir="ltr">
                                        @forelse ($line['fees'] as $fee)
                                            <span class="block">{{ number_format((float) $fee['amount'], 2) }} {{ $fee['currency'] }}</span>
                                        @empty
                                            —
                                        @endforelse
                                    </td>
                                    <td class="{{ $td }} text-end tabular-nums" dir="ltr">
                                        {{ number_format((float) $line['net'], 2) }}
                                        @if ($line['net_missing'] > 0)
                                            <span class="block text-xs text-status-critical" dir="auto">{{ trans_choice('donor_reports.net_missing', $line['net_missing'], ['count' => $line['net_missing']]) }}</span>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </section>
    @endif
</div>
