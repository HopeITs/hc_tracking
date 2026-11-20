<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\ProposalStatus;
use App\Enums\ResultType;
use App\Models\Activity;
use App\Models\Donor;
use App\Models\Indicator;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\Result;
use Illuminate\Database\Seeder;

/**
 * A local copy of the live proposal "Micro Projects - Latakia - ACN" in
 * Proposal draft, so the redesigned proposal page has the mockup's starting
 * point to test against: no currency on the budget, no file, no reviewer.
 *
 * Not part of DatabaseSeeder. Run it by hand:
 *
 *     php artisan db:seed --class=ProposalPageDemoSeeder
 *
 * Safe to run again: the proposal is matched on its internal number.
 */
class ProposalPageDemoSeeder extends Seeder
{
    public const NUMBER = 'HC-2026-990';

    public function run(): void
    {
        Activity::withoutRecording(function (): void {
            $donorId = Donor::query()->where('name', 'like', '%Church in Need%')->value('id')
                ?? Donor::factory()->create(['name' => 'Aid To The Church in Need'])->id;

            $proposal = Proposal::query()->where('internal_project_number', self::NUMBER)->first();

            if ($proposal !== null) {
                $this->framework($proposal);

                return;
            }

            $proposal = Proposal::factory()->create([
                'name' => 'Micro Projects - Latakia - ACN',
                'donor_id' => $donorId,
                'internal_project_number' => self::NUMBER,
                'planned_date_begin' => '2026-01-01',
                'planned_date_end' => '2026-12-31',
                'status' => ProposalStatus::ProposalDraft,
                'requested_budget' => '156400.00',
                'requested_currency' => null,
            ]);

            ProposalStatusNote::query()->create([
                'proposal_id' => $proposal->id,
                'status' => ProposalStatus::ConceptNote,
                'context' => 'Proposal recorded.',
                'date' => '2026-11-16',
                'is_correction' => false,
            ]);

            ProposalStatusNote::query()->create([
                'proposal_id' => $proposal->id,
                'status' => ProposalStatus::ProposalDraft,
                'context' => 'The proposal already exists.',
                'date' => '2026-11-16',
                'is_correction' => false,
            ]);

            $this->framework($proposal);
        });
    }

    /**
     * The mockup's framework (from the live Micro Project framework): one
     * impact, two outcomes, five outputs, their indicators without targets,
     * and six activities not yet linked to an output. Added once.
     */
    private function framework(Proposal $proposal): void
    {
        if (Result::query()->where('proposal_id', $proposal->id)->exists()) {
            return;
        }

        $result = fn (ResultType $type, string $statement, ?Result $parent = null): Result => Result::query()->create([
            'proposal_id' => $proposal->id,
            'parent_id' => $parent?->id,
            'type' => $type,
            'statement' => $statement,
            'checkout_period' => 'quarterly',
        ]);
        $indicator = fn (Result $owner, string $name, string $unit, ?int $target = null) => Indicator::query()->create([
            'result_id' => $owner->id,
            'name' => $name,
            'unit' => $unit,
            'target' => $target,
        ]);

        $impact = $result(ResultType::Impact, 'Enhancing the economic resilience of beneficiary households by supporting the establishment of sustainable income-generating projects and improving their capacity to generate income and meet basic needs.');
        $indicator($impact, 'Percentage of supported micro-Projects still operational and profitable 24–36 months after support ends.', '%');

        $oc1 = $result(ResultType::Outcome, 'Improved sustainability and growth of loan-supported micro-Projects.', $impact);
        $indicator($oc1, 'Percentage of funded micro-Projects remaining operational 12 months post-funding.', '%');
        $indicator($oc1, 'Percentage of micro-Projects generating profits after 12 months.', '%');
        $op11 = $result(ResultType::Output, 'Targeted households provided with funding for income-generating projects', $oc1);
        $indicator($op11, 'Number of micro-Projects that received funding from the project', 'projects');
        $op12 = $result(ResultType::Output, 'Project owners equipped with financial management, marketing, and planning capacities.', $oc1);
        $indicator($op12, 'Number of beneficiaries completing project management, marketing, and financial planning training', 'people');
        $op13 = $result(ResultType::Output, 'Improved business selection based on market assessment and economic feasibility.', $oc1);
        $indicator($op13, 'Number of market assessments conducted', 'assessments');

        $oc2 = $result(ResultType::Outcome, 'Improved income, economic stability, and capacity to meet the basic needs of beneficiary households.', $impact);
        $indicator($oc2, 'Average net monthly income of beneficiary households before and after the Support.', '%');
        $op21 = $result(ResultType::Output, 'Beneficiary households have diversified income sources', $oc2);
        $indicator($op21, 'Percentage of households adding a new income-generating activity during the project.', '%');
        $op22 = $result(ResultType::Output, 'Households have the capacity to meet their basic needs', $oc2);
        $indicator($op22, 'Percentage of households able to meet their basic needs without resorting to negative coping strategies.', '%');

        foreach ([
            ['Registration', 'continuous', [['number of registered HH', 50]]],
            ['M&E Follow-up visits', 'continuous', [['number of Visits', 50]]],
            ['Provide training courses', 'continuous', [['number of courses', null], ['number of people trained', 50]]],
            ['Market Study', 'event', [['number of studies conducted', 6]]],
            ['Pre/Post test for training course', 'continuous', [['number of people participant in the test', 40], ['number of tests', null]]],
            ['dissension making', 'event', [['number of projects approved', 50], ['number of projects entered the proses of decisions making', 70]]],
        ] as [$name, $kind, $counts]) {
            $activity = Result::query()->create([
                'proposal_id' => $proposal->id,
                'parent_id' => null,
                'type' => ResultType::Activity,
                'activity_kind' => $kind,
                'statement' => $name,
                'checkout_period' => 'quarterly',
            ]);

            foreach ($counts as [$countName, $target]) {
                $indicator($activity, $countName, 'number', $target);
            }
        }
    }
}
