<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\IndicatorLibrary\SaveLibraryIndicator;
use App\Enums\CollectionFrequency;
use App\Enums\IndicatorCollector;
use App\Enums\IndicatorMeasure;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\HrProgram;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\Result;
use App\Support\ArabicText;
use App\Support\IndicatorCode;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * Seeds the Indicator Library from today's indicators (D-11) and links each
 * indicator to its entry, so every indicator carries a library code.
 *
 * Indicators with the same name (folded like the library search) and the
 * same measure share one legacy entry; a name used with two measures gets an
 * entry per measure and a conflict line. An entry used in one program takes
 * that program's code, otherwise it is institutional (`HC`). The level comes
 * from where the oldest indicator of the group sits in its program framework.
 * A later run links new indicators to the legacy entry with their name and
 * measure, never to an entry MEAL created by hand; when MEAL has deactivated
 * that entry, they are still linked to it, with a warning.
 *
 * Results whose parents loop stop the run before anything is planned. The
 * whole plan is printed first. A program whose code is missing or shared
 * in HR (D-10a) stops the run before anything is written. Writes run in
 * chunks, one transaction each, without recording activity. Entries are
 * written here rather than through SaveLibraryIndicator, which authorizes a
 * signed-in user the console does not have.
 */
class SeedIndicatorLibrary extends Command
{
    /**
     * Groups written per transaction.
     */
    private const int ChunkSize = 50;

    /**
     * @var string
     */
    protected $signature = 'meal:seed-indicator-library {--dry-run : List the proposed entries and codes without changing anything}';

    /**
     * @var string
     */
    protected $description = 'Link every indicator that is not in the Indicator Library yet to a legacy library entry';

    /**
     * Every result, by id.
     *
     * @var array<int, Result>
     */
    private array $results = [];

    /**
     * A result's number: an outcome among its program's outcomes, an output
     * among the outputs under the same parent.
     *
     * @var array<int, int>
     */
    private array $ranks = [];

    /**
     * @var array<int, string>
     */
    private array $levelRefs = [];

    public function handle(): int
    {
        $indicators = Indicator::query()
            ->whereNull('library_indicator_id')
            ->orderBy('id')
            ->get(['id', 'name', 'result_id', 'measure', 'unit', 'frequency']);

        if ($indicators->isEmpty()) {
            $this->components->info('Every indicator is already in the library.');

            return self::SUCCESS;
        }

        $this->loadFramework();
        $loop = $this->parentLoop();

        if ($loop !== []) {
            $this->error('Results '.implode(', ', $loop)." are each other's parents, so their level cannot be worked out. Fix their parents on the results page.");
            $this->error('Nothing was changed.');

            return self::FAILURE;
        }

        $groups = $this->groups($indicators);
        $problems = $this->programProblems($groups);
        $groups = $this->proposeCodes($groups, $problems);

        $this->printPlan($groups);

        if ($problems !== []) {
            foreach (array_unique($problems) as $problem) {
                $this->error($problem);
            }

            $this->error('Nothing was changed. Ask the HR team to fix the program codes, then run this again.');

            return self::FAILURE;
        }

        if ((bool) $this->option('dry-run')) {
            $this->components->info('Dry run: nothing was changed.');

            return self::SUCCESS;
        }

        try {
            ['created' => $created, 'linked' => $linked] = $this->write($groups);
        } catch (ValidationException $exception) {
            $this->error((string) collect($exception->errors())->flatten()->first());

            return self::FAILURE;
        }

        $this->components->info("{$created} library entr".($created === 1 ? 'y' : 'ies')." created; {$linked} indicator(s) linked.");

        return self::SUCCESS;
    }

    /**
     * Read the whole results framework once and number its outcomes and
     * outputs by id.
     */
    private function loadFramework(): void
    {
        $this->results = Result::query()->orderBy('id')->get(['id', 'type', 'parent_id', 'program_id'])->keyBy('id')->all();

        $counters = [];

        foreach ($this->results as $result) {
            $parent = $this->sameProgramParent($result);
            $program = $result->program_id ?? 'none';

            $counter = match ($result->type) {
                ResultType::Impact => null,
                ResultType::Outcome => "outcomes:{$program}",
                ResultType::Output => match ($parent?->type) {
                    ResultType::Outcome, ResultType::Output => "under:{$parent->id}",
                    ResultType::Impact, null => "loose:{$program}",
                },
            };

            if ($counter !== null) {
                $counters[$counter] = ($counters[$counter] ?? 0) + 1;
                $this->ranks[$result->id] = $counters[$counter];
            }
        }
    }

