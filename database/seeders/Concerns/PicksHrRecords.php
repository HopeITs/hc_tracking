<?php

namespace Database\Seeders\Concerns;

use App\Models\HrCity;
use App\Models\HrEmployee;
use Database\Factories\Concerns\CreatesHrRecords;
use Illuminate\Support\Collection;

/**
 * Hands seeders the ids of real HR cities and employees to build demo data on.
 *
 * Seeders never create HR data. An empty HR table only happens in the test
 * suite, whose in-memory HR database accepts a few inserted rows; against the
 * real hr_db the READ ONLY connection would reject them.
 */
trait PicksHrRecords
{
    use CreatesHrRecords;

    /**
     * The ids of the HR cities projects can be placed in.
     *
     * @return Collection<int, int>
     */
    protected function hrCityIds(): Collection
    {
        $ids = HrCity::query()->pluck('id');

        return $ids->isNotEmpty() ? $ids : collect(range(1, 3))->map(fn (): int => $this->createHrCity());
    }

    /**
     * The ids of the HR employees work can be assigned to.
     *
     * @return Collection<int, int>
     */
    protected function hrEmployeeIds(): Collection
    {
        $ids = HrEmployee::query()->pluck('id');

        return $ids->isNotEmpty() ? $ids : collect(range(1, 6))->map(fn (): int => $this->createHrEmployee());
    }
}
