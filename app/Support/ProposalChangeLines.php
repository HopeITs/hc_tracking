<?php

declare(strict_types=1);

namespace App\Support;

use App\Actions\Proposals\SaveProposal;
use App\Models\Donor;
use App\Models\HrCenter;
use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\HrProgram;
use App\Models\User;
use App\Support\Reporting\PlaceBridge;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Str;

/**
 * The one reading of a change request's `changes` (contracts.md §7, Y-09):
 * each stored `{field, from, to}` as a labelled "from → to" line. The
 * requester's summary step, the review and decision screen and the proposal
 * page's chapter all render through here, so the three lists name the same
 * differences.
 *
 * Stored values are ids and plain values; names are resolved here, at read
 * time. Budget lines are left out for a reader who may not see money.
 * forMany() resolves every id of every request with one query per kind, so a
 * list of requests costs the same as one.
 */
final class ProposalChangeLines
{
    /** Fields whose values are money: shown only to a money holder. */
    private const MoneyFields = ['requestedBudget', 'requestedCurrency'];

    /**
     * @param  list<array<string, mixed>>  $changes
     * @return list<array{field: string, label: string, place: string|null, from: string, to: string}>
     */
    public static function for(array $changes, User $viewer): array
    {
        return self::forMany([$changes], $viewer)[0];
    }

