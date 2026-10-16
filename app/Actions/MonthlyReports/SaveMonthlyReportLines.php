<?php

declare(strict_types=1);

namespace App\Actions\MonthlyReports;

use App\Enums\DonorAttribution;
use App\Enums\Gender;
use App\Enums\IndicatorCollector;
use App\Enums\IndicatorMeasure;
use App\Enums\MonthlyReportEntryKind;
use App\Enums\MonthlyReportStatus;
use App\Models\Activity;
use App\Models\LibraryIndicator;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineShare;
use App\Support\MonthlyReportGuard;
use App\Support\MonthlyReportLineBuilder;
use App\Support\MonthlyReportMirrors;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Save the figures of some lines of a monthly report (contracts §13 Entry,
 * D-25, D-46, D-48a, D-50). The server computes every value and total; any
 * value or denominator the request sends for a computed figure is ignored.
 *
 * - In a draft, the preparer side fills the manager's lines (`update`).
 * - In a submitted report, the validator fills the MEAL lines only
 *   (`fillMealLines`). Either side touching the other's line → 403.
 * - `shared` attribution: the line is entered once and copied to every
 *   share. `split`: each share is entered and the line is their sum.
 * - Lines load through the report and shares through their line; one
 *   foreign id rejects the whole batch. Nothing is written on any error.
 */
final class SaveMonthlyReportLines
{
    public function __construct(private MonthlyReportMirrors $mirrors) {}

    /**
     * @param  list<array{
     *     id: int,
     *     value?: int|float|string|null,
     *     numerator?: int|float|string|null,
     *     denominator?: int|float|string|null,
     *     not_measured?: bool,
     *     note?: string|null,
     *     breakdowns?: list<array<string, mixed>>,
     *     shares?: list<array{id: int, value?: int|float|string|null, numerator?: int|float|string|null, denominator?: int|float|string|null, not_measured?: bool, breakdowns?: list<array<string, mixed>>}>,
     * }>  $lines
     */
    public function handle(MonthlyReport $report, MonthlyReportStatus $expectedStatus, array $lines): MonthlyReport
    {
        $this->validateShape($lines);

        return DB::transaction(function () use ($report, $expectedStatus, $lines): MonthlyReport {
            $ability = $expectedStatus === MonthlyReportStatus::Submitted ? 'fillMealLines' : 'update';
            $locked = MonthlyReportGuard::lock($report, $expectedStatus, $ability);
            $locked->project->loadMissing('program:id,donor_attribution');
            $attribution = MonthlyReportLineBuilder::attributionOf($locked->project);
            $actorId = (int) Auth::id();

            /** @var Collection<int, MonthlyReportLine> $models */
            $models = $locked->lines()
                ->with(['libraryIndicator', 'shares.mirror', 'shares.planIndicator.indicator'])
                ->findOrFail(array_map(fn (array $line): int => (int) $line['id'], $lines))
                ->keyBy('id');

            $byValidator = $locked->status === MonthlyReportStatus::Submitted;

            foreach ($models as $model) {
                if (($model->entered_by === IndicatorCollector::Meal) !== $byValidator) {
                    throw new AuthorizationException;
                }
            }

            $errors = [];
            $planned = [];

            foreach ($lines as $index => $input) {
                $line = $models[(int) $input['id']];
                $planned[] = [$line, $input, $this->plan($line, $input, $attribution, "lines.{$index}", $errors)];
            }

            if ($errors !== []) {
                throw ValidationException::withMessages($errors);
            }

            Activity::withoutRecording(function () use ($planned, $locked, $actorId, $byValidator): void {
                foreach ($planned as [$line, $input, $plan]) {
                    $this->write($line, $input, $plan, $actorId);
                    $this->mirrors->sync($locked, $line->shares, $actorId);
                }

                if (! $byValidator && $locked->prepared_by === null) {
                    $locked->forceFill(['prepared_by' => $actorId])->save();
                }
            });

            return $locked->refresh();
        });
    }

