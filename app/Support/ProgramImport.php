<?php

declare(strict_types=1);

namespace App\Support;

use App\Jobs\CheckProgramLogframe;
use App\Models\HrProgram;
use App\Models\Program;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * The one-time seed of the tracking-owned `programs` columns from HR, and the
 * drift report between the two afterwards (D-68, W-34b).
 *
 * This is the only code in the application that reads a program out of HR.
 * Until W-34b the name, code, slug and active flag were read live from
 * `hchr_programs` on every page; they are ours now, so HR's copy is consulted
 * once to bring the existing programs over and from then on only to say where
 * the two have drifted apart.
 *
 * A name somebody has already edited in tracking is never overwritten unless
 * `$force`: tracking owns the name, so HR's copy is no longer the truth.
 * Slugs are carried over so existing `programs/{slug}` URLs keep working.
 * HR is only read.
 */
final class ProgramImport
{
    /**
     * How far above the highest known id new tracking programs start, so a
     * program HR adds before the data is unified cannot collide with one of
     * ours (`W-34.md` §9).
     */
    public const IdHeadroom = 1000;

    /**
     * What an import would do, without doing any of it.
     *
     * @return array{inserts: Collection<int, stdClass>, updates: Collection<int, non-empty-array{name_en?: string, code?: string, slug?: string, is_active?: bool}>, orphans: Collection<int, stdClass>, kept: Collection<int, array{id: int, ours: string, theirs: string}>}
     */
    public function plan(bool $force = false): array
    {
        $hrPrograms = HrProgram::query()->toBase()->get(['id', 'name', 'code', 'slug', 'is_active'])->keyBy('id');
        $tracked = DB::table('programs')->get(['id', 'name_en', 'name_ar', 'code', 'slug', 'is_active'])->keyBy('id');

        return [
            'inserts' => $hrPrograms->reject(fn (stdClass $program): bool => $tracked->has($program->id)),
            'updates' => $this->pendingUpdates($hrPrograms, $tracked, $force),
            'orphans' => $tracked->reject(fn (stdClass $program): bool => $hrPrograms->has($program->id)),
            'kept' => $force ? collect() : $this->namesKeptInTracking($hrPrograms, $tracked),
        ];
    }

    /**
     * Write the plan, raise the id floor clear of HR's range, and ask about
     * the logframes of the programs this brought over.
     *
     * @return array{inserted: int, updated: int, idFloor: ?int}
     */
    public function apply(bool $force = false): array
    {
        $plan = $this->plan($force);
        $inserts = $plan['inserts'];
        $updates = $plan['updates'];

        DB::transaction(function () use ($inserts, $updates): void {
            $now = now();

            if ($inserts->isNotEmpty()) {
                DB::table('programs')->insert($inserts->map(fn (stdClass $program): array => [
                    'id' => $program->id,
                    'name_en' => trim((string) $program->name),
                    'code' => trim((string) $program->code),
                    'slug' => trim((string) $program->slug),
                    'is_active' => (bool) $program->is_active,
                    'created_at' => $now,
                    'updated_at' => $now,
                ])->values()->all());
            }

            foreach ($updates as $id => $changes) {
                DB::table('programs')->where('id', $id)->update([...$changes, 'updated_at' => $now]);
            }
        });

        Program::forgetLookup();

        if ($inserts->isNotEmpty()) {
            CheckProgramLogframe::dispatch(array_values($inserts->keys()->map(fn (mixed $id): int => (int) $id)->all()));
        }

        return ['inserted' => $inserts->count(), 'updated' => $updates->count(), 'idFloor' => $this->raiseIdFloor()];
    }

    /**
     * The tracking rows whose details differ from HR's, with the columns to
     * write. A filled `name_en` counts as tracking's own unless $force.
     *
     * @param  Collection<int, stdClass>  $hrPrograms
     * @param  Collection<int, stdClass>  $tracked
     * @return Collection<int, non-empty-array{name_en?: string, code?: string, slug?: string, is_active?: bool}>
     */
    private function pendingUpdates(Collection $hrPrograms, Collection $tracked, bool $force): Collection
    {
        $updates = [];

        foreach ($tracked as $row) {
            $hr = $hrPrograms->get($row->id);

            if ($hr === null) {
                continue;
            }

            $changes = [];

            if (($force || (string) $row->name_en === '') && trim((string) $hr->name) !== (string) $row->name_en) {
                $changes['name_en'] = trim((string) $hr->name);
            }

            if (($force || (string) $row->code === '') && trim((string) $hr->code) !== (string) $row->code) {
                $changes['code'] = trim((string) $hr->code);
            }

            if ($row->slug === null && trim((string) $hr->slug) !== '') {
                $changes['slug'] = trim((string) $hr->slug);
            }

            if ((bool) $row->is_active !== (bool) $hr->is_active) {
                $changes['is_active'] = (bool) $hr->is_active;
            }

            if ($changes !== []) {
                $updates[(int) $row->id] = $changes;
            }
        }

        return collect($updates);
    }

    /**
     * The names HR would change that tracking keeps, so the report says so
     * instead of staying silent about them.
     *
     * @param  Collection<int, stdClass>  $hrPrograms
     * @param  Collection<int, stdClass>  $tracked
     * @return Collection<int, array{id: int, ours: string, theirs: string}>
     */
    private function namesKeptInTracking(Collection $hrPrograms, Collection $tracked): Collection
    {
        return $tracked
            ->filter(function (stdClass $row) use ($hrPrograms): bool {
                $hr = $hrPrograms->get($row->id);

                return $hr !== null && (string) $row->name_en !== '' && (string) $row->name_en !== trim((string) $hr->name);
            })
            ->map(fn (stdClass $row): array => [
                'id' => (int) $row->id,
                'ours' => (string) $row->name_en,
                'theirs' => trim((string) $hrPrograms->get($row->id)->name),
            ]);
    }

    /**
     * Push the table's next id clear of the HR range, so the programs created
     * in tracking from now on cannot collide with one HR adds in the meantime.
     *
     * MySQL only: AUTO_INCREMENT is not portable, and the test suite's sqlite
     * connection has no equivalent. Returns the new floor, or null when none
     * was set.
     */
    private function raiseIdFloor(): ?int
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            return null;
        }

        $floor = max(
            (int) DB::table('programs')->max('id'),
            (int) HrProgram::query()->toBase()->max('id'),
        ) + self::IdHeadroom;

        $next = (int) (DB::selectOne('select auto_increment from information_schema.tables where table_schema = database() and table_name = ?', ['programs'])->auto_increment ?? 0);

        if ($next >= $floor) {
            return null;
        }

        DB::statement('alter table `programs` auto_increment = '.$floor);

        return $floor;
    }
}
