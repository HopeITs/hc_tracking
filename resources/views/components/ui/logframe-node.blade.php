{{--
    One node of the read-only results chain (T16): a node array from
    App\Support\ResultsChain, never a Result to query. Keeps the shared
    ResultType mark/accent identity and the data-tree-depth /
    data-result-type attributes (ResultsFrameworkTest, ProgramDetailTest).
--}}
@props(['node', 'depth'])

@php
    use App\Enums\ResultType;

    $result = $node['result'];
    $children = $node['children'];
    $contributions = $node['contributions'];
    $isRoot = $depth === 0;

    $surface = match ($result->type) {
        ResultType::Impact => 'border-secondary-dark bg-board shadow-[0_18px_40px_-24px_rgb(0_0_0/0.35)] dark:border-field-line',
        ResultType::Outcome => 'border-board-line bg-board shadow-[0_10px_28px_-22px_rgb(0_0_0/0.45)]',
        ResultType::Output, ResultType::Activity, ResultType::CrossCutting => 'border-board-line bg-board-head',
    };
    $mark = match ($result->type) {
        ResultType::Impact => 'bg-secondary-dark ring-secondary-dark dark:bg-ink dark:ring-ink',
        ResultType::Outcome => 'bg-primary ring-primary',
        ResultType::Output => 'bg-board ring-secondary-light',
        ResultType::Activity => 'bg-transparent ring-secondary-medium dark:ring-ink-muted',
        ResultType::CrossCutting => 'bg-secondary-light ring-secondary-light dark:bg-ink-muted dark:ring-ink-muted',
    };
    $accent = match ($result->type) {
        ResultType::Impact => 'h-1 bg-primary',
        ResultType::Outcome => 'h-0.5 bg-primary',
        ResultType::Output, ResultType::Activity, ResultType::CrossCutting => null,
    };
    $statementSize = match ($result->type) {
        ResultType::Impact => 'text-lg font-bold leading-snug',
        ResultType::Outcome => 'text-base font-semibold leading-snug',
        ResultType::Output, ResultType::Activity, ResultType::CrossCutting => 'text-sm font-medium leading-relaxed',
    };
    $muted = 'text-secondary-medium dark:text-ink-muted';
    $link = 'font-semibold underline decoration-secondary-light underline-offset-2 hover:text-primary dark:hover:text-brand-text';
@endphp

