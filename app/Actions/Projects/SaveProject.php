<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Jobs\CheckProgramLogframe;
use App\Models\HrCenter;
use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\Program;
use App\Models\Project;
use App\Models\User;
use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Create or update a project from the project form (contracts.md §3, §6).
 *
 * A new project is always a `draft` without dates: dates are `prohibited`
 * while the project is a draft and arrive with the approval (T08). The
 * status is never an input; an edit keeps it. A project runs from an HR
 * center (D-30): the city is the center's and is written here. A project in
 * a city with no center is instead hosted by another center (D-55, W-01):
 * it keeps the city sent with it, `center_id` stays null and
 * `host_center_id` names the host. One or the other, never both. Only one
 * open project may hold a (center ?? city, program) pair, checked by
 * Project::openProjectFor() under the program's row lock.
 *
 * The keys are camelCase so a ValidationException maps onto the Livewire
 * properties of the form.
 */
final class SaveProject
{
    /**
     * `startDate` and `endDate` are only accepted for a project that is no
     * longer a draft. `name` may be blank on create: it defaults to
     * «Program — Center». `sectorIds` (T34) is required on create; on edit
     * the sectors change only when the key is sent.
     *
     * `cityId` is read only when no center is sent (a hosted project); with a
     * center the city is the center's. `hostCenterId` is refused beside a
     * center and forced to null once a center is set.
     *
     * @param  array{name?: ?string, description?: ?string, startDate?: ?string, endDate?: ?string, targetBeneficiaries?: int|string|null, targetMen?: int|string|null, targetWomen?: int|string|null, targetBoys?: int|string|null, targetGirls?: int|string|null, centerId?: int|string|null, hostCenterId?: int|string|null, cityId?: int|string|null, programId?: int|string|null, userId?: int|string|null, sectorIds?: list<int|string>}  $data
     */
    public function handle(?Project $existing, array $data, User $actor): Project
    {
        $existing === null
            ? Gate::forUser($actor)->authorize('create', Project::class)
            : Gate::forUser($actor)->authorize('update', $existing);

        $input = $this->normalize($data);
        $programId = $this->positiveInteger($input['programId']);

        $saved = DB::transaction(function () use ($existing, $input, $actor, $programId): Project {
            // The program lock comes before any project row lock, as
            // openProjectFor() and ReopenProjectAsDraft take them.
            if ($programId !== null) {
                Program::query()->whereKey($programId)->lockForUpdate()->value('id');
            }

            $project = $existing === null
                ? new Project
                : Project::query()->with('ownerAssignment')->whereKey($existing->getKey())->lockForUpdate()->firstOrFail();

            if ($existing !== null) {
                Gate::forUser($actor)->authorize('update', $project);
            }

            $mayReassign = ! $project->exists || Gate::forUser($actor)->allows('reassign', $project);
            $validated = $this->validate($input, $project, $mayReassign);

            $center = $validated['centerId'] === null ? null : HrCenter::remembered((int) $validated['centerId']);
            $hostCenterId = $center === null ? $this->positiveInteger($validated['hostCenterId']) : null;
            $cityId = $this->positiveInteger($validated['cityId']);

            if (! $project->exists) {
                $project->status = ProjectStatus::Draft;
            }

            $project->fill([
                'name' => $validated['name'],
                'description' => $validated['description'],
                'target_beneficiaries' => $this->nullableInteger($validated['targetBeneficiaries']),
                'target_men' => $this->nullableInteger($validated['targetMen']),
                'target_women' => $this->nullableInteger($validated['targetWomen']),
                'target_boys' => $this->nullableInteger($validated['targetBoys']),
                'target_girls' => $this->nullableInteger($validated['targetGirls']),
                'center_id' => $center?->id,
                'host_center_id' => $hostCenterId,
                'city_id' => $center !== null ? $center->city_id : ($cityId ?? $project->city_id),
                'program_id' => $mayReassign ? (int) $validated['programId'] : $project->program_id,
                'user_id' => $mayReassign ? (int) $validated['userId'] : $project->user_id,
            ]);

            if ($project->status !== ProjectStatus::Draft) {
                $project->fill(['start_date' => $validated['startDate'], 'end_date' => $validated['endDate']]);
            }

            $this->refuseSecondOpenProject($project);

            $project->save();

            // A new project needs at least one sector (D-47); an edit syncs
            // only when the caller sends the sectors, so a caller that does
            // not know about them never clears them.
            if ($existing === null || $input['sectorIds'] !== null) {
                app(SyncProjectSectors::class)->handle($project, $input['sectorIds'] ?? [], requireOne: $existing === null);
            }

            return $project;
        });

        if ($saved->wasRecentlyCreated || $saved->wasChanged('program_id')) {
            CheckProgramLogframe::dispatch([(int) $saved->program_id])->afterCommit();
        }

        $existing?->refresh();

        return $saved;
    }

