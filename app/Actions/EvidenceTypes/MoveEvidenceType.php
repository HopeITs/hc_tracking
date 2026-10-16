<?php

declare(strict_types=1);

namespace App\Actions\EvidenceTypes;

use App\Models\EvidenceType;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Move an evidence type one place up or down the list. Every row is locked
 * (in ascending id order) and the whole list is renumbered 1…n, so two
 * people moving at once never leave duplicate or missing positions. A move
 * past either end changes nothing.
 */
final class MoveEvidenceType
{
    /**
     * @return int the type's new position, counted from 1
     */
    public function handle(int $evidenceTypeId, bool $towardsStart): int
    {
        return DB::transaction(function () use ($evidenceTypeId, $towardsStart): int {
            EvidenceType::query()->orderBy('id')->lockForUpdate()->pluck('id');

            $evidenceType = EvidenceType::query()->findOrFail($evidenceTypeId);

            Gate::authorize('update', $evidenceType);

            /** @var list<int> $order */
            $order = array_values(EvidenceType::query()->ordered()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
            $index = (int) array_search($evidenceType->id, $order, true);
            $target = $towardsStart ? $index - 1 : $index + 1;

            if ($target >= 0 && $target < count($order)) {
                [$order[$index], $order[$target]] = [$order[$target], $order[$index]];
                $index = $target;
            }

            foreach ($order as $position => $id) {
                EvidenceType::query()->whereKey($id)->where('sort_order', '!=', $position + 1)->update(['sort_order' => $position + 1]);
            }

            return $index + 1;
        });
    }
}
