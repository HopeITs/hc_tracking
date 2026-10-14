<?php

use App\Actions\Supervisions\AddSupervision;
use App\Actions\Supervisions\RemoveSupervision;
use App\Enums\Locale;
use App\Models\HrEmployee;
use App\Models\Supervision;
use App\Notifications\EmployeeRecipients;
use App\Support\EmployeeProfile;
use Flux\Flux;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;
use Livewire\Component;

/*
 * The Supervision tab of Users & Roles (T23, contracts §10, D-13): the HR
 * direct-manager relations, read-only, and the local exceptions General
 * Manager adds or removes. Supervision routes reminders and escalations; it
 * grants no permission. Every method re-checks supervisions.manage.
 */
new class extends Component {
    public string $search = '';

    public bool $showAddModal = false;

    public string $supervisorEmployeeId = '';

    public string $superviseeEmployeeId = '';

    public bool $showRemoveModal = false;

    #[Locked]
    public ?int $removingId = null;

    public function mount(): void
    {
        Gate::authorize('manage', Supervision::class);
    }

    /**
     * Relations grouped by supervisor, sorted by name, filtered by the search.
     *
     * @return list<array{id: int, name: string, inactive: bool, rows: list<array{supervisee: int, name: string, inactive: bool, fromHr: bool, supervisionId: int|null}>}>
     */
    #[Computed]
    public function groups(): array
    {
        $active = array_fill_keys(array_map(intval(...), HrEmployee::query()->toBase()->where('is_active', true)->pluck('id')->all()), true);

        /** @var list<array{supervisor: int, supervisee: int, supervisionId: int|null}> $pairs */
        $pairs = [];

        foreach (array_keys($active) as $employeeId) {
            $managerId = EmployeeProfile::managerOf($employeeId);

            if ($managerId !== null && $managerId !== $employeeId && isset($active[$managerId])) {
                $pairs[] = ['supervisor' => $managerId, 'supervisee' => $employeeId, 'supervisionId' => null];
            }
        }

        foreach (Supervision::query()->orderBy('id')->get(['id', 'supervisor_employee_id', 'supervisee_employee_id']) as $supervision) {
            $pairs[] = ['supervisor' => $supervision->supervisor_employee_id, 'supervisee' => $supervision->supervisee_employee_id, 'supervisionId' => $supervision->id];
        }

        $locale = Locale::current()->value;
        $names = app(EmployeeRecipients::class)->names(array_merge(array_column($pairs, 'supervisor'), array_column($pairs, 'supervisee')));
        $nameOf = fn (int $id): string => $names[$id][$locale] ?? '#'.$id;
        $search = Str::lower(trim($this->search));
        $groups = [];

        foreach ($pairs as $pair) {
            $haystack = Str::lower(implode(' ', [...array_values($names[$pair['supervisor']] ?? []), ...array_values($names[$pair['supervisee']] ?? [])]));

            if ($search !== '' && ! str_contains($haystack, $search)) {
                continue;
            }

            $groups[$pair['supervisor']] ??= ['id' => $pair['supervisor'], 'name' => $nameOf($pair['supervisor']), 'inactive' => ! isset($active[$pair['supervisor']]), 'rows' => []];
            $groups[$pair['supervisor']]['rows'][] = [
                'supervisee' => $pair['supervisee'],
                'name' => $nameOf($pair['supervisee']),
                'inactive' => ! isset($active[$pair['supervisee']]),
                'fromHr' => $pair['supervisionId'] === null,
                'supervisionId' => $pair['supervisionId'],
            ];
        }

        foreach ($groups as &$group) {
            usort($group['rows'], fn (array $first, array $second): int => strcasecmp($first['name'], $second['name']));
        }
        unset($group);

        usort($groups, fn (array $first, array $second): int => strcasecmp($first['name'], $second['name']));

        return $groups;
    }

    /**
     * The names shown in the remove confirmation.
     *
     * @return array{supervisor: string, supervisee: string}
     */
    #[Computed]
    public function removing(): array
    {
        $supervision = $this->removingId === null ? null : Supervision::query()->find($this->removingId);

        if ($supervision === null) {
            return ['supervisor' => '', 'supervisee' => ''];
        }

        $locale = Locale::current()->value;
        $names = app(EmployeeRecipients::class)->names([$supervision->supervisor_employee_id, $supervision->supervisee_employee_id]);

        return [
            'supervisor' => $names[$supervision->supervisor_employee_id][$locale] ?? '',
            'supervisee' => $names[$supervision->supervisee_employee_id][$locale] ?? '',
        ];
    }

    public function openAddModal(): void
    {
        Gate::authorize('manage', Supervision::class);

        $this->reset('supervisorEmployeeId', 'superviseeEmployeeId');
        $this->resetValidation();
        $this->showAddModal = true;
    }

    public function closeAddModal(): void
    {
        $this->showAddModal = false;
        $this->reset('supervisorEmployeeId', 'superviseeEmployeeId');
        $this->resetValidation();
    }

    public function addSupervision(AddSupervision $addSupervision): void
    {
        Gate::authorize('manage', Supervision::class);

        $this->validate([
            'supervisorEmployeeId' => ['required', 'integer'],
            'superviseeEmployeeId' => ['required', 'integer'],
        ], [
            'supervisorEmployeeId.*' => __('users.supervision_choose_an_active_employee'),
            'superviseeEmployeeId.*' => __('users.supervision_choose_an_active_employee'),
        ]);

        $addSupervision->handle((int) $this->supervisorEmployeeId, (int) $this->superviseeEmployeeId);

        $this->closeAddModal();
        unset($this->groups);

        Flux::toast(variant: 'success', text: __('users.supervision_added'));
    }

    public function confirmRemove(int $supervisionId): void
    {
        Gate::authorize('manage', Supervision::class);

        $this->removingId = $supervisionId;
        $this->showRemoveModal = true;
    }

    public function closeRemoveModal(): void
    {
        $this->showRemoveModal = false;
        $this->removingId = null;
    }

    public function removeSupervision(RemoveSupervision $removeSupervision): void
    {
        Gate::authorize('manage', Supervision::class);

        $removed = $this->removingId !== null && $removeSupervision->handle($this->removingId);

        $this->closeRemoveModal();
        unset($this->groups);

        Flux::toast(
            variant: $removed ? 'success' : 'warning',
            text: $removed ? __('users.supervision_removed') : __('users.supervision_already_removed'),
        );
    }
}; ?>

