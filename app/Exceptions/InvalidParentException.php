<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when a self-referencing tree is asked to accept a parent that would
 * corrupt it — a cycle, or a milestone reaching across projects.
 *
 * These are programming errors rather than user typos, so they are raised
 * loudly from the model layer instead of cancelling the save silently.
 */
final class InvalidParentException extends DomainException
{
    /**
     * The node was pointed at itself or at one of its own descendants.
     */
    public static function cycle(string $model): self
    {
        return new self("A {$model} cannot be nested under itself or one of its own descendants.");
    }

    /**
     * The milestone was pointed at a parent belonging to a different project.
     */
    public static function crossProject(): self
    {
        return new self('A milestone cannot be nested under a milestone from another project.');
    }
}
