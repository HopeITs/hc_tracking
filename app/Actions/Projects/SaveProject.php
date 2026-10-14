<?php

declare(strict_types=1);

namespace App\Actions\Projects;

use App\Enums\ProjectStatus;
use App\Models\HrCenter;
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
 * center (D-30): the city is the center's and is written here, and only one
 * open project may hold a (center, program) pair, checked by
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
     * «Program — Center».
     *
     * @param  array{name?: ?string, description?: ?string, startDate?: ?string, endDate?: ?string, targetBeneficiaries?: int|string|null, targetMen?: int|string|null, targetWomen?: int|string|null, targetBoys?: int|string|null, targetGirls?: int|string|null, centerId?: int|string|null, programId?: int|string|null, userId?: int|string|null}  $data
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
                'city_id' => $center === null ? $project->city_id : $center->city_id,
                'program_id' => $mayReassign ? (int) $validated['programId'] : $project->program_id,
                'user_id' => $mayReassign ? (int) $validated['userId'] : $project->user_id,
            ]);

            if ($project->status !== ProjectStatus::Draft) {
                $project->fill(['start_date' => $validated['startDate'], 'end_date' => $validated['endDate']]);
            }

            $this->refuseSecondOpenProject($project);

            $project->save();

            // T34 hook: SyncProjectSectors::handle($project, $sectorIds, requireOne: ! $existing) is called here once sectors land.

            return $project;
        });

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

        $keys = ['name', 'description', 'startDate', 'endDate', 'targetBeneficiaries', 'targetMen', 'targetWomen', 'targetBoys', 'targetGirls', 'centerId', 'programId', 'userId'];

        return array_combine($keys, array_map(fn (string $key): mixed => $blank($data[$key] ?? null), $keys));
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
                Rule::when(! $project->exists || $savedCenterId !== null, ['required'], ['nullable']),
                'integer',
                function (string $attribute, mixed $value, Closure $fail) use ($savedCenterId): void {
                    $center = HrCenter::remembered((int) $value);

                    if ($center === null || (! $center->is_active && $center->id !== $savedCenterId)) {
                        $fail(__('projects.choose_the_center'));
                    }
                },
            ],
            'programId' => $mayReassign
                ? ['required', 'integer', Rule::exists(Program::class, 'id')]
                : ['nullable'],
            'userId' => $mayReassign
                ? ['required', 'integer', Rule::exists(HrEmployee::class, 'id'), Rule::in($this->ownerCandidates($project))]
                : ['nullable'],
        ];

        /** @var array<string, mixed> $validated */
        $validated = Validator::make($input, $rules, [
            'name.required' => __('projects.enter_a_project_name'),
            'startDate.prohibited' => __('projects.dates_prohibited_draft'),
            'endDate.prohibited' => __('projects.dates_prohibited_draft'),
            'startDate.required' => __('projects.dates_required'),
            'endDate.required' => __('projects.dates_required'),
            'centerId.required' => __('projects.choose_the_center'),
            'centerId.integer' => __('projects.choose_the_center'),
            'programId.required' => __('projects.select_a_program_2'),
            'programId.exists' => __('projects.the_selected_program_is_no_longer_available'),
            'userId.required' => __('projects.select_a_project_owner'),
            'userId.exists' => __('projects.the_selected_owner_is_no_longer_available'),
            'userId.in' => __('projects.owner_must_be_program_manager'),
            'endDate.after_or_equal' => __('projects.the_end_date_must_be_on_or_after_the_start_date'),
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
     * «Program — Center» once both are chosen.
     *
     * @param  array<string, mixed>  $input
     */
    private function defaultName(array $input): ?string
    {
        $programId = $this->positiveInteger($input['programId']);
        $centerId = $this->positiveInteger($input['centerId']);

        if ($programId === null || $centerId === null) {
            return null;
        }

        $program = Program::query()->find($programId, ['id']);
        $center = HrCenter::remembered($centerId);

        return $program === null || $center === null ? null : $program->name.' — '.$center->name;
    }

    /**
     * One open project per (center, program) (D-30a): a create, or an edit
     * that moves the center or program of an open project, is refused when
     * another one already holds the pair. Nothing is written.
     */
    private function refuseSecondOpenProject(Project $project): void
    {
        $claimsPair = $project->center_id !== null
            && $project->status->isOpen()
            && (! $project->exists || $project->isDirty(['center_id', 'program_id']));

        if (! $claimsPair) {
            return;
        }

        $open = Project::openProjectFor($project->center_id, $project->program_id, $project->exists ? $project->id : null);

        if ($open !== null) {
            throw ValidationException::withMessages(['centerId' => __('projects.open_project_exists', ['name' => $open->name])]);
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
