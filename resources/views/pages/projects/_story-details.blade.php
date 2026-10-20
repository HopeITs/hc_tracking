{{--
    A chapter's read-only details (desk.js doneDetail): the plan summary and
    its reviewers, the report template lines, the months strip, and the lead
    proposal's own files for the other chapters. Each block reads its own
    #[Computed] value, so a chapter that is not shown costs no query.
--}}
@if ($i === 2 && ($plan = $this->planSummary) !== null)
    <div class="dk-block" data-test="story-plan-summary">
        <div class="dk-block-h"><h3>{{ __('story.plan.title') }}</h3></div>
        <div class="dk-benrow">
            <div class="dk-fig"><strong class="dk-num">{{ $plan['indicators'] }}</strong><span>{{ __('story.plan.indicators') }}</span></div>
            <div class="dk-fig"><strong class="dk-num">{{ $plan['targets'] }}</strong><span>{{ __('story.plan.targets') }}</span></div>
            <div class="dk-fig"><strong class="dk-num">{{ $plan['milestones'] }}</strong><span>{{ __('common.milestones') }}</span></div>
            <div class="dk-fig"><strong class="dk-num">{{ $plan['tasks'] }}</strong><span>{{ __('common.tasks') }}</span></div>
        </div>
        @if ($plan['budget'] !== null && $plan['budget'] !== [])
            <p class="dk-muted dk-small" data-test="story-plan-budget">{{ __('story.plan.budget') }}: <span class="dk-num" dir="ltr">{{ implode(' · ', $plan['budget']) }}</span></p>
        @endif
        @if ($plan['reviewers'] !== [])
            <h3 class="dk-fcat">{{ __('story.plan.reviewers') }}</h3>
            <ul class="dk-plist dk-tight" data-test="story-plan-reviewers">
                @foreach ($plan['reviewers'] as $reviewer)
                    <li wire:key="story-reviewer-{{ $loop->index }}">
                        <div><strong>{{ $reviewer['name'] }}</strong><span class="dk-is">{{ $reviewer['slot'] }}</span></div>
                        <span class="dk-small dk-muted">{{ $reviewer['seen'] ? __('story.plan.seen') : __('story.plan.not_seen') }}</span>
                    </li>
                @endforeach
            </ul>
        @endif
    </div>
@endif

@if ($i === 4 && $state !== 'later' && ($lines = $this->templateLines) !== [])
    <ul class="dk-tmpl" data-test="story-template-lines">
        @foreach ($lines as $line)
            <li wire:key="story-template-line-{{ $loop->index }}">
                <div>
                    @if ($line['code'] !== '')
                        <span class="dk-mono" dir="ltr">{{ $line['code'] }}</span>
                    @endif
                    <strong dir="auto">{{ $line['name'] }}</strong>
                </div>
                <span class="dk-small dk-muted">{{ $line['disaggregated'] ? __('story.template.disaggregated') : __('story.template.total_only') }}</span>
            </li>
        @endforeach
    </ul>
@endif

@if ($i === 5 && $story['months'] !== [])
    <div class="dk-months" data-test="story-months">
        @foreach ($story['months'] as $month)
            @if ($month['href'] !== null)
                <a class="dk-mo dk-{{ $month['state'] }}" href="{{ $month['href'] }}" wire:navigate aria-label="{{ $month['title'] }}" title="{{ $month['title'] }}" data-month-state="{{ $month['state'] }}" wire:key="story-month-{{ $loop->index }}"><span>{{ $month['label'] }}</span><i aria-hidden="true">{{ $month['state'] === 'open' ? '' : __('story.month_glyph.'.$month['state']) }}</i></a>
            @else
                <span class="dk-mo dk-{{ $month['state'] }}" aria-label="{{ $month['title'] }}" title="{{ $month['title'] }}" data-month-state="{{ $month['state'] }}" wire:key="story-month-{{ $loop->index }}"><span>{{ $month['label'] }}</span><i aria-hidden="true">{{ $month['state'] === 'open' ? '' : __('story.month_glyph.'.$month['state']) }}</i></span>
            @endif
        @endforeach
    </div>
    <div class="dk-legend">
        @foreach (['validated', 'submitted', 'returned', 'late', 'open'] as $legendState)
            <span><i class="dk-mc dk-{{ $legendState === 'open' ? 'draft' : $legendState }}"></i>{{ __('story.month_state.'.$legendState) }}</span>
        @endforeach
    </div>
@endif

@if (in_array($i, [1, 3, 6], true) && isset($this->chapterFiles[$i]))
    <div class="dk-files" data-test="story-chapter-files">
        @foreach ($this->chapterFiles[$i] as $fileName)
            <span class="dk-file" dir="auto" wire:key="story-chapter-{{ $i }}-file-{{ $loop->index }}"><x-desk.icon name="file" />{{ $fileName }}</span>
        @endforeach
    </div>
@endif
