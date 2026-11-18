{{--
    Plan preparation as one sectioned page (2026-11-17, replaces the 4-step
    wizard): the proposal, the activities, their dates and places, and the
    check-and-send summary are all on screen at once. A sticky index follows
    the scroll and marks each section done or waiting; one sticky footer holds
    the send button. Included by ⚡plan.blade.php in plan mode.
--}}
@php
    $items = $plan->items;
    $savedPicks = $items->pluck('result_id')->map(fn (int $id): string => (string) $id)->sort()->values()->all();
    $currentPicks = collect($picked)->sort()->values()->all();
    $picksUnsaved = $savedPicks !== $currentPicks;
    $readyCount = $items->where('is_ready', true)->count();
    $allReady = $items->isNotEmpty() && $readyCount === $items->count();
    $sections = [
        ['id' => 'proposal', 'label' => __('annual_plans.wf.proposal_title'), 'state' => 'done'],
        ['id' => 'activities', 'label' => __('annual_plans.wf.activities_title'), 'state' => $items->isNotEmpty() && ! $picksUnsaved ? 'done' : 'todo'],
        ['id' => 'dates', 'label' => __('annual_plans.wf.dates_title'), 'state' => $allReady ? 'done' : ($items->isEmpty() ? 'todo' : 'attention')],
        ['id' => 'send', 'label' => __('annual_plans.wf.send_title'), 'state' => 'todo'],
    ];
@endphp

<div class="ap-sections" x-data="{
        current: 'proposal',
        init() {
            const observer = new IntersectionObserver((entries) => {
                entries.filter((entry) => entry.isIntersecting).forEach((entry) => { this.current = entry.target.dataset.planSection; });
            }, { rootMargin: '-30% 0px -60% 0px' });
            this.$root.querySelectorAll('[data-plan-section]').forEach((section) => observer.observe(section));
        },
        go(id) {
            const target = this.$root.querySelector(`[data-plan-section='${id}']`);
            target?.scrollIntoView({ behavior: matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth', block: 'start' });
            this.current = id;
        },
    }">
    <nav class="ap-index" aria-label="{{ __('common.sections_in_this_form') }}" data-plan-index>
        @foreach ($sections as $index => $section)
            <a href="#plan-{{ $section['id'] }}" @click.prevent="go('{{ $section['id'] }}')"
                class="ap-index-item is-{{ $section['state'] }}" :class="{ 'is-current': current === '{{ $section['id'] }}' }"
                :aria-current="current === '{{ $section['id'] }}' ? 'true' : null" data-plan-index-item="{{ $section['id'] }}">
                <i aria-hidden="true">{{ $section['state'] === 'done' ? '✓' : $index + 1 }}</i>
                <span>{{ $section['label'] }}</span>
            </a>
        @endforeach
    </nav>

    <div id="plan-proposal" data-plan-section="proposal">@include('pages.annual-plans._plan-step-proposal')</div>
    <div id="plan-activities" data-plan-section="activities">@include('pages.annual-plans._plan-step-activities', ['picksUnsaved' => $picksUnsaved])</div>
    <div id="plan-dates" data-plan-section="dates">
        @if ($items->isEmpty())
            <section class="ap-card" data-plan-step="dates">
                <div>
                    <h2>{{ __('annual_plans.wf.dates_title') }}</h2>
                    <p class="ap-q">{{ __('annual_plans.wf.dates_need_activities') }}</p>
                </div>
            </section>
        @else
            @include('pages.annual-plans._plan-step-dates')
        @endif
    </div>
    <div id="plan-send" data-plan-section="send">
        @if ($items->isNotEmpty())
            @include('pages.annual-plans._plan-step-send')
        @endif
    </div>

    <div class="ap-foot" data-plan-footer>
        <span class="ap-sp ap-sm" data-plan-footer-state>
            @if ($picksUnsaved && $picked !== [])
                {{ __('annual_plans.wf.footer_save_activities') }}
            @elseif ($items->isEmpty())
                {{ __('annual_plans.wf.footer_pick_activities') }}
            @elseif (! $allReady)
                {{ __('annual_plans.wf.ready_count', ['ready' => $readyCount, 'total' => $items->count()]) }}
            @else
                {{ __('annual_plans.wf.footer_ready') }}
            @endif
        </span>
        @if (! $allReady || $picksUnsaved)
            <button type="button" class="dk-btn" @click="go('{{ $items->isEmpty() || $picksUnsaved ? 'activities' : 'dates' }}')" data-test="plan-show-missing">{{ __('annual_plans.wf.footer_show_me') }}</button>
        @endif
        <button type="button" class="dk-btn dk-primary" wire:click="submitPlan" wire:loading.attr="disabled" wire:target="submitPlan" @disabled(! $allReady || $picksUnsaved) data-test="send-to-meal">{{ __('annual_plans.wf.send_button') }} ✓</button>
    </div>
</div>
