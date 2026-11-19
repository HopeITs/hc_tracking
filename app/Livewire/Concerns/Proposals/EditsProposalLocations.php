<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\Proposals;

use App\Actions\Targets\ClearLocationTargets;
use App\Models\HrCenter;
use App\Models\HrCity;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use App\Support\TargetCounts;
use Flux\Flux;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Locked;

/**
 * The location rows of the proposal form (W-34a slice 3): where a proposal
 * runs, as city, program (or a new program request), responsible and one or
 * more centers. They replace the project picker and go to SaveProposal as
 * `locations`, which validates and writes them.
 *
 * A row that already stands for a linked project shows its city and program
 * locked (the link owns them, ProposalLocationRows matches rows by both and
 * never deletes a linked row); only its responsible and centers change here,
 * also on an approved proposal, which is how centers are given to the
 * proposals entered before the rows existed.
 *
 * Used by ManagesProposalForm, which provides `editingProposalId`.
 *
 * @property ?int $editingProposalId
 * @property-read bool $removingLocationDeletesTargets
 * @property-read string $locationTargetsUrl
 */
trait EditsProposalLocations
{
    /** The program select's value for "a program HR does not have yet". */
    public const NewProgramValue = 'new';

    /**
     * The rows as the form edits them. `key` only keeps wire:key stable and
     * `project` names the linked project of a row that has one; neither is
     * sent.
     *
     * @var list<array{key: string, city_id: string, program_id: string, responsible_employee_id: string, center_ids: list<string>, city_wide: bool, new_program_name_en: string, new_program_name_ar: string, project: string|null, location_id: int|null}>
     */
    public array $locations = [];

    /**
     * Whether the edited proposal had rows when the form opened. An edit of a
     * proposal without rows sends none until the user adds one, so saving its
     * other details is never refused for the missing place.
     */
    #[Locked]
    public bool $hadLocationRows = false;

    /**
     * Y-10: removing a saved row that carries targets asks first. The dialog
     * names how many, offers to delete them (a holder of `targets.delete`,
     * none reported) or to open them, and the row stays until then.
     */
    public bool $showLocationTargetsModal = false;

    #[Locked]
    public ?int $removingLocationIndex = null;

    /**
     * @var array{count: int, reported: int}
     */
    #[Locked]
    public array $removingLocationTargets = ['count' => 0, 'reported' => 0];

    /**
     * Active HR cities for the row's city select.
     *
     * @return Collection<int, HrCity>
     */
    #[Computed]
    public function locationCityOptions(): Collection
    {
        return HrCity::query()->where('is_active', true)->select(['id', 'name_en', 'name_ar'])->orderBy(HrCity::nameColumn())->orderBy('id')->get();
    }

    /**
     * Programs for the row's program select.
     *
     * @return Collection<int, Program>
     */
    #[Computed]
    public function locationProgramOptions(): Collection
    {
        return Program::options();
    }

    /**
     * Active centers by city id, so each row offers the centers of its own
     * city only. A center HR has since deactivated stays on a row that holds
     * it, with its id, so the row reads true.
     *
     * @return array<int, list<array{id: int, name: string}>>
     */
    #[Computed]
    public function locationCentersByCity(): array
    {
        $byCity = [];

        foreach (HrCenter::options() as $center) {
            $byCity[(int) $center->city_id][] = ['id' => (int) $center->id, 'name' => $center->name];
        }

        foreach ($this->locations as $row) {
            foreach ($row['center_ids'] as $centerId) {
                $held = HrCenter::remembered((int) $centerId);

                // Filed under the center's own city, so a row that moves city never keeps it.
                if ($held === null || in_array((int) $centerId, array_column($byCity[(int) $held->city_id] ?? [], 'id'), true)) {
                    continue;
                }

                $byCity[(int) $held->city_id][] = ['id' => (int) $centerId, 'name' => $held->name];
            }
        }

        return $byCity;
    }

    /**
     * Append an empty row and move focus to it.
     */
    public function addLocationRow(): void
    {
        $this->locations[] = $this->blankLocationRow();

        $this->dispatch('proposal-location-focus', index: count($this->locations) - 1);
    }

    /**
     * Remove a row. A row that stands for a linked project stays: only
     * unlinking the project on the Projects tab removes it.
     */
    public function removeLocationRow(int $index): void
    {
        if (! isset($this->locations[$index]) || $this->locations[$index]['project'] !== null) {
            return;
        }

        $row = $this->savedLocationRow($index);
        $counts = $row === null ? ['count' => 0, 'reported' => 0] : TargetCounts::ofLocation($row);

        if ($counts['count'] > 0) {
            $this->removingLocationIndex = $index;
            $this->removingLocationTargets = $counts;
            unset($this->removingLocationDeletesTargets);
            $this->showLocationTargetsModal = true;

            return;
        }

        $this->dropLocationRow($index);
    }

