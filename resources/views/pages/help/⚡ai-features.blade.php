<?php

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Layout('layouts::desk'), Title('titles.ai_features')] class extends Component {
    /**
     * Every AI feature, in the order an officer meets them, with whether the
     * viewer may use it and where it lives. Reached from the top bar's Help
     * menu; every signed-in person may read it.
     *
     * @return list<array{key: string, icon: string, available: bool|null, href: string|null}>
     */
    #[Computed]
    public function features(): array
    {
        /** @var User $user */
        $user = Auth::user();
        $link = fn (string $route, string $permission): ?string => $user->can($permission) ? route($route) : null;

        return [
            ['key' => 'draft', 'icon' => 'pencil-square', 'available' => $user->can('ai.draft'), 'href' => $user->can('ai.draft') ? $link('monthly-reports.index', 'reports.view') : null],
            ['key' => 'rate', 'icon' => 'hand-thumb-up', 'available' => $user->can('ai.draft'), 'href' => null],
            ['key' => 'check', 'icon' => 'light-bulb', 'available' => $user->can('reports.view'), 'href' => $link('monthly-reports.index', 'reports.view')],
            ['key' => 'brief', 'icon' => 'sparkles', 'available' => $user->can('ai.summarize'), 'href' => $user->can('ai.summarize') ? $link('projects.index', 'projects.view') : null],
            ['key' => 'forecast', 'icon' => 'arrow-trending-up', 'available' => $user->can('indicators.view'), 'href' => $link('indicators.index', 'indicators.view')],
            ['key' => 'digest', 'icon' => 'envelope', 'available' => null, 'href' => null],
            ['key' => 'usage', 'icon' => 'chart-bar', 'available' => $user->can('ai.view-usage'), 'href' => $link('admin.ai-usage.index', 'ai.view-usage')],
        ];
    }
};
?>

<section class="dk-story" aria-labelledby="ai-features-heading" data-ai-features>
    <a class="dk-back" href="{{ route('dashboard') }}" wire:navigate><x-desk.icon name="back" class="dk-flip" />{{ __('desk.your_desk') }}</a>

    <header class="flex max-w-3xl flex-col gap-3">
        <h1 id="ai-features-heading" class="page-title">{{ __('ai.guide.title') }}</h1>
        <p class="text-base text-secondary-medium dark:text-ink-muted">{{ __('ai.guide.intro') }}</p>
        <p class="flex items-start gap-2 text-sm" data-ai-privacy>
            <flux:icon.lock-closed variant="micro" class="mt-0.5 size-4 shrink-0" aria-hidden="true" />
            <span><strong>{{ __('ai.guide.privacy_heading') }}.</strong> {{ __('ai.guide.privacy') }}</span>
        </p>
    </header>

    {{-- One feature per stop on a thread: the dot is the feature's icon, the line ties them as one assistant. --}}
    <ol class="mt-8 flex flex-col" role="list">
        @foreach ($this->features as $feature)
            @php($text = 'ai.guide.items.'.$feature['key'])
            <li class="relative flex gap-4 pb-8 ps-1 last:pb-0" id="ai-{{ $feature['key'] }}" wire:key="ai-feature-{{ $feature['key'] }}" data-ai-feature-guide="{{ $feature['key'] }}">
                @unless ($loop->last)
                    <span class="absolute start-[1.1rem] top-10 bottom-0 w-px bg-secondary-soft dark:bg-line" aria-hidden="true"></span>
                @endunless

                <span class="relative z-[1] mt-0.5 grid size-9 shrink-0 place-items-center rounded-full border border-secondary-soft bg-white dark:border-line dark:bg-surface-1" aria-hidden="true">
                    <x-dynamic-component :component="'flux::icon.'.$feature['icon']" variant="micro" class="size-4" />
                </span>

                <div class="flex min-w-0 flex-1 flex-col gap-3">
                    <div class="flex flex-wrap items-center gap-2">
                        <h2 class="text-lg font-bold">{{ __($text.'.name') }}</h2>
                        @if ($feature['available'] === true)
                            <span class="dk-pill dk-good" data-ai-available="yes">{{ __('ai.guide.yours') }}</span>
                        @elseif ($feature['available'] === false)
                            <span class="dk-pill" data-ai-available="no">{{ __('ai.guide.not_yours') }}</span>
                        @endif
                        @if ($feature['href'] !== null)
                            <a class="dk-btn dk-sm ms-auto" href="{{ $feature['href'] }}" wire:navigate data-ai-go="{{ $feature['key'] }}">{{ __('ai.guide.open') }}<x-desk.icon name="arrow" class="dk-flip" /></a>
                        @endif
                    </div>

                    <dl class="grid max-w-4xl gap-x-8 gap-y-3 text-sm md:grid-cols-[9rem_minmax(0,1fr)]">
                        @foreach (['what', 'where', 'how', 'who'] as $part)
                            <dt class="dk-eyebrow pt-0.5">{{ __('ai.guide.'.$part) }}</dt>
                            <dd class="max-w-prose">{{ __($text.'.'.$part) }}</dd>
                        @endforeach
                    </dl>
                </div>
            </li>
        @endforeach
    </ol>
</section>
