<?php

declare(strict_types=1);

namespace App\Actions\IndicatorLibrary;

use App\Enums\CollectionFrequency;
use App\Enums\DenominatorBasis;
use App\Enums\IndicatorCollector;
use App\Enums\IndicatorMeasure;
use App\Enums\ResultType;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\PlanIndicator;
use App\Support\IndicatorCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Create or edit an Indicator Library entry (contracts.md §11.1).
 *
 * The code is generated on create and regenerated when an unused entry moves
 * to another program or level; once any indicator uses the entry its program,
 * level and code are frozen (D-10). The measure locks once a linked indicator
 * has targets; until then a new measure is mirrored onto every linked
 * indicator in the same transaction. Both checks run on the row reloaded
 * under lockForUpdate(), never on what the page last saw.
 */
class SaveLibraryIndicator
{
    /**
     * The level reference a program framework uses: G, CC, OC1, OP1.2, AC1.2.3.
     */
    public const string LevelRefPattern = '/^(G|CC|OC\d+|OP\d+(\.\d+)*|AC\d+(\.\d+)*)$/';

    /**
     * @param  array<string, mixed>  $input  keyed by column name, plus `institutional` (true = no program)
     *
     * @throws ValidationException
     */
    public function handle(?int $libraryIndicatorId, array $input): LibraryIndicator
    {
        if ($libraryIndicatorId === null) {
            Gate::authorize('create', LibraryIndicator::class);
        } else {
            Gate::authorize('update', LibraryIndicator::query()->findOrFail($libraryIndicatorId));
        }

        $attributes = $this->attributesFrom($this->validate($input));

        return DB::transaction(function () use ($libraryIndicatorId, $attributes): LibraryIndicator {
            $entry = $libraryIndicatorId === null
                ? new LibraryIndicator
                : LibraryIndicator::query()->lockForUpdate()->findOrFail($libraryIndicatorId);

            $movesCode = ! $entry->exists
                || $entry->program_id !== $attributes['program_id']
                || $entry->level_ref !== $attributes['level_ref'];
            $changesMeasure = $entry->exists && $entry->measure !== $attributes['measure'];

            if ($entry->exists && $movesCode && $entry->indicators()->exists()) {
                throw ValidationException::withMessages(['level_ref' => __('indicator_library.code_frozen')]);
            }

            if ($changesMeasure && PlanIndicator::query()->whereIn('indicator_id', $entry->indicators()->select('id'))->exists()) {
                throw ValidationException::withMessages(['measure' => __('indicator_library.measure_locked')]);
            }

            if ($movesCode) {
                $entry->forceFill(IndicatorCode::next($attributes['program_id'], $attributes['level_ref']));
            }

            $entry->fill($attributes)->save();

            if ($changesMeasure) {
                Indicator::query()
                    ->where('library_indicator_id', $entry->id)
                    ->update(['measure' => $attributes['measure']->value]);
            }

            return $entry;
        }, 3);
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     *
     * @throws ValidationException
     */
    private function validate(array $input): array
    {
        $text = ['nullable', 'string', 'max:5000'];

        return Validator::make($input, [
            'institutional' => ['boolean'],
            'program_id' => [Rule::requiredIf(! filter_var($input['institutional'] ?? false, FILTER_VALIDATE_BOOL)), 'nullable', 'integer'],
            'level_ref' => ['required', 'string', 'max:20', 'regex:'.self::LevelRefPattern],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'definition' => $text,
            'calculation_method' => $text,
            'measure' => ['required', Rule::enum(IndicatorMeasure::class)],
            'unit' => ['nullable', 'string', 'max:50'],
            'numerator_label' => ['nullable', 'string', 'max:255'],
            'denominator_label' => ['nullable', 'string', 'max:255'],
            'denominator_basis' => ['nullable', Rule::enum(DenominatorBasis::class)],
            'is_disaggregated' => ['boolean'],
            'collected_by' => ['required', Rule::enum(IndicatorCollector::class)],
            'is_cumulative' => ['boolean'],
            'frequency' => ['required', Rule::enum(CollectionFrequency::class)],
            'frequency_note' => $text,
            'follow_up_months' => ['required_if:frequency,'.CollectionFrequency::FollowUp->value, 'nullable', 'integer', 'min:1', 'max:120'],
            'suggested_level' => ['nullable', Rule::enum(ResultType::class)],
            'data_source' => $text,
            'means_of_verification' => $text,
        ], [
            'name_ar.required' => __('indicator_library.name_ar_required'),
            'program_id.required' => __('indicator_library.program_required'),
            'level_ref.required' => __('indicator_library.level_ref_format'),
            'level_ref.regex' => __('indicator_library.level_ref_format'),
            'follow_up_months.required_if' => __('indicator_library.follow_up_months_required'),
        ])->validate();
    }

    /**
     * The columns to store. A percentage has no unit and divides by the basis
     * MEAL chose, else the collector's default; any other measure has no
     * basis. Follow-up months are kept only for a follow-up frequency.
     *
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function attributesFrom(array $validated): array
    {
        $measure = IndicatorMeasure::from($validated['measure']);
        $collector = IndicatorCollector::from($validated['collected_by']);
        $frequency = CollectionFrequency::from($validated['frequency']);
        $isPercentage = $measure === IndicatorMeasure::Percentage;
        $institutional = filter_var($validated['institutional'] ?? false, FILTER_VALIDATE_BOOL);

        return [
            'program_id' => $institutional || blank($validated['program_id'] ?? null) ? null : (int) $validated['program_id'],
            'level_ref' => $validated['level_ref'],
            'name_ar' => $validated['name_ar'],
            'name_en' => filled($validated['name_en'] ?? null) ? $validated['name_en'] : null,
            'definition' => $validated['definition'] ?? null,
            'calculation_method' => $validated['calculation_method'] ?? null,
            'measure' => $measure,
            'unit' => $isPercentage || blank($validated['unit'] ?? null) ? null : $validated['unit'],
            'numerator_label' => $validated['numerator_label'] ?? null,
            'denominator_label' => $validated['denominator_label'] ?? null,
            'denominator_basis' => match (true) {
                ! $isPercentage => null,
                filled($validated['denominator_basis'] ?? null) => DenominatorBasis::from($validated['denominator_basis']),
                default => $collector->defaultDenominatorBasis(),
            },
            'is_disaggregated' => (bool) ($validated['is_disaggregated'] ?? false),
            'collected_by' => $collector,
            'is_cumulative' => (bool) ($validated['is_cumulative'] ?? false),
            'frequency' => $frequency,
            'frequency_note' => $validated['frequency_note'] ?? null,
            'follow_up_months' => $frequency === CollectionFrequency::FollowUp ? (int) $validated['follow_up_months'] : null,
            'suggested_level' => filled($validated['suggested_level'] ?? null) ? ResultType::from($validated['suggested_level']) : null,
            'data_source' => $validated['data_source'] ?? null,
            'means_of_verification' => $validated['means_of_verification'] ?? null,
        ];
    }
}
