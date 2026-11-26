<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\ProposalLocation;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * A location row became active (contracts.md §5, Y-17). Replaces
 * ProjectActivated; the notification key `project.activated` keeps its name
 * (wave4/AGENT.md §8.5).
 */
final class LocationActivated implements ShouldDispatchAfterCommit
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly ProposalLocation $row) {}
}
