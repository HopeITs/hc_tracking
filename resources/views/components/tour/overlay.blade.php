@php
    /*
     * Each step names the selectors it can point at (first visible one wins;
     * a step with none visible is skipped) plus its translated copy. Page
     * steps reuse the hooks the shared UI components render (see
     * config/tour.php), so every page gets a tour matching what it really has.
     */
    $routeKey = str_replace(['.', '-'], '_', (string) request()->route()?->getName());

    $copy = function (string $id, string $field, array $sources) {
        foreach ($sources as $source) {
            $key = "{$source}.{$id}.{$field}";

            if (\Illuminate\Support\Facades\Lang::has($key)) {
                return __($key);
            }
        }

        return null;
    };

    $step = function (string $id, array $targets = [], bool $union = false, array $sources = [''], bool $mobileBody = false) use ($copy, $routeKey): array {
        $sources = array_map(fn (string $source) => match ($source) {
            'page' => "tour_pages.pages.{$routeKey}",
            'blocks' => 'tour_pages.blocks',
            default => 'tour',
        }, $sources);

        return [
            'key' => "{$routeKey}-{$id}",
            'targets' => $targets,
            'union' => $union,
            'kicker' => $copy($id, 'kicker', $sources) ?? __('tour.page_kicker'),
            'title' => $copy($id, 'title', $sources) ?? $id,
            'body' => $copy($id, 'body', $sources) ?? '',
            'bodyMobile' => $mobileBody ? $copy($id, 'body_mobile', $sources) : null,
        ];
    };

    $blockSteps = collect(config('tour.blocks'))
        ->map(fn (array $block) => $step($block['id'], $block['targets'], $block['union'] ?? false, ['page', 'blocks']));
    $extraSteps = collect(config('tour.pages')[(string) request()->route()?->getName()] ?? [])
        ->map(fn (array $extra) => $step($extra['id'], $extra['targets'], $extra['union'] ?? false, ['page']));

    $welcome = $step('intro', [], false, ['page']);
    $welcome['kicker'] = __('tour.welcome.kicker');
    $welcome['title'] = $copy('intro', 'title', ["tour_pages.pages.{$routeKey}"]) ?? __('tour.welcome.title');
    $welcome['body'] = $copy('intro', 'body', ["tour_pages.pages.{$routeKey}"]) ?? __('tour.welcome.body');
    $welcome['key'] = 'welcome';

    /* The top bar is explained once, on the Desk, not repeated on every page; the Desk steps come from config/tour.php. */
    $shellSteps = [
        $step('logo', ['[data-desk-logo]']),
        $step('ask', ['[data-desk-ask]']),
        $step('ask_answers', ['[data-desk-ask]']),
        $step('tools', ['[data-desk-tools]']),
        $step('locale', ['[data-desk-top-bar] [data-locale-toggle]']),
        $step('profile', ['[data-tour="profile"]']),
    ];
    $closingSteps = [
        $step('help', ['[data-tour="help"]']),
    ];

    $steps = [
        $welcome,
        ...$blockSteps->where('key', "{$routeKey}-detail")->all(),
        $step('page', ['[data-desk-register] .dk-reg-h h1', '[data-page-header] [data-flux-heading]'], sources: ['page', '']),
        $step('filters', ['[data-desk-register-bar]', '[data-page-header-filters]'], sources: ['page', '']),
        $step('actions', ['[data-desk-register-actions]', '[data-page-header-actions]'], sources: ['page', '']),
        ...$extraSteps->all(),
        ...$blockSteps->where('key', '!=', "{$routeKey}-detail")->all(),
    ];

    if (request()->routeIs('dashboard')) {
        $steps = [$welcome, ...$shellSteps, ...$extraSteps->all(), ...$closingSteps];
    }

    /* Opens by itself once, on someone's first visit to the dashboard; after that only the header button starts it. */
    $autostart = request()->routeIs('dashboard') && auth()->user()?->tour_seen_at === null
        ? route('tour.seen')
        : null;
@endphp

<div
    x-data="hcTour(@js($steps), @js($autostart))"
    x-on:hc-tour-start.window="start($event)"
    x-on:keydown.window="keydown($event)"
    x-on:resize.window="queuePlace()"
    x-on:scroll.window.passive="queuePlace()"
    x-cloak
    data-tour-root