    /**
     * Trim the text, turn blanks into null and fill the keys the rules read.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function normalize(array $data): array
    {
        $blank = fn (mixed $value): mixed => is_string($value) && trim($value) === '' ? null : (is_string($value) ? trim($value) : $value);

        $keys = ['name', 'description', 'startDate', 'endDate', 'targetBeneficiaries', 'targetMen', 'targetWomen', 'targetBoys', 'targetGirls', 'centerId', 'hostCenterId', 'cityId', 'programId', 'userId'];

        $normalized = array_combine($keys, array_map(fn (string $key): mixed => $blank($data[$key] ?? null), $keys));
        $normalized['sectorIds'] = array_key_exists('sectorIds', $data) ? array_values((array) $data['sectorIds']) : null;

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(array $input, Project $project, bool $mayReassign): array
    {
        $isDraft = ! $project->exists || $project->status === ProjectStatus::Draft;
        $mustHoldDates = $project->exists && in_array($project->status, [ProjectStatus::Approved, ProjectStatus::Active], true);
        $savedCenterId = $project->exists ? $project->center_id : null;
        // A placement (center or host) is required on create, and on edit once
        // the project has one; a legacy row with neither stays editable until W-02.
        $placementRequired = ! $project->exists || $savedCenterId !== null || $project->host_center_id !== null;
        $isHosted = $input['centerId'] === null && $input['hostCenterId'] !== null;

        $input['name'] ??= $project->exists ? null : $this->defaultName($input);

        $rules = [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'startDate' => $isDraft ? ['prohibited'] : [Rule::when($mustHoldDates, ['required'], ['nullable']), 'date'],
            'endDate' => $isDraft ? ['prohibited'] : [Rule::when($mustHoldDates, ['required'], ['nullable']), 'date', Rule::when($input['startDate'] !== null, ['after_or_equal:startDate'])],
            'targetBeneficiaries' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'targetMen' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'targetWomen' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'targetBoys' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'targetGirls' => ['nullable', 'integer', 'min:0', 'max:4294967295'],
            'centerId' => [
                Rule::when($placementRequired, ['required_without:hostCenterId'], ['nullable']),
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($savedCenterId): void {
                    $center = HrCenter::remembered((int) $value);

                    if ($center === null || (! $center->is_active && $center->id !== $savedCenterId)) {
                        $fail(__('projects.choose_the_center'));
                    }
                },
            ],
            'hostCenterId' => [
                'nullable',
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($input): void {
                    if ($input['centerId'] !== null) {
                        $fail((string) $input['centerId'] === (string) $value ? __('projects.cannot_host_own_center') : __('projects.choose_center_or_host'));

                        return;
                    }

                    $host = HrCenter::remembered((int) $value);

                    if ($host === null || ! $host->is_active) {
                        $fail(__('projects.choose_the_center'));
                    }
                },
            ],
            'cityId' => [
                Rule::when($isHosted, ['required'], ['nullable']),
                'integer',
                Rule::exists(HrCity::class, 'id'),
            ],
            'programId' => $mayReassign
                ? ['required', 'integer', Rule::exists(Program::class, 'id')]
                : ['nullable'],
            'userId' => $mayReassign
                ? ['required', 'integer', Rule::exists(HrEmployee::class, 'id'), Rule::in($this->ownerCandidates($project))]
                : ['nullable'],
            // Reported with the other fields; SyncProjectSectors checks it again
            // (and which sectors are usable) under the project lock.
            'sectorIds' => $project->exists ? ['nullable', 'array'] : ['required', 'array', 'min:1'],
        ];

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($input, $rules, [
            'name.required' => __('projects.enter_a_project_name'),
            'startDate.prohibited' => __('projects.dates_prohibited_draft'),
            'endDate.prohibited' => __('projects.dates_prohibited_draft'),
            'startDate.required' => __('projects.dates_required'),
            'endDate.required' => __('projects.dates_required'),
            'centerId.required_without' => __('projects.choose_center_or_host'),
            'centerId.integer' => __('projects.choose_the_center'),
            'hostCenterId.integer' => __('projects.choose_the_center'),
            'cityId.required' => __('projects.choose_the_city'),
            'cityId.integer' => __('projects.choose_the_city'),
            'cityId.exists' => __('projects.choose_the_city'),
            'programId.required' => __('projects.select_a_program_2'),
            'programId.exists' => __('projects.the_selected_program_is_no_longer_available'),
            'userId.required' => __('projects.select_a_project_owner'),
            'userId.exists' => __('projects.the_selected_owner_is_no_longer_available'),
            'userId.in' => __('projects.owner_must_be_program_manager'),
            'endDate.after_or_equal' => __('projects.the_end_date_must_be_on_or_after_the_start_date'),
            'sectorIds.required' => __('sectors.choose_at_least_one'),
            'sectorIds.min' => __('sectors.choose_at_least_one'),
            'sectorIds.array' => __('sectors.unavailable'),
        ])->validate();

        return [...$input, ...$validated];
    }

    /**
     * Who may own a project: a program manager, or the present owner so an
     * edit that leaves the owner alone still passes.
     *
     * @return list<int>
     */
    private function ownerCandidates(Project $project): array
    {
        $ids = Program::query()->whereNotNull('manager_id')->pluck('manager_id')->map(fn (mixed $id): int => (int) $id);

        if ($project->exists) {
            $ids->push((int) $project->user_id);
        }

        return $ids->unique()->values()->all();
    }