    /**
     * The ids of the first results found whose parents lead back to
     * themselves, smallest first; empty when the framework has no loop. The
     * results page never builds one; data written around it could.
     *
     * @return list<int>
     */
    private function parentLoop(): array
    {
        $finished = [];

        foreach (array_keys($this->results) as $startId) {
            $path = [];
            $currentId = $startId;

            while ($currentId !== null && isset($this->results[$currentId]) && ! isset($finished[$currentId])) {
                if (isset($path[$currentId])) {
                    $loop = array_slice(array_keys($path), (int) array_search($currentId, array_keys($path), true));
                    sort($loop);

                    return $loop;
                }

                $path[$currentId] = true;
                $currentId = $this->results[$currentId]->parent_id;
            }

            $finished += $path;
        }

        return [];
    }

    /**
     * The result's parent when it belongs to the same program; a parent in
     * another program counts as none.
     */
    private function sameProgramParent(Result $result): ?Result
    {
        $parent = $result->parent_id === null ? null : ($this->results[$result->parent_id] ?? null);

        return $parent !== null && $parent->program_id === $result->program_id ? $parent : null;
    }

    /**
     * The library level of a result: `G`, `OC{n}`, or `OP` followed by the
     * outcome number and the output's place under each parent.
     */
    private function levelRef(Result $result): string
    {
        if (isset($this->levelRefs[$result->id])) {
            return $this->levelRefs[$result->id];
        }

        $parent = $this->sameProgramParent($result);

        $levelRef = match ($result->type) {
            ResultType::Impact => 'G',
            ResultType::Outcome => 'OC'.$this->ranks[$result->id],
            ResultType::Output => match ($parent?->type) {
                ResultType::Output => $this->levelRef($parent).'.'.$this->ranks[$result->id],
                ResultType::Outcome => 'OP'.$this->ranks[$parent->id].'.'.$this->ranks[$result->id],
                ResultType::Impact, null => 'OP'.$this->ranks[$result->id],
            },
        };

        if (strlen($levelRef) > 20 || preg_match(SaveLibraryIndicator::LevelRefPattern, $levelRef) !== 1) {
            throw new LogicException("Result {$result->id} gives the level [{$levelRef}], which the library does not accept.");
        }

        return $this->levelRefs[$result->id] = $levelRef;
    }

    /**
     * The unlinked indicators grouped by folded name and measure, oldest
     * group first, each matched to the legacy entry with that name if there
     * is one.
     *
     * @param  Collection<int, Indicator>  $indicators
     * @return list<array{name: string, measure: IndicatorMeasure, programId: ?int, levelRef: string, first: Indicator, indicatorIds: list<int>, existing: ?LibraryIndicator, code: ?string}>
     */
    private function groups(Collection $indicators): array
    {
        $legacyEntries = [];

        foreach (LibraryIndicator::query()->where('is_legacy', true)->orderBy('id')->get(['id', 'code', 'name_ar', 'name_en', 'measure', 'is_active']) as $entry) {
            foreach (array_filter([$entry->name_ar, $entry->name_en], filled(...)) as $name) {
                $legacyEntries[self::groupKey($name, $entry->measure)] ??= $entry;
            }
        }

        $groups = [];
        $programIds = [];
        $measuresByName = [];

        foreach ($indicators as $indicator) {
            $key = self::groupKey($indicator->name, $indicator->measure);
            $result = $this->results[$indicator->result_id];

            $groups[$key] ??= [
                'name' => self::tidy($indicator->name),
                'measure' => $indicator->measure,
                'programId' => null,
                'levelRef' => $this->levelRef($result),
                'first' => $indicator,
                'indicatorIds' => [],
                'existing' => $legacyEntries[$key] ?? null,
                'code' => null,
            ];
            $groups[$key]['indicatorIds'][] = $indicator->id;
            $programIds[$key][$result->program_id ?? 'none'] = $result->program_id;
            $measuresByName[self::foldedName($indicator->name)][$indicator->measure->value] = self::tidy($indicator->name);
        }

        foreach ($groups as $key => $group) {
            $groups[$key]['programId'] = count($programIds[$key]) === 1 ? array_values($programIds[$key])[0] : null;
        }

        foreach ($measuresByName as $measures) {
            if (count($measures) > 1) {
                ksort($measures);
                $this->warn('Conflict: "'.reset($measures).'" is used with more than one measure ('.implode(', ', array_keys($measures)).'); it gets one entry per measure.');
            }
        }

        return array_values($groups);
    }

