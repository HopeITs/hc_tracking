<?php

declare(strict_types=1);

namespace App\Livewire\Concerns\MonthlyReports;

use App\Actions\MonthlyReports\SaveMonthlyReportLines;
use App\Enums\DonorAttribution;
use App\Enums\Gender;
use App\Enums\MonthlyReportEntryKind;
use App\Enums\MonthlyReportStatus;
use App\Models\MonthlyReport;
use App\Models\MonthlyReportLine;
use App\Models\MonthlyReportLineBreakdown;
use Flux\Flux;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

/**
 * Autosave of the monthly report grid (T17B): each blur sends that one line,
 * whole, to SaveMonthlyReportLines with the status the page rendered. The
 * server computes every value and total, so only the entered figures go
 * out; errors come back onto the input that caused them.
 *
 * Requires ReadsMonthlyReport.
 *
 * @property-read MonthlyReport $report  #[Computed] report()
 * @property-read DonorAttribution $attribution  #[Computed] attribution()
 */
trait EditsMonthlyReportLines
{
    /**
     * The entered figures, keyed by line id:
     * {value, numerator, denominator, not_measured, note,
     *  breakdowns: {male|female: {value, numerator, denominator, disabled_*}},
     *  shares: {share id: {value, numerator, denominator, not_measured, breakdowns}}}.
     *
     * @var array<int|string, array<string, mixed>>
     */
    public array $figures = [];

    /**
     * Save the line whose figure just changed.
     */
    public function updatedFigures(mixed $value, string $key): void
    {
        $lineId = (int) explode('.', $key)[0];
        $this->forgetLineErrors($lineId);
        $line = $this->report->lines->firstWhere('id', $lineId);

        if ($line === null) {
            unset($this->figures[$lineId]);
            $this->addError('figures.'.$lineId, __('monthly_reports.line_not_in_report'));

            return;
        }

        [$payload, $shareIds] = $this->linePayload($line);

        try {
            app(SaveMonthlyReportLines::class)->handle($this->report, $this->renderedStatus(), [$payload]);
        } catch (ValidationException $exception) {
            $this->showLineErrors($lineId, $exception->errors(), $shareIds);

            return;
        } catch (AuthorizationException) {
            $this->refuseLineSave();

            return;
        } catch (ModelNotFoundException) {
            $this->addError('figures.'.$lineId, __('monthly_reports.line_not_in_report'));

            return;
        }

        $this->forgetMonthlyReport();
        $this->seedFigures($lineId);
    }

    /**
     * Fill the inputs from the stored figures: every line, or one.
     */
    protected function seedFigures(?int $onlyLineId = null): void
    {
        if ($onlyLineId === null) {
            $this->figures = [];
        }

        foreach ($this->report->lines as $line) {
            if ($onlyLineId !== null && $line->id !== $onlyLineId) {
                continue;
            }

            $state = [
                ...self::entryState($line->value, $line->numerator, $line->denominator, $line->not_measured),
                'note' => $line->note ?? '',
                'breakdowns' => self::breakdownState($line->breakdowns->whereNull('monthly_report_line_share_id')->all()),
                'shares' => [],
            ];

            foreach ($line->shares as $share) {
                $state['shares'][$share->id] = [
                    ...self::entryState($share->value, $share->numerator, $share->denominator, $share->not_measured),
                    'breakdowns' => self::breakdownState($line->breakdowns->where('monthly_report_line_share_id', $share->id)->all()),
                ];
            }

            $this->figures[$line->id] = $state;
        }
    }

    /**
     * The SaveMonthlyReportLines entry for one line, and the share ids in
     * the order they were sent (to put errors back on the right input).
     *
     * @return array{0: array{id: int, note: ?string, value?: ?string, numerator?: ?string, denominator?: ?string, not_measured?: bool, breakdowns?: list<array<string, mixed>>, shares?: list<array{id: int, value?: ?string, numerator?: ?string, denominator?: ?string, not_measured?: bool, breakdowns?: list<array<string, mixed>>}>}, 1: list<int>}
     */
    protected function linePayload(MonthlyReportLine $line): array
    {
        $state = $this->figures[$line->id] ?? [];
        $payload = ['id' => $line->id, 'note' => self::text($state['note'] ?? null)];
        $shareIds = [];

        if ($this->attribution === DonorAttribution::Split && $line->shares->isNotEmpty()) {
            $payload['shares'] = [];

            foreach ($line->shares as $share) {
                $shareIds[] = $share->id;
                $payload['shares'][] = ['id' => $share->id, ...$this->entryPayload($line, $state['shares'][$share->id] ?? [])];
            }

            return [$payload, $shareIds];
        }

        return [[...$payload, ...$this->entryPayload($line, $state)], $shareIds];
    }

    /**
     * The entered figures of a line (shared) or a share (split): only the
     * fields its entry kind takes.
     *
     * @param  array<string, mixed>  $state
     * @return array{value?: ?string, numerator?: ?string, denominator?: ?string, not_measured?: bool, breakdowns?: list<array<string, mixed>>}
     */
    protected function entryPayload(MonthlyReportLine $line, array $state): array
    {
        $kind = MonthlyReportEntryKind::for($line->libraryIndicator);
        $fields = self::fieldsFor($kind);
        $entry = [];

        if ($line->libraryIndicator->is_disaggregated) {
            $entry['breakdowns'] = self::breakdownPayload($state, $fields);
        } else {
            if (in_array('value', $fields, true)) {
                $entry['value'] = self::number($state['value'] ?? null);
            }

            if (in_array('numerator', $fields, true)) {
                $entry['numerator'] = self::number($state['numerator'] ?? null);
            }

            if (in_array('denominator', $fields, true)) {
                $entry['denominator'] = self::number($state['denominator'] ?? null);
            }
        }

        if ($kind === MonthlyReportEntryKind::EnteredRatio) {
            $entry['not_measured'] = (bool) ($state['not_measured'] ?? false);
        }

        return $entry;
    }

