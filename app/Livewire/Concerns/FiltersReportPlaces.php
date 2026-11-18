<?php

declare(strict_types=1);

namespace App\Livewire\Concerns;

use App\Models\HrCenter;
use App\Models\HrCity;
use App\Support\Reporting\ReportPlace;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The place half of the D-79 filter set, shared by the monthly matrix and
 * the monthly-reports list (Y-05) so both read a `center` value, label a
 * place and offer the same center options one way: `c{hrCenterId}`,
 * `field-{cityId}` (Field work, D-88) or `unset` (Center not set, D-82),
 * never merged.
 */
trait FiltersReportPlaces
{
    /** @var Collection<int, HrCity>|null */
    private ?Collection $allCitiesMemo = null;

    /**
     * The city a `center` value stands in: an HR center's city or a Field
     * work city; null for Center not set or an unknown value.
     */
    protected function cityOfCenter(string $value): ?int
    {
        if (preg_match('/^c(\d{1,18})$/', $value, $match) === 1) {
            return HrCenter::remembered((int) $match[1])?->city_id;
        }

        return preg_match('/^field-(\d{1,18})$/', $value, $match) === 1 ? (int) $match[1] : null;
    }

    /**
     * The id a filter value names when that record exists, else null.
     *
     * @param  class-string<Model>|null  $model
     * @param  list<int>|null  $allowed  a fixed list to check against instead of a table
     */
    protected function existingId(string $value, ?string $model, ?array $allowed = null): ?int
    {
        if ($value === '' || ! ctype_digit($value) || strlen($value) > 18) {
            return null;
        }

        $id = (int) $value;

        if ($allowed !== null) {
            return in_array($id, array_map('intval', $allowed), true) ? $id : null;
        }

        return $model !== null && $model::query()->whereKey($id)->exists() ? $id : null;
    }

    /**
     * A `center` value as ExtractionFilter arguments: c{id} (an HR center
     * that exists), field-{city} (that city's Field work, with its city) or
     * unset; an unknown value is no filter.
     *
     * @return array{centerId?: int, cityWide?: true, cityId?: int, centerUnset?: true}
     */
    protected function centerArgumentsOf(string $value): array
    {
        if ($value === 'unset') {
            return ['centerUnset' => true];
        }

        if (preg_match('/^field-(\d{1,18})$/', $value, $match) === 1 && $this->allCities()->has((int) $match[1])) {
            return ['cityWide' => true, 'cityId' => (int) $match[1]];
        }

        if (preg_match('/^c(\d{1,18})$/', $value, $match) === 1 && HrCenter::remembered((int) $match[1]) !== null) {
            return ['centerId' => (int) $match[1]];
        }

        return [];
    }

    /**
     * The `center` value of a place.
     */
    protected function centerValueOf(ReportPlace $place): string
    {
        return match (true) {
            $place->cityWide => 'field-'.$place->cityId,
            $place->centerId !== null => 'c'.$place->centerId,
            default => 'unset',
        };
    }

    /**
     * The places a viewer can pick (D-79, D-82, D-88): each HR center that
     * holds a report template ("center · city"), one Field work option per
     * city with a city-wide template, and Center not set when a template has
     * no center; narrowed by city and program, and to the given templates
     * when a list is passed.
     *
     * @param  list<int>|null  $templateIds
     * @return list<array{value: string, label: string}>
     */
    protected function centerOptionsFor(?int $cityId, ?int $programId, ?array $templateIds = null): array
    {
        if ($templateIds === []) {
            return [];
        }

        $places = DB::table('report_templates')
            ->whereNotNull('city_id')
            ->whereNotNull('program_id')
            ->when($templateIds !== null, fn ($query) => $query->whereIn('id', $templateIds))
            ->when($cityId !== null, fn ($query) => $query->where('city_id', $cityId))
            ->when($programId !== null, fn ($query) => $query->where('program_id', $programId))
            ->distinct()
            ->get(['city_id', 'center_id', 'city_wide']);

        $centers = [];
        $fieldWork = [];
        $hasUnset = false;

        foreach ($places as $place) {
            $placeCityId = (int) $place->city_id;

            if ((bool) $place->city_wide) {
                $fieldWork['field-'.$placeCityId] = $this->placeLabel(new ReportPlace($placeCityId, 0, null, true))['center'];
            } elseif ($place->center_id !== null) {
                $centers['c'.$place->center_id] = $this->placeLabel(new ReportPlace($placeCityId, 0, (int) $place->center_id, false))['center'];
            } else {
                $hasUnset = true;
            }
        }

        $sorted = fn (array $options): array => collect($options)
            ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
            ->sortBy(fn (array $option): string => mb_strtolower($option['label']))
            ->values()
            ->all();

        return [
            ...$sorted($centers),
            ...$sorted($fieldWork),
            ...($hasUnset ? [['value' => 'unset', 'label' => __('reports.matrix.place_not_set')]] : []),
        ];
    }

    /**
     * A `Y-m` value as its first day, or null.
     */
    protected function monthOf(string $value): ?CarbonImmutable
    {
        return preg_match('/^(\d{4})-(0[1-9]|1[0-2])$/', $value, $match) === 1
            ? CarbonImmutable::create((int) $match[1], (int) $match[2], 1)
            : null;
    }

    /**
     * Every HR city, active or not, keyed by id: one HR read per request.
     *
     * @return Collection<int, HrCity>
     */
    protected function allCities(): Collection
    {
        return $this->allCitiesMemo ??= HrCity::query()->orderBy(HrCity::nameColumn())->orderBy('id')->get(['id', 'name_en', 'name_ar', 'is_active'])->keyBy('id');
    }

    /**
     * A place's labels: "center · city", "Field work · city" or "Center not
     * set", and the city's name.
     *
     * @return array{center: string, city: ?string}
     */
    protected function placeLabel(ReportPlace $place): array
    {
        $city = $this->allCities()->get($place->cityId)?->name;
        $cityLabel = $city ?? (string) $place->cityId;

        $center = match (true) {
            $place->cityWide => __('reports.matrix.place_field_work', ['city' => $cityLabel]),
            $place->centerId !== null => __('reports.matrix.place_center', ['center' => HrCenter::remembered($place->centerId)->name ?? '#'.$place->centerId, 'city' => $cityLabel]),
            default => __('reports.matrix.place_not_set', ['city' => $cityLabel]),
        };

        return ['center' => $center, 'city' => $city];
    }
}
