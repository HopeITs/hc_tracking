        {{-- Funding flow: donors → proposals → projects. --}}
        @if (count($this->flow['currencies']) > 0 || count($this->flow['unfunded']) > 0)
            <section aria-labelledby="flow-heading" class="border-t pt-6 {{ $hairline }}" x-data="{ currency: @js($this->flow['currencies'][0]['currency'] ?? null) }" data-funding-flow>
                <div class="flex flex-wrap items-end justify-between gap-x-6 gap-y-2">
                    <div class="min-w-0">
                        <h2 id="flow-heading" class="text-lg font-bold {{ $inkText }}">{{ __('proposals.funding_flow') }}</h2>
                        <p class="mt-0.5 text-xs {{ $mutedText }}">{{ __('proposals.funding_flow_hint') }}</p>
                    </div>
                    @if (count($this->flow['currencies']) > 1)
                        <div class="inline-flex rounded-lg bg-board-head p-1 dark:bg-white/5" role="group" aria-label="{{ __('proposals.currency') }}">
                            @foreach ($this->flow['currencies'] as $graph)
                                <button type="button" x-on:click="currency = @js($graph['currency'])" :aria-pressed="(currency === @js($graph['currency'])).toString()" class="min-h-8 rounded-md px-3 text-xs font-bold tabular-nums transition-colors {{ $mutedText }} aria-pressed:bg-white aria-pressed:text-secondary-dark aria-pressed:shadow-sm dark:aria-pressed:bg-surface-2 dark:aria-pressed:text-ink" wire:key="flow-tab-{{ $graph['currency'] }}">{{ $graph['currency'] }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>

                @foreach ($this->flow['currencies'] as $graph)
                    @php
                        $rows = max(count($graph['donors']), count($graph['proposals']), (int) ceil(count($graph['projects']) * 0.55));
                        $flowHeight = max(16, $rows * 5.25);
                        $flowKey = 'flow-'.$graph['currency'].'-'.md5(json_encode($graph['graph']).$donor);
                    @endphp
                    <div x-show="currency === @js($graph['currency'])" wire:key="{{ $flowKey }}">
                        {{-- Wide screens: the drawn flow. --}}
                        <div class="relative mt-6 hidden @3xl/directory:block" x-data="fundingFlow(@js($graph['graph']))">
                            <svg class="pointer-events-none absolute inset-0 size-full overflow-visible" aria-hidden="true" wire:ignore>
                                <g class="flow-reveal" x-ref="paths"></g>
                            </svg>

                            <div class="grid grid-cols-[minmax(0,0.75fr)_minmax(3.5rem,0.75fr)_minmax(0,1.35fr)_minmax(3rem,0.6fr)_minmax(0,1.3fr)] gap-y-3">
                                <p class="type-label text-end {{ $mutedText }}">{{ __('common.donors') }}</p>
                                <span></span>
                                <p class="type-label ps-5 {{ $mutedText }}">{{ __('common.proposals') }}</p>
                                <span></span>
                                <p class="type-label ps-5 {{ $mutedText }}">{{ __('common.projects') }}</p>

                                {{-- Donors --}}
                                <ul class="flex flex-col gap-2.5" style="height: {{ $flowHeight }}rem" role="list">
                                    @foreach ($graph['donors'] as $node)
                                        @php
                                            $isFiltered = $donor === (string) $node['id'];
                                        @endphp
                                        <li class="flex min-h-15" style="flex: {{ max(1, round($node['total'] / max($graph['total'], 1) * 100, 2)) }} 1 0%" wire:key="flow-donor-{{ $graph['currency'] }}-{{ $node['id'] }}">
                                            <button
                                                type="button"
                                                wire:click="toggleDonor({{ $node['id'] }})"
                                                aria-pressed="{{ $isFiltered ? 'true' : 'false' }}"
                                                x-on:mouseenter="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:mouseleave="$store.proposalFlow.focus = null"
                                                x-on:focus="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:blur="$store.proposalFlow.focus = null"
                                                :class="$store.proposalFlow.isDimmed('{{ $node['key'] }}') && 'opacity-30'"
                                                class="group flex w-full cursor-pointer items-stretch justify-end gap-3 rounded-sm text-end transition-opacity duration-200 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                                                title="{{ __('proposals.filter_by_donor', ['name' => $node['name']]) }}"
                                                data-flow-node="{{ $node['key'] }}"
                                            >
                                                <span class="flex min-w-0 flex-col justify-center">
                                                    <span @class(['truncate text-sm font-bold underline-offset-4 group-hover:underline', 'text-primary dark:text-brand-text' => $isFiltered, $inkText => ! $isFiltered])>{{ $node['name'] }}</span>
                                                    <span class="truncate text-xs tabular-nums {{ $mutedText }}">{{ $amount($node['total']) }} · {{ trans_choice('common.count_agreement', $node['agreements'], ['count' => $node['agreements']]) }}</span>
                                                </span>
                                                <span data-flow-out="{{ $node['key'] }}" @class(['w-2 shrink-0 rounded-full', 'bg-primary dark:bg-ink' => $isFiltered, 'bg-funding' => ! $isFiltered])></span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>

                                <span></span>

                                {{-- Proposals --}}
                                <ul class="flex flex-col gap-2.5" style="height: {{ $flowHeight }}rem" role="list">
                                    @foreach ($graph['proposals'] as $node)
                                        <li class="flex min-h-15" style="flex: {{ max(1, round($node['total'] / max($graph['total'], 1) * 100, 2)) }} 1 0%" wire:key="flow-proposal-{{ $graph['currency'] }}-{{ $node['id'] }}">
                                            <a
                                                href="{{ $proposalHref($node['id']) }}"
                                                x-on:mouseenter="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:mouseleave="$store.proposalFlow.focus = null"
                                                x-on:focus="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:blur="$store.proposalFlow.focus = null"
                                                :class="$store.proposalFlow.isDimmed('{{ $node['key'] }}') && 'opacity-30'"
                                                class="group flex w-full items-stretch gap-3 rounded-sm transition-opacity duration-200 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                                                data-flow-node="{{ $node['key'] }}"
                                            >
                                                <span data-flow-in="{{ $node['key'] }}" class="w-2 shrink-0 rounded-full bg-secondary-dark dark:bg-ink-muted"></span>
                                                <span class="flex min-w-0 flex-1 flex-col justify-center">
                                                    <span class="line-clamp-2 text-sm leading-snug font-bold underline-offset-4 group-hover:underline {{ $inkText }}">{{ $node['name'] }}</span>
                                                    <span class="flex min-w-0 items-center gap-1.5 text-xs tabular-nums {{ $mutedText }}">
                                                        <span class="truncate">{{ $amount($node['total']) }} {{ $graph['currency'] }}</span>
                                                        <span aria-hidden="true">·</span>
                                                        <span class="truncate">{{ $node['status']->label() }}</span>
                                                    </span>
                                                </span>
                                                <span data-flow-out="{{ $node['key'] }}" class="my-auto size-2 shrink-0 rounded-full bg-secondary-light"></span>
                                            </a>
                                        </li>
                                    @endforeach
                                </ul>

                                <span></span>

                                {{-- Projects --}}
                                <ul class="flex flex-col justify-around gap-1" style="height: {{ $flowHeight }}rem" role="list">
                                    @forelse ($graph['projects'] as $node)
                                        <li wire:key="flow-project-{{ $graph['currency'] }}-{{ $node['id'] }}">
                                            <a
                                                href="{{ route('projects.show', tap(new \App\Models\Project, fn ($project) => $project->id = $node['id'])) }}"
                                                wire:navigate
                                                x-on:mouseenter="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:mouseleave="$store.proposalFlow.focus = null"
                                                x-on:focus="$store.proposalFlow.focus = '{{ $node['key'] }}'"
                                                x-on:blur="$store.proposalFlow.focus = null"
                                                :class="$store.proposalFlow.isDimmed('{{ $node['key'] }}') && 'opacity-30'"
                                                class="group flex min-h-8 items-center gap-2.5 rounded-sm transition-opacity duration-200 focus-visible:outline-2 focus-visible:outline-offset-4 focus-visible:outline-primary"
                                                data-flow-node="{{ $node['key'] }}"
                                            >
                                                <span data-flow-in="{{ $node['key'] }}" class="size-2.5 shrink-0 rounded-full border-2 border-secondary-dark bg-white dark:border-ink-muted dark:bg-surface-1"></span>
                                                <span class="line-clamp-2 text-sm leading-snug font-semibold underline-offset-4 group-hover:underline {{ $inkText }}">{{ $node['name'] }}</span>
                                            </a>
                                        </li>
                                    @empty
                                        <li class="ps-5 text-xs {{ $mutedText }}">{{ __('proposals.no_projects_linked') }}</li>
                                    @endforelse
                                </ul>
                            </div>
                        </div>

                        {{-- Narrow screens: the same chain as a stacked thread. --}}
                        <ul class="mt-4 flex flex-col gap-5 @3xl/directory:hidden" role="list">
                            @foreach ($graph['donors'] as $node)
                                <li wire:key="flow-stack-{{ $graph['currency'] }}-{{ $node['id'] }}">
                                    <button type="button" wire:click="toggleDonor({{ $node['id'] }})" aria-pressed="{{ $donor === (string) $node['id'] ? 'true' : 'false' }}" class="flex min-h-11 w-full items-center gap-2.5 text-start">
                                        <span class="size-3 shrink-0 rounded-full bg-funding" aria-hidden="true"></span>
                                        <span class="min-w-0 flex-1 truncate text-sm font-bold {{ $inkText }}">{{ $node['name'] }}</span>
                                        <span class="shrink-0 text-xs font-semibold tabular-nums {{ $mutedText }}">{{ $amount($node['total']) }} {{ $graph['currency'] }}</span>
                                    </button>
                                    <ul class="ms-1.5 flex flex-col border-s-2 border-funding/30 ps-4" role="list">
                                        @foreach ($graph['graph']['money'] as $ribbon)
                                            @if ($ribbon['from'] === $node['key'])
                                                @php
                                                    $target = collect($graph['proposals'])->firstWhere('key', $ribbon['to']);
                                                @endphp
                                                <li class="flex items-center gap-2 text-sm" wire:key="flow-stack-{{ $graph['currency'] }}-{{ $ribbon['from'] }}-{{ $ribbon['to'] }}">
                                                    <a href="{{ $proposalHref($target['id']) }}" class="min-w-0 flex-1 truncate py-2.5 font-semibold hover:underline {{ $inkText }}">{{ $target['name'] }}</a>
                                                    <span class="shrink-0 text-xs tabular-nums {{ $mutedText }}">{{ $amount($ribbon['amount']) }}</span>
                                                </li>
                                            @endif
                                        @endforeach
                                    </ul>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endforeach

                @if (count($this->flow['unfunded']) > 0)
                    <p class="mt-6 flex flex-wrap items-center gap-x-4 gap-y-1 border-t border-dashed pt-4 text-xs {{ $hairline }} {{ $mutedText }}" data-unfunded>
                        <span class="font-semibold">{{ __('proposals.not_yet_funded') }}</span>
                        @foreach ($this->flow['unfunded'] as $unfunded)
                            <a href="{{ $proposalHref($unfunded['id']) }}" class="inline-flex min-h-8 items-center gap-1.5 font-semibold hover:underline {{ $inkText }}" wire:key="unfunded-{{ $unfunded['id'] }}">
                                <span class="size-2 rounded-full border border-dashed border-secondary-medium dark:border-ink-faint" aria-hidden="true"></span>
                                {{ $unfunded['name'] }}
                            </a>
                        @endforeach
                    </p>
                @endif
            </section>
        @endif
