{{--
    The Projects tab of proposals/show: link a project, and each funded
    project with its milestones and tasks. Included by ⚡show.blade.php,
    whose top PHP block defines $mutedText, $inkText, $hairline, $amount,
    $term, $chronology and $arrivals.
--}}
@use('App\Enums\TaskStatus')
            @can('update', $this->proposal)
            <form wire:submit="linkProject" class="mb-6 flex flex-col gap-2 @xl/detail:flex-row @xl/detail:items-end" data-link-project>
                <div class="min-w-0 flex-1">
                    <x-ui.select wire:model="projectToLink" :label="__('proposals.fund_another_project')">
                        <flux:select.option value="">{{ __('proposals.select_a_project') }}</flux:select.option>
                        @foreach ($this->linkableProjects as $linkable)
                            <flux:select.option :value="$linkable->id" :wire:key="'link-project-'.$linkable->id">{{ $linkable->name }}</flux:select.option>
                        @endforeach
                    </x-ui.select>
                </div>
                <x-ui.button type="submit" tone="primary" icon="link" class="min-h-10 touch-manipulation" :disabled="$this->linkableProjects->isEmpty()" data-test="link-project">{{ __('proposals.link_project') }}</x-ui.button>
            </form>
            @endcan

            @if ($this->projects->isEmpty())
                <x-ui.empty-state icon="rectangle-stack" :heading="__('proposals.no_projects_linked')" :body="__('proposals.projects_this_proposal_funds_will_appear_here')" />
            @else
                <div class="flex flex-col gap-8">
                    @foreach ($this->projects as $project)
                        @php
                            $projectTasks = $this->tasks->filter(fn (\App\Models\Task $task): bool => $task->sourceProjectId() === $project->id);
                            $projectDone = $projectTasks->where('status', TaskStatus::Done)->count();
                            // Work scheduled past the grant's own end has no funding behind it after that date.
                            $runsPastTheGrant = $project->end_date !== null
                                && $this->proposal->planned_date_end !== null
                                && $project->end_date->gt($this->proposal->planned_date_end);
                        @endphp
                        <article class="relative min-w-0 ps-6" wire:key="project-{{ $project->id }}" data-project="{{ $project->id }}">
                            {{-- The thread: one mark per funded project, filled once its milestones are all reached. --}}
                            <span class="absolute start-[0.1875rem] top-5 bottom-0 w-px bg-secondary-soft dark:bg-line-strong" aria-hidden="true"></span>
                            <span @class([
                                'absolute start-0 top-1.5 size-2.5 rounded-full',
                                'bg-secondary-dark dark:bg-ink-muted' => $project->milestones_count > 0 && $project->reached_milestones_count >= $project->milestones_count,
                                'border-2 border-secondary-dark bg-white dark:border-ink-muted dark:bg-surface-1' => $project->milestones_count === 0 || $project->reached_milestones_count < $project->milestones_count,
                            ]) aria-hidden="true"></span>

                            <div class="flex flex-wrap items-baseline justify-between gap-x-4 gap-y-1">
                                <div class="min-w-0">
                                    <a href="{{ route('projects.show', $project) }}" wire:navigate class="text-base font-bold underline-offset-4 hover:underline focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-secondary-medium {{ $inkText }}">{{ $project->name }}</a>
                                    <p class="mt-0.5 flex flex-wrap items-center gap-x-2 gap-y-0.5 text-xs {{ $mutedText }}">
                                        <span>{{ $project->program->name }}</span>
                                        <span aria-hidden="true">·</span>
                                        <span>{{ $project->city?->name ?? __('common.unknown_city') }}</span>
                                        @if ($project->start_date !== null || $project->end_date !== null)
                                            <span aria-hidden="true">·</span>
                                            <span class="tabular-nums">{{ __('proposals.planned_range', ['begin' => $project->start_date?->translatedFormat(__('formats.date')) ?? '—', 'end' => $project->end_date?->translatedFormat(__('formats.date')) ?? '—']) }}</span>
                                        @endif
                                        @if ($project->risks_count > 0)
                                            <span aria-hidden="true">·</span>
                                            {{-- Weight, not colour: HC red on this page means overdue, and it fails contrast on the dark ground anyway. --}}
                                            <span class="font-bold {{ $inkText }}">{{ trans_choice('common.count_risk', $project->risks_count, ['count' => $project->risks_count]) }}</span>
                                        @endif
                                    </p>
                                    {{-- A project running past the grant it is funded by is the fundraiser's problem, so it is said plainly.
                                         Tinted chip rather than bare red text: --color-primary on the dark ground reads at 1.14:1,
                                         while status-critical on its own soft field clears 4.5:1 in both themes. --}}
                                    @if ($runsPastTheGrant)
                                        <p class="mt-1.5 inline-flex items-center gap-1.5 rounded-md bg-status-critical-soft px-2 py-0.5 text-xs font-semibold text-status-critical" data-outruns-grant>
                                            <flux:icon.exclamation-triangle variant="micro" class="size-3.5 shrink-0" aria-hidden="true" />
                                            {{ __('proposals.runs_past_the_grant_term') }}
                                        </p>
                                    @endif
                                </div>

                                <div class="flex shrink-0 items-center gap-1">
                                    <x-ui.enum-badge :case="$project->status" />
                                    @can('update', $this->proposal)
                                    <x-ui.button tone="ghost" size="sm" icon="link-slash" class="size-9! touch-manipulation" wire:click="confirmUnlinkProject({{ $project->id }})" :aria-label="__('proposals.unlink_project_name', ['name' => $project->name])" data-test="unlink-project-{{ $project->id }}" />
                                    @endcan
                                </div>
                            </div>

                            {{-- Two measures on one line: milestones reached, tasks done. --}}
                            <div class="mt-3 grid gap-x-8 gap-y-3 @2xl/detail:grid-cols-2">
                                {{-- Both bars mean the same thing — finished against committed — so both fill in the same colour. --}}
                                @foreach ([
                                    ['key' => 'milestones', 'label' => __('common.milestones'), 'done' => $project->reached_milestones_count, 'total' => $project->milestones_count],
                                    ['key' => 'tasks', 'label' => __('common.tasks'), 'done' => $projectDone, 'total' => $projectTasks->count()],
                                ] as $measure)
                                    <div class="min-w-0" data-measure="{{ $measure['key'] }}">
                                        <p class="flex items-baseline justify-between gap-2 text-xs">
                                            <span class="type-label {{ $mutedText }}">{{ $measure['label'] }}</span>
                                            <span class="font-bold tabular-nums {{ $measure['total'] === 0 ? $mutedText : $inkText }}">{{ $measure['total'] === 0 ? '—' : $measure['done'].' / '.$measure['total'] }}</span>
                                        </p>
                                        @php
                                            $slots = min(max($measure['total'], 1), 20);
                                            $filled = $measure['total'] > 0 ? (int) round($measure['done'] / $measure['total'] * $slots) : 0;
                                        @endphp
                                        <div class="mt-1.5 flex h-1.5 w-full gap-0.5" aria-hidden="true">
                                            @for ($slot = 0; $slot < $slots; $slot++)
                                                <span @class(['h-full flex-1 rounded-full', 'bg-status-good' => $slot < $filled, 'bg-secondary-soft dark:bg-line-strong' => $slot >= $filled])></span>
                                            @endfor
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                        </article>
                    @endforeach
                </div>
            @endif
