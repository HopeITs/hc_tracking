<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Actions\IndicatorLibrary\SaveLibraryIndicator;
use App\Enums\ActivityKind;
use App\Enums\CollectionFrequency;
use App\Enums\IndicatorCollector;
use App\Enums\IndicatorMeasure;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\HrProgram;
use App\Models\Indicator;
use App\Models\LibraryIndicator;
use App\Models\Program;
use App\Models\Result;
use App\Support\ArabicText;
use App\Support\IndicatorCode;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use JsonException;
use Throwable;

/**
 * Imports one track of a program framework from a committed logframe file
 * (`database/seeders/data/logframes/*.json`, T32, D-37).
 *
 * The goal becomes an impact root, outcomes and outputs sit under it, each
 * output's activities become `activity` results, and cross-cutting rows
 * become one `cross-cutting` root. Every indicator becomes an entry of the
 * program's Indicator Library, coded by IndicatorCode::next() (D-10), and an
 * indicator on its program-framework result carrying the default target.
 *
 * Re-running is safe: results are matched by parent, type and statement,
 * framework indicators by result and name, and library entries by program,
 * folded name and measure, so the same indicator in two tracks is one entry.
 * An entry edited by hand after its last import is reported as a conflict
 * and left as it is. Results already in the framework that the file does not
 * name are never touched. Writes run in one transaction without recording
 * activity; a dry run rolls it back after printing the plan. Entries are
 * written here rather than through SaveLibraryIndicator, which authorizes a
 * signed-in user the console does not have.
 */
