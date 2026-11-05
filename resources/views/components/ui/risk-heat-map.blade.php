@props(['cells' => [], 'unrated' => 0, 'heading' => null])

{{--
    A 3×3 heat map of rated risks: likelihood up, severity across (the
    desk map's orientation, user decision 2026-10-22). `cells` are counts
    keyed "likelihood:severity"; risks with no rating are counted apart in
    `unrated`. Each square carries its count as text and an aria-label; a
    table with the same numbers is the screen-reader alternative. Score
    ≥ 6 is high, ≥ 4 medium, else low, always said in words in the table.
--}}
@php
    use App\Enums\StatusTone;

    $levels = [3 => __('risks.rating_high'), 2 => __('risks.rating_medium'), 1 => __('risks.rating_low')];
    $tone = fn (int $score): StatusTone => match (true) {
        $score >= 6 => StatusTone::Critical,
        $score >= 4 => StatusTone::Warn,
        default => StatusTone::Neutral,
    };
    $heading ??= __('risks.heat_title');
@endphp

<section {{ $attributes->class('rounded-xl border border-secondary-soft bg-white p-4 shadow-card dark:border-transparent dark:bg-surface-1 dark:shadow-raised') }} aria-labelledby="risk-heat-heading" data-risk-heat>
    <h3 id="risk-heat-heading" class="type-label text-secondary-medium dark:text-ink-muted">{{ $heading }}</h3>

    <p class="mt-1 type-meta text-secondary-medium dark:text-ink-muted" aria-hidden="true">{{ __('risks.heat_axes') }}</p>

    <div class="mt-3 grid grid-cols-3 gap-1.5" aria-hidden="true">
        @foreach ([3, 2, 1] as $likelihood)
            @foreach ([1, 2, 3] as $severity)
                @php
                    $key = $likelihood.':'.$severity;
                    $count = (int) ($cells[$key] ?? 0);
                @endphp
                <span
                    class="flex h-14 items-center justify-center rounded-md border text-base font-bold tabular-nums {{ $tone($likelihood * $severity)->badgeClasses() }}"
                    data-risk-cell="{{ $key }}"
                    data-risk-count="{{ $key }}:{{ $count }}"
                >{{ $count > 0 ? $count : '' }}</span>
            @endforeach
        @endforeach
    </div>

    @if ($unrated > 0)
        <p class="mt-2 type-meta text-secondary-medium dark:text-ink-muted" data-test="risk-heat-unrated">{{ trans_choice('risks.heat_unrated', $unrated, ['count' => $unrated]) }}</p>
    @endif

    <table class="sr-only">
        <caption>{{ __('risks.heat_table_caption') }}</caption>
        <thead>
            <tr>
                <th scope="col">{{ __('risks.likelihood') }}</th>
                <th scope="col">{{ __('risks.severity') }}</th>
                <th scope="col">{{ __('risks.heat_count') }}</th>
            </tr>
        </thead>
        <tbody>
            @foreach ([3, 2, 1] as $likelihood)
                @foreach ([1, 2, 3] as $severity)
                    <tr>
                        <th scope="row">{{ $levels[$likelihood] }}</th>
                        <td>{{ $levels[$severity] }}</td>
                        <td>{{ (int) ($cells[$likelihood.':'.$severity] ?? 0) }}</td>
                    </tr>
                @endforeach
            @endforeach
        </tbody>
    </table>
</section>
