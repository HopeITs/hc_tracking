<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Models\Activity;
use App\Models\HrProgram;
use App\Models\Program;
use App\Models\StrategicPillar;
use App\Models\Strategy;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use JsonException;
use Throwable;

/**
 * Imports a strategy's pillars and assigns their programs from a committed
 * file (`database/seeders/data/strategy-2026-2030.json`, T32, D-35a).
 *
 * Pillars are matched by (strategy, code) and created or updated. Programs
 * are matched by HR id, never by name: a program HR does not have (yet) is
 * reported as missing and stays "No pillar". A program of another strategy,
 * or one already on another pillar, is reported as a conflict and left as
 * it is; a program without a strategy takes the pillar's, the same rule as
 * AssignProgramToPillar. Writes run in one transaction without recording
 * activity; a dry run rolls it back after printing the plan. Programs are
 * written here rather than through AssignProgramToPillar, which authorizes a
 * signed-in user the console does not have.
 */
class ImportStrategyPillars extends Command
{
    /**
     * @var string
     */
    protected $signature = 'strategy:import-pillars
        {file : The strategy JSON file, absolute or relative to the project}
        {--strategy= : The strategy id or slug (defaults to the file\'s strategy_slug)}
        {--dry-run : Print what would change without changing anything}';

    /**
     * @var string
     */
    protected $description = 'Import the strategic pillars of a strategy and assign their programs';

    /**
     * @var list<array{pillar: string, program: string, status: string}>
     */
    private array $rows = [];

    /**
     * @var array{created: int, updated: int, unchanged: int}
     */
    private array $pillarTotals = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

    public function handle(): int
    {
        $this->rows = [];
        $this->pillarTotals = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        $data = $this->readFile();

        if ($data === null) {
            return self::FAILURE;
        }

        $reference = (string) ($this->option('strategy') ?? $data['strategy_slug'] ?? '');
        $strategy = Strategy::query()
            ->where(fn ($query) => $query->where('slug', $reference)->when(ctype_digit($reference), fn ($query) => $query->orWhere('id', (int) $reference)))
            ->first();

        if ($strategy === null) {
            $this->error("Strategy not found: {$reference}. Nothing was changed.");

            return self::FAILURE;
        }

        $isDryRun = (bool) $this->option('dry-run');

        Activity::withoutRecording(function () use ($data, $strategy, $isDryRun): void {
            DB::beginTransaction();

            try {
                $this->import($data['pillars'], $strategy);
            } catch (Throwable $exception) {
                DB::rollBack();

                throw $exception;
            }

            $isDryRun ? DB::rollBack() : DB::commit();
        });

        $this->printReport($strategy);

        if ($isDryRun) {
            $this->components->info('Dry run: nothing was changed.');
        }

        return self::SUCCESS;
    }

    /**
     * The decoded file, or null after printing why it cannot be read.
     *
     * @return array{strategy_slug?: string, pillars: list<array{code: string, name_ar: string, name_en?: ?string, goal_ar?: ?string, goal_en?: ?string, sort_order?: int, programs: list<array{hr_program_id: ?int, label: string}>}>}|null
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

        $validator = Validator::make(is_array($data) ? $data : [], [
            'strategy_slug' => ['nullable', 'string'],
            'pillars' => ['required', 'array'],
            'pillars.*.code' => ['required', 'string', 'max:20', 'distinct'],
            'pillars.*.name_ar' => ['required', 'string', 'max:255'],
            'pillars.*.name_en' => ['nullable', 'string', 'max:255'],
            'pillars.*.goal_ar' => ['nullable', 'string', 'max:2000'],
            'pillars.*.goal_en' => ['nullable', 'string', 'max:2000'],
            'pillars.*.sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'pillars.*.programs' => ['present', 'array'],
            'pillars.*.programs.*.hr_program_id' => ['nullable', 'integer'],
            'pillars.*.programs.*.label' => ['required', 'string'],
        ]);

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $message) {
                $this->error($message);
            }

            $this->error('Nothing was changed.');

            return null;
        }

        /** @var array{strategy_slug?: string, pillars: list<array{code: string, name_ar: string, name_en?: ?string, goal_ar?: ?string, goal_en?: ?string, sort_order?: int, programs: list<array{hr_program_id: ?int, label: string}>}>} $data */
        return $data;
    }

    /**
     * @param  list<array{code: string, name_ar: string, name_en?: ?string, goal_ar?: ?string, goal_en?: ?string, sort_order?: int, programs: list<array{hr_program_id: ?int, label: string}>}>  $pillars
     */
    private function import(array $pillars, Strategy $strategy): void
    {
        $hrIds = array_filter(array_column(array_merge(...array_column($pillars, 'programs')), 'hr_program_id'));
        $hrNames = HrProgram::query()->whereKey($hrIds)->pluck('name', 'id')->map(fn (mixed $name): string => trim((string) $name))->all();

        if (Program::query()->whereKey(array_keys($hrNames))->count() < count($hrNames)) {
            Program::syncFromHr();
        }

        $programs = Program::query()->whereKey(array_keys($hrNames))->lockForUpdate()->orderBy('id')->get()->keyBy('id');

        foreach ($pillars as $index => $source) {
            $pillar = $this->pillar($strategy, $source, $index + 1);

            foreach ($source['programs'] as $listed) {
                $hrId = $listed['hr_program_id'];
                $program = $hrId === null ? null : $programs->get($hrId);
                $name = $hrId !== null && isset($hrNames[$hrId]) ? "{$hrNames[$hrId]} (HR {$hrId})" : $listed['label'];

                $this->rows[] = ['pillar' => $pillar->code, 'program' => $name, 'status' => $this->assign($program, $pillar)];
            }
        }
    }

    /**
     * The strategy's pillar with this code, created or updated from the file.
     *
     * @param  array{code: string, name_ar: string, name_en?: ?string, goal_ar?: ?string, goal_en?: ?string, sort_order?: int, programs: list<array{hr_program_id: ?int, label: string}>}  $source
     */
    private function pillar(Strategy $strategy, array $source, int $position): StrategicPillar
    {
        $pillar = $strategy->pillars()->where('code', $source['code'])->lockForUpdate()->first()
            ?? new StrategicPillar(['strategy_id' => $strategy->id, 'code' => $source['code']]);

        $pillar->fill([
            'name_ar' => $source['name_ar'],
            'name_en' => $source['name_en'] ?? null,
            'goal_ar' => $source['goal_ar'] ?? null,
            'goal_en' => $source['goal_en'] ?? null,
            'sort_order' => $source['sort_order'] ?? $position,
        ]);

        $key = ! $pillar->exists ? 'created' : ($pillar->isDirty() ? 'updated' : 'unchanged');
        $pillar->save();
        $this->pillarTotals[$key]++;

        return $pillar;
    }

    /**
     * Put the program on the pillar unless something stops it, and say what
     * happened.
     */
    private function assign(?Program $program, StrategicPillar $pillar): string
    {
        if ($program === null) {
            return 'missing in HR';
        }

        if ($program->strategy_id !== null && $program->strategy_id !== $pillar->strategy_id) {
            return 'conflict: in another strategy';
        }

        if ($program->strategic_pillar_id === $pillar->id) {
            return 'unchanged';
        }

        if ($program->strategic_pillar_id !== null) {
            return 'conflict: already on another pillar';
        }

        $program->strategic_pillar_id = $pillar->id;
        $program->strategy_id ??= $pillar->strategy_id;
        $program->save();

        return 'assigned';
    }

    private function printReport(Strategy $strategy): void
    {
        $this->line("Strategy: {$strategy->name}");
        $this->table(['Pillar', 'Program', 'Status'], array_map(fn (array $row): array => array_values($row), $this->rows));

        $statuses = array_count_values(array_map(fn (array $row): string => str_starts_with($row['status'], 'conflict') ? 'conflict' : $row['status'], $this->rows));

        $this->table(['', 'Created', 'Updated', 'Unchanged', 'Conflicts', 'Missing'], [
            ['Pillars', $this->pillarTotals['created'], $this->pillarTotals['updated'], $this->pillarTotals['unchanged'], '—', '—'],
            ['Programs', '—', $statuses['assigned'] ?? 0, $statuses['unchanged'] ?? 0, $statuses['conflict'] ?? 0, $statuses['missing in HR'] ?? 0],
        ]);
    }
}
