<?php

declare(strict_types=1);

namespace App\Notifications\Alerts;

use Carbon\CarbonImmutable;

/**
 * One open item with a date, as the deadline scanners see it.
 */
final readonly class DeadlineItem
{
    /**
     * @param  list<int>  $directUserIds  people named on the item
     * @param  list<string>  $watcherRoles  who hears when nobody is named
     * @param  string|null  $eventKey  catalog key prefix of a scanner-driven event (`department_task` → `.due` / `.overdue`); its enabled flag and oversight apply
     * @param  list<int>  $overdueUserIds  people who hear only once the item is overdue (a task's requester)
     * @param  list<int>|null  $dueSoonStages  working-day stages of its own; null = the global `due_soon_days`
     * @param  list<int>  $unassignedUserIds  set when nobody carries the item yet: who must name a person (D-33)
     * @param  list<int>  $exemptUserIds  direct people who hear without the module's view permission (a donor report's responsible person)
     * @param  array{route?: string, parameters?: array<string, mixed>}  $link  where the alert leads instead of the subject's page
     */
    public function __construct(
        public string $subjectType,
        public int $subjectId,
        public string $module,
        public string $label,
        public CarbonImmutable $dueDate,
        public array $directUserIds,
        public array $watcherRoles = [],
        public ?string $eventKey = null,
        public array $overdueUserIds = [],
        public ?int $projectId = null,
        public ?array $dueSoonStages = null,
        public array $unassignedUserIds = [],
        public array $exemptUserIds = [],
        public array $link = [],
    ) {}
}
