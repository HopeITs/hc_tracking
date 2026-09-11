<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\DonorType;
use App\Enums\FileType;
use App\Enums\IndicatorMeasure;
use App\Enums\MediaCollection;
use App\Enums\MilestoneStatus;
use App\Enums\Period;
use App\Enums\ProjectStatus;
use App\Enums\ProposalStatus;
use App\Enums\ReportStatus;
use App\Enums\ResultType;
use App\Enums\TaskPriority;
use App\Enums\TaskStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Indicator;
use App\Models\Media;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Report;
use App\Models\ReportFile;
use App\Models\Result;
use App\Models\Task;
use App\Models\TaskAttachment;
use App\Models\TransfersTracking;
use Illuminate\Support\Facades\DB;
use ValueError;

/**
 * Every enum-cast column in the schema.
 *
 * `assign` is a case written through Eloquent, `stored` the string it must
 * leave in the column. `default` is the case the column default must hydrate
 * as, or null where the column has no default. `badCasing` is a value that
 * differs from a real member only by capitalisation and must therefore be
 * rejected on read.
 *
 * @return array<string, array{0: array<string, mixed>}>
 */
function enumColumns(): array
{
    return [
        'milestones.status' => [[
            'model' => Milestone::class,
            'table' => 'milestones',
            'column' => 'status',
            'width' => 50,
            'assign' => MilestoneStatus::Cancelled,
            'stored' => 'cancelled',
            'default' => MilestoneStatus::Pending,
            'badCasing' => 'Pending',
        ]],
        'tasks.status' => [[
            'model' => Task::class,
            'table' => 'tasks',
            'column' => 'status',
            'width' => 50,
            'assign' => TaskStatus::UnderReview,
            'stored' => 'under review',
            'default' => TaskStatus::Todo,
            'badCasing' => 'TODO',
        ]],
        'tasks.priority' => [[
            'model' => Task::class,
            'table' => 'tasks',
            'column' => 'priority',
            'width' => 50,
            'assign' => TaskPriority::High,
            'stored' => 'high',
            'default' => TaskPriority::Medium,
            'badCasing' => 'Medium',
        ]],
        'transfers_tracking.ar_status' => [[
            'model' => TransfersTracking::class,
            'table' => 'transfers_tracking',
            'column' => 'ar_status',
            'width' => 50,
            'assign' => ArStatus::Failed,
            'stored' => 'failed',
            'default' => ArStatus::Pending,
            'badCasing' => 'Pending',
        ]],
        'proposals.status' => [[
            'model' => Proposal::class,
            'table' => 'proposals',
            'column' => 'status',
            'width' => 50,
            'assign' => ProposalStatus::Active,
            'stored' => 'active',
            'default' => ProposalStatus::ConceptNote,
            'badCasing' => 'Active',
        ]],
        'proposal_status_notes.status' => [[
            'model' => ProposalStatusNote::class,
            'table' => 'proposal_status_notes',
            'column' => 'status',
            'width' => 50,
            'assign' => ProposalStatus::Approved,
            'stored' => 'approved',
            'default' => null,
            'badCasing' => 'Approved',
        ]],
        'results.type' => [[
            'model' => Result::class,
            'table' => 'results',
            'column' => 'type',
            'width' => 50,
            'assign' => ResultType::Impact,
            'stored' => 'impact',
            'default' => null,
            'badCasing' => 'Impact',
        ]],
        'results.checkout_period' => [[
            'model' => Result::class,
            'table' => 'results',
            'column' => 'checkout_period',
            'width' => 50,
            'assign' => Period::SemiAnnual,
            'stored' => 'semi-annual',
            'default' => Period::Quarterly,
            'badCasing' => 'Quarterly',
        ]],
        'indicators.measure' => [[
            'model' => Indicator::class,
            'table' => 'indicators',
            'column' => 'measure',
            'width' => 50,
            'assign' => IndicatorMeasure::Percentage,
            'stored' => 'percentage',
            'default' => IndicatorMeasure::Numeric,
            'badCasing' => 'Numeric',
        ]],
        'indicators.frequency' => [[
            'model' => Indicator::class,
            'table' => 'indicators',
            'column' => 'frequency',
            'width' => 50,
            'assign' => Period::Annual,
            'stored' => 'annual',
            'default' => Period::Quarterly,
            'badCasing' => 'Annual',
        ]],
        'donors.type' => [[
            'model' => Donor::class,
            'table' => 'donors',
            'column' => 'type',
            'width' => 50,
            'assign' => DonorType::Bilateral,
            'stored' => 'bilateral',
            'default' => null,
            'badCasing' => 'Bilateral',
        ]],
        'projects.status' => [[
            'model' => Project::class,
            'table' => 'projects',
            'column' => 'status',
            'width' => 50,
            'assign' => ProjectStatus::Archived,
            'stored' => 'archived',
            'default' => ProjectStatus::Draft,
            'badCasing' => 'Draft',
        ]],
        'reports.status' => [[
            'model' => Report::class,
            'table' => 'reports',
            'column' => 'status',
            'width' => 50,
            'assign' => ReportStatus::Reviewed,
            'stored' => 'reviewed',
            'default' => ReportStatus::Draft,
            'badCasing' => 'Draft',
        ]],
        'media.collection' => [[
            'model' => Media::class,
            'table' => 'media',
            'column' => 'collection',
            'width' => 50,
            'assign' => MediaCollection::Agreement,
            'stored' => 'agreement',
            'default' => MediaCollection::Other,
            'badCasing' => 'Other',
        ]],
        'media.type' => [[
            'model' => Media::class,
            'table' => 'media',
            'column' => 'type',
            'width' => 50,
            'assign' => FileType::Jpg,
            'stored' => 'jpg',
            'default' => null,
            'badCasing' => 'JPG',
        ]],
        'plan_indicators.period' => [[
            'model' => PlanIndicator::class,
            'table' => 'plan_indicators',
            'column' => 'period',
            'width' => 50,
            'assign' => Period::Monthly,
            'stored' => 'monthly',
            'default' => Period::Quarterly,
            'badCasing' => 'Monthly',
        ]],
        'report_files.type' => [[
            'model' => ReportFile::class,
            'table' => 'report_files',
            'column' => 'type',
            'width' => 50,
            'assign' => FileType::Png,
            'stored' => 'png',
            'default' => null,
            'badCasing' => 'PNG',
        ]],
        'task_attachments.type' => [[
            'model' => TaskAttachment::class,
            'table' => 'task_attachments',
            'column' => 'type',
            'width' => 50,
            'assign' => FileType::Docx,
            'stored' => 'docx',
            'default' => null,
            'badCasing' => 'DOCX',
        ]],
        'donor_proposal.currency' => [[
            'model' => DonorProposal::class,
            'table' => 'donor_proposal',
            'column' => 'currency',
            'width' => 10,
            'assign' => Currency::Eur,
            'stored' => 'EUR',
            'default' => Currency::Usd,
            'badCasing' => 'usd',
        ]],
        'transfers_tracking.amount_received_currency' => [[
            'model' => TransfersTracking::class,
            'table' => 'transfers_tracking',
            'column' => 'amount_received_currency',
            'width' => 10,
            'assign' => Currency::Syp,
            'stored' => 'SYP',
            'default' => Currency::Usd,
            'badCasing' => 'syp',
        ]],
    ];
}

