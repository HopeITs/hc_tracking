        <x-ui.panel :title="__('projects.project_files')" :count="$this->project->media_count" :subtitle="__('projects.project_files_subtitle')">
            <x-slot:toolbar>
                @can('update', $this->project)<x-ui.button tone="primary" size="sm" icon="plus" wire:click="openCreateFileModal" data-test="add-project-file-button">{{ __('projects.add_file') }}</x-ui.button>@endcan
            </x-slot:toolbar>

            @if ($this->project->media->isEmpty())
                <x-ui.empty-state icon="paper-clip" :heading="__('common.no_files_recorded')" :body="__('projects.file_records_for_this_project_will_appear_here')">
                    <x-slot:action>
                        @can('update', $this->project)<x-ui.button size="sm" tone="primary" icon="plus" wire:click="openCreateFileModal">{{ __('projects.add_file') }}</x-ui.button>@endcan
                    </x-slot:action>
                </x-ui.empty-state>
            @else
                @if (count($this->fileCategoryCounts) > 2)
                    <div class="flex flex-wrap items-center gap-2 border-b border-secondary-soft p-3.5 dark:border-line">
                        @foreach ($this->fileCategoryCounts as $category)
                            @php $isActive = $fileCategoryFilter === $category['value']; @endphp
                            <button
                                type="button"
                                wire:click="$set('fileCategoryFilter', '{{ $category['value'] }}')"
                                wire:key="project-file-filter-{{ $category['value'] }}"
                                aria-pressed="{{ $isActive ? 'true' : 'false' }}"
                                @class([
                                    'inline-flex items-center gap-1.5 rounded-full border px-3 py-1.5 type-meta font-semibold transition-colors focus-visible:outline-2 focus-visible:outline-primary',
                                    'border-secondary-dark bg-secondary-dark text-white dark:border-ink dark:bg-ink dark:text-surface-0' => $isActive,
                                    'border-secondary-soft text-secondary-medium hover:border-secondary-light hover:text-secondary-dark dark:border-line dark:text-ink-muted dark:hover:text-ink' => ! $isActive,
                                ])
                            >
                                {{ $category['label'] }}
                                <span @class([
                                    'inline-flex min-w-5 items-center justify-center rounded-full px-1.5 py-px text-[10px] tabular-nums',
                                    'bg-white/20' => $isActive,
                                    'bg-secondary-soft dark:bg-surface-2' => ! $isActive,
                                ])>{{ $category['count'] }}</span>
                            </button>
                        @endforeach
                    </div>
                @endif

                <div class="grid gap-3 p-3.5 @lg/detail:grid-cols-2 @4xl/detail:grid-cols-3 @6xl/detail:p-5">
                    @forelse ($this->filteredProjectFiles as $media)
                        <x-ui.file-card :media="$media" :project="$this->project" :editable="auth()->user()->can('update', $this->project)" wire:key="project-file-{{ $media->id }}" />
                    @empty
                        <div class="col-span-full">
                            <x-ui.empty-state icon="funnel" :heading="__('projects.no_files_in_this_category')" />
                        </div>
                    @endforelse
                </div>
            @endif
        </x-ui.panel>