    /**
     * Why a program cannot give its code (D-10a): its HR code is missing, or
     * HR has given it to another program too.
     *
     * @param  list<array{name: string, measure: IndicatorMeasure, programId: ?int, levelRef: string, first: Indicator, indicatorIds: list<int>, existing: ?LibraryIndicator, code: ?string}>  $groups
     * @return array<int, string> program id => problem
     */
    private function programProblems(array $groups): array
    {
        $needed = array_unique(array_filter(array_map(
            fn (array $group): ?int => $group['existing'] === null ? $group['programId'] : null,
            $groups,
        ), fn (?int $programId): bool => $programId !== null));

        if ($needed === []) {
            return [];
        }

        $hrPrograms = HrProgram::query()->get(['id', 'code', 'name']);
        $programsByCode = $hrPrograms->groupBy(fn (HrProgram $program): string => trim((string) $program->code));
        $problems = [];

        foreach ($needed as $programId) {
            $code = trim((string) $hrPrograms->firstWhere('id', $programId)?->code);

            if ($code === '') {
                $problems[$programId] = "Program {$programId} has no code in HR.";

                continue;
            }

            $sharing = $programsByCode->get($code);

            if ($sharing !== null && $sharing->count() > 1) {
                $names = $sharing->map(fn (HrProgram $program): string => "{$program->id} ".trim($program->name))->implode(', ');
                $problems[$programId] = "HR program code {$code} is used by more than one program: {$names}.";
            }
        }

        return $problems;
    }

    /**
     * The code each new entry will get: the program prefix, the level and the
     * next serial after the library's current highest at that level.
     *
     * @param  list<array{name: string, measure: IndicatorMeasure, programId: ?int, levelRef: string, first: Indicator, indicatorIds: list<int>, existing: ?LibraryIndicator, code: ?string}>  $groups
     * @param  array<int, string>  $problems
     * @return list<array{name: string, measure: IndicatorMeasure, programId: ?int, levelRef: string, first: Indicator, indicatorIds: list<int>, existing: ?LibraryIndicator, code: ?string}>
     */
    private function proposeCodes(array $groups, array $problems): array
    {
        $serials = [];

        foreach (DB::table('library_indicators')->selectRaw('program_id, level_ref, max(serial) as top_serial')->groupBy('program_id', 'level_ref')->get() as $row) {
            $serials[($row->program_id ?? 'none').'|'.$row->level_ref] = (int) $row->top_serial;
        }

        $hrCodes = HrProgram::query()
            ->whereIn('id', array_filter(array_column($groups, 'programId')))
            ->pluck('code', 'id')
            ->map(fn (mixed $code): string => trim((string) $code))
            ->all();

        foreach ($groups as $index => $group) {
            if ($group['existing'] !== null) {
                $groups[$index]['code'] = $group['existing']->code;

                continue;
            }

            if ($group['programId'] !== null && isset($problems[$group['programId']])) {
                continue;
            }

            $slot = ($group['programId'] ?? 'none').'|'.$group['levelRef'];
            $serials[$slot] = ($serials[$slot] ?? 0) + 1;
            $prefix = $group['programId'] === null ? IndicatorCode::Institutional : $hrCodes[$group['programId']];
            $groups[$index]['code'] = "{$prefix}.{$group['levelRef']}.{$serials[$slot]}";
        }

        return $groups;
    }

