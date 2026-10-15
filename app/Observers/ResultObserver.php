<?php

declare(strict_types=1);

namespace App\Observers;

use App\Enums\ResultType;
use App\Exceptions\InvalidParentException;
use App\Models\Result;

/**
 * Keeps the logframe tree well formed.
 *
 * results.parent_id is a plain self-referencing foreign key, so nothing at the
 * database level stops a result pointing at itself or at one of its own
 * descendants, nesting under another framework, or breaking the activity and
 * cross-cutting rules (contracts §11.2).
 *
 * The rules here are the ones no stored row can already break, so legacy
 * program-framework shapes (an output under an output, a child in another
 * program) still save untouched. The impact → outcome → output order of the
 * program framework is enforced by SaveResult, which turns every rule into a
 * translated message; this observer is the backstop.
 */
final class ResultObserver
{
    /**
     * Reject a parent or level that would corrupt the tree.
     *
     * Runs only when the placement changes. The parent is re-read here, inside
     * the save, so a parent deleted meanwhile is left to the foreign key.
     *
     * @throws InvalidParentException
     */
    public function saving(Result $result): void
    {
        if (! $result->isDirty(['parent_id', 'type', 'proposal_id', 'program_id'])) {
            return;
        }

        $type = $result->type;
        $parentKey = $result->parent_id;

        if ($result->exists && $result->isDirty('type')) {
            $this->ensureChildrenStillFit($result, $type);
        }

        if ($parentKey === null) {
            if ($type === ResultType::Activity) {
                throw InvalidParentException::wrongLevel();
            }

            return;
        }

        if ($type === ResultType::CrossCutting) {
            throw InvalidParentException::crossCuttingRoot();
        }

        if ($result->isDirty('parent_id') && $result->wouldCycleUnder($parentKey)) {
            throw InvalidParentException::cycle('result');
        }

        $parent = Result::query()
            ->select(['id', 'type', 'proposal_id', 'program_id'])
            ->find($parentKey);

        if ($parent === null) {
            return;
        }

        if ($parent->type === ResultType::CrossCutting) {
            throw InvalidParentException::crossCuttingRoot();
        }

        if (! $parent->type->acceptsChildren()
            || ($type === ResultType::Activity && $parent->type !== ResultType::Output)) {
            throw InvalidParentException::wrongLevel();
        }

        if ($result->proposal_id !== $parent->proposal_id
            || ($result->proposal_id !== null && $result->program_id !== $parent->program_id)) {
            throw InvalidParentException::crossFramework();
        }
    }

    /**
     * A level that takes no children, or anything but an output above an
     * activity, cannot be given to a result that already has children.
     *
     * @throws InvalidParentException
     */
    private function ensureChildrenStillFit(Result $result, ResultType $type): void
    {
        $childTypes = Result::query()
            ->where('parent_id', $result->getKey())
            ->distinct()
            ->pluck('type')
            ->map(fn (ResultType|string $childType): ResultType => $childType instanceof ResultType ? $childType : ResultType::from($childType));

        if ($childTypes->isEmpty()) {
            return;
        }

        if ($type === ResultType::CrossCutting) {
            throw InvalidParentException::crossCuttingRoot();
        }

        if (! $type->acceptsChildren()
            || ($type !== ResultType::Output && $childTypes->contains(ResultType::Activity))) {
            throw InvalidParentException::wrongLevel();
        }
    }
}