>
    <template x-if="open">
        <div class="fixed inset-0 z-[100]" role="presentation">
            {{-- Blocks the page and dims it; the spotlight below takes over the dimming around its target. --}}
            <div
                class="absolute inset-0 bg-secondary-dark/65 transition-opacity duration-200 motion-reduce:transition-none"
                :class="spot.visible ? 'opacity-0' : 'opacity-100'"
                x-on:click="finish(false)"
            ></div>

            <div
                class="pointer-events-none absolute rounded-xl ring-2 ring-primary shadow-[0_0_0_100vmax_color-mix(in_oklab,var(--color-secondary-dark)_65%,transparent)] transition-[top,left,width,height,opacity] duration-300 ease-out motion-reduce:transition-none"
                :style="spotStyle()"
                aria-hidden="true"
            ></div>

            <section
                x-ref="card"
                role="dialog"
                aria-modal="true"
                aria-labelledby="tour-title"
                aria-describedby="tour-body"
                :style="cardStyle()"
                :class="card.sheet ? 'inset-x-3 bottom-3' : 'w-[min(23rem,calc(100vw-2rem))]'"
                class="absolute overflow-hidden rounded-2xl border border-secondary-soft bg-white text-secondary-dark shadow-[0_18px_48px_-12px_rgb(88_89_91/0.45)] transition-[top,left] duration-300 ease-out motion-reduce:transition-none dark:border-line dark:bg-surface-3 dark:text-ink dark:shadow-raised"
            >
                <div class="h-1 bg-primary" aria-hidden="true"></div>

                <div class="p-5 pb-4">
                    <div class="flex items-start gap-3">
                        <span x-show="step?.key === 'welcome'" class="grid size-12 shrink-0 place-items-center rounded-xl text-white bg-primary" aria-hidden="true">
                            <flux:icon.map class="size-6" />
                        </span>
                        <span x-show="step?.key !== 'welcome'" class="text-4xl leading-none font-bold tracking-tight text-status-critical tabular-nums" x-text="number(index)" aria-hidden="true"></span>

                        <div class="min-w-0 flex-1 pt-0.5">
                            <p class="text-[11px] font-semibold tracking-[0.12em] text-secondary-light uppercase dark:text-ink-muted" x-text="step?.kicker"></p>
                            <p class="mt-1 text-xs text-secondary-medium tabular-nums dark:text-ink-muted" x-text="@js(__('tour.step_of', ['current' => '{c}', 'total' => '{t}'])).replace('{c}', index + 1).replace('{t}', steps.length)"></p>
                        </div>

                        <button
                            type="button"
                            x-on:click="finish(false)"
                            class="-me-1.5 -mt-1.5 inline-flex size-8 shrink-0 cursor-pointer touch-manipulation items-center justify-center rounded-lg text-secondary-medium hover:bg-secondary-soft/70 hover:text-secondary-dark dark:text-ink-muted dark:hover:bg-white/10 dark:hover:text-ink"
                            aria-label="{{ __('tour.close') }}"
                        >
                            <flux:icon.x-mark variant="mini" class="size-4" />
                        </button>
                    </div>

                    <h2 id="tour-title" class="mt-4 text-xl leading-snug font-bold text-balance" x-text="step?.title"></h2>
                    <p id="tour-body" class="mt-1.5 text-sm leading-relaxed text-secondary-medium text-pretty dark:text-ink-muted" x-text="body"></p>
                </div>

                <div class="flex gap-1 px-5" aria-hidden="true">
                    <template x-for="(item, position) in steps" :key="item.key">
                        <span
                            class="h-1 flex-1 rounded-full transition-colors duration-300"
                            :class="position <= index ? 'bg-primary' : 'bg-secondary-soft dark:bg-line-strong'"
                        ></span>
                    </template>
                </div>

                <div class="flex items-center gap-2 p-5 pt-4">
                    <button
                        type="button"
                        x-show="! isLast"
                        x-on:click="finish(false)"
                        class="me-auto cursor-pointer touch-manipulation rounded-lg px-2 py-2 text-sm font-medium text-secondary-medium hover:text-secondary-dark max-lg:min-h-11 dark:text-ink-muted dark:hover:text-ink"
                    >
                        {{ __('tour.skip') }}
                    </button>

                    <button
                        type="button"
                        x-show="index > 0"
                        x-on:click="back()"
                        :class="isLast ? 'ms-auto' : ''"
                        class="inline-flex cursor-pointer touch-manipulation items-center gap-1.5 rounded-lg border border-secondary-soft px-3.5 py-2 text-sm font-semibold text-secondary-dark hover:bg-secondary-soft/60 max-lg:min-h-11 dark:border-line-strong dark:text-ink dark:hover:bg-white/10"
                    >
                        <flux:icon.arrow-left variant="micro" class="rtl:-scale-x-100" />
                        {{ __('tour.back') }}
                    </button>

                    <button
                        type="button"
                        x-ref="primary"
                        x-on:click="next()"
                        :class="isLast && index === 0 ? 'ms-auto' : ''"
                        class="inline-flex cursor-pointer touch-manipulation items-center gap-1.5 rounded-lg bg-primary px-4 py-2 text-sm font-semibold text-white hover:bg-primary/90 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary max-lg:min-h-11"
                    >
                        <span x-text="isLast ? @js(__('tour.finish')) : @js(__('tour.next'))"></span>
                        <flux:icon.arrow-right x-show="! isLast" variant="micro" class="rtl:-scale-x-100" />
                        <flux:icon.check x-show="isLast" variant="micro" />
                    </button>
                </div>
            </section>
        </div>
    </template>
</div>