class ImportProgramFramework extends Command
{
    /**
     * @var string
     */
    protected $signature = 'meal:import-framework
        {file : The logframe JSON file, absolute or relative to the project}
        {--program= : The HR id of the program (defaults to the file\'s hr_program_id)}
        {--dry-run : Print what would change without changing anything}';

    /**
     * @var string
     */
    protected $description = 'Import a program framework track and its indicators into the Indicator Library';

    private CarbonImmutable $now;

    private int $programId;

    /**
     * The program's library entries by folded name and measure.
     *
     * @var array<string, LibraryIndicator>
     */
    private array $entries = [];

    /**
     * @var list<array{code: string, level: string, name: string, status: string}>
     */
    private array $entryRows = [];

    /**
     * @var array{results_created: int, results_reused: int, indicators_created: int, indicators_updated: int, indicators_unchanged: int}
     */
    private array $totals = ['results_created' => 0, 'results_reused' => 0, 'indicators_created' => 0, 'indicators_updated' => 0, 'indicators_unchanged' => 0];

    /**
     * Ids of the program-framework results this file names.
     *
     * @var array<int, true>
     */
    private array $matchedResultIds = [];

    /**
     * Activities the file lists where results cannot hold them.
     *
     * @var list<string>
     */
    private array $skippedActivities = [];

    public function handle(): int
    {
        $this->entries = [];
        $this->entryRows = [];
        $this->matchedResultIds = [];
        $this->skippedActivities = [];
        $this->totals = array_map(fn (): int => 0, $this->totals);

        $data = $this->readFile();

        if ($data === null) {
            return self::FAILURE;
        }

        $nodes = $this->tree($data);

        if ($nodes === null) {
            return self::FAILURE;
        }

        $this->programId = $this->option('program') !== null ? (int) $this->option('program') : (int) $data['hr_program_id'];

        if (! $this->programCodeMatches((string) $data['program_code'])) {
            $this->error('Nothing was changed.');

            return self::FAILURE;
        }

        $this->now = CarbonImmutable::now();
        $isDryRun = (bool) $this->option('dry-run');

        $untouched = Activity::withoutRecording(function () use ($nodes, $isDryRun): array {
            DB::beginTransaction();

            try {
                $untouched = $this->import($nodes);
            } catch (Throwable $exception) {
                DB::rollBack();

                throw $exception;
            }

            $isDryRun ? DB::rollBack() : DB::commit();

            return $untouched;
        });

        $this->printReport($untouched);

        if ($isDryRun) {
            $this->components->info('Dry run: nothing was changed.');
        }

        return self::SUCCESS;
    }

    /**
     * The decoded file, or null after printing why it cannot be read.
     *
     * @return array<string, mixed>|null
     */
    private function readFile(): ?array
    {
        $file = (string) $this->argument('file');
        $path = is_file($file) ? $file : base_path($file);

        if (! is_file($path)) {
            $this->error("File not found: {$file}");

            return null;
        }

        try {
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            $this->error("The file is not valid JSON: {$exception->getMessage()}");

            return null;
        }

        if (! is_array($data)) {
            $this->error('The file must hold one logframe object.');

            return null;
        }

        $validator = Validator::make($data, $this->rules());

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            $this->error('Nothing was changed.');

            return null;
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function rules(): array
    {
        $rules = [
            'program_code' => ['required', 'string'],
            'hr_program_id' => ['nullable', 'integer'],
            'track' => ['required', 'string'],
            'goal' => ['required', 'array'],
            'goal.statement' => ['required', 'string'],
            'outcomes' => ['present', 'array'],
            'outcomes.*.statement' => ['required', 'string'],
            'outcomes.*.outputs' => ['present', 'array'],
            'outcomes.*.outputs.*.statement' => ['required', 'string'],
            'cross_cutting' => ['sometimes', 'array'],
            'cross_cutting.statement' => ['required_with:cross_cutting', 'string'],
        ];

        foreach (['goal.indicators', 'outcomes.*.indicators', 'outcomes.*.outputs.*.indicators', 'cross_cutting.indicators'] as $prefix) {
            $rules += [
                "{$prefix}.*.ref" => ['required', 'string'],
                "{$prefix}.*.name_ar" => ['required', 'string', 'max:255'],
                "{$prefix}.*.measure" => ['required', Rule::enum(IndicatorMeasure::class)],
                "{$prefix}.*.unit" => ['nullable', 'string', 'max:50'],
                "{$prefix}.*.target_raw" => ['required', 'string'],
                "{$prefix}.*.definition" => ['nullable', 'string'],
                "{$prefix}.*.calculation_method" => ['nullable', 'string'],
                "{$prefix}.*.frequency" => ['required', Rule::enum(CollectionFrequency::class)],
                "{$prefix}.*.follow_up_months" => ['nullable', 'integer', 'min:1', 'max:120'],
                "{$prefix}.*.frequency_note" => ['nullable', 'string'],
                "{$prefix}.*.collected_by" => ['required', Rule::enum(IndicatorCollector::class)],
                "{$prefix}.*.activities" => ['present', 'array'],
                "{$prefix}.*.activities.*" => ['string'],
            ];
        }

        return $rules;
    }

    /**
     * The file as a result tree with every indicator's level and default
     * target worked out, or null after printing the rows that do not fit.
     *
     * @param  array<string, mixed>  $data
     *                                      Nodes come parents first; `parent` is the index of the parent node.
     * @return list<array{type: ResultType, statement: string, parent: ?int, indicators: list<array<string, mixed>>, activities: list<string>}>|null
     */
    private function tree(array $data): ?array
    {
        $problems = [];
        $nodes = [];

        $node = function (ResultType $type, array $source, ?int $parent) use (&$problems, &$nodes): int {
            $indicators = [];
            $activities = [];

            foreach ($source['indicators'] ?? [] as $indicator) {
                $levelRef = self::levelRef($type, (string) $indicator['ref']);
                $target = self::defaultTarget((string) $indicator['target_raw'], IndicatorMeasure::from($indicator['measure']));

                if ($levelRef === null) {
                    $problems[] = "{$indicator['ref']}: the number does not fit a {$type->value} level.";
                }

                if ($indicator['frequency'] === CollectionFrequency::FollowUp->value && ($indicator['follow_up_months'] ?? null) === null) {
                    $problems[] = "{$indicator['ref']}: a follow-up indicator needs follow_up_months.";
                }

                if ($target === null) {
                    $problems[] = "{$indicator['ref']}: the target \"{$indicator['target_raw']}\" is not one of 0.8, 80%, -, ** or زيادة 20%.";
                }

                $indicators[] = $indicator + ['level_ref' => $levelRef, 'default' => $target];

                foreach ($indicator['activities'] as $activity) {
                    $activity = self::tidy((string) $activity);

                    if ($type === ResultType::Output) {
                        $activities[$activity] = $activity;
                    } elseif ($activity !== '') {
                        $this->skippedActivities[] = "{$indicator['ref']}: {$activity}";
                    }
                }
            }

            $nodes[] = [
                'type' => $type,
                'statement' => self::tidy((string) $source['statement']),
                'parent' => $parent,
                'indicators' => $indicators,
                'activities' => array_values(array_filter($activities, fn (string $activity): bool => $activity !== '')),
            ];

            return count($nodes) - 1;
        };

        $goal = $node(ResultType::Impact, $data['goal'], null);

        foreach ($data['outcomes'] as $outcome) {
            $outcomeIndex = $node(ResultType::Outcome, $outcome, $goal);

            foreach ($outcome['outputs'] as $output) {
                $node(ResultType::Output, $output, $outcomeIndex);
            }
        }

        if (isset($data['cross_cutting'])) {
            $node(ResultType::CrossCutting, $data['cross_cutting'], null);
        }

        if ($problems !== []) {
            foreach ($problems as $problem) {
                $this->error($problem);
            }

            $this->error('Nothing was changed.');

            return null;
        }

        return $nodes;
    }

    /**
     * Whether the program's HR code is the file's and no other program has
     * it (D-10a); prints why not.
     */
    private function programCodeMatches(string $fileCode): bool
    {
        $code = trim((string) HrProgram::query()->whereKey($this->programId)->value('code'));

        if ($code === '') {
            $this->error("Program {$this->programId} is not in HR or has no code there.");

            return false;
        }

        if ($code !== $fileCode) {
            $this->error("Program {$this->programId} has the HR code {$code}, but the file is for program code {$fileCode}.");

            return false;
        }

        if (HrProgram::query()->where('code', $code)->count() > 1) {
            $this->error("HR program code {$code} is used by more than one program. Ask the HR team to fix it, then run this again.");

            return false;
        }

        return true;
    }

    /**
     * Write the tree and return the ids of the program-framework results the
     * file does not name, which were left as they are.
     *
     * @param  list<array{type: ResultType, statement: string, parent: ?int, indicators: list<array<string, mixed>>, activities: list<string>}>  $nodes
     * @return list<int>
     */
    private function import(array $nodes): array
    {
        if (! Program::query()->whereKey($this->programId)->exists()) {
            Program::syncFromHr();
        }

        $existingIds = Result::query()->programFramework()->where('program_id', $this->programId)->orderBy('id')->pluck('id')->all();

        foreach (LibraryIndicator::query()->where('program_id', $this->programId)->orderBy('id')->get() as $entry) {
            $this->entries[self::entryKey($entry->name_ar, $entry->measure)] ??= $entry;
        }

        $resultIds = [];

        foreach ($nodes as $index => $node) {
            $result = $this->result($node['type'], $node['statement'], $node['parent'] === null ? null : $resultIds[$node['parent']]);
            $resultIds[$index] = $result->id;

            foreach ($node['indicators'] as $indicator) {
                $this->writeIndicator($result, $indicator);
            }

            foreach ($node['activities'] as $activity) {
                $this->result(ResultType::Activity, $activity, $result->id);
            }
        }

        return array_values(array_filter($existingIds, fn (int $id): bool => ! isset($this->matchedResultIds[$id])));
    }

    /**
     * The program-framework result with this parent, type and statement,
     * created when missing.
     */
    private function result(ResultType $type, string $statement, ?int $parentId): Result
    {
        $result = Result::query()
            ->programFramework()
            ->where('program_id', $this->programId)
            ->where('parent_id', $parentId)
            ->where('type', $type)
            ->where('statement', $statement)
            ->orderBy('id')
            ->first();

        if ($result === null) {
            $result = Result::query()->create([
                'statement' => $statement,
                'parent_id' => $parentId,
                'program_id' => $this->programId,
                'type' => $type,
                'activity_kind' => $type === ResultType::Activity ? ActivityKind::Continuous : null,
            ]);
            $this->totals['results_created']++;
        } else {
            $this->totals['results_reused']++;
        }

        $this->matchedResultIds[$result->id] = true;

        return $result;
    }

    /**
     * @param  array<string, mixed>  $source
     */
    private function writeIndicator(Result $result, array $source): void
    {
        /** @var array{target: ?string, measure: IndicatorMeasure, tracking_only: bool, note: ?string} $default */
        $default = $source['default'];
        [$entry, $isConflict] = $this->libraryEntry($result->type, $source, $default);

        $indicator = Indicator::query()
            ->where('result_id', $result->id)
            ->where('name', self::tidy((string) $source['name_ar']))
            ->first() ?? new Indicator(['result_id' => $result->id, 'name' => self::tidy((string) $source['name_ar'])]);

        if ($indicator->exists && $isConflict) {
            $this->totals['indicators_unchanged']++;

            return;
        }

        $period = CollectionFrequency::from($source['frequency'])->period();

        $indicator->fill([
            'library_indicator_id' => $entry->id,
            'code' => $entry->code,
            'measure' => $entry->measure,
            'unit' => $entry->unit,
            'target' => $default['target'],
            'is_tracking_only' => $default['tracking_only'],
        ]);

        if ($period !== null) {
            $indicator->frequency = $period;
        }

        $key = ! $indicator->exists ? 'indicators_created' : ($indicator->isDirty() ? 'indicators_updated' : 'indicators_unchanged');

        $indicator->save();
        $this->totals[$key]++;
    }

    /**
     * The program's library entry for this indicator: created with the next
     * code, updated, or left alone when edited by hand since its last import.
     *
     * @param  array<string, mixed>  $source
     * @param  array{target: ?string, measure: IndicatorMeasure, tracking_only: bool, note: ?string}  $default
     * @return array{0: LibraryIndicator, 1: bool} the entry and whether it is a conflict
     */
    private function libraryEntry(ResultType $level, array $source, array $default): array
    {
        $name = self::tidy((string) $source['name_ar']);
        $key = self::entryKey($name, $default['measure']);
        $entry = $this->entries[$key] ?? null;

        if ($entry !== null && ($entry->imported_at === null || $entry->updated_at?->gt($entry->imported_at))) {
            $this->entryRows[] = ['code' => $entry->code, 'level' => $entry->level_ref, 'name' => $name, 'status' => 'conflict'];

            return [$entry, true];
        }

        $isPercentage = $default['measure'] === IndicatorMeasure::Percentage;
        $collector = IndicatorCollector::from($source['collected_by']);
        $calculation = self::tidy((string) ($source['calculation_method'] ?? ''), keepLines: true);

        if ($default['note'] !== null) {
            $calculation = trim($calculation."\n".$default['note']);
        }

        $isNew = $entry === null;
        $entry ??= new LibraryIndicator([
            'program_id' => $this->programId,
            'level_ref' => $source['level_ref'],
            'name_ar' => $name,
            'measure' => $default['measure'],
        ]);

        $entry->fill([
            'definition' => self::nullIfEmpty(self::tidy((string) ($source['definition'] ?? ''), keepLines: true)),
            'calculation_method' => self::nullIfEmpty($calculation),
            'unit' => $isPercentage ? null : self::nullIfEmpty(self::tidy((string) ($source['unit'] ?? ''))),
            'denominator_basis' => $isPercentage ? $collector->defaultDenominatorBasis() : null,
            'collected_by' => $collector,
            'frequency' => CollectionFrequency::from($source['frequency']),
            'frequency_note' => self::nullIfEmpty(self::tidy((string) ($source['frequency_note'] ?? ''), keepLines: true)),
            'follow_up_months' => $source['follow_up_months'] ?? null,
            'suggested_level' => $level,
        ]);

        $status = $isNew ? 'created' : ($entry->isDirty() ? 'updated' : 'unchanged');

        if ($isNew) {
            ['code' => $code, 'serial' => $serial] = IndicatorCode::next($this->programId, (string) $source['level_ref']);
            $entry->forceFill(['code' => $code, 'serial' => $serial]);
        }

        if ($status !== 'unchanged') {
            $entry->forceFill(['imported_at' => $this->now, 'updated_at' => $this->now])->save();
        }

        $this->entries[$key] = $entry;
        $this->entryRows[] = ['code' => $entry->code, 'level' => $entry->level_ref, 'name' => $name, 'status' => $status];

        return [$entry, false];
    }

    /**
     * @param  list<int>  $untouched
     */
    private function printReport(array $untouched): void
    {
        $this->table(['Code', 'Level', 'Name', 'Status'], array_map(fn (array $row): array => array_values($row), $this->entryRows));

        $statuses = array_count_values(array_column($this->entryRows, 'status'));

        $this->table(['', 'Created', 'Updated', 'Unchanged', 'Conflicts'], [
            ['Library entries', $statuses['created'] ?? 0, $statuses['updated'] ?? 0, $statuses['unchanged'] ?? 0, $statuses['conflict'] ?? 0],
            ['Framework results', $this->totals['results_created'], '—', $this->totals['results_reused'], '—'],
            ['Framework indicators', $this->totals['indicators_created'], $this->totals['indicators_updated'], $this->totals['indicators_unchanged'], '—'],
        ]);

        foreach ($this->entryRows as $row) {
            if ($row['status'] === 'conflict') {
                $this->warn("Conflict: {$row['code']} was edited by hand after its last import (or was not imported); it was left as it is.");
            }
        }

        foreach ($this->skippedActivities as $activity) {
            $this->warn("Skipped activity (only an output can hold activities): {$activity}");
        }

        if ($untouched !== []) {
            $this->line('Results already in the program framework, left as they are: '.implode(', ', $untouched).'.');
        }
    }

    /**
     * The library level of an indicator: `G` on the goal, `OC{n}` on outcome
     * n, `OP{n}.{m}` on output n.m, `CC` on a cross-cutting row; null when
     * the logframe number does not fit the node it sits on.
     */
    private static function levelRef(ResultType $type, string $ref): ?string
    {
        $levelRef = match ($type) {
            ResultType::Impact => preg_match('/^G\d*$/i', $ref) === 1 ? 'G' : null,
            ResultType::CrossCutting => preg_match('/^CC\d*$/i', $ref) === 1 ? 'CC' : null,
            ResultType::Outcome => preg_match('/^O(\d+)\.\d+$/i', $ref, $matches) === 1 ? "OC{$matches[1]}" : null,
            ResultType::Output => preg_match('/^OP(\d+)\.(\d+)[a-z]?$/i', $ref, $matches) === 1 ? "OP{$matches[1]}.{$matches[2]}" : null,
            ResultType::Activity => null,
        };

        return $levelRef !== null && preg_match(SaveLibraryIndicator::LevelRefPattern, $levelRef) === 1 ? $levelRef : null;
    }

    /**
     * The default target a logframe symbol stands for (D-37): a ratio such
     * as `0.8` or a percent such as `80%` is a percentage target, `-` means
     * tracking only, `**` means set per proposal, and `زيادة 20%` is a 20 %
     * rise from the baseline. Null when the symbol is none of these.
     *
     * @return array{target: ?string, measure: IndicatorMeasure, tracking_only: bool, note: ?string}|null
     */
    private static function defaultTarget(string $raw, IndicatorMeasure $measure): ?array
    {
        $raw = self::tidy($raw);

        if ($raw === '-' || $raw === '**') {
            return ['target' => null, 'measure' => $measure, 'tracking_only' => $raw === '-', 'note' => null];
        }

        if (preg_match('/^زيادة\s*(\d+(?:\.\d+)?)\s*%$/u', $raw, $matches) === 1) {
            return [
                'target' => self::decimal((float) $matches[1]),
                'measure' => IndicatorMeasure::Percentage,
                'tracking_only' => false,
                'note' => "الهدف الافتراضي: {$raw} عن خط الأساس (change from baseline).",
            ];
        }

        if (preg_match('/^(\d+(?:\.\d+)?)\s*%\s*(.*)$/u', $raw, $matches) === 1) {
            return [
                'target' => self::decimal((float) $matches[1]),
                'measure' => IndicatorMeasure::Percentage,
                'tracking_only' => false,
                'note' => $matches[2] !== '' ? "الهدف الافتراضي: {$raw}" : null,
            ];
        }

        if (! is_numeric($raw)) {
            return null;
        }

        $isRatio = (float) $raw <= 1;

        return [
            'target' => self::decimal($isRatio ? (float) $raw * 100 : (float) $raw),
            'measure' => $isRatio ? IndicatorMeasure::Percentage : $measure,
            'tracking_only' => false,
            'note' => null,
        ];
    }

    private static function decimal(float $value): string
    {
        return number_format(round($value, 2), 2, '.', '');
    }

    private static function entryKey(string $name, IndicatorMeasure $measure): string
    {
        return ArabicText::normalize(self::tidy($name)).'|'.$measure->value;
    }

    /**
     * The text trimmed, with each run of spaces made one; line breaks are
     * kept when asked.
     */
    private static function tidy(string $text, bool $keepLines = false): string
    {
        if (! $keepLines) {
            return trim((string) preg_replace('/\s+/u', ' ', $text));
        }

        $lines = array_map(fn (string $line): string => trim((string) preg_replace('/[^\S\n]+/u', ' ', $line)), explode("\n", $text));

        return implode("\n", array_filter($lines, fn (string $line): bool => $line !== ''));
    }

    private static function nullIfEmpty(string $text): ?string
    {
        return $text === '' ? null : $text;
    }
}