    /**
     * The computed figures of a line and its shares.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, list<string>>  $errors
     * @return array{line: array<string, mixed>, shares: array<int, array<string, mixed>>, breakdowns: array<int, list<array<string, mixed>>|null>}
     */
    private function plan(MonthlyReportLine $line, array $input, DonorAttribution $attribution, string $path, array &$errors): array
    {
        $library = $line->libraryIndicator;

        if ($attribution === DonorAttribution::Shared) {
            [$figures, $breakdowns] = $this->figures($input, $library, $line->fixed_denominator, $path, $errors);

            return [
                'line' => $figures,
                'shares' => $line->shares->mapWithKeys(fn (MonthlyReportLineShare $share): array => [$share->id => $figures])->all(),
                'breakdowns' => array_key_exists('breakdowns', $input) ? [0 => $breakdowns] : [],
            ];
        }

        $shares = $line->shares->keyBy('id');
        $planned = [];
        $breakdowns = [];

        foreach ($input['shares'] ?? [] as $shareIndex => $shareInput) {
            $share = $shares[(int) $shareInput['id']] ?? throw (new ModelNotFoundException)->setModel(MonthlyReportLineShare::class, [(int) $shareInput['id']]);
            [$planned[$share->id], $shareBreakdowns] = $this->figures($shareInput, $library, $share->fixed_denominator, "{$path}.shares.{$shareIndex}", $errors);

            if (array_key_exists('breakdowns', $shareInput)) {
                $breakdowns[$share->id] = $shareBreakdowns;
            }
        }

        $all = $shares->map(fn (MonthlyReportLineShare $share): array => $planned[$share->id] ?? [
            'value' => $share->value, 'numerator' => $share->numerator, 'denominator' => $share->denominator, 'not_measured' => $share->not_measured,
        ]);

        return ['line' => $this->sum($all->all(), $library), 'shares' => $planned, 'breakdowns' => $breakdowns];
    }

