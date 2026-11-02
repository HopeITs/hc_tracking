{{--
    One project's actions in the list and timeline views of projects/index: the
    eye that opens it, a Blocked badge when its owner declined, and every other
    action folded into one "⋯" menu. Expects $project.
--}}
@use('App\Enums\ProjectStatus')
@php
    $canEditProject = auth()->user()?->can('update', $project) ?? false;
    $canArchiveProject = auth()->user()?->can('delete', $project) ?? false;
@endphp

<div class="dk-pjl-acts">
    <x-ui.blocked-badge :project="$project" />

    <a href="{{ route('projects.show', $project) }}" wire:navigate class="dk-iconbtn dk-sm" aria-label="{{ __('projects.open_project', ['name' => $project->name]) }}" title="{{ __('projects.open') }}">
        <flux:icon.eye class="size-4.5" />
    </a>

    @if ($canEditProject || $canArchiveProject)
        <span class="dk-pmenu" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape="open = false">
            <button type="button" class="dk-iconbtn dk-sm" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="menu" aria-label="{{ __('projects.more_actions_for', ['name' => $project->name]) }}">
                <x-desk.icon name="more" />
            </button>

            <span class="dk-pop dk-pjl-menu" role="menu" x-show="open" x-cloak x-on:click="open = false">
                @if ($canEditProject)
                    <button type="button" role="menuitem" wire:click="openEditModal({{ $project->id }})" aria-label="{{ __('common.edit_name', ['name' => $project->name]) }}">
                        <flux:icon.pencil-square variant="micro" class="size-4" aria-hidden="true" />{{ __('projects.edit') }}
                    </button>
                @endif

                @if ($canArchiveProject)
                    @if ($project->status === ProjectStatus::Archived)
                        <button type="button" role="menuitem" wire:click="restoreProject({{ $project->id }})" aria-label="{{ __('projects.restore_name', ['name' => $project->name]) }}">
                            <flux:icon.arrow-uturn-left variant="micro" class="size-4" aria-hidden="true" />{{ __('projects.restore') }}
                        </button>
                    @else
                        <button type="button" role="menuitem" wire:click="confirmArchive({{ $project->id }})" aria-label="{{ __('projects.archive_name', ['name' => $project->name]) }}">
                            <flux:icon.archive-box variant="micro" class="size-4" aria-hidden="true" />{{ __('projects.archive_project') }}
                        </button>
                    @endif
                @endif
            </span>
        </span>
    @endif
</div>
