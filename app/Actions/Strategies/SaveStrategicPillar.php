<?php

declare(strict_types=1);

namespace App\Actions\Strategies;

use App\Models\StrategicPillar;
use App\Models\Strategy;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Create or edit a strategic pillar of a strategy (contracts §11.2, D-35).
 * An existing pillar is loaded through its strategy, so another strategy's
 * pillar is never reachable (BOLA).
 */
final class SaveStrategicPillar
{
    /**
     * @param  array{code?: ?string, name_ar?: ?string, name_en?: ?string, goal_ar?: ?string, goal_en?: ?string, sort_order?: int|string|null}  $input
     *
     * @throws ValidationException
     */
    public function handle(int $strategyId, ?int $pillarId, array $input): StrategicPillar
    {
        $strategy = Strategy::query()->findOrFail($strategyId);

        if ($pillarId === null) {
            Gate::authorize('create', StrategicPillar::class);
        } else {
            Gate::authorize('update', $strategy->pillars()->findOrFail($pillarId));
        }

        $validated = $this->validate($strategy, $pillarId, $input);

        return DB::transaction(function () use ($strategy, $pillarId, $validated): StrategicPillar {
            $pillar = $pillarId === null
                ? new StrategicPillar(['strategy_id' => $strategy->id])
                : $strategy->pillars()->lockForUpdate()->findOrFail($pillarId);

            $pillar->fill($validated)->save();

            return $pillar;
        });
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{code: string, name_ar: string, name_en: ?string, goal_ar: ?string, goal_en: ?string, sort_order: int}
     */
    private function validate(Strategy $strategy, ?int $pillarId, array $input): array
    {
        $trimmed = fn (mixed $value): ?string => is_string($value) && trim($value) !== '' ? trim($value) : null;

        $data = [
            'code' => $trimmed($input['code'] ?? null),
            'name_ar' => $trimmed($input['name_ar'] ?? null),
            'name_en' => $trimmed($input['name_en'] ?? null),
            'goal_ar' => $trimmed($input['goal_ar'] ?? null),
            'goal_en' => $trimmed($input['goal_en'] ?? null),
            'sort_order' => $input['sort_order'] ?? 0,
        ];

        /** @var array{code: string, name_ar: string, name_en: ?string, goal_ar: ?string, goal_en: ?string, sort_order: int|string} $validated */
        $validated = Validator::make($data, [
            'code' => [
                'required', 'string', 'max:20',
                Rule::unique('strategic_pillars', 'code')->where('strategy_id', $strategy->id)->ignore($pillarId),
            ],
            'name_ar' => ['required', 'string', 'max:255'],
            'name_en' => ['nullable', 'string', 'max:255'],
            'goal_ar' => ['nullable', 'string', 'max:2000'],
            'goal_en' => ['nullable', 'string', 'max:2000'],
            'sort_order' => ['integer', 'min:0', 'max:65535'],
        ], [
            'code.required' => __('strategies.pillar_code_required'),
            'code.max' => __('strategies.pillar_code_too_long'),
            'code.unique' => __('strategies.pillar_code_taken'),
            'name_ar.required' => __('strategies.pillar_name_ar_required'),
            'name_ar.max' => __('strategies.pillar_name_too_long'),
            'name_en.max' => __('strategies.pillar_name_too_long'),
            'goal_ar.max' => __('strategies.pillar_goal_too_long'),
            'goal_en.max' => __('strategies.pillar_goal_too_long'),
            'sort_order.*' => __('strategies.pillar_sort_order_invalid'),
        ])->validate();

        $validated['sort_order'] = (int) $validated['sort_order'];

        return $validated;
    }
}
