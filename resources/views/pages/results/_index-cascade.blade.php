{{-- The chain of statements, one panel per program. Shown when the page's view is cascade. --}}
        @foreach ($this->groupedRoots as $programName => $nodes)
            @php
                $groupStatements = collect($nodes)->sum(fn (array $node): int => $node['rollup']['statements']);
                $groupIndicators = collect($nodes)->sum(fn (array $node): int => $node['rollup']['indicators']);
                $groupTargets = collect($nodes)->sum(fn (array $node): int => $node['rollup']['targets']);
                $groupUnmeasured = collect($nodes)->sum(fn (array $node): int => $node['rollup']['unmeasured']);
                $groupProgram = collect($nodes)->map(fn (array $node) => $node['result']->program)->filter()->first();
            @endphp
            <x-ui.panel
                :title="$programName"
                :count="$groupStatements"
                :subtitle="trans_choice('programs.logframe_count_indicators', $groupIndicators, ['count' => $groupIndicators]).' · '.trans_choice('common.count_target', $groupTargets, ['count' => $groupTargets])"
                wire:key="result-group-{{ $loop->index }}-{{ Str::slug($programName) }}"
            >
                <x-slot:toolbar>
                    <div class="flex flex-wrap items-center gap-3">
                        <span @class([
                            'inline-flex items-center gap-1.5 rounded-full px-2.5 py-1 type-meta font-semibold',
                            'bg-status-warn-soft text-status-warn' => $groupUnmeasured > 0,
                            'bg-status-good-soft text-status-good' => $groupUnmeasured === 0,
                        ])>
                            <flux:icon :name="$groupUnmeasured > 0 ? 'exclamation-triangle' : 'check-circle'" variant="micro" class="size-3.5" aria-hidden="true" />
                            {{ trans_choice('programs.logframe_count_unmeasured', $groupUnmeasured, ['count' => $groupUnmeasured]) }}
                        </span>

                        @if ($groupProgram !== null)
                            <a href="{{ route('programs.show', $groupProgram) }}" wire:navigate class="type-meta font-semibold text-secondary-medium hover:text-primary dark:hover:text-brand-text hover:underline dark:text-ink-muted">
                                {{ __('common.view_name', ['name' => $groupProgram->name]) }}
                            </a>
                        @endif
                    </div>
                </x-slot:toolbar>

                <div class="space-y-5 bg-ground px-3 py-5 @2xl/directory:px-5 @2xl/directory:py-6">
                    @foreach ($nodes as $node)
                        <x-ui.framework-node :node="$node" :depth="0" :order="$loop->index" wire:key="framework-root-{{ $node['result']->id }}" />
                    @endforeach
                </div>
            </x-ui.panel>
        @endforeach
