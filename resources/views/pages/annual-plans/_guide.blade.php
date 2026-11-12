{{-- "How this works for you": the steps of the person reading the page, collapsed by default ($guideKey: pm, meal, officer, centre, ceo, viewer). --}}
<details class="ap-guide" data-guide="{{ $guideKey }}">
    <summary>{{ __('annual_plans.wf.guide_title') }}</summary>
    <ol>
        @foreach (__('annual_plans.wf.guide.'.$guideKey) as $line)
            <li>{!! $line !!}</li>
        @endforeach
    </ol>
</details>
