<?php

use App\Support\Ai\AiTrialStats;
use Carbon\CarbonImmutable;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('titles.ai_usage')] class extends Component {
    /**
     * '30' for the last 30 days, 'all' for everything since the trial began.
     */
    #[Url(except: '30')]
    public string $period = '30';

    /**
     * @return array<string, mixed>
     */
    #[Computed]
    public function stats(): array
    {
        return AiTrialStats::since($this->period === 'all' ? null : CarbonImmutable::now()->subDays(30));
    }

    public function featureLabel(string $feature): string
    {
        $key = 'ai.usage.features.'.$feature;

        return trans()->has($key) ? __($key) : $feature;
    }
};
?>

@php
    $stats = $this->stats;
    $drafts = $stats['drafts'];
    $periods = ['30' => __('ai.usage.last_30_days'), 'all' => __('ai.usage.all_time')];
@endphp

<x-desk.register heading-id="ai-usage-heading" :title="__('ai.usage.title')">
    <x-slot:figures>
        {{ __('ai.usage.figures', ['provider' => config('ai.default'), 'model' => config('ai.models.default')]) }}
    </x-slot:figures>

    <x-slot:filters>
        <div class="flex flex-wrap gap-2" role="group" aria-label="{{ __('ai.usage.period') }}">
            @foreach ($periods as $value => $label)
                <button type="button" wire:click="$set('period', '{{ $value }}')" @class(['dk-btn dk-sm', 'dk-primary' => $period === (string) $value]) aria-pressed="{{ $period === (string) $value ? 'true' : 'false' }}" data-ai-period="{{ $value }}">{{ $label }}</button>
            @endforeach
        </div>
    </x-slot:filters>

    @if ($stats['calls'] === 0)
        <x-ui.empty-state icon="sparkles" :heading="__('ai.usage.empty')" :body="__('ai.usage.empty_body')" />
    @else
        <div class="flex flex-col gap-10" data-ai-usage>
            {{-- Three figures that answer "is it used, is it reliable, is it quick". --}}
            <dl class="grid gap-6 sm:grid-cols-3">
                <div class="flex flex-col gap-1">
                    <dt class="dk-eyebrow">{{ __('ai.usage.calls') }}</dt>
                    <dd class="dk-num text-4xl font-bold" data-ai-figure="calls">{{ number_format($stats['calls']) }}</dd>
                    <dd class="dk-muted dk-small">{{ trans_choice('ai.usage.people', $stats['people'], ['count' => $stats['people']]) }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="dk-eyebrow">{{ __('ai.usage.success_rate') }}</dt>
                    <dd class="dk-num text-4xl font-bold" data-ai-figure="success">{{ $stats['success_rate'] }}%</dd>
                    <dd class="dk-muted dk-small">{{ trans_choice('ai.usage.failed', $stats['failed'], ['count' => $stats['failed']]) }}</dd>
                </div>
                <div class="flex flex-col gap-1">
                    <dt class="dk-eyebrow">{{ __('ai.usage.typical_answer') }}</dt>
                    <dd class="dk-num text-4xl font-bold" data-ai-figure="speed">{{ $stats['typical_seconds'] === null ? '—' : __('ai.usage.seconds', ['seconds' => $stats['typical_seconds']]) }}</dd>
                    <dd class="dk-muted dk-small">{{ __('ai.usage.tokens', ['count' => number_format($stats['tokens'])]) }}</dd>
                </div>
            </dl>

            {{-- What officers made of the drafts: the proof of value. --}}
            <section aria-labelledby="ai-drafts-heading" class="flex flex-col gap-3 border-t border-secondary-soft pt-6 dark:border-line" data-ai-drafts>
                <h2 id="ai-drafts-heading" class="text-base font-bold">{{ __('ai.usage.drafts_heading') }}</h2>

                @if ($drafts['made'] === 0)
                    <p class="dk-muted text-sm">{{ __('ai.usage.no_drafts') }}</p>
                @else
                    <p class="max-w-3xl text-sm">{{ __('ai.usage.drafts_summary', ['made' => $drafts['made'], 'rated' => $drafts['rated']]) }}</p>

                    @if ($drafts['rated'] > 0)
                        <div class="flex max-w-xl flex-col gap-1.5">
                            <div class="flex h-2.5 overflow-hidden rounded-full bg-secondary-soft dark:bg-surface-3" role="img" aria-label="{{ __('ai.usage.helpful_rate', ['percent' => $drafts['helpful_rate']]) }}">
                                <span class="bg-status-good" style="width: {{ $drafts['helpful_rate'] }}%"></span>
                            </div>
                            <p class="flex flex-wrap gap-x-4 text-sm">
                                <span><span class="font-semibold dk-num" data-ai-figure="helpful">{{ $drafts['helpful_rate'] }}%</span> {{ __('ai.usage.rated_helpful') }} ({{ $drafts['helpful'] }})</span>
                                <span class="dk-muted">{{ __('ai.usage.rated_not_helpful') }} ({{ $drafts['not_helpful'] }})</span>
                            </p>
                        </div>
                    @endif

                    @if ($drafts['kept_percent'] !== null)
                        <p class="text-sm"><span class="font-semibold dk-num" data-ai-figure="kept">{{ $drafts['kept_percent'] }}%</span> {{ __('ai.usage.kept') }}</p>
                    @endif
                @endif
            </section>

            {{-- Each feature on its own line. --}}
            <section aria-labelledby="ai-features-heading" class="flex flex-col gap-3 border-t border-secondary-soft pt-6 dark:border-line">
                <h2 id="ai-features-heading" class="text-base font-bold">{{ __('ai.usage.features_heading') }}</h2>

                <div class="overflow-x-auto">
                    <table class="w-full min-w-[32rem] text-sm">
                        <thead>
                            <tr class="border-b border-secondary-medium text-start dark:border-line-strong">
                                <th class="dk-eyebrow py-2 text-start">{{ __('ai.usage.feature') }}</th>
                                <th class="dk-eyebrow py-2 text-end">{{ __('ai.usage.calls') }}</th>
                                <th class="dk-eyebrow py-2 text-end">{{ __('ai.usage.failures') }}</th>
                                <th class="dk-eyebrow py-2 text-end">{{ __('ai.usage.typical_answer') }}</th>
                                <th class="dk-eyebrow py-2 text-end">{{ __('ai.usage.last_used') }}</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($stats['features'] as $row)
                                <tr class="border-b border-secondary-soft dark:border-line" wire:key="feature-{{ $row['feature'] }}" data-ai-feature="{{ $row['feature'] }}">
                                    <td class="py-2.5 font-semibold">{{ $this->featureLabel($row['feature']) }}</td>
                                    <td class="dk-num py-2.5 text-end">{{ number_format($row['calls']) }}</td>
                                    <td @class(['dk-num py-2.5 text-end', 'text-status-critical' => $row['failed'] > 0])>{{ $row['failed'] }}</td>
                                    <td class="dk-num py-2.5 text-end">{{ $row['typical_seconds'] === null ? '—' : __('ai.usage.seconds', ['seconds' => $row['typical_seconds']]) }}</td>
                                    <td class="dk-num py-2.5 text-end">{{ $row['last_used']?->translatedFormat(__('formats.date')) ?? '—' }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </section>
        </div>
    @endif
</x-desk.register>