    /**
     * One row per sex (D-50) with the kind's fields and their "of whom
     * persons with disabilities".
     *
     * @param  array<string, mixed>  $state
     * @param  list<string>  $fields
     * @return list<array<string, mixed>>
     */
    protected static function breakdownPayload(array $state, array $fields): array
    {
        $rows = [];

        foreach (Gender::cases() as $gender) {
            $row = ['gender' => $gender->value];

            foreach ($fields as $field) {
                $row[$field] = self::number($state['breakdowns'][$gender->value][$field] ?? null);
                $row["disabled_{$field}"] = self::number($state['breakdowns'][$gender->value]["disabled_{$field}"] ?? null);
            }

            $rows[] = $row;
        }

        return $rows;
    }

    /**
     * Put `lines.0.…` errors on the inputs (`figures.{line}.…`); anything
     * about the report itself (stale, locked) is a toast and a reload.
     *
     * @param  array<string, list<string>>  $errors
     * @param  list<int>  $shareIds
     */
    protected function showLineErrors(int $lineId, array $errors, array $shareIds): void
    {
        foreach ($errors as $key => $messages) {
            if (! str_starts_with($key, 'lines.0.')) {
                Flux::toast(variant: 'danger', text: $messages[0]);
                $this->forgetMonthlyReport();
                $this->seedFigures();

                return;
            }

            $this->addError('figures.'.$lineId.'.'.self::inputPath(substr($key, strlen('lines.0.')), $shareIds), $messages[0]);
        }
    }

    /**
     * A save the policy refused: the report was submitted or the
     * permission is gone.
     */
    protected function refuseLineSave(): void
    {
        $locked = $this->report->status !== MonthlyReportStatus::Draft;
        Flux::toast(variant: 'danger', text: $locked ? __('monthly_reports.locked') : __('monthly_reports.not_allowed'));
        $this->forgetMonthlyReport();
        $this->seedFigures();
    }

    protected function forgetLineErrors(int $lineId): void
    {
        $bag = $this->getErrorBag();

        foreach ($bag->keys() as $key) {
            if ($key === 'figures.'.$lineId || str_starts_with($key, 'figures.'.$lineId.'.')) {
                $bag->forget($key);
            }
        }
    }

    /**
     * `shares.1.breakdowns.0.disabled_value` → `shares.{id}.breakdowns.male.disabled_value`.
     *
     * @param  list<int>  $shareIds
     */
    protected static function inputPath(string $path, array $shareIds): string
    {
        $segments = explode('.', $path);
        $genders = array_map(fn (Gender $gender): string => $gender->value, Gender::cases());

        foreach ($segments as $index => $segment) {
            $next = $segments[$index + 1] ?? null;

            if ($segment === 'shares' && is_numeric($next)) {
                $segments[$index + 1] = (string) ($shareIds[(int) $next] ?? $next);
            }

            if ($segment === 'breakdowns' && is_numeric($next)) {
                $segments[$index + 1] = $genders[(int) $next] ?? $next;
            }
        }

        return implode('.', $segments);
    }

    /**
     * @return list<string>
     */
    protected static function fieldsFor(MonthlyReportEntryKind $kind): array
    {
        return match ($kind) {
            MonthlyReportEntryKind::Count => ['value'],
            MonthlyReportEntryKind::FixedRatio => ['numerator'],
            MonthlyReportEntryKind::EnteredRatio => ['numerator', 'denominator'],
        };
    }

    /**
     * @return array{value: string, numerator: string, denominator: string, not_measured: bool}
     */
    protected static function entryState(mixed $value, mixed $numerator, mixed $denominator, bool $notMeasured): array
    {
        return [
            'value' => self::input($value),
            'numerator' => self::input($numerator),
            'denominator' => self::input($denominator),
            'not_measured' => $notMeasured,
        ];
    }

    /**
     * @param  array<int, MonthlyReportLineBreakdown>  $rows
     * @return array<string, array<string, string>>
     */
    protected static function breakdownState(array $rows): array
    {
        $state = [];

        foreach (Gender::cases() as $gender) {
            $row = collect($rows)->first(fn (MonthlyReportLineBreakdown $breakdown): bool => $breakdown->gender === $gender);
            $state[$gender->value] = [];

            foreach (['value', 'numerator', 'denominator'] as $field) {
                $state[$gender->value][$field] = self::input($row?->{$field});
                $state[$gender->value]["disabled_{$field}"] = self::input($row?->{"disabled_{$field}"});
            }
        }

        return $state;
    }

    /**
     * A stored decimal as the input shows it: "12" for "12.00", "" for NULL.
     */
    protected static function input(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $text = (string) $value;

        return str_contains($text, '.') ? rtrim(rtrim($text, '0'), '.') : $text;
    }

    protected static function number(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }

    protected static function text(mixed $value): ?string
    {
        $text = is_scalar($value) ? trim((string) $value) : '';

        return $text === '' ? null : $text;
    }
}
