<?php

declare(strict_types=1);

namespace App\Actions\Programs;

use App\Models\HrEmployee;
use App\Models\Program;
use App\Models\Strategy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Create or edit a program (W-34b slice 2, D-68): tracking owns programs, so
 * the names, code and active flag are written here, alongside the strategy
 * and manager links. Creating needs `programs.create`; editing needs
 * `programs.update`. The slug is made once from `name_en` when the program is
 * created and never changes, so existing links keep working after a rename.
 */
final class SaveProgram
{
    /**
     * @param  array{name_en?: ?string, name_ar?: ?string, code?: ?string, is_active?: bool|int|string|null, strategy_id?: int|string|null, manager_id?: int|string|null}  $input
     *
     * @throws ValidationException
     */
    public function handle(?int $programId, array $input): Program
    {
        if ($programId === null) {
            Gate::authorize('create', Program::class);
        } else {
            Gate::authorize('update', Program::query()->findOrFail($programId));
        }

        $validated = $this->validate($programId, $input);

        return DB::transaction(function () use ($programId, $validated): Program {
            $program = $programId === null
                ? new Program
                : Program::query()->lockForUpdate()->findOrFail($programId);

            $program->fill([
                'name_en' => $validated['name_en'],
                'name_ar' => $validated['name_ar'],
                'code' => $validated['code'] ?? '',
                'strategy_id' => $validated['strategy_id'],
                'manager_id' => $validated['manager_id'],
            ]);
            $program->forceFill(['is_active' => $validated['is_active']]);
            $program->save();

            return $program;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{name_en: string, name_ar: ?string, code: ?string, is_active: bool, strategy_id: ?int, manager_id: ?int}
     */
    private function validate(?int $programId, array $input): array
    {
        $trimmed = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;
        $id = fn (mixed $value): mixed => $value === '' ? null : $value;

        $validated = Validator::make(
            [
                'name_en' => $trimmed($input['name_en'] ?? null),
                'name_ar' => $trimmed($input['name_ar'] ?? null),
                'code' => $trimmed($input['code'] ?? null),
                'is_active' => $input['is_active'] ?? true,
                'strategy_id' => $id($input['strategy_id'] ?? null),
                'manager_id' => $id($input['manager_id'] ?? null),
            ],
            [
                'name_en' => ['required', 'string', 'max:255'],
                'name_ar' => ['nullable', 'string', 'max:255'],
                // Indicator Library codes start with the program code, so it must be unique (D-10a).
                'code' => ['nullable', 'string', 'max:50', Rule::unique(Program::class, 'code')->ignore($programId)],
                'is_active' => ['boolean'],
                'strategy_id' => ['nullable', 'integer', Rule::exists(Strategy::class, 'id')],
                'manager_id' => ['nullable', 'integer', Rule::exists(HrEmployee::class, 'id')],
            ],
            [
                'name_en.required' => __('programs.enter_english_name'),
                'code.unique' => __('programs.code_taken'),
                'strategy_id.exists' => __('programs.the_selected_strategy_is_no_longer_available'),
                'manager_id.exists' => __('programs.the_selected_manager_is_no_longer_available'),
            ],
        )->validate();

        return [
            'name_en' => $validated['name_en'],
            'name_ar' => $validated['name_ar'],
            'code' => $validated['code'],
            'is_active' => (bool) $validated['is_active'],
            'strategy_id' => $validated['strategy_id'] === null ? null : (int) $validated['strategy_id'],
            'manager_id' => $validated['manager_id'] === null ? null : (int) $validated['manager_id'],
        ];
    }
}
