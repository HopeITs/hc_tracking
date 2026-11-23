{{--
    "More about this proposal" (FoldsProposalSections): folded rows with an
    icon, a title, one plain summary line and a red flag when needed. A body
    renders only while its section is open. Included by ⚡show.blade.php,
    whose top PHP block defines $mutedText, $inkText, $hairline, $amount,
    $term, $chronology and $arrivals.
--}}
<div class="pp-more" data-test="more-sections">
    <div class="pp-more-head">
        <div>
            <h2>{{ __('proposals.sections.title') }}</h2>
            <span class="pp-small">{{ __('proposals.sections.intro') }}</span>
        </div>
        @php
            $sectionKeys = array_column($this->sections, 'key');
            $allSectionsOpen = $sectionKeys !== [] && array_diff($sectionKeys, $openSections) === [];
            $noSectionOpen = array_intersect($sectionKeys, $openSections) === [];
        @endphp
        <span class="pp-more-acts">
            <button type="button" class="pp-btn pp-btn-sm" wire:click="openAllSections" @disabled($allSectionsOpen) data-test="sections-open-all">{{ __('proposals.framework.open_all') }}</button>
            <button type="button" class="pp-btn pp-btn-sm" wire:click="closeAllSections" @disabled($noSectionOpen) data-test="sections-close-all">{{ __('proposals.framework.close_all') }}</button>
        </span>
    </div>

    <div class="pp-secs">
        @foreach ($this->sections as $section)
            @php
                $isOpenSection = in_array($section['key'], $openSections, true);
            @endphp
            <section @class(['pp-sec', 'pp-sec-open' => $isOpenSection]) id="sec-{{ $section['key'] }}" wire:key="sec-{{ $section['key'] }}" data-section="{{ $section['key'] }}">
                <button type="button" class="pp-sec-h" wire:click="toggleSection('{{ $section['key'] }}')" aria-expanded="{{ $isOpenSection ? 'true' : 'false' }}" aria-controls="sec-body-{{ $section['key'] }}" data-test="section-toggle-{{ $section['key'] }}">
                    <span class="pp-sec-ic"><flux:icon :name="$section['icon']" class="size-[18px]" aria-hidden="true" /></span>
                    <span class="pp-sec-t">
                        <b>{{ $section['title'] }}</b>
                        <span dir="auto">{{ $section['summary'] }}</span>
                    </span>
                    @if ($section['flag'] !== null)
                        <span class="pp-pill pp-pill-red">{{ $section['flag'] }}</span>
                    @else
                        <span class="pp-sec-noflag"></span>
                    @endif
                    <svg @class(['pp-chev', 'pp-chev-up' => $isOpenSection]) width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="m6 9 6 6 6-6"/></svg>
                </button>

                @if ($isOpenSection)
                    <div class="pp-sec-b" id="sec-body-{{ $section['key'] }}" data-tab-panel="{{ $section['tab'] }}">
                        @switch($section['key'])
                            @case('money')
                                @include('pages.proposals._sec-money')
                                @break
                            @case('coming')
                                @include('pages.proposals._show-coming-up')
                                @break
                            @case('projects')
                                @include('pages.proposals._sec-projects')
                                @break
                            @case('plan')
                                @include('pages.proposals._sec-plan')
                                @break
                            @case('reports')
                                @include('pages.proposals._sec-reports')
                                @break
                            @case('targets')
                                @include('pages.proposals._tab-targets')
                                @break
                            @case('docs')
                                @include('pages.proposals._sec-docs')
                                @break
                            @case('history')
                                @include('pages.proposals._sec-history')
                                @break
                        @endswitch
                    </div>
                @endif
            </section>
        @endforeach
    </div>
</div>

@include('pages.proposals._sec-history-modals')
