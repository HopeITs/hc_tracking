{{--
    The program page's Overview tab, built to be read top-down in one glance:

    1. "Needs your attention": the first few items, the rest one click away.
    2. The projects as a card grid in two folding groups, "Needs attention"
       (open) and "On track" (folded once anything needs attention). Each card
       shows pace and three key facts; targets and money fold under "Details".
    3. Program settings, folded.

    Folding is client-side (Alpine, remembered per browser with $persist) and
    the name search runs in the browser, so neither costs a round trip.
    Report, target and money facts render only when the trait read them
    (TracksProgramHealth skips each query without its permission).
--}}
@php
    use App\Enums\ProjectStatus;
    use App\Support\ProjectPace;

    $ledger = $this->projectLedger;
    $attention = $this->attention;
    $showReports = auth()->user()?->can('reports.view') === true;
    $reportMonth = \Carbon\CarbonImmutable::today()->subMonthNoOverflow()->translatedFormat('M');
    $attentionPreview = 3;

    $paceLabel = fn (string $pace, \App\Models\ProposalLocation $project): string => match ($pace) {
        'closed' => $project->status->label(),
        'past_end' => __('programs.pace_past_end'),
        'not_started' => __('programs.pace_not_started'),
        'unmeasured' => __('programs.pace_unmeasured'),
        'complete' => __('programs.pace_complete'),
        'no_window' => __('programs.pace_no_window'),
        'on_track' => __('programs.pace_on_track'),
        'slipping' => __('programs.pace_slipping'),
        default => __('programs.pace_behind'),
    };
    $paceTone = fn (string $pace): string => match (true) {
        in_array($pace, ProjectPace::CRITICAL, true) => 'dk-bad',
        in_array($pace, ProjectPace::WARNING, true) => 'dk-warn',
        in_array($pace, ['on_track', 'complete'], true) => 'dk-good',
        default => 'dk-mute',
    };
    $ledgerFilter = fn (array $row): string => match (true) {
        $row['needsAttention'] => 'behind',
        $row['pace'] === 'closed' => 'closed',
        default => 'on_track',
    };
    $ledgerRows = collect($ledger)
        ->sortBy(fn (array $row): int => ['behind' => 0, 'on_track' => 1, 'closed' => 2][$ledgerFilter($row)])
        ->values()
        ->all();
    $filterCounts = collect($ledgerRows)->countBy(fn (array $row): string => $ledgerFilter($row));
    $filters = array_values(array_filter([
        ['key' => 'all', 'label' => __('programs.filter_all'), 'count' => count($ledgerRows)],
        ['key' => 'behind', 'label' => __('programs.filter_behind'), 'count' => $filterCounts->get('behind', 0)],
        ['key' => 'on_track', 'label' => __('programs.filter_on_track'), 'count' => $filterCounts->get('on_track', 0)],
        $filterCounts->get('closed', 0) > 0 ? ['key' => 'closed', 'label' => __('programs.filter_closed'), 'count' => $filterCounts->get('closed')] : null,
    ]));
    $ledgerItems = array_map(fn (array $row): array => [
        'id' => $row['project']->id,
        'filter' => $ledgerFilter($row),
        'name' => mb_strtolower($row['project']->headline()),
    ], $ledgerRows);
@endphp

