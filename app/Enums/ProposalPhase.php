<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The phase of work a proposal is in.
 *
 * A phase is never stored. It is derived from ProposalStatus, which is the
 * single source of truth — `proposals` has no `phase` column, because two
 * independent columns could disagree.
 */
enum ProposalPhase: string
{
    case Planning = 'planning';
    case Implementation = 'implementation';
    case Monitoring = 'monitoring';
    case Closure = 'closure';
    case Archived = 'archived';

    /**
     * The statuses that place a proposal in this phase.
     *
     * Derived from ProposalStatus::phase() rather than listed again here, so
     * the mapping between the two enums exists in exactly one place.
     *
     * @return array<int, ProposalStatus>
     */
    public function statuses(): array
    {
        return array_values(array_filter(
            ProposalStatus::cases(),
            fn (ProposalStatus $status): bool => $status->phase() === $this,
        ));
    }

    /**
     * All phase values, for validation rules and console prompts.
     *
     * @return array<int, string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