<div data-tree-depth="{{ $depth }}" data-result-type="{{ $result->type->value }}" x-data="{ open: true, contributions: false }" class="break-inside-avoid-page">
    @if ($node['lineage'] !== [])
        <p class="mb-1.5 flex flex-wrap items-center gap-x-1.5 gap-y-0.5 type-meta {{ $muted }}" data-chain-lineage>
            <flux:icon.arrow-turn-down-right variant="micro" class="size-3.5 rtl:-scale-x-100" aria-hidden="true" />
            <span>{{ __('results_framework.chain_lineage') }}</span>
            @foreach ($node['lineage'] as $ancestor)
                <span class="inline-flex items-center gap-1">
                    <span class="font-semibold">{{ $ancestor->type->label() }}:</span>
                    <span dir="auto">{{ str($ancestor->statement)->limit(80) }}</span>
                </span>
                @unless ($loop->last)
                    <flux:icon.chevron-right variant="micro" class="size-3 rtl:rotate-180" aria-hidden="true" />
                @endunless
            @endforeach
        </p>
    @endif

    <article class="relative overflow-hidden rounded-md border px-4 py-3 text-secondary-dark dark:text-ink {{ $surface }}">
        @if ($accent !== null)
            <span aria-hidden="true" class="absolute inset-x-0 top-0 {{ $accent }}"></span>
        @endif

        <div class="flex flex-wrap items-center gap-x-3 gap-y-1.5">
            <span class="inline-flex items-center gap-1.5">
                <span aria-hidden="true" class="size-2 rounded-full ring-2 ring-inset {{ $mark }}"></span>
                <span class="type-label">{{ $result->type->label() }}</span>
            </span>

            @if ($node['proposal'] !== null)
                <span class="inline-flex flex-wrap items-center gap-x-1.5 type-meta {{ $muted }}" data-chain-proposal>
                    <flux:icon.document-text variant="micro" class="size-3.5" aria-hidden="true" />
                    @if ($node['proposal']['url'] !== null)
                        <a href="{{ $node['proposal']['url'] }}" wire:navigate class="{{ $link }}" dir="auto">{{ $node['proposal']['name'] }}</a>
                    @else
                        <span class="font-semibold" dir="auto">{{ $node['proposal']['name'] }}</span>
                    @endif
                    @if ($node['proposal']['donor'] !== null)
                        <span aria-hidden="true">·</span>
                        @if ($node['proposal']['donorUrl'] !== null)
                            <a href="{{ $node['proposal']['donorUrl'] }}" wire:navigate class="{{ $link }}" dir="auto">{{ $node['proposal']['donor'] }}</a>
                        @else
                            <span dir="auto">{{ $node['proposal']['donor'] }}</span>
                        @endif
                    @endif
                </span>
            @endif

            @foreach ($node['gaps'] as $gap)
                <x-ui.logframe-gap :gap="$gap" />
            @endforeach

            @if ($children !== [])
                <button
                    type="button"
                    x-on:click="open = ! open"
                    x-bind:aria-expanded="open.toString()"
                    aria-controls="chain-children-{{ $result->id }}"
                    class="ms-auto inline-flex cursor-pointer items-center gap-1 rounded-sm type-meta font-semibold text-secondary-dark hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium print:hidden dark:text-ink dark:hover:text-brand-text"
                >
                    {{ trans_choice('programs.logframe_count_contributing', count($children), ['count' => count($children)]) }}
                    <flux:icon.chevron-down variant="micro" class="size-3.5 transition-transform duration-200" x-bind:class="open ? '' : '-rotate-90 rtl:rotate-90'" aria-hidden="true" />
                </button>
            @endif
        </div>

        <p class="mt-2 text-pretty {{ $statementSize }}" dir="auto">{{ $result->statement }}</p>

        @if ($node['indicators'] !== [])
            <ul class="mt-3 space-y-2" aria-label="{{ __('results_framework.chain_indicators') }}">
                @foreach ($node['indicators'] as $row)
                    <x-ui.logframe-indicator :row="$row" wire:key="chain-indicator-{{ $row['indicator']->id }}" />
                @endforeach
            </ul>
        @endif

        @if ($result->type === ResultType::Activity)
            <x-ui.logframe-milestones :activity-id="$result->id" :groups="$node['milestoneGroups']" :schedule="$node['schedule']" :statuses="$node['activityStatuses'] ?? []" :tasks="$node['departmentTasks'] ?? []" />
        @endif
    </article>

    @if ($children !== [])
        <div id="chain-children-{{ $result->id }}" x-show="open" x-collapse class="print:block!">
            <x-ui.logframe-branch :nodes="$children" :depth="$depth + 1" />
        </div>
    @endif

    @if ($contributions !== [])
        <div class="ms-4 mt-2.5 ps-5" data-chain-contributions>
            <button
                type="button"
                x-on:click="contributions = ! contributions"
                x-bind:aria-expanded="contributions.toString()"
                aria-controls="chain-contributions-{{ $result->id }}"
                class="inline-flex cursor-pointer items-center gap-1.5 rounded-sm type-meta font-semibold text-secondary-dark hover:text-primary focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium print:hidden dark:text-ink dark:hover:text-brand-text"
            >
                <flux:icon.document-text variant="micro" class="size-3.5" aria-hidden="true" />
                {{ trans_choice('results_framework.chain_contributions', count($contributions), ['count' => count($contributions)]) }}
                <flux:icon.chevron-down variant="micro" class="size-3.5 transition-transform duration-200" x-bind:class="contributions ? '' : '-rotate-90 rtl:rotate-90'" aria-hidden="true" />
            </button>
            <div id="chain-contributions-{{ $result->id }}" x-show="contributions" x-collapse x-cloak class="print:block!">
                <x-ui.logframe-branch :nodes="$contributions" :depth="$depth + 1" />
            </div>
        </div>
    @endif
</div>
