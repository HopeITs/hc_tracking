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
     */
    public function __construct(
        public string $subjectType,
        public int $subjectId,
        public string $module,
        public string $label,
        public CarbonImmutable $dueDate,
        public array $directUserIds,
        public array $watcherRoles = [],
    ) {}
}