dataset('enum columns', fn (): array => enumColumns());

dataset('defaulted enum columns', fn (): array => array_filter(
    enumColumns(),
    fn (array $row): bool => $row[0]['default'] !== null,
));

test('an enum assigned to a model is stored as its string value', function (array $column) {
    $model = $column['model']::factory()->create([$column['column'] => $column['assign']]);

    expect($model->fresh()->{$column['column']})->toBe($column['assign']);

    $this->assertDatabaseHas($column['table'], [
        'id' => $model->id,
        $column['column'] => $column['stored'],
    ]);
})->with('enum columns');

/**
 * For the `proposals.status` row, this does not actually exercise the column
 * default: `Proposal` carries `protected $attributes = ['status' => 'concept
 * note']` mirroring it, so `ProposalFactory::create()` inserts 'concept note'
 * explicitly (it is in the INSERT's value list) rather than omitting the
 * column and letting the database supply it. `fresh()` then reads back
 * exactly that inserted value, so the assertion is really testing the model's
 * `$attributes` mirror round-tripping through the database, not the column's
 * `->default('concept note')`. It is still a correct assertion — it proves
 * the mirror produces the right enum case — and the other rows still exercise
 * a genuine column default. The `proposals.status` column default itself is
 * proven independently by a raw insert in tests/Feature/ProposalTest.php.
 */
test('a column default hydrates as its enum case', function (array $column) {
    $model = $column['model']::factory()->create()->fresh();

    expect($model->{$column['column']})->toBe($column['default']);
})->with('defaulted enum columns');

/**
 * Casing is part of the value, exactly as it is for UserRole. A value that
 * differs only by capitalisation is not a fifth member — it is unreadable.
 */
test('a value differing only by casing is not accepted on read', function (array $column) {
    $model = $column['model']::factory()->create();

    DB::table($column['table'])
        ->where('id', $model->id)
        ->update([$column['column'] => $column['badCasing']]);

    $column['model']::query()->findOrFail($model->id)->{$column['column']};
})->with('enum columns')->throws(ValueError::class);

/**
 * The bound is the column's real width, not a blanket 50. The four currency
 * columns are VARCHAR(10), so a blanket check would pass a value the database
 * would then truncate or reject.
 */
test('every enum value fits its column', function (array $column) {
    foreach ($column['assign']::values() as $value) {
        expect(strlen($value))
            ->toBeLessThanOrEqual($column['width'], "{$value} does not fit {$column['table']}.{$column['column']}");
    }
})->with('enum columns');
