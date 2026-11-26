<?php

declare(strict_types=1);

namespace App\Actions\Locations;

use App\Enums\ProjectStatus;
use App\Models\ProposalLocation;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Change what a location row delivers (contracts.md §3, Y-17): its
 * description, its working calendar, the five beneficiary targets and its
 * sectors.
 *
 * The delivery half of the former SaveProject, moved onto the row. What is
 * deliberately NOT here: the city, the program, the centers and the
 * responsible. Those say **where** the work runs and **who** answers for it,
 * and they are decided on the proposal — through `SaveProposal` before
 * approval, or a change request afterwards (Y-09). A row is never created
 * here either: a row exists because a proposal has it (D-84, D-87), which is
 * why `ProposalLocationPolicy::create()` is false.
 *
 * Dates are the grant's, not the form's: a draft is refused them outright
 * and an approved or active row keeps the window its proposal gave it
 * (ApproveLocationFromProposal, LocationPeriods).
 */
final class SaveLocationDetails
{
    /** @var list<string> */
    private const Targets = ['targetBeneficiaries', 'targetMen', 'targetWomen', 'targetBoys', 'targetGirls'];

    /**
     * @param  array<string, mixed>  $data  keyed by the form's property names
     *
     * @throws ValidationException
     */
    public function handle(ProposalLocation $row, array $data, User $actor): ProposalLocation
    {
        Gate::authorize('update', $row);

        $saved = DB::transaction(function () use ($row, $data): ProposalLocation {
            $locked = ProposalLocation::query()->with('ownerAssignment')->whereKey($row->getKey())->lockForUpdate()->firstOrFail();

            Gate::authorize('update', $locked);

            $validated = $this->validate($data, $locked);

            $locked->fill([
                'description' => $validated['description'],
                'target_beneficiaries' => $this->nullableInteger($validated['targetBeneficiaries'] ?? null),
                'target_men' => $this->nullableInteger($validated['targetMen'] ?? null),
                'target_women' => $this->nullableInteger($validated['targetWomen'] ?? null),
                'target_boys' => $this->nullableInteger($validated['targetBoys'] ?? null),
                'target_girls' => $this->nullableInteger($validated['targetGirls'] ?? null),
            ]);

            if (array_key_exists('scheduleWeekend', $validated) && $validated['scheduleWeekend'] !== null) {
                $locked->schedule_weekend = $validated['scheduleWeekend'];
            }

            if (array_key_exists('workingDays', $validated)) {
                $locked->working_days = $validated['workingDays'];
            }

            if ($locked->isDirty()) {
                $locked->save();
            }

            return $locked;
        });

        if (array_key_exists('sectorIds', $data) && is_array($data['sectorIds'])) {
            app(SyncLocationSectors::class)->handle($saved, array_values($data['sectorIds']));
        }

        $row->refresh();

        return $saved;
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function validate(array $data, ProposalLocation $row): array
    {
        $rules = [
            'description' => ['nullable', 'string'],
            'scheduleWeekend' => ['nullable', 'string', Rule::in(['sat-sun', 'fri-sat', 'fri-only', 'none'])],
            'workingDays' => ['nullable', 'array'],
            'workingDays.*' => ['integer', 'between:0,6'],
            // Reported with the other fields; SyncLocationSectors checks it
            // again, and which sectors are usable, under the row's lock.
            'sectorIds' => ['nullable', 'array'],
            // The grant sets the period, never this form (contracts.md §3).
            'startDate' => ['prohibited'],
            'endDate' => ['prohibited'],
        ];

        foreach (self::Targets as $target) {
            $rules[$target] = ['nullable', 'integer', 'min:0', 'max:4294967295'];
        }

        /** @var array<string, mixed> $validated */
        $validated = Validator::make([...$data, 'description' => $data['description'] ?? null], $rules, [
            'startDate.prohibited' => __($row->status === ProjectStatus::Draft ? 'projects.dates_prohibited_draft' : 'projects.dates_set_by_the_grant'),
            'endDate.prohibited' => __($row->status === ProjectStatus::Draft ? 'projects.dates_prohibited_draft' : 'projects.dates_set_by_the_grant'),
            'sectorIds.array' => __('sectors.unavailable'),
        ])->validate();

        return [...$data, ...$validated];
    }

    private function nullableInteger(mixed $value): ?int
    {
        return $value === null || $value === '' ? null : (int) $value;
    }
}