    /**
     * Whether the dialog offers to delete the row's targets: none reported,
     * and the user may delete targets (D-23).
     */
    #[Computed]
    public function removingLocationDeletesTargets(): bool
    {
        return $this->removingLocationTargets['count'] > 0 && $this->removingLocationTargets['reported'] === 0 && Gate::allows('targets.delete');
    }

    /**
     * "Delete the targets and remove the location": the targets go now
     * (ClearLocationTargets), the row leaves the form and is removed when
     * the proposal is saved.
     */
    public function clearRemovingLocationTargets(): void
    {
        Gate::authorize('targets.delete');

        $index = $this->removingLocationIndex;
        $row = $index === null ? null : $this->savedLocationRow($index);

        if ($row === null || $this->editingProposalId === null) {
            $this->closeLocationTargetsModal();

            return;
        }

        $deleted = app(ClearLocationTargets::class)->handle(Proposal::query()->findOrFail($this->editingProposalId), $row->id);

        $this->closeLocationTargetsModal();
        $this->dropLocationRow($index);

        Flux::toast(variant: 'success', text: trans_choice('proposals.location_targets_cleared', $deleted, ['count' => $deleted]));
    }

    /**
     * The edited proposal's targets tab, which the dialog opens in a new tab.
     */
    #[Computed]
    public function locationTargetsUrl(): string
    {
        $proposal = Proposal::query()->findOrFail($this->editingProposalId);

        return route('proposals.show', ['proposal' => $proposal, 'tab' => 'targets']);
    }

    public function closeLocationTargetsModal(): void
    {
        $this->showLocationTargetsModal = false;
        $this->removingLocationIndex = null;
        $this->removingLocationTargets = ['count' => 0, 'reported' => 0];
        unset($this->removingLocationDeletesTargets);
    }

    /**
     * The saved row behind a form row, loaded through the edited proposal
     * (BOLA): a new row, or an id that is not this proposal's, gives null.
     */
    private function savedLocationRow(int $index): ?ProposalLocation
    {
        $locationId = $this->locations[$index]['location_id'] ?? null;

        if ($locationId === null || $locationId === '' || $this->editingProposalId === null) {
            return null;
        }

        return ProposalLocation::query()
            ->where('proposal_id', $this->editingProposalId)
            ->whereKey((int) $locationId)
            ->first();
    }

    /**
     * Take a row off the form; it is removed from the proposal on save.
     */
    private function dropLocationRow(int $index): void
    {
        $this->locations = array_values(array_filter($this->locations, fn (int $key): bool => $key !== $index, ARRAY_FILTER_USE_KEY));
        $this->resetValidation();

        // Focus lands on the row above, or on the first row when the first one went.
        $this->dispatch('proposal-location-focus', index: max(0, $index - 1));
    }

    /**
     * Keep each row coherent as it is edited: a new city drops the centers of
     * the old one, a chosen program proposes its manager as the responsible
     * when none is set, and a program from the list clears the typed names.
     */
    public function updatedLocations(mixed $value, string $path): void
    {
        [$index, $field] = array_pad(explode('.', $path, 2), 2, null);

        if (! ctype_digit((string) $index) || ! isset($this->locations[(int) $index])) {
            return;
        }

        $index = (int) $index;
        $row = $this->locations[$index];

        if ($field === 'city_id') {
            $allowed = array_map('strval', array_column($this->locationCentersByCity()[(int) $row['city_id']] ?? [], 'id'));
            $row['center_ids'] = array_values(array_intersect($row['center_ids'], $allowed));
        }

        if ($field === 'city_wide') {
            $row['city_wide'] = (bool) $row['city_wide'];

            if ($row['city_wide']) {
                $row['center_ids'] = [];
            }
        }

        if ($field === 'program_id' && $row['program_id'] !== self::NewProgramValue) {
            $row['new_program_name_en'] = '';
            $row['new_program_name_ar'] = '';

            $managerId = ctype_digit($row['program_id'])
                ? Program::query()->whereKey((int) $row['program_id'])->value('manager_id')
                : null;

            if ($row['responsible_employee_id'] === '' && is_numeric($managerId)) {
                $row['responsible_employee_id'] = (string) $managerId;
            }
        }

        $this->locations = array_values(array_replace($this->locations, [$index => $row]));
    }

