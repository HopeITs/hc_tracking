<?php

declare(strict_types=1);

namespace App\Actions\RiskLibrary;

use App\Models\RiskLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Move a library entry one place up or down the list. Every row is locked
 * (in ascending id order) and the whole list is renumbered 1…n, so two
 * managers moving at once never leave duplicate or missing positions. A move
 * past either end changes nothing.
 */
final class MoveRiskLibraryEntry
{
    /**
     * @return int the entry's new position, counted from 1
     */
    public function handle(int $entryId, bool $towardsStart): int
    {
        return DB::transaction(function () use ($entryId, $towardsStart): int {
            RiskLibrary::query()->orderBy('id')->lockForUpdate()->pluck('id');

            $entry = RiskLibrary::query()->findOrFail($entryId);

            Gate::authorize('update', $entry);

            /** @var list<int> $order */
            $order = array_values(RiskLibrary::query()->ordered()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
            $index = (int) array_search($entry->id, $order, true);
            $target = $towardsStart ? $index - 1 : $index + 1;

            if ($target >= 0 && $target < count($order)) {
                [$order[$index], $order[$target]] = [$order[$target], $order[$index]];
                $index = $target;
            }

            foreach ($order as $position => $id) {
                RiskLibrary::query()->whereKey($id)->where('sort_order', '!=', $position + 1)->update(['sort_order' => $position + 1]);
            }

            return $index + 1;
        });
    }
}
