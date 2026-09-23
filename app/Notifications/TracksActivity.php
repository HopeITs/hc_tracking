<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Models\Activity;

/**
 * A model that activity is recorded against (a "subject").
 */
interface TracksActivity
{
    /**
     * The access-control module, e.g. "tasks".
     */
    public function activityModule(): string;

    /**
     * The program this record rolls up to, for the manager cascade.
     */
    public function activityProgramId(): ?int;

    /**
     * The record's display name for sentences.
     */
    public function activityLabel(): string;

    /**
     * Fields holding HR employee ids, recorded with both-language names.
     *
     * @return list<string>
     */
    public function activityEmployeeFields(): array;

    /**
     * Record an activity no model event can see.
     *
     * @param  array<string, array{0: mixed, 1: mixed}>  $changes
     * @param  array<string, mixed>  $properties
     */
    public function recordActivity(string $event, array $changes = [], array $properties = []): ?Activity;
}
