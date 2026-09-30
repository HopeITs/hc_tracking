<?php

use App\Models\Account;
use App\Models\Activity;
use Database\Seeders\NotificationDemoSeeder;
use Database\Seeders\RealAccountsSeeder;
use Database\Seeders\RealDataSeeder;
use Database\Seeders\RealDonorsSeeder;
use Database\Seeders\RealMilestonesSeeder;
use Database\Seeders\RealProjectsSeeder;
use Database\Seeders\RealProposalsSeeder;
use Database\Seeders\RealResultsSeeder;
use Database\Seeders\RealRisksSeeder;
use Database\Seeders\RealTasksSeeder;
use Illuminate\Database\Seeder;

/**
 * The real-data seeders in the order their dependencies require.
 *
 * @return array<int, class-string<Seeder>>
 */
function realSeedersInDependencyOrder(): array
{
    return [
        RealAccountsSeeder::class,
        RealDonorsSeeder::class,
        RealProjectsSeeder::class,
        RealMilestonesSeeder::class,
        RealProposalsSeeder::class,
        RealResultsSeeder::class,
        RealRisksSeeder::class,
        RealTasksSeeder::class,
    ];
}

/**
 * Swap each seeder for a stand-in that records that it ran and whether
 * activity was being recorded at the time.
 *
 * @param  array<int, class-string<Seeder>>  $seeders
 * @return ArrayObject<int, array{0: class-string<Seeder>, 1: bool}>
 */
function recordSeederRuns(array $seeders): ArrayObject
{
    $runs = new ArrayObject;

    foreach ($seeders as $seeder) {
        app()->instance($seeder, new class($seeder, $runs) extends Seeder
        {
            public function __construct(private string $seeder, private ArrayObject $runs) {}

            public function run(): void
            {
                $this->runs[] = [$this->seeder, Activity::isRecording()];
            }
        });
    }

    return $runs;
}

test('the real data seeder runs every real seeder in dependency order with activity recording off', function () {
    $runs = recordSeederRuns(realSeedersInDependencyOrder());

    $this->seed(RealDataSeeder::class);

    expect($runs->getArrayCopy())->toBe(array_map(fn (string $seeder): array => [$seeder, false], realSeedersInDependencyOrder()));
});

test('the database seeder loads no real records and no demo notifications', function () {
    $runs = recordSeederRuns([...realSeedersInDependencyOrder(), NotificationDemoSeeder::class]);

    $this->seed();

    expect($runs->getArrayCopy())->toBe([]);
});

test('the real accounts seeder stores each account once when run again', function () {
    $this->seed(RealAccountsSeeder::class);
    $firstRun = Account::query()->pluck('account_number')->sort()->values()->all();

    $this->seed(RealAccountsSeeder::class);

    expect($firstRun)->not->toBeEmpty()
        ->and(Account::query()->pluck('account_number')->sort()->values()->all())->toBe($firstRun)
        ->and($firstRun)->toBe(array_values(array_unique($firstRun)));
});
