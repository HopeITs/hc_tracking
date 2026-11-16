<?php

declare(strict_types=1);

namespace App\Actions\Indicators;

use App\Enums\CollectionFrequency;
use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\Result;
use App\Support\IndicatorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Put an indicator written by hand on a framework into the Indicator Library
 * (MEAL manager request, 2026-11-13), so every indicator in use is also an
 * entry others can pick.
 *
 * - Who: whoever may create the indicator (`indicators.create`, checked by the
 *   form); the library's own policy is not asked, because the entry follows
 *   from an indicator the user was allowed to write.
 * - An active entry of the same program with the same name and measure is
 *   reused; otherwise a new entry is created at the level of the indicator's
 *   result, with a generated code.
 * - The indicator then points at the entry and carries its code.
 */
final class AddIndicatorToLibrary
{
    /**
     * @throws ValidationException when the program has no usable HR code (IndicatorCode)
     */
    public function handle(Indicator $indicator): LibraryIndicator
    {
        return DB::transaction(function () use ($indicator): LibraryIndicator {
            $result = Result::query()
                ->select(['id', 'parent_id', 'program_id', 'proposal_id', 'type'])
                ->findOrFail($indicator->result_id);

            $entry = LibraryIndicator::query()
                ->active()
                ->when(
                    $result->program_id === null,
                    fn ($query) => $query->whereNull('program_id'),
                    fn ($query) => $query->where('program_id', $result->program_id),
                )
                ->where('measure', $indicator->measure->value)
                ->where(fn ($query) => $query->where('name_ar', $indicator->name)->orWhere('name_en', $indicator->name))
                ->orderBy('id')
                ->first();

            if ($entry === null) {
                $levelRef = $this->levelRefOf($result);

                $entry = new LibraryIndicator;
                $entry->forceFill(IndicatorCode::next($result->program_id, $levelRef));
                $entry->fill([
                    'program_id' => $result->program_id,
                    'level_ref' => $levelRef,
                    'name_ar' => $indicator->name,
                    'measure' => $indicator->measure,
                    'unit' => $indicator->unit,
                    'frequency' => CollectionFrequency::from($indicator->frequency->value),
                    'suggested_level' => $result->type,
                ])->save();
            }

            $indicator->fill(['library_indicator_id' => $entry->id, 'code' => $entry->code])->save();

            return $entry;
        }, 3);
    }

    /**
     * The level reference of a result, in the library's own notation: G for an
     * impact, CC for a cross-cutting result, otherwise OC / OP / AC followed
     * by the position of each step of its chain (OC2, OP2.1, AC2.1.3).
     */
    private function levelRefOf(Result $result): string
    {
        $prefix = match ($result->type) {
            ResultType::Impact => 'G',
            ResultType::CrossCutting => 'CC',
            ResultType::Outcome => 'OC',
            ResultType::Output => 'OP',
            ResultType::Activity => 'AC',
        };

        if (in_array($result->type, [ResultType::Impact, ResultType::CrossCutting], true)) {
            return $prefix;
        }

        $positions = [];
        $node = $result;

        while ($node !== null && $node->type !== ResultType::Impact) {
            array_unshift($positions, $this->positionAmongSiblings($node));

            $node = $node->parent_id === null
                ? null
                : Result::query()->select(['id', 'parent_id', 'program_id', 'proposal_id', 'type'])->find($node->parent_id);
        }

        return $prefix.implode('.', $positions);
    }

    /**
     * Where a result stands among the results of its level under the same
     * parent, in the same framework, counted from 1 in creation order.
     */
    private function positionAmongSiblings(Result $result): int
    {
        return Result::query()
            ->where('type', $result->type->value)
            ->where('id', '<=', $result->id)
            ->when($result->parent_id === null, fn ($query) => $query->whereNull('parent_id'), fn ($query) => $query->where('parent_id', $result->parent_id))
            ->when($result->program_id === null, fn ($query) => $query->whereNull('program_id'), fn ($query) => $query->where('program_id', $result->program_id))
            ->when($result->proposal_id === null, fn ($query) => $query->whereNull('proposal_id'), fn ($query) => $query->where('proposal_id', $result->proposal_id))
            ->count();
    }
}
