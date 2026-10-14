<?php

declare(strict_types=1);

namespace App\Actions\Supervisions;

use App\Models\HrEmployee;
use App\Models\Supervision;
use App\Support\EmployeeProfile;
use App\Support\SupervisionMap;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

/**
 * Add a local supervision on top of HR (contracts §10, D-13). Both people
 * must be active in HR now (re-checked here, the picker is never trusted);
 * nobody supervises themselves; HR's own direct manager and any A↔B loop are
 * refused. The unique pair index settles two admins acting at once. Error
 * keys match the Supervision tab's add modal.
 */
class AddSupervision
{
    /**
     * @throws ValidationException when the pair breaks a supervision rule
     */
    public function handle(int $supervisorEmployeeId, int $superviseeEmployeeId): Supervision
    {
        Gate::authorize('manage', Supervision::class);

        $this->ensureBothActive($supervisorEmployeeId, $superviseeEmployeeId);

        if ($supervisorEmployeeId === $superviseeEmployeeId) {
            $this->refuse('users.supervision_self');
        }

        if (EmployeeProfile::managerOf($superviseeEmployeeId) === $supervisorEmployeeId) {
            $this->refuse('users.supervision_already_from_hr');
        }

        if (EmployeeProfile::managerOf($supervisorEmployeeId) === $superviseeEmployeeId) {
            $this->refuse('users.supervision_mutual');
        }

        $supervision = DB::transaction(function () use ($supervisorEmployeeId, $superviseeEmployeeId): Supervision {
            $this->refuseExistingPair($supervisorEmployeeId, $superviseeEmployeeId, lock: true);

            try {
                return Supervision::query()->create([
                    'supervisor_employee_id' => $supervisorEmployeeId,
                    'supervisee_employee_id' => $superviseeEmployeeId,
                    'created_by' => Auth::id(),
                ]);
            } catch (UniqueConstraintViolationException) {
                // Another admin committed this pair, or its reverse, after we looked.
                $this->refuseExistingPair($supervisorEmployeeId, $superviseeEmployeeId, lock: false);
                $this->refuse('users.supervision_duplicate');
            }
        });

        SupervisionMap::flush();

        return $supervision;
    }

    private function ensureBothActive(int $supervisorEmployeeId, int $superviseeEmployeeId): void
    {
        $active = HrEmployee::query()
            ->whereKey([$supervisorEmployeeId, $superviseeEmployeeId])
            ->where('is_active', true)
            ->pluck('id')
            ->map(fn (int|string $id): int => (int) $id)
            ->all();

        $errors = [];

        foreach (['supervisorEmployeeId' => $supervisorEmployeeId, 'superviseeEmployeeId' => $superviseeEmployeeId] as $field => $employeeId) {
            if (! in_array($employeeId, $active, true)) {
                $errors[$field] = __('users.supervision_choose_an_active_employee');
            }
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    /**
     * The same pair is a duplicate; the reverse pair would be a loop.
     */
    private function refuseExistingPair(int $supervisorEmployeeId, int $superviseeEmployeeId, bool $lock): void
    {
        $existing = Supervision::query()
            ->where('pair_low', min($supervisorEmployeeId, $superviseeEmployeeId))
            ->where('pair_high', max($supervisorEmployeeId, $superviseeEmployeeId))
            ->when($lock, fn ($query) => $query->lockForUpdate())
            ->value('supervisor_employee_id');

        if ($existing !== null) {
            $this->refuse((int) $existing === $supervisorEmployeeId ? 'users.supervision_duplicate' : 'users.supervision_mutual');
        }
    }

    private function refuse(string $messageKey): never
    {
        throw ValidationException::withMessages(['superviseeEmployeeId' => __($messageKey)]);
    }
}