    /**
     * One figure set from one entry: the line in `shared`, a share in `split`.
     *
     * @param  array<string, mixed>  $input
     * @param  array<string, list<string>>  $errors
     * @return array{0: array{value: ?float, numerator: ?float, denominator: ?float, not_measured: bool}, 1: list<array<string, mixed>>|null}
     */
    private function figures(array $input, LibraryIndicator $library, ?int $fixedDenominator, string $path, array &$errors): array
    {
        $kind = MonthlyReportEntryKind::for($library);
        $value = self::number($input['value'] ?? null);
        $numerator = self::number($input['numerator'] ?? null);
        $denominator = self::number($input['denominator'] ?? null);
        $breakdowns = null;

        if ($library->is_disaggregated && ! empty($input['breakdowns'])) {
            [$breakdowns, $value, $numerator, $denominator] = $this->breakdowns($input['breakdowns'], $kind, $path, $errors, $value, $numerator, $denominator);
        }

        $empty = ['value' => null, 'numerator' => null, 'denominator' => null, 'not_measured' => false];

        $figures = match ($kind) {
            MonthlyReportEntryKind::Count => [...$empty, 'value' => $value],
            MonthlyReportEntryKind::FixedRatio => $this->fixedRatio($numerator, $fixedDenominator, $path, $errors),
            MonthlyReportEntryKind::EnteredRatio => ($input['not_measured'] ?? false) === true
                ? [...$empty, 'not_measured' => true]
                : $this->enteredRatio($numerator, $denominator, $library, $path, $errors),
        };

        return [$figures, $breakdowns];
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array{value: ?float, numerator: ?float, denominator: ?float, not_measured: bool}
     */
    private function fixedRatio(?float $count, ?int $fixedDenominator, string $path, array &$errors): array
    {
        if ($count !== null && $fixedDenominator !== null && $count > $fixedDenominator) {
            $errors["{$path}.numerator"] = [self::message('above_fixed_denominator', ['count' => $fixedDenominator])];
        }

        $value = $count !== null && $fixedDenominator !== null && $fixedDenominator > 0 ? round($count / $fixedDenominator * 100, 2) : null;

        return ['value' => $value, 'numerator' => $count, 'denominator' => $fixedDenominator === null ? null : (float) $fixedDenominator, 'not_measured' => false];
    }

    /**
     * @param  array<string, list<string>>  $errors
     * @return array{value: ?float, numerator: ?float, denominator: ?float, not_measured: bool}
     */
    private function enteredRatio(?float $numerator, ?float $denominator, LibraryIndicator $library, string $path, array &$errors): array
    {
        $figures = ['value' => null, 'numerator' => $numerator, 'denominator' => $denominator, 'not_measured' => false];

        if ($numerator === null && $denominator === null) {
            return $figures;
        }

        if ($numerator === null || $denominator === null) {
            $errors["{$path}.".($numerator === null ? 'numerator' : 'denominator')] = [self::message('value_required')];

            return $figures;
        }

        if ($denominator <= 0.0) {
            $errors["{$path}.denominator"] = [self::message('zero_denominator')];

            return $figures;
        }

        if ($library->measure === IndicatorMeasure::Percentage && $numerator > $denominator) {
            $errors["{$path}.numerator"] = [self::message('numerator_above_denominator')];
        }

        return [...$figures, 'value' => round($numerator / $denominator * MonthlyReportEntryKind::multiplierFor($library), 2)];
    }

    /**
     * The by-sex breakdown rows (D-50) and the totals they make.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  array<string, list<string>>  $errors
     * @return array{0: list<array<string, mixed>>, 1: ?float, 2: ?float, 3: ?float}
     */
    private function breakdowns(array $rows, MonthlyReportEntryKind $kind, string $path, array &$errors, ?float $value, ?float $numerator, ?float $denominator): array
    {
        $fields = match ($kind) {
            MonthlyReportEntryKind::Count => ['value'],
            MonthlyReportEntryKind::FixedRatio => ['numerator'],
            MonthlyReportEntryKind::EnteredRatio => ['numerator', 'denominator'],
        };
        $clean = [];
        $totals = [];

        foreach ($rows as $index => $row) {
            $stored = ['gender' => $row['gender']];

            foreach ($fields as $field) {
                $amount = self::number($row[$field] ?? null);
                $disabled = self::number($row["disabled_{$field}"] ?? null);
                $stored[$field] = $amount;
                $stored["disabled_{$field}"] = $disabled;

                if ($disabled !== null && $disabled > ($amount ?? 0.0)) {
                    $errors["{$path}.breakdowns.{$index}.disabled_{$field}"] = [self::message('disabled_above_gender')];
                }

                if ($amount !== null) {
                    $totals[$field] = ($totals[$field] ?? 0.0) + $amount;
                }
            }

            $clean[] = $stored;
        }

        return match ($kind) {
            MonthlyReportEntryKind::Count => [$clean, $totals['value'] ?? null, $numerator, $denominator],
            MonthlyReportEntryKind::FixedRatio => [$clean, $value, $totals['numerator'] ?? null, $denominator],
            MonthlyReportEntryKind::EnteredRatio => [$clean, $value, $totals['numerator'] ?? null, $totals['denominator'] ?? null],
        };
    }

    /**
     * The line total of `split` shares: counts sum; ratios pool
     * Σ numerator ÷ Σ denominator over the measured shares (D-25).
     *
     * @param  array<int, array<string, mixed>>  $shares
     * @return array{value: ?float, numerator: ?float, denominator: ?float, not_measured: bool}
     */
    private function sum(array $shares, LibraryIndicator $library): array
    {
        $kind = MonthlyReportEntryKind::for($library);
        $values = array_filter(array_map(fn (array $share): ?float => self::number($share['value']), $shares), fn (?float $value): bool => $value !== null);

        if ($kind === MonthlyReportEntryKind::Count) {
            return ['value' => $values === [] ? null : array_sum($values), 'numerator' => null, 'denominator' => null, 'not_measured' => false];
        }

        $measured = array_filter($shares, fn (array $share): bool => ! $share['not_measured'] && self::number($share['numerator']) !== null && self::number($share['denominator']) !== null);
        $numerator = array_sum(array_map(fn (array $share): float => (float) self::number($share['numerator']), $measured));
        $denominator = array_sum(array_map(fn (array $share): float => (float) self::number($share['denominator']), $measured));
        $allNotMeasured = $shares !== [] && array_filter($shares, fn (array $share): bool => ! $share['not_measured']) === [];

        if ($measured === [] || $denominator <= 0.0) {
            return ['value' => null, 'numerator' => null, 'denominator' => null, 'not_measured' => $allNotMeasured];
        }

        return [
            'value' => round($numerator / $denominator * MonthlyReportEntryKind::multiplierFor($library), 2),
            'numerator' => $numerator,
            'denominator' => $denominator,
            'not_measured' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @param  array{line: array<string, mixed>, shares: array<int, array<string, mixed>>, breakdowns: array<int, list<array<string, mixed>>|null>}  $plan
     */
    private function write(MonthlyReportLine $line, array $input, array $plan, int $actorId): void
    {
        $line->fill([...$plan['line'], 'filled_by' => $actorId]);

        if (array_key_exists('note', $input)) {
            $line->note = $input['note'] === null ? null : (string) $input['note'];
        }

        $line->save();

        foreach ($line->shares as $share) {
            if (array_key_exists($share->id, $plan['shares'])) {
                $share->fill($plan['shares'][$share->id])->save();
            }
        }

        foreach ($plan['breakdowns'] as $shareId => $rows) {
            $scope = $line->breakdowns();
            $shareId === 0 ? $scope->whereNull('monthly_report_line_share_id') : $scope->where('monthly_report_line_share_id', $shareId);
            $scope->delete();

            foreach ($rows ?? [] as $row) {
                $line->breakdowns()->create([...$row, 'monthly_report_line_share_id' => $shareId === 0 ? null : $shareId]);
            }
        }
    }

    /**
     * Types only; the domain rules are checked in plan().
     *
     * @param  array<int, mixed>  $lines
     */
    private function validateShape(array $lines): void
    {
        $number = ['nullable', 'numeric', 'min:0', 'max:999999999999'];
        $breakdown = [
            'gender' => ['required', Rule::enum(Gender::class)],
            'value' => $number, 'numerator' => $number, 'denominator' => $number,
            'disabled_value' => $number, 'disabled_numerator' => $number, 'disabled_denominator' => $number,
        ];
        $rules = ['lines' => ['present', 'array'], 'lines.*.id' => ['required', 'integer'], 'lines.*.note' => ['nullable', 'string', 'max:5000'],
            'lines.*.not_measured' => ['sometimes', 'boolean'], 'lines.*.shares' => ['sometimes', 'array'], 'lines.*.shares.*.id' => ['required', 'integer'],
            'lines.*.shares.*.not_measured' => ['sometimes', 'boolean'], 'lines.*.breakdowns' => ['sometimes', 'array', 'max:2'], 'lines.*.shares.*.breakdowns' => ['sometimes', 'array', 'max:2']];

        foreach (['value', 'numerator', 'denominator'] as $field) {
            $rules["lines.*.{$field}"] = $number;
            $rules["lines.*.shares.*.{$field}"] = $number;
        }

        foreach ($breakdown as $field => $rule) {
            $rules["lines.*.breakdowns.*.{$field}"] = $rule;
            $rules["lines.*.shares.*.breakdowns.*.{$field}"] = $rule;
        }

        $rules['lines.*.breakdowns.*.gender'][] = 'distinct';
        $rules['lines.*.shares.*.breakdowns.*.gender'][] = 'distinct';

        Validator::make(['lines' => $lines], $rules)->validate();
    }

    /**
     * A `monthly_reports.*` message.
     *
     * @param  array<string, int|string>  $replace
     */
    private static function message(string $key, array $replace = []): string
    {
        return trans()->get("monthly_reports.{$key}", $replace);
    }

    private static function number(mixed $value): ?float
    {
        return $value === null || $value === '' ? null : (float) $value;
    }
}
