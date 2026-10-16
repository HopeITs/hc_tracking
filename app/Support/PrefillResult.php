<?php

declare(strict_types=1);

namespace App\Support;

/**
 * What one report template pre-fill did (contracts.md §12.4, T33): the
 * templates and pending lines it created, the targets it skipped because
 * their indicator has no library link ("Link it to the library first"), and
 * the `fixed`-basis lines left without a `fixed_denominator` (a NULL
 * `project_proposal.target_beneficiaries`, D-25) for a human to fill.
 * Ids only, so a command can print them and T27 can check the last list.
 */
final readonly class PrefillResult
{
    /**
     * @param  list<int>  $createdTemplateIds
     * @param  list<int>  $createdLineIds
     * @param  list<int>  $unlinkedPlanIndicatorIds
     * @param  list<int>  $missingFixedDenominatorLineIds
     */
    public function __construct(
        public array $createdTemplateIds = [],
        public array $createdLineIds = [],
        public array $unlinkedPlanIndicatorIds = [],
        public array $missingFixedDenominatorLineIds = [],
    ) {}

    /**
     * Both results together, each list without duplicates.
     */
    public function merge(self $other): self
    {
        return new self(
            self::union($this->createdTemplateIds, $other->createdTemplateIds),
            self::union($this->createdLineIds, $other->createdLineIds),
            self::union($this->unlinkedPlanIndicatorIds, $other->unlinkedPlanIndicatorIds),
            self::union($this->missingFixedDenominatorLineIds, $other->missingFixedDenominatorLineIds),
        );
    }

    /**
     * Whether the pre-fill wrote anything.
     */
    public function createdAnything(): bool
    {
        return $this->createdTemplateIds !== [] || $this->createdLineIds !== [];
    }

    /**
     * @param  list<int>  $first
     * @param  list<int>  $second
     * @return list<int>
     */
    private static function union(array $first, array $second): array
    {
        return array_values(array_unique([...$first, ...$second]));
    }
}