    /**
     * «Program — Center» when a center is sent, else «Program — City» when a
     * city is (a hosted project, W-02); null while either side is missing.
     * The same rule the form proposes with.
     *
     * @param  array<string, mixed>  $input
     */
    private function defaultName(array $input): ?string
    {
        $programId = $this->positiveInteger($input['programId']);
        $centerId = $this->positiveInteger($input['centerId']);
        $cityId = $this->positiveInteger($input['cityId']);

        if ($programId === null || ($centerId === null && $cityId === null)) {
            return null;
        }

        $program = Program::query()->find($programId, ['id']);
        $placement = $centerId !== null
            ? HrCenter::remembered($centerId)?->name
            : HrCity::query()->find($cityId, ['id', 'name_en', 'name_ar'])?->name;

        return $program === null || $placement === null ? null : $program->name.' — '.$placement;
    }

    /**
     * One open project per (center ?? city, program) (D-30a, D-55): a create,
     * or an edit that moves the placement or program of an open project, is
     * refused when another one already holds the pair. Nothing is written. A
     * legacy row with neither center nor host is not checked until W-02.
     */
    private function refuseSecondOpenProject(Project $project): void
    {
        $claimsPair = ($project->center_id !== null || $project->host_center_id !== null)
            && $project->status->isOpen()
            && (! $project->exists || $project->isDirty(['center_id', 'host_center_id', 'city_id', 'program_id']));

        if (! $claimsPair) {
            return;
        }

        $open = Project::openProjectFor($project->center_id, (int) $project->city_id, $project->program_id, $project->exists ? $project->id : null);

        if ($open !== null) {
            $field = $project->center_id !== null ? 'centerId' : 'hostCenterId';

            throw ValidationException::withMessages([$field => __('projects.open_project_exists', ['name' => $open->name])]);
        }
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }

    private function positiveInteger(mixed $value): ?int
    {
        return is_int($value) || (is_string($value) && ctype_digit($value)) ? (int) $value : null;
    }
}
