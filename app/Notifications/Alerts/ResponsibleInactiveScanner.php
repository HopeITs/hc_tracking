<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use App\Enums\NotificationPriority;
use App\Enums\ProjectStatus;
use App\Models\HrEmployee;
use App\Models\ProposalLocation;
use App\Notifications\RecipientResolver;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Collection;

/**
 * `proposal.responsible_inactive` (contracts.md §15.2, D-62): work still
 * named after somebody who has left HR, asking to be reassigned.
 *
 * Once per person per record. The ledger stage is the employee id, which is
 * what makes that true without storing anything: a second run claims the
 * same stage and is silent, reassigning to another departed person raises
 * again under the new id, and reassigning to somebody who is still here
 * drops the record out of the query altogether.
 *
 * HR is read once per scan into an in-memory set — never a `whereHas` across
 * connections (contracts.md §10) — and nothing about HR is written here.
 *
 * **Only open places are scanned** (Y-16, D-93): the responsible on a
 * location row is what D-62 asks about, and a row is where the work now
 * lives. Two rows may stand at one place (D-77), where a project raised one
 * notice: the stage carries the place as well as the person, so the second
 * row of a place is silent.
 */
final class ResponsibleInactiveScanner implements Scanner
{
    private const Rule = 'responsible_inactive';

    public function __construct(
        private AlertDispatcher $dispatcher,
        private RecipientResolver $resolver,
    ) {}

    public function scan(CarbonImmutable $now): int
    {
        $inactive = $this->inactiveEmployees();

        if ($inactive->isEmpty()) {
            return 0;
        }

        return $this->places($inactive);
    }

    /**
     * Open places whose responsible employee has left, one notification each.
     *
     * @param  Collection<int, HrEmployee>  $inactive  keyed by employee id
     */
    private function places(Collection $inactive): int
    {
        $rows = ProposalLocation::query()
            ->whereIn('proposal_locations.status', array_map(fn (ProjectStatus $status): string => $status->value, array_filter(ProjectStatus::cases(), fn (ProjectStatus $status): bool => $status->isOpen())))
            ->whereIn('responsible_employee_id', $inactive->keys()->all())
            ->with(['centers', 'city:id,name_en,name_ar', 'program:id', 'proposal:id,name'])
            ->get(['id', 'proposal_id', 'project_id', 'city_id', 'city_wide', 'program_id', 'responsible_employee_id']);

        if ($rows->isEmpty()) {
            return 0;
        }

        $managers = $this->resolver->usersWithRoles(['Public Relations Manager']);
        $sent = 0;

        foreach ($rows as $row) {
            $employeeId = (int) $row->responsible_employee_id;

            $payload = $this->dispatcher->payload(
                // The subject is a place, so the module that guards it is
                // the one the recipient filter must use, although the
                // catalog key lives under `proposals` (D-62 names one key
                // for both halves).
                'projects',
                NotificationPriority::Important,
                'notifications.alerts.responsible_inactive.project',
                [
                    'subject' => $row->headline(),
                    'person' => $inactive[$employeeId]->name,
                ],
                'proposal_location',
                $row->id,
            );

            // The stage is the person and the place, not the date: that is
            // «once per employee per place» (D-62, D-77).
            $sent += $this->dispatcher->send(self::Rule, 'employee:'.$employeeId.':'.$row->place()->key(), $payload, $managers);
        }

        return $sent;
    }

    /**
     * Everyone HR has marked inactive, read once, keyed by id. An HR that
     * cannot be reached leaves the scan silent rather than noisy.
     *
     * @return Collection<int, HrEmployee>
     */
    private function inactiveEmployees(): Collection
    {
        try {
            return HrEmployee::query()
                ->where('is_active', false)
                ->get(['id', 'first_name_en', 'first_name_ar', 'last_name_en', 'last_name_ar'])
                ->keyBy('id');
        } catch (QueryException) {
            return collect();
        }
    }
}
