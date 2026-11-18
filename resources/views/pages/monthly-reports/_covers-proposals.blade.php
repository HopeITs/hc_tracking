{{-- The proposals a monthly report's template covers (Y-03, D-76), under its place: two reports of one place and month are told apart by this line. $proposals: list of {id, slug, name, number} from MonthlyReportNames. --}}
<p class="mt-0.5 type-meta text-secondary-medium dark:text-ink-muted" data-covers-proposals>
    @if ($proposals === [])
        {{ __('monthly_reports.no_proposal') }}
    @else
        {{ trans_choice('monthly_reports.covers_proposals', count($proposals), ['names' => '']) }}
        @foreach ($proposals as $coveredProposal)
            @can('proposals.view')
                <a href="{{ route('proposals.show', $coveredProposal['slug']) }}" wire:navigate class="font-semibold text-secondary-dark underline-offset-2 hover:underline dark:text-ink" dir="auto">{{ $coveredProposal['name'] }}</a>@if (! $loop->last){{ app()->getLocale() === 'ar' ? '، ' : ', ' }}@endif
            @else
                <span class="font-semibold text-secondary-dark dark:text-ink" dir="auto">{{ $coveredProposal['name'] }}</span>@if (! $loop->last){{ app()->getLocale() === 'ar' ? '، ' : ', ' }}@endif
            @endcan
        @endforeach
    @endif
</p>
