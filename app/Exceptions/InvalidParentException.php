<?php

declare(strict_types=1);

namespace App\Exceptions;

use DomainException;

/**
 * Thrown when a self-referencing tree is asked to accept a parent that would
 * corrupt it — a cycle, a milestone reaching across projects, or a result
 * breaking the logframe chain or its framework.
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

    /**
     * The result was placed at a level the chain does not allow under that
     * parent: an activity outside an output, or anything under an activity.
     */
    public static function wrongLevel(): self
    {
        return new self('A result must sit directly under the level above it; an activity sits under an output only.');
    }

    /**
     * The result and its parent belong to different frameworks (another
     * proposal, the program framework, or another program inside a proposal).
     */
    public static function crossFramework(): self
    {
        return new self('A result cannot be nested under a result from another framework.');
    }

    /**
     * A cross-cutting result was given a parent, or something was nested
     * under one.
     */
    public static function crossCuttingRoot(): self
    {
        return new self('A cross-cutting result has no parent and no children.');
    }
}