    /**
     * @param  array<int|string, list<array<string, mixed>>>  $changeLists  keyed as the caller likes
     * @return array<int|string, list<array{field: string, label: string, place: string|null, from: string, to: string}>>
     */
    public static function forMany(array $changeLists, User $viewer): array
    {
        $showsMoney = SaveProposal::canSeeMoney($viewer);
        $all = array_merge([], ...array_values($changeLists));
        $names = self::names($all);

        $lines = [];

        foreach ($changeLists as $key => $changes) {
            $lines[$key] = [];

            foreach ($changes as $change) {
                $field = (string) ($change['field'] ?? '');

                if (! $showsMoney && in_array($field, self::MoneyFields, true)) {
                    continue;
                }

                $lines[$key][] = self::line($field, $change, $names);
            }
        }

        return $lines;
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  array{donors: array<int, string>, cities: array<int, string>, employees: array<int, string>, projects: array<int, string>}  $names
     * @return array{field: string, label: string, place: string|null, from: string, to: string}
     */
    private static function line(string $field, array $change, array $names): array
    {
        $from = $change['from'] ?? null;
        $to = $change['to'] ?? null;

        if ($field === 'locations') {
            /** @var array{city_id?: int|string, program_id?: int|string|null} $row */
            $row = $change['row'] ?? [];
            $cityId = (int) ($row['city_id'] ?? 0);
            $programId = isset($row['program_id']) ? (int) $row['program_id'] : null;

            return [
                'field' => $field,
                'label' => __('proposals.change.fields.locations'),
                'place' => implode(' · ', array_filter([
                    $names['cities'][$cityId] ?? __('common.unknown_city'),
                    $programId === null ? __('proposals.change.program_waiting') : self::programName($programId),
                ])),
                'from' => is_array($from) ? self::rowValue($from, $names) : __('proposals.change.row_absent'),
                'to' => is_array($to) ? self::rowValue($to, $names) : __('proposals.change.row_removed'),
            ];
        }

        if ($field === 'beneficiaries') {
            $projectId = (int) ($change['project_id'] ?? 0);

            return [
                'field' => $field,
                'label' => __('proposals.change.fields.beneficiaries'),
                'place' => $names['projects'][$projectId] ?? '#'.$projectId,
                'from' => self::plain($from),
                'to' => self::plain($to),
            ];
        }

        $value = match ($field) {
            'donorId' => fn (mixed $id): string => $id === null ? '—' : ($names['donors'][(int) $id] ?? '#'.$id),
            'plannedDateBegin', 'plannedDateEnd' => fn (mixed $date): string => blank($date) ? '—' : CarbonImmutable::parse((string) $date)->translatedFormat(__('formats.date')),
            'requestedBudget' => fn (mixed $amount): string => blank($amount) ? '—' : number_format((float) $amount, 2),
            'notes', 'conceptNote', 'narrative' => fn (mixed $text): string => blank($text) ? '—' : Str::limit((string) $text, 160),
            default => fn (mixed $plain): string => self::plain($plain),
        };

        return [
            'field' => $field,
            'label' => __('proposals.change.fields.'.$field),
            'place' => null,
            'from' => $value($from),
            'to' => $value($to),
        ];
    }

    /**
     * One side of a location row: its centers (or field work) and responsible.
     *
     * @param  array<string, mixed>  $side
     * @param  array{donors: array<int, string>, cities: array<int, string>, employees: array<int, string>, projects: array<int, string>}  $names
     */
    private static function rowValue(array $side, array $names): string
    {
        /** @var list<int|string> $centerIds */
        $centerIds = $side['center_ids'] ?? [];

        $centers = ($side['city_wide'] ?? false) === true
            ? __('proposals.change.field_work')
            : ($centerIds === []
                ? __('proposals.change.no_center')
                : implode(', ', array_map(fn (int|string $id): string => self::centerName((int) $id), $centerIds)));

        $responsibleId = $side['responsible_employee_id'] ?? null;
        $responsible = $responsibleId === null
            ? __('proposals.change.no_responsible')
            : ($names['employees'][(int) $responsibleId] ?? '#'.$responsibleId);

        return __('proposals.change.row_value', ['centers' => $centers, 'responsible' => $responsible]);
    }

    private static function plain(mixed $value): string
    {
        return $value === null || $value === '' ? '—' : (string) $value;
    }

    /**
     * Every name the lists need, one query per kind.
     *
     * @param  list<array<string, mixed>>  $changes
     * @return array{donors: array<int, string>, cities: array<int, string>, employees: array<int, string>, projects: array<int, string>}
     */
    private static function names(array $changes): array
    {
        $ids = ['donors' => [], 'cities' => [], 'employees' => [], 'projects' => []];

        foreach ($changes as $change) {
            match ($change['field'] ?? null) {
                'donorId' => array_push($ids['donors'], ...array_filter([$change['from'] ?? null, $change['to'] ?? null], 'is_numeric')),
                'beneficiaries' => array_push($ids['projects'], (int) ($change['project_id'] ?? 0)),
                'locations' => self::collectRowIds($change, $ids),
                default => null,
            };
        }

        $ids = array_map(fn (array $list): array => array_values(array_unique(array_map('intval', $list))), $ids);

        return [
            'donors' => $ids['donors'] === [] ? [] : Donor::query()->whereKey($ids['donors'])->pluck('name', 'id')->all(),
            'cities' => $ids['cities'] === [] ? [] : HrCity::query()->whereKey($ids['cities'])->get(['id', 'name_en', 'name_ar'])->mapWithKeys(fn (HrCity $city): array => [$city->id => (string) $city->name])->all(),
            'employees' => $ids['employees'] === [] ? [] : HrEmployee::query()->whereKey($ids['employees'])->get(HrEmployee::NameColumns)->mapWithKeys(fn (HrEmployee $employee): array => [$employee->id => (string) $employee->name])->all(),
            'projects' => $ids['projects'] === [] ? [] : app(PlaceBridge::class)->projectsByIds($ids['projects'])->map(fn (mixed $project): string => (string) $project->name)->all(),
        ];
    }

    /**
     * @param  array<string, mixed>  $change
     * @param  array{donors: list<mixed>, cities: list<mixed>, employees: list<mixed>, projects: list<mixed>}  $ids
     */
    private static function collectRowIds(array $change, array &$ids): null
    {
        $ids['cities'][] = $change['row']['city_id'] ?? 0;

        foreach ([$change['from'] ?? null, $change['to'] ?? null] as $side) {
            if (is_array($side) && ($side['responsible_employee_id'] ?? null) !== null) {
                $ids['employees'][] = $side['responsible_employee_id'];
            }
        }

        return null;
    }

    private static function centerName(int $centerId): string
    {
        try {
            return HrCenter::remembered($centerId)->name ?? '#'.$centerId;
        } catch (QueryException) {
            return '#'.$centerId;
        }
    }

    private static function programName(int $programId): string
    {
        try {
            $name = HrProgram::nameOf($programId);
        } catch (QueryException) {
            $name = '';
        }

        return $name !== '' ? $name : '#'.$programId;
    }
}