    /**
     * Fill the rows from the edited proposal, its program requests included.
     */
    protected function loadLocationRows(int $proposalId): void
    {
        $rows = ProposalLocation::query()
            ->where('proposal_id', $proposalId)
            ->with(['centers', 'programRequest', 'project:id,name'])
            ->orderBy('sort')
            ->orderBy('id')
            ->get();

        $this->locations = array_values($rows->map(fn (ProposalLocation $row): array => [
            'key' => Str::random(8),
            'city_id' => (string) $row->city_id,
            'program_id' => $row->program_id !== null ? (string) $row->program_id : ($row->is_new ? self::NewProgramValue : ''),
            'responsible_employee_id' => $row->responsible_employee_id !== null ? (string) $row->responsible_employee_id : '',
            'center_ids' => array_map('strval', $row->centerIds()),
            'city_wide' => (bool) $row->city_wide,
            'new_program_name_en' => $row->programRequest->name_en ?? '',
            'new_program_name_ar' => $row->programRequest->name_ar ?? '',
            'project' => $row->project?->name,
            'location_id' => $row->id,
        ])->all());

        $this->hadLocationRows = $this->locations !== [];
    }

    /**
     * Start a form with one empty row, without moving focus off the first field.
     */
    protected function startLocationRows(): void
    {
        $this->locations = [$this->blankLocationRow()];
    }

    protected function resetLocationRows(): void
    {
        $this->locations = [];
        $this->hadLocationRows = false;
        $this->closeLocationTargetsModal();
    }

    /**
     * The rows as SaveProposal's `locations`. A row left completely empty
     * (the one a create starts with, say) is not a place and is not sent.
     *
     * @return list<array{city_id: string, program_id: string|null, responsible_employee_id: string|null, center_ids: list<string>, city_wide: bool, is_new: bool, new_program_name_en: string, new_program_name_ar: string}>
     */
    protected function locationPayload(): array
    {
        return array_values(array_map(fn (array $row): array => [
            'city_id' => $row['city_id'],
            'program_id' => $row['program_id'] === '' || $row['program_id'] === self::NewProgramValue ? null : $row['program_id'],
            'responsible_employee_id' => $row['responsible_employee_id'] === '' ? null : $row['responsible_employee_id'],
            'center_ids' => $row['city_wide'] ? [] : $row['center_ids'],
            'city_wide' => $row['city_wide'],
            'is_new' => $row['program_id'] === self::NewProgramValue,
            'new_program_name_en' => $row['program_id'] === self::NewProgramValue ? $row['new_program_name_en'] : '',
            'new_program_name_ar' => $row['program_id'] === self::NewProgramValue ? $row['new_program_name_ar'] : '',
        ], array_filter($this->locations, fn (array $row): bool => ! $this->isBlankLocationRow($row))));
    }

    /**
     * Whether any rows other than empty ones are on the form.
     */
    protected function hasLocationRows(): bool
    {
        return array_filter($this->locations, fn (array $row): bool => ! $this->isBlankLocationRow($row)) !== [];
    }

    /**
     * @param  array{city_id: string, program_id: string, responsible_employee_id: string, center_ids: list<string>, city_wide: bool, new_program_name_en: string, new_program_name_ar: string, project: string|null, location_id?: int|null}  $row
     */
    private function isBlankLocationRow(array $row): bool
    {
        return $row['project'] === null
            && $row['city_id'] === ''
            && $row['program_id'] === ''
            && $row['responsible_employee_id'] === ''
            && $row['center_ids'] === []
            && ! $row['city_wide'];
    }

    /**
     * @return array{key: string, city_id: string, program_id: string, responsible_employee_id: string, center_ids: list<string>, city_wide: bool, new_program_name_en: string, new_program_name_ar: string, project: null, location_id: null}
     */
    private function blankLocationRow(): array
    {
        return [
            'key' => Str::random(8),
            'city_id' => '',
            'program_id' => '',
            'responsible_employee_id' => '',
            'center_ids' => [],
            'city_wide' => false,
            'new_program_name_en' => '',
            'new_program_name_ar' => '',
            'project' => null,
            'location_id' => null,
        ];
    }

    /**
     * Start the form with one row taken from a project (New Concept Note on
     * the project page): its city, program, responsible and center. The
     * project is not linked; the row is a place like any other.
     */
    protected function seedLocationRowFromProject(Project $project): void
    {
        $row = $this->blankLocationRow();
        $row['city_id'] = (string) $project->city_id;
        $row['program_id'] = (string) $project->program_id;
        $row['responsible_employee_id'] = $project->user_id !== null ? (string) $project->user_id : '';
        $row['center_ids'] = $project->center_id !== null ? [(string) $project->center_id] : [];

        $this->locations = [$row];
    }
}
