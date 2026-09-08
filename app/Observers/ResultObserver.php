<?php

declare(strict_types=1);

namespace App\Observers;

use App\Exceptions\InvalidParentException;
use App\Models\Result;

/**
 * Keeps the logframe tree well formed.
 *
 * results.parent_id is a plain self-referencing foreign key, so nothing at the
 * database level stops a result pointing at itself or at one of its own
 * descendants.
 */
final class ResultObserver
{
    /**
     * Reject a parent that would close a loop.
     *
     * @throws InvalidParentException
     */
    public function saving(Result $result): void
    {
        $parentKey = $result->parent_id;

        if ($parentKey === null || ! $result->isDirty('parent_id')) {
            return;
        }

        if ($result->wouldCycleUnder($parentKey)) {
            throw InvalidParentException::cycle('result');
        }
    }
}