<div class="dk-prog-ov" x-data="{ query: '' }">
    {{-- 1. Needs your attention --}}
    <section class="dk-prog-fold" x-data="{ open: $persist(true).as('program-attention-open'), shown: {{ $attentionPreview }}, total: {{ count($attention) }}, step: {{ $attentionPreview }} }" :class="{ 'dk-prog-fold-on': open }" data-test="program-attention">
        <button type="button" class="dk-prog-fold-h" @click="open = ! open" :aria-expanded="open.toString()" aria-controls="program-attention-body">
            <h2 id="program-attention-heading">{{ __('programs.attention_heading') }}</h2>
            @if ($attention !== [])
                <span @class(['dk-prog-count', 'dk-bad' => in_array('critical', array_column($attention, 'tone'), true)])>{{ count($attention) }}</span>
            @endif
            <x-desk.icon name="chev" class="dk-prog-fold-i" />
        </button>

        <div id="program-attention-body" class="dk-prog-fold-b" x-show="open" x-collapse>
            @if ($attention === [])
                <p class="dk-prog-clear"><x-desk.icon name="check" />{{ $ledger === [] ? __('programs.attention_no_projects') : __('programs.attention_none') }}</p>
            @else
                <ul class="dk-prog-attn">
                    @foreach ($attention as $item)
                        <li wire:key="program-attention-{{ $loop->index }}" @class(['dk-prog-attn-i', 'dk-bad' => $item['tone'] === 'critical', 'dk-warn' => $item['tone'] !== 'critical']) data-attention="{{ $item['key'] }}"
                            @if ($loop->index >= $attentionPreview) x-show="shown > {{ $loop->index }}" x-cloak @endif>
                            <span class="dk-prog-attn-t">
                                {{ $item['text'] }}
                                @if ($item['mine'])
                                    <span class="dk-prog-mine">{{ __('programs.attention_yours') }}</span>
                                @endif
                            </span>
                            <a href="{{ $item['href'] }}" wire:navigate class="dk-attn-a">{{ $item['label'] }}<x-desk.icon name="arrow" class="dk-flip" /></a>
                        </li>
                    @endforeach
                </ul>
                @if (count($attention) > $attentionPreview)
                    <div class="dk-prog-more-row">
                        <button type="button" class="dk-prog-more" x-show="shown < total" @click="shown = Math.min(total, shown + step)" data-test="program-attention-more">
                            <span x-text="@js(__('programs.attention_load_more')).replace(':count', Math.min(step, total - shown))">{{ __('programs.attention_load_more', ['count' => min($attentionPreview, count($attention) - $attentionPreview)]) }}</span>
                        </button>
                        <button type="button" class="dk-prog-more" x-show="shown > step" x-cloak @click="shown = step">{{ __('programs.attention_show_less') }}</button>
                    </div>
                @endif
            @endif
        </div>
    </section>

    {{-- 2. The projects: one grid, filter chips, one row at a time; search covers every project. --}}
    <section class="dk-prog-projects" aria-labelledby="program-ledger-heading" data-test="program-ledger"
        x-data="{
            filter: 'all',
            rows: 1,
            cols: 1,
            items: @js($ledgerItems),
            get searching() { return query.trim() !== '' },
            get matches() {
                const needle = query.trim().toLowerCase();
                return this.items.filter((item) => this.searching ? item.name.includes(needle) : (this.filter === 'all' || item.filter === this.filter));
            },
            get limit() { return this.searching ? Infinity : this.rows * this.cols },
            get remaining() { return Math.max(0, this.matches.length - this.limit) },
            shows(id) { const index = this.matches.findIndex((item) => item.id === id); return index > -1 && index < this.limit },
            measure() { if (this.$refs.grid) { this.cols = Math.max(1, getComputedStyle(this.$refs.grid).gridTemplateColumns.split(' ').filter(Boolean).length) } },
            pick(key) { this.filter = key; this.rows = 1 },
        }"
        x-init="$nextTick(() => measure())" @resize.window.debounce.150ms="measure()">
        <div class="dk-prog-projects-h">
            <h2 id="program-ledger-heading">{{ __('programs.ledger_heading') }} <span class="dk-num">{{ count($ledger) }}</span></h2>
            @if (count($ledger) > 3)
                <label class="dk-prog-search">
                    <span class="sr-only">{{ __('programs.find_a_project') }}</span>
                    <x-desk.icon name="search" />
                    <input type="search" x-model="query" placeholder="{{ __('programs.find_a_project') }}" data-test="program-ledger-search" />
                </label>
            @endif
        </div>

        @if ($ledger === [])
            <p class="dk-prog-clear">{{ __('common.no_projects_yet') }}</p>
        @else
            <div class="dk-prog-chips" role="group" aria-label="{{ __('programs.filter_projects') }}" :class="{ 'dk-prog-chips-off': searching }">
                @foreach ($filters as $chip)
                    <button type="button" wire:key="program-filter-{{ $chip['key'] }}" @click="pick('{{ $chip['key'] }}')" :aria-pressed="(filter === '{{ $chip['key'] }}').toString()" aria-pressed="{{ $chip['key'] === 'all' ? 'true' : 'false' }}" data-ledger-filter="{{ $chip['key'] }}" @disabled($chip['count'] === 0 && $chip['key'] !== 'all')>
                        @if ($chip['key'] !== 'all')
                            <span @class(['dk-prog-group-dot', 'dk-warn' => $chip['key'] === 'behind', 'dk-good' => $chip['key'] === 'on_track'])></span>
                        @endif
                        <span>{{ $chip['label'] }}</span>
                        <span class="dk-num">{{ $chip['count'] }}</span>
                    </button>
                @endforeach
            </div>

            <ul class="dk-prog-cards" x-ref="grid">
                @foreach ($ledgerRows as $row)
                        @php
                            $project = $row['project'];
                            $isActive = $project->status === ProjectStatus::Active;
                        @endphp
                        <li class="dk-prog-card" wire:key="program-card-{{ $project->id }}" data-ledger-row data-ledger-filter-key="{{ $ledgerFilter($row) }}"
                            x-show="shows({{ $project->id }})" @if ($loop->index >= 1) x-cloak @endif>

                            {{-- 1. Name and city: the name always takes two lines so every card lines up. --}}
                            <div class="dk-prog-card-h">
                                <h3 class="dk-prog-card-t">
                                    <a href="{{ route('projects.show', $project) }}" wire:navigate class="dk-prog-card-link" title="{{ $project->headline() }} · {{ $project->label() }}">{{ $project->headline() }}</a>
                                </h3>
                                <p class="dk-prog-card-o" data-ledger-city><x-desk.icon name="pin" />{{ $project->city?->name ?? '—' }}</p>
                            </div>

                            {{-- 2. Pace and progress: the tick on the bar marks where it should be by today. --}}
                            <div class="dk-prog-card-pace">
                                <div class="dk-prog-card-row">
                                    @if ($row['pace'] === 'closed')
                                        <x-ui.enum-badge :case="$project->status" />
                                    @else
                                        <span class="dk-prog-pill {{ $paceTone($row['pace']) }}">{{ $paceLabel($row['pace'], $project) }}</span>
                                    @endif
                                    <span class="dk-prog-card-pct dk-num">{{ $row['progress'] === null ? '—' : $row['progress'].'%' }}</span>
                                </div>
                                <span class="dk-prog-bar" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-label="{{ __('programs.delivery_progress') }}" @if ($row['progress'] !== null) aria-valuenow="{{ $row['progress'] }}" @endif
                                    @if ($row['elapsed'] !== null && $isActive) title="{{ __('programs.pace_expected', ['percent' => (int) round($row['elapsed'])]) }}" @endif>
                                    <i style="width: {{ $row['progress'] ?? 0 }}%"></i>
                                    @if ($row['elapsed'] !== null && $isActive)
                                        <b style="inset-inline-start: {{ round($row['elapsed'], 1) }}%"></b>
                                    @endif
                                </span>
                            </div>

                            {{-- 3. The three facts that most often need a hand, always in the same cells. --}}
                            <dl @class(['dk-prog-cells', 'dk-prog-cells-2' => $showReports, 'dk-prog-cells-1' => ! $showReports])>
                                <div @class(['dk-bad' => $row['pastDueTasks'] > 0])>
                                    <dt><x-desk.icon name="check" />{{ __('common.tasks') }}</dt>
                                    <dd>{{ $row['pastDueTasks'] > 0 ? trans_choice('programs.ledger_past_due', $row['pastDueTasks'], ['count' => $row['pastDueTasks']]) : trans_choice('programs.ledger_open_tasks', $row['openTasks'], ['count' => $row['openTasks']]) }}</dd>
                                </div>
                                @if ($showReports)
                                    <div @class(['dk-bad' => $row['reportExpected'] && $row['report'] === null]) data-ledger-report>
                                        <dt><x-desk.icon name="file" />{{ __('programs.ledger_report', ['month' => $reportMonth]) }}</dt>
                                        <dd>
                                            @if ($row['report'] !== null)
                                                <x-ui.enum-badge :case="$row['report']" />
                                            @elseif ($row['reportExpected'])
                                                {{ __('programs.ledger_report_missing') }}
                                            @else
                                                <span class="dk-muted">{{ __('programs.ledger_report_not_due') }}</span>
                                            @endif
                                        </dd>
                                    </div>
                                @endif
                            </dl>

                            {{-- 4. Who runs it, pinned to the bottom. --}}
                            <div class="dk-prog-card-f">
                                @if ($project->responsible)
                                    <x-ui.avatar :src="\App\Models\HrEmployee::avatarUrl($project->responsible_employee_id)" class="dk-av dk-av-xs" aria-hidden="true" data-ledger-manager-photo />
                                @endif
                                <span>{{ $project->responsible?->name ?? __('common.not_assigned') }}</span>
                            </div>
                        </li>
                @endforeach
            </ul>

            <p class="dk-prog-clear" x-cloak x-show="matches.length === 0">{{ __('programs.ledger_nothing_matches') }}</p>

            <div class="dk-prog-more-row" x-show="! searching" x-cloak>
                <button type="button" class="dk-prog-more" x-show="remaining > 0" @click="rows++" data-test="program-ledger-more">
                    <span x-text="Math.min(cols, remaining) === 1 ? @js(__('programs.ledger_load_one')) : @js(__('programs.ledger_load_row')).replace(':count', Math.min(cols, remaining))">{{ __('programs.ledger_load_one') }}</span>
                </button>
                <button type="button" class="dk-prog-more" x-show="rows > 1" @click="rows = 1">{{ __('programs.attention_show_less') }}</button>
            </div>
        @endif
    </section>

    {{-- 3. Program settings, folded --}}
    <section class="dk-prog-fold" x-data="{ open: $persist(false).as('program-settings-open') }" :class="{ 'dk-prog-fold-on': open }" data-program-donor-attribution>
        <button type="button" class="dk-prog-fold-h" @click="open = ! open" :aria-expanded="open.toString()" aria-controls="program-settings-body">
            <h2>{{ __('programs.settings_heading') }}</h2>
            <span class="dk-prog-fold-hint">{{ __('programs.donor_attribution') }}: {{ $this->program->donor_attribution->label() }}</span>
            <x-desk.icon name="chev" class="dk-prog-fold-i" />
        </button>
        <div id="program-settings-body" class="dk-prog-fold-b" x-show="open" x-collapse x-cloak>
            <div class="dk-prog-setting">
                <div class="min-w-0">
                    <label for="program-donor-attribution" class="dk-prog-setting-l">{{ __('programs.donor_attribution') }}</label>
                    <p class="dk-small dk-muted">{{ __('programs.donor_attribution_help') }}</p>
                </div>
                @can('report-templates.manage')
                    <div class="w-full max-w-72">
                        <flux:select id="program-donor-attribution" size="sm" wire:change="setDonorAttribution($event.target.value)" wire:loading.attr="disabled" wire:target="setDonorAttribution">
                            @foreach (\App\Enums\DonorAttribution::cases() as $attribution)
                                <flux:select.option value="{{ $attribution->value }}" :selected="$this->program->donor_attribution === $attribution">{{ $attribution->label() }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                @endcan
            </div>
        </div>
    </section>
</div>