    /**
     * @param  list<array{name: string, measure: IndicatorMeasure, programId: ?int, levelRef: string, first: Indicator, indicatorIds: list<int>, existing: ?LibraryIndicator, code: ?string}>  $groups
     */
    private function printPlan(array $groups): void
    {
        $this->table(
            ['Code', 'Measure', 'Name', 'Indicators'],
            array_map(fn (array $group): array => [
                ($group['code'] ?? '—').($group['existing'] !== null ? ' (existing)' : ''),
                $group['measure']->value,
                $group['name'],
                count($group['indicatorIds']),
            ], $groups),
        );

        foreach ($groups as $group) {
            if ($group['existing'] !== null && ! $group['existing']->is_active) {
                $this->warn("{$group['existing']->code} \"{$group['name']}\" is deactivated; ".count($group['indicatorIds']).' indicator(s) will still be linked to it. Reactivate it, or move them to another entry.');
            }
        }

        $englishOnly = array_filter($groups, fn (array $group): bool => $group['existing'] === null && ! self::isArabic($group['name']));

        if ($englishOnly !== []) {
            $this->line('For MEAL review (no Arabic name yet; the English name is copied into it):');

            foreach ($englishOnly as $group) {
                $this->line('  '.($group['code'] ?? '—')."  {$group['name']}");
            }
        }
    }

    /**
     * Create the new entries and link every indicator, one transaction per
     * chunk, recording no activity.
     *
     * @param  list<array{name: string, measure: IndicatorMeasure, programId: ?int, levelRef: string, first: Indicator, indicatorIds: list<int>, existing: ?LibraryIndicator, code: ?string}>  $groups
     * @return array{created: int, linked: int}
     *
     * @throws ValidationException when HR changed a program code since the plan was made
     */
    private function write(array $groups): array
    {
        $totals = ['created' => 0, 'linked' => 0];

        Activity::withoutRecording(function () use ($groups, &$totals): void {
            foreach (array_chunk($groups, self::ChunkSize) as $chunk) {
                DB::transaction(function () use ($chunk, &$totals): void {
                    foreach ($chunk as $group) {
                        $entry = $group['existing'] ?? $this->createEntry($group);
                        $totals['created'] += $group['existing'] === null ? 1 : 0;

                        $totals['linked'] += Indicator::query()
                            ->whereKey($group['indicatorIds'])
                            ->whereNull('library_indicator_id')
                            ->update(['library_indicator_id' => $entry->id, 'code' => $entry->code]);
                    }
                });
            }
        });

        return $totals;
    }

    /**
     * A legacy entry built from the group's oldest indicator.
     *
     * @param  array{name: string, measure: IndicatorMeasure, programId: ?int, levelRef: string, first: Indicator, indicatorIds: list<int>, existing: ?LibraryIndicator, code: ?string}  $group
     */
    private function createEntry(array $group): LibraryIndicator
    {
        $first = $group['first'];
        $isPercentage = $group['measure'] === IndicatorMeasure::Percentage;
        ['code' => $code, 'serial' => $serial] = IndicatorCode::next($group['programId'], $group['levelRef']);

        $entry = new LibraryIndicator([
            'program_id' => $group['programId'],
            'level_ref' => $group['levelRef'],
            'name_ar' => $group['name'],
            'name_en' => self::isArabic($group['name']) ? null : $group['name'],
            'measure' => $group['measure'],
            'unit' => $isPercentage ? null : $first->unit,
            'denominator_basis' => $isPercentage ? IndicatorCollector::ProjectTeam->defaultDenominatorBasis() : null,
            'frequency' => CollectionFrequency::from($first->frequency->value),
            'suggested_level' => $this->results[$first->result_id]->type,
            'is_legacy' => true,
        ]);
        $entry->forceFill(['code' => $code, 'serial' => $serial])->save();

        return $entry;
    }

    private static function groupKey(string $name, IndicatorMeasure $measure): string
    {
        return self::foldedName($name).'|'.$measure->value;
    }

    /**
     * The name as the library search compares it: tidied, then folded.
     */
    private static function foldedName(string $name): string
    {
        return ArabicText::normalize(self::tidy($name));
    }

    /**
     * The name trimmed, with each run of spaces made one.
     */
    private static function tidy(string $name): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $name));
    }

    private static function isArabic(string $name): bool
    {
        return preg_match('/\p{Arabic}/u', $name) === 1;
    }
}
