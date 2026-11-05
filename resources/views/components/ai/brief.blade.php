{{--
    The AI brief of a record page (App\Livewire\Concerns\SummarizesWithAi):
    a "Summarize with AI" button, then the verdict, headline, points and next
    step once made. Kept while the page's facts stay the same.
--}}
@if ($this->canSummarizeWithAi())
    @php
        $brief = $this->aiSummary;
        $verdictTone = ['on_track' => 'dk-good', 'watch' => 'dk-warn', 'at_risk' => 'dk-bad'];
    @endphp

    <section {{ $attributes->class('flex flex-col gap-3 border-y border-secondary-soft py-4 dark:border-line') }} aria-labelledby="ai-brief-heading" data-ai-brief>
        <div class="flex flex-wrap items-center justify-between gap-3">
            <h2 id="ai-brief-heading" class="dk-eyebrow inline-flex items-center gap-1.5">
                <flux:icon.sparkles variant="micro" class="size-3.5" aria-hidden="true" />{{ __('ai.brief') }}
            </h2>

            @if ($this->aiSummaryKey !== null)
                <p wire:poll.3s="collectAiSummary" class="dk-muted dk-small inline-flex items-center gap-1.5" aria-live="polite" data-ai-brief-pending>
                    <flux:icon.arrow-path variant="micro" class="size-4 animate-spin motion-reduce:animate-none" aria-hidden="true" />{{ __('ai.summarizing') }}
                </p>
            @elseif ($brief === null)
                <button type="button" class="dk-btn dk-sm" wire:click="summarizeWithAi" wire:loading.attr="disabled" wire:target="summarizeWithAi" data-test="ai-summarize">
                    <flux:icon.sparkles variant="micro" class="size-4" aria-hidden="true" />{{ __('ai.summarize') }}
                </button>
            @endif
        </div>

        @if ($brief !== null)
            <div class="flex flex-col gap-2" data-ai-brief-body>
                <p class="flex flex-wrap items-baseline gap-2 text-base font-semibold" dir="auto">
                    <span class="dk-pill {{ $verdictTone[$brief['verdict']] }}" data-ai-verdict="{{ $brief['verdict'] }}">{{ __('ai.verdicts.'.$brief['verdict']) }}</span>
                    <span>{{ $brief['headline'] }}</span>
                </p>

                @if ($brief['points'] !== [])
                    <ul class="flex max-w-3xl list-disc flex-col gap-1 ps-5 text-sm" dir="auto">
                        @foreach ($brief['points'] as $point)
                            <li>{{ $point }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($brief['next_step'] !== '')
                    <p class="text-sm" dir="auto"><span class="font-semibold">{{ __('ai.next_step') }}:</span> {{ $brief['next_step'] }}</p>
                @endif

                <p class="dk-muted dk-small">{{ __('ai.brief_note', ['date' => \Carbon\CarbonImmutable::parse($brief['made_at'])->translatedFormat(__('formats.date'))]) }}</p>
            </div>
        @endif
    </section>
@endif
