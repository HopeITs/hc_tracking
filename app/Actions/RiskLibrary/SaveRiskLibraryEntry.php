<?php

declare(strict_types=1);

namespace App\Actions\RiskLibrary;

use App\Models\RiskLibrary;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Create or edit a risk library entry (contracts.md §7, W-09). Only the
 * wording, category and default ratings come from the form: a new entry is
 * active and goes last, and the flag and the position move only through their
 * own actions. Editing an entry never changes a risk already picked from it:
 * the proposal keeps the wording it copied.
 */
final class SaveRiskLibraryEntry
{
    /**
     * @param  array{title_en?: ?string, title_ar?: ?string, category?: ?string, default_likelihood?: int|string|null, default_severity?: int|string|null}  $input
     *
     * @throws ValidationException
     */
    public function handle(?int $entryId, array $input): RiskLibrary
    {
        if ($entryId === null) {
            Gate::authorize('create', RiskLibrary::class);
        } else {
            Gate::authorize('update', RiskLibrary::query()->findOrFail($entryId));
        }

        $validated = $this->validate($entryId, $input);

        return DB::transaction(function () use ($entryId, $validated): RiskLibrary {
            $entry = $entryId === null
                ? new RiskLibrary
                : RiskLibrary::query()->lockForUpdate()->findOrFail($entryId);

            $entry->fill($validated);

            if (! $entry->exists) {
                $entry->forceFill(['sort_order' => (int) RiskLibrary::query()->lockForUpdate()->max('sort_order') + 1]);
            }

            $entry->save();

            return $entry;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{title_en: string, title_ar: string, category: ?string, default_likelihood: ?int, default_severity: ?int}
     */
    private function validate(?int $entryId, array $input): array
    {
        $text = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;
        $rating = fn (mixed $value): int|string|null => $value === null || $value === '' ? null : $value;

        /** @var array{title_en: string, title_ar: string, category: ?string, default_likelihood: ?int, default_severity: ?int} */
        return Validator::make(
            [
                'title_en' => $text($input['title_en'] ?? null),
                'title_ar' => $text($input['title_ar'] ?? null),
                'category' => $text($input['category'] ?? null),
                'default_likelihood' => $rating($input['default_likelihood'] ?? null),
                'default_severity' => $rating($input['default_severity'] ?? null),
            ],
            [
                'title_en' => ['required', 'string', 'max:255', Rule::unique('risk_library', 'title_en')->ignore($entryId)],
                'title_ar' => ['required', 'string', 'max:255'],
                'category' => ['nullable', 'string', 'max:40'],
                'default_likelihood' => ['nullable', 'integer', 'between:1,3'],
                'default_severity' => ['nullable', 'integer', 'between:1,3'],
            ],
            [
                'title_en.required' => __('risk_library.enter_english_title'),
                'title_en.max' => __('risk_library.english_title_too_long'),
                'title_en.unique' => __('risk_library.english_title_taken'),
                'title_ar.required' => __('risk_library.enter_arabic_title'),
                'title_ar.max' => __('risk_library.arabic_title_too_long'),
                'category.max' => __('risk_library.category_too_long'),
                'default_likelihood.*' => __('risk_library.rating_between'),
                'default_severity.*' => __('risk_library.rating_between'),
            ],
        )->validate();
    }
}
