<?php

declare(strict_types=1);

namespace App\Actions\Sectors;

use App\Models\Sector;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

/**
 * Move a sector one place up or down the list. Every sector row is locked
 * (in ascending id order) and the whole list is renumbered 1…n, so two
 * managers moving at once never leave duplicate or missing positions. A move
 * past either end changes nothing.
 */
final class MoveSector
{
    /**
     * @return int the sector's new position, counted from 1
     */
    public function handle(int $sectorId, bool $towardsStart): int
    {
        return DB::transaction(function () use ($sectorId, $towardsStart): int {
            Sector::query()->orderBy('id')->lockForUpdate()->pluck('id');

            $sector = Sector::query()->findOrFail($sectorId);

            Gate::authorize('update', $sector);

            /** @var list<int> $order */
            $order = array_values(Sector::query()->ordered()->pluck('id')->map(fn (mixed $id): int => (int) $id)->all());
            $index = (int) array_search($sector->id, $order, true);
            $target = $towardsStart ? $index - 1 : $index + 1;

            if ($target >= 0 && $target < count($order)) {
                [$order[$index], $order[$target]] = [$order[$target], $order[$index]];
                $index = $target;
            }

            foreach ($order as $position => $id) {
                Sector::query()->whereKey($id)->where('sort_order', '!=', $position + 1)->update(['sort_order' => $position + 1]);
            }

            return $index + 1;
        });
    }
}