<div class="grid min-w-0 gap-5" data-supervision-tab>
    <div class="flex flex-wrap items-end justify-between gap-4">
        <p class="max-w-2xl text-sm leading-6 text-secondary-medium dark:text-ink-muted">{{ __('users.supervision_intro') }}</p>
        <x-ui.button tone="primary" icon="plus" wire:click="openAddModal" data-test="add-supervision-button">{{ __('users.supervision_add') }}</x-ui.button>
    </div>

    <div class="max-w-md">
        <x-ui.input id="supervision-search" type="search" icon="magnifying-glass" :label="__('users.supervision_search')" :placeholder="__('users.supervision_search')" wire:model.live.debounce.350ms="search" clearable />
    </div>

    @if ($this->groups === [])
        <x-ui.empty-state
            icon="users"
            :heading="$search === '' ? __('users.supervision_empty') : __('users.supervision_no_match')"
            :body="$search === '' ? __('users.supervision_empty_body') : null"
        />
    @else
        <div class="grid min-w-0 gap-x-10 gap-y-8 @3xl/directory:grid-cols-2">
            @foreach ($this->groups as $group)
                <section class="min-w-0" wire:key="supervisor-{{ $group['id'] }}" aria-labelledby="supervisor-{{ $group['id'] }}-name">
                    <header class="flex items-baseline gap-2.5">
                        <h3 id="supervisor-{{ $group['id'] }}-name" class="truncate text-base font-bold text-secondary-dark dark:text-ink">{{ $group['name'] }}</h3>
                        <span class="text-xs font-semibold tabular-nums text-secondary-light dark:text-ink-muted">{{ count($group['rows']) }}</span>
                        @if ($group['inactive'])
                            <span class="inline-flex items-center gap-1 text-xs font-semibold text-secondary-medium dark:text-ink-muted">
                                <flux:icon.no-symbol variant="micro" class="size-3.5" aria-hidden="true" />
                                {{ __('users.supervision_inactive') }}
                            </span>
                        @endif
                    </header>

                    <ul class="ms-1.5 mt-2 border-s border-secondary-soft ps-4 dark:border-line" role="list">
                        @foreach ($group['rows'] as $row)
                            <li
                                class="relative flex min-h-11 items-center gap-3 py-1.5"
                                wire:key="supervision-{{ $group['id'] }}-{{ $row['supervisee'] }}-{{ $row['supervisionId'] ?? 'hr' }}"
                                @if ($row['fromHr']) data-supervision-hr="{{ $group['id'] }}-{{ $row['supervisee'] }}" @endif
                            >
                                <span @class([
                                    'absolute -start-[1.3rem] top-1/2 size-2 -translate-y-1/2 rounded-full ring-2 ring-white dark:ring-surface-0',
                                    'bg-secondary-light dark:bg-ink-muted' => $row['fromHr'],
                                    'bg-primary dark:bg-brand' => ! $row['fromHr'],
                                ]) aria-hidden="true"></span>

                                <span class="min-w-0 flex-1 truncate text-sm text-secondary-dark dark:text-ink">{{ $row['name'] }}</span>

                                @if ($row['inactive'])
                                    <span class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-secondary-medium dark:text-ink-muted">
                                        <flux:icon.no-symbol variant="micro" class="size-3.5" aria-hidden="true" />
                                        {{ __('users.supervision_inactive') }}
                                    </span>
                                @endif

                                @if ($row['fromHr'])
                                    <span class="inline-flex shrink-0 items-center gap-1 text-xs font-semibold text-secondary-medium dark:text-ink-muted" title="{{ __('users.supervision_hr_read_only') }}">
                                        <flux:icon.building-office variant="micro" class="size-3.5" aria-hidden="true" />
                                        {{ __('users.supervision_from_hr') }}
                                    </span>
                                @else
                                    <x-ui.button tone="ghost" size="sm" wire:click="confirmRemove({{ $row['supervisionId'] }})" data-test="remove-supervision-{{ $row['supervisionId'] }}">
                                        {{ __('users.supervision_remove') }}
                                    </x-ui.button>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </section>
            @endforeach
        </div>
    @endif

    <x-ui.modal
        wire:model="showAddModal"
        size="md"
        :title="__('users.supervision_add')"
        :description="__('users.supervision_add_description')"
        submit="addSupervision"
        close="closeAddModal"
        required-note
    >
        @if ($showAddModal)
            <div class="grid gap-y-4">
                <x-ui.form-select wire:model="supervisorEmployeeId" :label="__('users.supervision_supervisor')" :placeholder="__('users.supervision_select_employee')" required employees />
                <x-ui.form-select wire:model="superviseeEmployeeId" :label="__('users.supervision_supervisee')" :placeholder="__('users.supervision_select_employee')" required employees />
            </div>
        @endif

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-supervision-button">{{ __('users.supervision_add') }}</x-ui.button>
        </x-slot:actions>
    </x-ui.modal>

    <x-ui.confirm-modal
        wire:model="showRemoveModal"
        close="closeRemoveModal"
        :title="__('users.supervision_remove_title')"
        :message="__('users.supervision_remove_message', $this->removing)"
        confirm="removeSupervision"
        :confirm-label="__('users.supervision_remove')"
        confirm-test="confirm-remove-supervision-button"
        tone="neutral"
    />
</div>
