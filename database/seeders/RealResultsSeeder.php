<?php

namespace Database\Seeders;

use App\Enums\ReportStatus;
use App\Models\Indicator;
use App\Models\IndicatorProgress;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Result;
use App\Models\User;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The organisation's real logframe: results, indicators, plan targets,
 * recorded progress, and narrative reports.
 *
 * Deliberately NOT wired into DatabaseSeeder::run() — this is real programme
 * data, not demo data every fresh dev/test database should get. Depends on
 * RealProjectsSeeder and RealProposalsSeeder having already run. Run it
 * explicitly:
 *
 *     php artisan db:seed --class=RealResultsSeeder
 *
 * or with every other real seeder, in dependency order, via RealDataSeeder.
 *
 * Results are resolved by statement text (unique across the source sheet) so
 * a result's `parent_id` can point at a sibling inserted earlier in the same
 * run. Indicator names are likewise unique across the whole sheet.
 *
 * The Plan Indicators tab's "source_name" column does not always match a
 * proposal name verbatim (e.g. "Family to Family" for the proposal
 * "Family to Family - CP", or "Study Zone Syria - 6 Centers" for
 * "Study Zone Syria - CDCS") — `PLAN_INDICATOR_SOURCE_PROPOSALS` below maps
 * each label to the actual proposal it names.
 *
 * The Reports tab gives no plan/project/proposal reference, only an
 * indicator name and a narrative that names a specific project — several
 * indicator names are targeted by more than one plan indicator (e.g. once
 * from an MCC proposal and again from a CP proposal, for the same project
 * and the same amount). Each report row below carries the project name
 * needed to resolve the right plan indicator; where more than one plan
 * indicator still matches, the first one inserted is used.
 */
class RealResultsSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    protected const PLAN_INDICATOR_SOURCE_PROPOSALS = [
        'Family to Family' => 'Family to Family - CP',
        'Micro Projects - CP' => 'Micro Projects - CP',
        'Micro Projects - MCC' => 'Micro Projects - MCC',
        'Micro Projects Damascus - 2026' => 'Micro Projects Damascus - ACN',
        'Micro Projects Homs - 2026' => 'Micro Projects Homs - ACN',
        'Micro Projects Latakia - 2026' => 'Micro Projects Latakia - ACN',
        'Micro Projects Al-Wadi - 2026' => 'Micro Projects Al-Wadi - ACN',
        'ADISC - MCC' => 'ADISC - MCC',
        'DDISC - MCC' => 'DDISC - MCC',
        'Study Zone Syria - 6 Centers' => 'Study Zone Syria - CDCS',
        'Children University Aleppo - MCC' => 'Children University Aleppo - MCC',
        'Children University Aleppo - CP' => 'Children University Aleppo - CP',
        'Leadership Aleppo' => 'Leadership Aleppo - MCC',
        'Leadership Homs - Missio Achen' => 'Leadership Homs - Missio Achen',
        'School to School - Equipments' => 'Educational equipment - Myriad',
        'Houses Restoration' => 'Houses Restoration - CGR',
    ];

    public function run(): void
    {
        $resultIds = collect();

        foreach ($this->results() as $result) {
            $parentId = $result['parent'] === null ? null : $resultIds->get($result['parent']);

            $created = Result::query()->firstOrCreate(
                ['statement' => $result['statement']],
                [
                    'parent_id' => $parentId,
                    'program_id' => $result['program_id'],
                    'type' => $result['type'],
                    'checkout_period' => $result['checkout_period'],
                ],
            );

            $resultIds->put($result['statement'], $created->id);
        }

        $projectIds = Project::query()->pluck('id', 'name');
        $indicatorIds = collect();

        foreach ($this->indicators() as $indicator) {
            $resultId = $resultIds->get($indicator['result']);
            $projectId = $projectIds->get($indicator['project']);

            if ($resultId === null || $projectId === null) {
                throw new RuntimeException("Unknown result \"{$indicator['result']}\" or project \"{$indicator['project']}\" for indicator \"{$indicator['name']}\".");
            }

            $created = Indicator::query()->firstOrCreate(
                ['name' => $indicator['name']],
                [
                    'result_id' => $resultId,
                    'project_id' => $projectId,
                    'measure' => $indicator['measure'],
                    'unit' => $indicator['unit'],
                    'baseline' => $indicator['baseline'],
                    'target' => $indicator['target'],
                    'frequency' => 'quarterly',
                ],
            );

            $indicatorIds->put($indicator['name'], $created->id);
        }

        $proposalIds = Proposal::query()->pluck('id', 'name');

        foreach ($this->planIndicators() as $row) {
            $indicatorId = $indicatorIds->get($row['indicator']);
            $projectId = $projectIds->get($row['project']);
            $proposalName = self::PLAN_INDICATOR_SOURCE_PROPOSALS[$row['source']] ?? null;
            $proposalId = $proposalName === null ? null : $proposalIds->get($proposalName);

            if ($indicatorId === null || $projectId === null || $proposalId === null) {
                throw new RuntimeException("Unknown indicator \"{$row['indicator']}\", project \"{$row['project']}\" or source \"{$row['source']}\" for a plan indicator.");
            }

            PlanIndicator::query()->firstOrCreate(
                ['source_type' => 'proposal', 'source_id' => $proposalId, 'indicator_id' => $indicatorId, 'project_id' => $projectId],
                ['target' => $row['target'], 'period' => $row['period']],
            );
        }

        foreach ($this->indicatorProgress() as $row) {
            $indicatorId = $indicatorIds->get($row['indicator']);

            if ($indicatorId === null) {
                throw new RuntimeException("Unknown indicator \"{$row['indicator']}\" for a progress reading.");
            }

            IndicatorProgress::query()->firstOrCreate(
                ['indicator_id' => $indicatorId, 'date' => $row['date']],
                [
                    'value' => $row['value'],
                    'notes' => $row['notes'],
                    'source_of_verification' => $row['source_of_verification'],
                    'recorded_by' => User::query()->inRandomOrder()->value('id'),
                ],
            );
        }

        foreach ($this->reports() as $row) {
            $indicatorId = $indicatorIds->get($row['indicator']);
            $projectId = $projectIds->get($row['project']);

            if ($indicatorId === null || $projectId === null) {
                throw new RuntimeException("Unknown indicator \"{$row['indicator']}\" or project \"{$row['project']}\" for a report.");
            }

            $planIndicatorId = PlanIndicator::query()
                ->where('indicator_id', $indicatorId)
                ->where('project_id', $projectId)
                ->orderBy('id')
                ->value('id');

            if ($planIndicatorId === null) {
                throw new RuntimeException("No plan indicator for \"{$row['indicator']}\" on \"{$row['project']}\".");
            }

            Report::query()->firstOrCreate(
                ['plan_indicator_id' => $planIndicatorId, 'planned_date' => $row['planned_date']],
                [
                    'user_id' => User::query()->inRandomOrder()->value('id'),
                    'status' => ReportStatus::Approved,
                    'period_start' => $row['period_start'],
                    'period_end' => $row['period_end'],
                    'value' => $row['value'],
                    'narrative_report_notes' => $row['narrative_report_notes'],
                    'executive_summary' => $row['executive_summary'],
                    'key_achievements' => $row['key_achievements'],
                    'challenges' => $row['challenges'],
                    'lessons_learned' => $row['lessons_learned'],
                    'next_period_plans' => $row['next_period_plans'],
                    'submit_date' => $row['submit_date'],
                    'is_seen_by_meal' => true,
                ],
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function results(): array
    {
        return [
            ['statement' => 'Vulnerable individuals, farmers, and families achieve economic self-reliance and sustainable livelihoods.', 'parent' => null, 'program_id' => 2, 'type' => 'outcome', 'checkout_period' => 'quarterly'],
            ['statement' => 'Target beneficiaries establish, sustain, or expand viable small businesses and agricultural enterprises.', 'parent' => 'Vulnerable individuals, farmers, and families achieve economic self-reliance and sustainable livelihoods.', 'program_id' => 2, 'type' => 'output', 'checkout_period' => 'quarterly'],
            ['statement' => 'Small business grants, toolkits, and microcredit packages are distributed to approved beneficiaries.', 'parent' => 'Target beneficiaries establish, sustain, or expand viable small businesses and agricultural enterprises.', 'program_id' => 2, 'type' => 'output', 'checkout_period' => 'quarterly'],
            ['statement' => 'Feasibility assessments, business plan development, and post-funding mentoring visits are conducted.', 'parent' => 'Target beneficiaries establish, sustain, or expand viable small businesses and agricultural enterprises.', 'program_id' => 4, 'type' => 'impact', 'checkout_period' => 'annual'],
            ['statement' => 'Crisis-affected households maintain dignity, cover essential basic needs, and build household resilience.', 'parent' => null, 'program_id' => 4, 'type' => 'outcome', 'checkout_period' => 'quarterly'],
            ['statement' => 'Target households have regular and reliable access to essential food, healthcare, and basic necessities.', 'parent' => 'Crisis-affected households maintain dignity, cover essential basic needs, and build household resilience.', 'program_id' => 4, 'type' => 'output', 'checkout_period' => 'quarterly'],
            ['statement' => 'Monthly multipurpose cash assistance transfers are distributed to verified vulnerable families.', 'parent' => 'Target households have regular and reliable access to essential food, healthcare, and basic necessities.', 'program_id' => 4, 'type' => 'output', 'checkout_period' => 'quarterly'],
            ['statement' => 'Household vulnerability assessments and post-distribution monitoring (PDM) surveys are completed.', 'parent' => 'Target households have regular and reliable access to essential food, healthcare, and basic necessities.', 'program_id' => 7, 'type' => 'impact', 'checkout_period' => 'annual'],
            ['statement' => 'Youth and university graduates achieve career readiness, workforce integration, and economic empowerment.', 'parent' => null, 'program_id' => 7, 'type' => 'outcome', 'checkout_period' => 'quarterly'],
            ['statement' => 'Trainees acquire specialized digital, technical, and professional skills aligned with labor market demand.', 'parent' => 'Youth and university graduates achieve career readiness, workforce integration, and economic empowerment.', 'program_id' => 7, 'type' => 'output', 'checkout_period' => 'quarterly'],
            ['statement' => 'Specialized technical, IT, digital design, and career development training sessions are delivered.', 'parent' => 'Trainees acquire specialized digital, technical, and professional skills aligned with labor market demand.', 'program_id' => 7, 'type' => 'output', 'checkout_period' => 'quarterly'],
            ['statement' => 'Trainees receive career counseling, internship opportunities, and direct connections to employers.', 'parent' => 'Trainees acquire specialized digital, technical, and professional skills aligned with labor market demand.', 'program_id' => 8, 'type' => 'impact', 'checkout_period' => 'annual'],
            ['statement' => 'University students and youth have equitable access to productive learning environments that foster academic success.', 'parent' => null, 'program_id' => 8, 'type' => 'outcome', 'checkout_period' => 'quarterly'],
            ['statement' => 'Students utilize safe, fully-equipped study facilities that enhance academic performance and peer collaboration.', 'parent' => 'University students and youth have equitable access to productive learning environments that foster academic success.', 'program_id' => 8, 'type' => 'output', 'checkout_period' => 'quarterly'],
            ['statement' => 'Fully-equipped study centers offering uninterrupted electricity, internet, and study stations are maintained.', 'parent' => 'Students utilize safe, fully-equipped study facilities that enhance academic performance and peer collaboration.', 'program_id' => 8, 'type' => 'output', 'checkout_period' => 'quarterly'],
            ['statement' => 'Academic workshops, peer-study sessions, and educational community activities are organized.', 'parent' => 'Students utilize safe, fully-equipped study facilities that enhance academic performance and peer collaboration.', 'program_id' => 10, 'type' => 'impact', 'checkout_period' => 'annual'],
            ['statement' => 'Crisis-affected children experience emotional well-being, social integration, and cognitive development.', 'parent' => null, 'program_id' => 10, 'type' => 'outcome', 'checkout_period' => 'quarterly'],
            ['statement' => 'Children demonstrate improved psychosocial well-being, positive social behaviors, and developmental skills.', 'parent' => 'Crisis-affected children experience emotional well-being, social integration, and cognitive development.', 'program_id' => 10, 'type' => 'output', 'checkout_period' => 'quarterly'],
            ['statement' => 'Interactive non-formal educational sessions and developmental learning activities are delivered.', 'parent' => 'Children demonstrate improved psychosocial well-being, positive social behaviors, and developmental skills.', 'program_id' => 10, 'type' => 'output', 'checkout_period' => 'quarterly'],
            ['statement' => 'Structured psychosocial support (PSS) workshops, creative arts, and recreational events are conducted.', 'parent' => 'Children demonstrate improved psychosocial well-being, positive social behaviors, and developmental skills.', 'program_id' => 13, 'type' => 'impact', 'checkout_period' => 'annual'],
            ['statement' => 'Equip youth to lead within their communities more effectively, with an emphasis on crisis management.', 'parent' => null, 'program_id' => 13, 'type' => 'outcome', 'checkout_period' => 'quarterly'],
            ['statement' => 'Increased leadership competencies, crisis management skills, and civic responsibility among youth leaders.', 'parent' => 'Equip youth to lead within their communities more effectively, with an emphasis on crisis management.', 'program_id' => 13, 'type' => 'output', 'checkout_period' => 'quarterly'],
            ['statement' => 'Comprehensive leadership curriculum, mentorship tracks, and specialized skills workshops are completed.', 'parent' => 'Increased leadership competencies, crisis management skills, and civic responsibility among youth leaders.', 'program_id' => 13, 'type' => 'outcome', 'checkout_period' => 'quarterly'],
            ['statement' => 'Increased community engagement and participation in initiatives led by empowered youth leaders.', 'parent' => 'Equip youth to lead within their communities more effectively, with an emphasis on crisis management.', 'program_id' => 13, 'type' => 'output', 'checkout_period' => 'quarterly'],
            ['statement' => 'Community-focused social initiatives and localized response projects are designed and implemented.', 'parent' => 'Increased community engagement and participation in initiatives led by empowered youth leaders.', 'program_id' => 6, 'type' => 'impact', 'checkout_period' => 'annual'],
            ['statement' => 'School-aged children access safe, protective, and well-equipped learning environments in formal schools.', 'parent' => null, 'program_id' => 6, 'type' => 'outcome', 'checkout_period' => 'quarterly'],
            ['statement' => 'Damaged schools and learning facilities are rehabilitated and equipped to resume formal educational activities.', 'parent' => 'School-aged children access safe, protective, and well-equipped learning environments in formal schools.', 'program_id' => 6, 'type' => 'output', 'checkout_period' => 'quarterly'],
            ['statement' => 'Physical rehabilitation, classroom repairs, and sanitary facility improvements are completed in schools.', 'parent' => 'Damaged schools and learning facilities are rehabilitated and equipped to resume formal educational activities.', 'program_id' => 6, 'type' => 'output', 'checkout_period' => 'quarterly'],
            ['statement' => 'Essential educational equipment, school supplies, and renewable energy/heating units are installed.', 'parent' => 'Damaged schools and learning facilities are rehabilitated and equipped to resume formal educational activities.', 'program_id' => 3, 'type' => 'impact', 'checkout_period' => 'annual'],
            ['statement' => 'Affected populations and displaced families receive rapid, dignified life-saving relief and shelter protection.', 'parent' => null, 'program_id' => 3, 'type' => 'outcome', 'checkout_period' => 'quarterly'],
            ['statement' => 'Immediate emergency basic needs and vital shelter requirements are met during sudden crises and shocks.', 'parent' => 'Affected populations and displaced families receive rapid, dignified life-saving relief and shelter protection.', 'program_id' => 3, 'type' => 'output', 'checkout_period' => 'quarterly'],
            ['statement' => 'Emergency food parcels, hygiene kits, winterization supplies, and non-food items are distributed.', 'parent' => 'Immediate emergency basic needs and vital shelter requirements are met during sudden crises and shocks.', 'program_id' => 3, 'type' => 'output', 'checkout_period' => 'quarterly'],
            ['statement' => 'Damaged houses, workshops, and communal facilities are rehabilitated for safe re-occupancy.', 'parent' => 'Immediate emergency basic needs and vital shelter requirements are met during sudden crises and shocks.', 'program_id' => 3, 'type' => 'output', 'checkout_period' => 'quarterly'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function indicators(): array
    {
        return [
            ['name' => '% of supported business owners reporting improved household financial stability', 'result' => 'Vulnerable individuals, farmers, and families achieve economic self-reliance and sustainable livelihoods.', 'project' => 'Micro Projects Aleppo', 'measure' => 'percentage', 'unit' => '%', 'baseline' => '0', 'target' => '75'],
            ['name' => '% of funded micro-projects remaining operational and profitable after 6 months', 'result' => 'Target beneficiaries establish, sustain, or expand viable small businesses and agricultural enterprises.', 'project' => 'Micro Projects Aleppo', 'measure' => 'percentage', 'unit' => '%', 'baseline' => '0', 'target' => '80'],
            ['name' => 'Number of micro-projects and small businesses funded and launched', 'result' => 'Small business grants, toolkits, and microcredit packages are distributed to approved beneficiaries.', 'project' => 'Micro Projects Aleppo', 'measure' => 'numeric', 'unit' => 'businesses', 'baseline' => '0', 'target' => '800'],
            ['name' => 'Number of business feasibility assessments and mentoring follow-up visits conducted', 'result' => 'Feasibility assessments, business plan development, and post-funding mentoring visits are conducted.', 'project' => 'Micro Projects Aleppo', 'measure' => 'numeric', 'unit' => 'visits', 'baseline' => '0', 'target' => '1200'],
            ['name' => '% of beneficiary families reporting ability to meet their priority basic needs', 'result' => 'Crisis-affected households maintain dignity, cover essential basic needs, and build household resilience.', 'project' => 'Family to Family Aleppo', 'measure' => 'percentage', 'unit' => '%', 'baseline' => '20', 'target' => '85'],
            ['name' => '% of target families with improved Food Consumption Score (FCS)', 'result' => 'Target households have regular and reliable access to essential food, healthcare, and basic necessities.', 'project' => 'Family to Family Aleppo', 'measure' => 'percentage', 'unit' => '%', 'baseline' => '35', 'target' => '80'],
            ['name' => 'Number of vulnerable households receiving monthly cash transfers', 'result' => 'Monthly multipurpose cash assistance transfers are distributed to verified vulnerable families.', 'project' => 'Family to Family Aleppo', 'measure' => 'numeric', 'unit' => 'households', 'baseline' => '0', 'target' => '3200'],
            ['name' => 'Number of household vulnerability and PDM monitoring surveys conducted', 'result' => 'Household vulnerability assessments and post-distribution monitoring (PDM) surveys are completed.', 'project' => 'Family to Family Aleppo', 'measure' => 'numeric', 'unit' => 'surveys', 'baseline' => '0', 'target' => '3200'],
            ['name' => 'Number of youth participants securing jobs or paid internships post-training', 'result' => 'Youth and university graduates achieve career readiness, workforce integration, and economic empowerment.', 'project' => 'DISC Aleppo', 'measure' => 'numeric', 'unit' => 'graduates', 'baseline' => '0', 'target' => '560'],
            ['name' => '% of trainees passing end-of-course technical and employability competency evaluations', 'result' => 'Trainees acquire specialized digital, technical, and professional skills aligned with labor market demand.', 'project' => 'DISC Aleppo', 'measure' => 'percentage', 'unit' => '%', 'baseline' => '0', 'target' => '85'],
            ['name' => 'Number of trainees completing specialized professional and digital training tracks', 'result' => 'Specialized technical, IT, digital design, and career development training sessions are delivered.', 'project' => 'DISC Aleppo', 'measure' => 'numeric', 'unit' => 'trainees', 'baseline' => '0', 'target' => '600'],
            ['name' => 'Number of 1-on-1 career counseling and job-matching sessions completed', 'result' => 'Trainees receive career counseling, internship opportunities, and direct connections to employers.', 'project' => 'DISC Aleppo', 'measure' => 'numeric', 'unit' => 'sessions', 'baseline' => '0', 'target' => '500'],
            ['name' => '% of students reporting that Study Zone significantly supported their academic success', 'result' => 'University students and youth have equitable access to productive learning environments that foster academic success.', 'project' => 'Study Zone Aleppo', 'measure' => 'percentage', 'unit' => '%', 'baseline' => '0', 'target' => '85'],
            ['name' => '% of visiting students reporting high satisfaction with study space facilities and services', 'result' => 'Students utilize safe, fully-equipped study facilities that enhance academic performance and peer collaboration.', 'project' => 'Study Zone Aleppo', 'measure' => 'percentage', 'unit' => '%', 'baseline' => '0', 'target' => '80'],
            ['name' => 'Number of unique university students registered and utilizing study facilities', 'result' => 'Fully-equipped study centers offering uninterrupted electricity, internet, and study stations are maintained.', 'project' => 'Study Zone Aleppo', 'measure' => 'numeric', 'unit' => 'students', 'baseline' => '0', 'target' => '7000'],
            ['name' => 'Number of educational workshops and student community events hosted', 'result' => 'Academic workshops, peer-study sessions, and educational community activities are organized.', 'project' => 'Study Zone Aleppo', 'measure' => 'numeric', 'unit' => 'events', 'baseline' => '0', 'target' => '24'],
            ['name' => '% of participating children showing improved emotional well-being and social interaction', 'result' => 'Crisis-affected children experience emotional well-being, social integration, and cognitive development.', 'project' => 'Children University Aleppo', 'measure' => 'percentage', 'unit' => '%', 'baseline' => '0', 'target' => '80'],
            ['name' => '% of children achieving developmental milestones across learning and creative modules', 'result' => 'Children demonstrate improved psychosocial well-being, positive social behaviors, and developmental skills.', 'project' => 'Children University Aleppo', 'measure' => 'percentage', 'unit' => '%', 'baseline' => '0', 'target' => '75'],
            ['name' => 'Number of children participating in non-formal education and interactive learning', 'result' => 'Interactive non-formal educational sessions and developmental learning activities are delivered.', 'project' => 'Children University Aleppo', 'measure' => 'numeric', 'unit' => 'children', 'baseline' => '0', 'target' => '600'],
            ['name' => 'Number of structured PSS sessions, creative arts, and recreational events conducted', 'result' => 'Structured psychosocial support (PSS) workshops, creative arts, and recreational events are conducted.', 'project' => 'Children University Aleppo', 'measure' => 'numeric', 'unit' => 'sessions', 'baseline' => '0', 'target' => '48'],
            ['name' => '% of participants feeling equipped with leadership skills to lead effectively in crisis', 'result' => 'Equip youth to lead within their communities more effectively, with an emphasis on crisis management.', 'project' => 'LEAP Aleppo', 'measure' => 'percentage', 'unit' => '%', 'baseline' => '0', 'target' => '80'],
            ['name' => '% of youth leaders demonstrating mastery of core leadership and management modules', 'result' => 'Increased leadership competencies, crisis management skills, and civic responsibility among youth leaders.', 'project' => 'LEAP Aleppo', 'measure' => 'percentage', 'unit' => '%', 'baseline' => '0', 'target' => '85'],
            ['name' => 'Number of young leaders completing the full LEAP formation and coaching cycle', 'result' => 'Comprehensive leadership curriculum, mentorship tracks, and specialized skills workshops are completed.', 'project' => 'LEAP Aleppo', 'measure' => 'numeric', 'unit' => 'leaders', 'baseline' => '0', 'target' => '60'],
            ['name' => '% of youth-led community initiatives meeting planned social objectives and community reach', 'result' => 'Increased community engagement and participation in initiatives led by empowered youth leaders.', 'project' => 'LEAP Aleppo', 'measure' => 'percentage', 'unit' => '%', 'baseline' => '0', 'target' => '85'],
            ['name' => 'Number of community-based initiatives successfully launched by youth leader teams', 'result' => 'Community-focused social initiatives and localized response projects are designed and implemented.', 'project' => 'LEAP Aleppo', 'measure' => 'numeric', 'unit' => 'initiatives', 'baseline' => '0', 'target' => '6'],
            ['name' => 'Number of students benefiting from rehabilitated and equipped school facilities', 'result' => 'School-aged children access safe, protective, and well-equipped learning environments in formal schools.', 'project' => 'School to School Aleppo', 'measure' => 'numeric', 'unit' => 'students', 'baseline' => '0', 'target' => '5000'],
            ['name' => '% of targeted schools fully functional and operational following rehabilitation', 'result' => 'Damaged schools and learning facilities are rehabilitated and equipped to resume formal educational activities.', 'project' => 'School to School Aleppo', 'measure' => 'percentage', 'unit' => '%', 'baseline' => '0', 'target' => '100'],
            ['name' => 'Number of damaged schools undergoing physical and sanitary rehabilitation', 'result' => 'Physical rehabilitation, classroom repairs, and sanitary facility improvements are completed in schools.', 'project' => 'School to School Aleppo', 'measure' => 'numeric', 'unit' => 'schools', 'baseline' => '0', 'target' => '10'],
            ['name' => 'Number of schools receiving educational equipment, solar systems, or winter heating units', 'result' => 'Physical rehabilitation, classroom repairs, and sanitary facility improvements are completed in schools.', 'project' => 'School to School Aleppo', 'measure' => 'numeric', 'unit' => 'schools', 'baseline' => '0', 'target' => '15'],
            ['name' => 'Number of conflict- or disaster-affected individuals supported with emergency assistance', 'result' => 'Affected populations and displaced families receive rapid, dignified life-saving relief and shelter protection.', 'project' => 'Houses & Facility Rehabilitation', 'measure' => 'numeric', 'unit' => 'individuals', 'baseline' => '0', 'target' => '3000'],
            ['name' => '% of assisted disaster-affected households reporting satisfied emergency shelter and NFI needs', 'result' => 'Immediate emergency basic needs and vital shelter requirements are met during sudden crises and shocks.', 'project' => 'Houses & Facility Rehabilitation', 'measure' => 'percentage', 'unit' => '%', 'baseline' => '0', 'target' => '85'],
            ['name' => 'Number of emergency relief kits (food, hygiene, NFIs) distributed to displaced families', 'result' => 'Emergency food parcels, hygiene kits, winterization supplies, and non-food items are distributed.', 'project' => 'Houses & Facility Rehabilitation', 'measure' => 'numeric', 'unit' => 'kits', 'baseline' => '0', 'target' => '1500'],
            ['name' => 'Number of damaged residential houses and community facilities repaired and rehabilitated', 'result' => 'Damaged houses, workshops, and communal facilities are rehabilitated for safe re-occupancy.', 'project' => 'Houses & Facility Rehabilitation', 'measure' => 'numeric', 'unit' => 'structures', 'baseline' => '0', 'target' => '720'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function planIndicators(): array
    {
        return [
            ['source' => 'Family to Family', 'indicator' => 'Number of vulnerable households receiving monthly cash transfers', 'target' => '3200', 'project' => 'Family to Family Aleppo', 'period' => 'monthly'],
            ['source' => 'Family to Family', 'indicator' => '% of target families with improved Food Consumption Score (FCS)', 'target' => '80', 'project' => 'Family to Family Aleppo', 'period' => 'quarterly'],
            ['source' => 'Family to Family', 'indicator' => 'Number of household vulnerability and PDM monitoring surveys conducted', 'target' => '3200', 'project' => 'Family to Family Aleppo', 'period' => 'quarterly'],
            ['source' => 'Family to Family', 'indicator' => '% of beneficiary families reporting ability to meet their priority basic needs', 'target' => '85', 'project' => 'Family to Family Aleppo', 'period' => 'annual'],
            ['source' => 'Micro Projects - CP', 'indicator' => 'Number of micro-projects and small businesses funded and launched', 'target' => '250', 'project' => 'Micro Projects Aleppo', 'period' => 'quarterly'],
            ['source' => 'Micro Projects - CP', 'indicator' => '% of funded micro-projects remaining operational and profitable after 6 months', 'target' => '80', 'project' => 'Micro Projects Aleppo', 'period' => 'quarterly'],
            ['source' => 'Micro Projects - CP', 'indicator' => 'Number of business feasibility assessments and mentoring follow-up visits conducted', 'target' => '400', 'project' => 'Micro Projects Aleppo', 'period' => 'quarterly'],
            ['source' => 'Micro Projects - CP', 'indicator' => '% of supported business owners reporting improved household financial stability', 'target' => '75', 'project' => 'Micro Projects Aleppo', 'period' => 'annual'],
            ['source' => 'Micro Projects - MCC', 'indicator' => 'Number of micro-projects and small businesses funded and launched', 'target' => '100', 'project' => 'Micro Projects Aleppo', 'period' => 'quarterly'],
            ['source' => 'Micro Projects Damascus - 2026', 'indicator' => 'Number of micro-projects and small businesses funded and launched', 'target' => '150', 'project' => 'Micro Projects Damascus', 'period' => 'quarterly'],
            ['source' => 'Micro Projects Homs - 2026', 'indicator' => 'Number of micro-projects and small businesses funded and launched', 'target' => '150', 'project' => 'Micro Projects Homs', 'period' => 'quarterly'],
            ['source' => 'Micro Projects Latakia - 2026', 'indicator' => 'Number of micro-projects and small businesses funded and launched', 'target' => '100', 'project' => 'Micro Projects Latakia', 'period' => 'quarterly'],
            ['source' => 'Micro Projects Al-Wadi - 2026', 'indicator' => 'Number of micro-projects and small businesses funded and launched', 'target' => '50', 'project' => 'Micro Projects Al-Wadi', 'period' => 'quarterly'],
            ['source' => 'ADISC - MCC', 'indicator' => 'Number of trainees completing specialized professional and digital training tracks', 'target' => '300', 'project' => 'DISC Aleppo', 'period' => 'quarterly'],
            ['source' => 'ADISC - MCC', 'indicator' => '% of trainees passing end-of-course technical and employability competency evaluations', 'target' => '85', 'project' => 'DISC Aleppo', 'period' => 'quarterly'],
            ['source' => 'ADISC - MCC', 'indicator' => 'Number of 1-on-1 career counseling and job-matching sessions completed', 'target' => '250', 'project' => 'DISC Aleppo', 'period' => 'quarterly'],
            ['source' => 'ADISC - MCC', 'indicator' => 'Number of youth participants securing jobs or paid internships post-training', 'target' => '280', 'project' => 'DISC Aleppo', 'period' => 'annual'],
            ['source' => 'DDISC - MCC', 'indicator' => 'Number of trainees completing specialized professional and digital training tracks', 'target' => '300', 'project' => 'DISC Damascus', 'period' => 'quarterly'],
            ['source' => 'DDISC - MCC', 'indicator' => '% of trainees passing end-of-course technical and employability competency evaluations', 'target' => '85', 'project' => 'DISC Damascus', 'period' => 'quarterly'],
            ['source' => 'DDISC - MCC', 'indicator' => 'Number of youth participants securing jobs or paid internships post-training', 'target' => '280', 'project' => 'DISC Damascus', 'period' => 'annual'],
            ['source' => 'Study Zone Syria - 6 Centers', 'indicator' => 'Number of unique university students registered and utilizing study facilities', 'target' => '7000', 'project' => 'Study Zone Aleppo', 'period' => 'quarterly'],
            ['source' => 'Study Zone Syria - 6 Centers', 'indicator' => '% of visiting students reporting high satisfaction with study space facilities and services', 'target' => '80', 'project' => 'Study Zone Aleppo', 'period' => 'quarterly'],
            ['source' => 'Study Zone Syria - 6 Centers', 'indicator' => 'Number of educational workshops and student community events hosted', 'target' => '24', 'project' => 'Study Zone Aleppo', 'period' => 'quarterly'],
            ['source' => 'Study Zone Syria - 6 Centers', 'indicator' => '% of students reporting that Study Zone significantly supported their academic success', 'target' => '85', 'project' => 'Study Zone Aleppo', 'period' => 'annual'],
            ['source' => 'Children University Aleppo - MCC', 'indicator' => 'Number of children participating in non-formal education and interactive learning', 'target' => '300', 'project' => 'Children University Aleppo', 'period' => 'quarterly'],
            ['source' => 'Children University Aleppo - MCC', 'indicator' => 'Number of structured PSS sessions, creative arts, and recreational events conducted', 'target' => '24', 'project' => 'Children University Aleppo', 'period' => 'quarterly'],
            ['source' => 'Children University Aleppo - MCC', 'indicator' => '% of children achieving developmental milestones across learning and creative modules', 'target' => '75', 'project' => 'Children University Aleppo', 'period' => 'quarterly'],
            ['source' => 'Children University Aleppo - MCC', 'indicator' => '% of participating children showing improved emotional well-being and social interaction', 'target' => '80', 'project' => 'Children University Aleppo', 'period' => 'annual'],
            ['source' => 'Children University Aleppo - CP', 'indicator' => 'Number of children participating in non-formal education and interactive learning', 'target' => '300', 'project' => 'Children University Aleppo', 'period' => 'quarterly'],
            ['source' => 'Children University Aleppo - CP', 'indicator' => 'Number of structured PSS sessions, creative arts, and recreational events conducted', 'target' => '24', 'project' => 'Children University Aleppo', 'period' => 'quarterly'],
            ['source' => 'Leadership Aleppo', 'indicator' => 'Number of young leaders completing the full LEAP formation and coaching cycle', 'target' => '30', 'project' => 'LEAP Aleppo', 'period' => 'quarterly'],
            ['source' => 'Leadership Aleppo', 'indicator' => '% of youth leaders demonstrating mastery of core leadership and management modules', 'target' => '85', 'project' => 'LEAP Aleppo', 'period' => 'quarterly'],
            ['source' => 'Leadership Aleppo', 'indicator' => 'Number of community-based initiatives successfully launched by youth leader teams', 'target' => '3', 'project' => 'LEAP Aleppo', 'period' => 'quarterly'],
            ['source' => 'Leadership Aleppo', 'indicator' => '% of youth-led community initiatives meeting planned social objectives and community reach', 'target' => '85', 'project' => 'LEAP Aleppo', 'period' => 'quarterly'],
            ['source' => 'Leadership Aleppo', 'indicator' => '% of participants feeling equipped with leadership skills to lead effectively in crisis', 'target' => '80', 'project' => 'LEAP Aleppo', 'period' => 'annual'],
            ['source' => 'Leadership Homs - Missio Achen', 'indicator' => 'Number of young leaders completing the full LEAP formation and coaching cycle', 'target' => '25', 'project' => 'LEAP Homs', 'period' => 'quarterly'],
            ['source' => 'Leadership Homs - Missio Achen', 'indicator' => 'Number of community-based initiatives successfully launched by youth leader teams', 'target' => '3', 'project' => 'LEAP Homs', 'period' => 'quarterly'],
            ['source' => 'School to School - Equipments', 'indicator' => 'Number of damaged schools undergoing physical and sanitary rehabilitation', 'target' => '5', 'project' => 'School to School Aleppo', 'period' => 'quarterly'],
            ['source' => 'School to School - Equipments', 'indicator' => 'Number of schools receiving educational equipment, solar systems, or winter heating units', 'target' => '10', 'project' => 'School to School Aleppo', 'period' => 'quarterly'],
            ['source' => 'School to School - Equipments', 'indicator' => '% of targeted schools fully functional and operational following rehabilitation', 'target' => '100', 'project' => 'School to School Aleppo', 'period' => 'quarterly'],
            ['source' => 'School to School - Equipments', 'indicator' => 'Number of students benefiting from rehabilitated and equipped school facilities', 'target' => '2500', 'project' => 'School to School Aleppo', 'period' => 'annual'],
            ['source' => 'Houses Restoration', 'indicator' => 'Number of damaged residential houses and community facilities repaired and rehabilitated', 'target' => '720', 'project' => 'Houses & Facility Rehabilitation', 'period' => 'quarterly'],
            ['source' => 'Houses Restoration', 'indicator' => 'Number of emergency relief kits (food, hygiene, NFIs) distributed to displaced families', 'target' => '1500', 'project' => 'Houses & Facility Rehabilitation', 'period' => 'quarterly'],
            ['source' => 'Houses Restoration', 'indicator' => '% of assisted disaster-affected households reporting satisfied emergency shelter and NFI needs', 'target' => '85', 'project' => 'Houses & Facility Rehabilitation', 'period' => 'quarterly'],
            ['source' => 'Houses Restoration', 'indicator' => 'Number of conflict- or disaster-affected individuals supported with emergency assistance', 'target' => '3000', 'project' => 'Houses & Facility Rehabilitation', 'period' => 'annual'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function indicatorProgress(): array
    {
        return [
            ['indicator' => '% of supported business owners reporting improved household financial stability', 'date' => '2026-03-31', 'value' => '78', 'notes' => 'Survey showed significant improvement in income generation and household resilience.', 'source_of_verification' => 'Annual beneficiary household impact assessment'],
            ['indicator' => '% of funded micro-projects remaining operational and profitable after 6 months', 'date' => '2026-03-31', 'value' => '82', 'notes' => 'Follow-up monitoring verified 82% active operational rate after six months.', 'source_of_verification' => 'Quarterly post-funding monitoring visits & business records'],
            ['indicator' => 'Number of micro-projects and small businesses funded and launched', 'date' => '2026-03-31', 'value' => '2800', 'notes' => 'Cumulative total of 2800 micro-projects funded across Syrian governorates.', 'source_of_verification' => 'Beneficiary grant agreements disbursement receipts & asset handover'],
            ['indicator' => 'Number of business feasibility assessments and mentoring follow-up visits conducted', 'date' => '2026-03-31', 'value' => '3450', 'notes' => 'Mentoring team completed regular feasibility evaluations and coaching sessions.', 'source_of_verification' => 'Field visit reports and project technical evaluation forms'],
            ['indicator' => '% of beneficiary families reporting ability to meet their priority basic needs', 'date' => '2026-03-31', 'value' => '87', 'notes' => 'Beneficiaries reported high dignity and flexibility in addressing living essentials.', 'source_of_verification' => 'Annual impact assessment & household feedback'],
            ['indicator' => '% of target families with improved Food Consumption Score (FCS)', 'date' => '2026-03-31', 'value' => '82', 'notes' => 'Significant reduction in household food insecurity reported across cohorts.', 'source_of_verification' => 'Post-distribution monitoring (PDM) quarterly survey'],
            ['indicator' => 'Number of vulnerable households receiving monthly cash transfers', 'date' => '2026-03-31', 'value' => '3200', 'notes' => 'Regular monthly multi-purpose cash delivered directly to verified households.', 'source_of_verification' => 'Bank transfer confirmations and verified beneficiary receipt logs'],
            ['indicator' => 'Number of household vulnerability and PDM monitoring surveys conducted', 'date' => '2026-03-31', 'value' => '3200', 'notes' => '100% of targeted households surveyed during intake and ongoing monitoring.', 'source_of_verification' => 'PDM survey database & MEAL assessment records'],
            ['indicator' => 'Number of youth participants securing jobs or paid internships post-training', 'date' => '2026-03-31', 'value' => '560', 'notes' => 'Graduates successfully transitioned into local companies NGOs or independent freelancing.', 'source_of_verification' => 'Employment verification letters and employer feedback forms'],
            ['indicator' => '% of trainees passing end-of-course technical and employability competency evaluations', 'date' => '2026-03-31', 'value' => '88', 'notes' => 'High pass rate achieved across IT graphic design and vocational modules.', 'source_of_verification' => 'Examination grades project submissions and practical test scores'],
            ['indicator' => 'Number of trainees completing specialized professional and digital training tracks', 'date' => '2026-03-31', 'value' => '1500', 'notes' => 'Trainees completed targeted courses across Aleppo Damascus and Homs centers.', 'source_of_verification' => 'Attendance registers and training completion certificates'],
            ['indicator' => 'Number of 1-on-1 career counseling and job-matching sessions completed', 'date' => '2026-03-31', 'value' => '1250', 'notes' => 'Career advisors provided individualized CV reviews and interview preparation.', 'source_of_verification' => 'Career counseling appointment logs and student action plans'],
            ['indicator' => '% of students reporting that Study Zone significantly supported their academic success', 'date' => '2026-03-31', 'value' => '88', 'notes' => 'Students highlighted reliable power and internet as essential for exam prep.', 'source_of_verification' => 'Annual student survey & academic success evaluation'],
            ['indicator' => '% of visiting students reporting high satisfaction with study space facilities and services', 'date' => '2026-03-31', 'value' => '86', 'notes' => 'Positive feedback regarding quiet environment equipment and peer collaboration.', 'source_of_verification' => 'Digital entrance survey and quarterly satisfaction questionnaires'],
            ['indicator' => 'Number of unique university students registered and utilizing study facilities', 'date' => '2026-03-31', 'value' => '27000', 'notes' => 'Over 27000 unique students reached across 7 operational centers.', 'source_of_verification' => 'Automated digital access control logs and student registration cards'],
            ['indicator' => 'Number of educational workshops and student community events hosted', 'date' => '2026-03-31', 'value' => '32', 'notes' => 'Workshops held on research methods academic writing and architecture tools.', 'source_of_verification' => 'Event schedules guest sign-in sheets and workshop reports'],
            ['indicator' => '% of participating children showing improved emotional well-being and social interaction', 'date' => '2026-03-31', 'value' => '82', 'notes' => 'Observation confirmed reduced stress behaviors and improved peer bonding.', 'source_of_verification' => 'Pre- and post-intervention psychosocial assessment (PSS tool)'],
            ['indicator' => '% of children achieving developmental milestones across learning and creative modules', 'date' => '2026-03-31', 'value' => '78', 'notes' => 'Children demonstrated notable cognitive growth and creative expression.', 'source_of_verification' => 'Child development tracking portfolios and educator evaluation sheets'],
            ['indicator' => 'Number of children participating in non-formal education and interactive learning', 'date' => '2026-03-31', 'value' => '1000', 'notes' => 'Interactive learning delivered in Aleppo and Homs centers.', 'source_of_verification' => 'Child enrollment registries and weekly attendance rosters'],
            ['indicator' => 'Number of structured PSS sessions, creative arts, and recreational events conducted', 'date' => '2026-03-31', 'value' => '54', 'notes' => 'Activities included art therapy music theatre and structured play sessions.', 'source_of_verification' => 'Activity logs facilitator session notes and photo documentation'],
            ['indicator' => '% of participants feeling equipped with leadership skills to lead effectively in crisis', 'date' => '2026-03-31', 'value' => '82', 'notes' => 'Graduates expressed strong confidence in managing teams during shocks.', 'source_of_verification' => 'MCC Project Completion Report & end-of-cohort assessment'],
            ['indicator' => '% of youth leaders demonstrating mastery of core leadership and management modules', 'date' => '2026-03-31', 'value' => '86', 'notes' => 'Leaders mastered strategic planning Belbin team roles and project management.', 'source_of_verification' => 'Post-training evaluation forms and individual coaching profiles'],
            ['indicator' => 'Number of young leaders completing the full LEAP formation and coaching cycle', 'date' => '2026-03-31', 'value' => '100', 'notes' => 'Cohort groups successfully completed the intensive leadership and spiritual accompaniment tracks.', 'source_of_verification' => 'Graduation certificates and leadership cohort roster'],
            ['indicator' => '% of youth-led community initiatives meeting planned social objectives and community reach', 'date' => '2026-03-31', 'value' => '86', 'notes' => 'Initiatives (e.g. Dakkakeen Notice Memory of the Eye) reached target communities.', 'source_of_verification' => 'Community initiative evaluation reports and social media engagement analytics'],
            ['indicator' => 'Number of community-based initiatives successfully launched by youth leader teams', 'date' => '2026-03-31', 'value' => '7', 'notes' => 'Initiatives addressed local heritage youth awareness and elderly support.', 'source_of_verification' => 'Initiative charter documents project reports and public launch records'],
            ['indicator' => 'Number of students benefiting from rehabilitated and equipped school facilities', 'date' => '2026-03-31', 'value' => '15000', 'notes' => 'Children resumed studies in restored and winterized classrooms.', 'source_of_verification' => 'School enrollment records and directorate of education registers'],
            ['indicator' => '% of targeted schools fully functional and operational following rehabilitation', 'date' => '2026-03-31', 'value' => '100', 'notes' => 'All supported schools achieved full structural and sanitary reopening.', 'source_of_verification' => 'Joint handover inspection reports with municipal education authorities'],
            ['indicator' => 'Number of damaged schools undergoing physical and sanitary rehabilitation', 'date' => '2026-03-31', 'value' => '40', 'notes' => 'Repairs included roof waterproofing window replacement and WASH facilities.', 'source_of_verification' => 'Engineering bill of quantities (BOQ) and site completion certificates'],
            ['indicator' => 'Number of schools receiving educational equipment, solar systems, or winter heating units', 'date' => '2026-03-31', 'value' => '25', 'notes' => 'Solar PV panels and diesel heating systems installed to ensure winter continuity.', 'source_of_verification' => 'Asset delivery notes equipment installation sign-offs and photos'],
            ['indicator' => 'Number of conflict- or disaster-affected individuals supported with emergency assistance', 'date' => '2026-03-31', 'value' => '45000', 'notes' => 'Rapid assistance provided to earthquake and crisis-affected civilians.', 'source_of_verification' => 'Emergency response distribution lists and recipient national ID records'],
            ['indicator' => '% of assisted disaster-affected households reporting satisfied emergency shelter and NFI needs', 'date' => '2026-03-31', 'value' => '88', 'notes' => 'Families confirmed immediate relief met critical safety and warmth needs.', 'source_of_verification' => 'Emergency post-distribution monitoring and humanitarian relief surveys'],
            ['indicator' => 'Number of emergency relief kits (food, hygiene, NFIs) distributed to displaced families', 'date' => '2026-03-31', 'value' => '4400', 'notes' => 'Kits included family food baskets dignity kits and winterization sets.', 'source_of_verification' => 'Warehouse dispatch logs truck waybills and recipient signature sheets'],
            ['indicator' => 'Number of damaged residential houses and community facilities repaired and rehabilitated', 'date' => '2026-03-31', 'value' => '720', 'notes' => 'Houses workshops and common residential facilities restored in Aleppo.', 'source_of_verification' => 'Shelter technical assessment reports and home re-occupancy agreements'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function reports(): array
    {
        return [
            [
                'indicator' => 'Number of young leaders completing the full LEAP formation and coaching cycle',
                'project' => 'LEAP Aleppo',
                'planned_date' => '2026-03-31',
                'period_start' => '2024-01-01',
                'period_end' => '2026-03-31',
                'value' => '26',
                'narrative_report_notes' => 'Comprehensive youth leadership formation cycle completed in Aleppo. Participants completed technical, spiritual, and mentorship tracks.',
                'executive_summary' => 'Successful conclusion of LEAP Group 2 with 26 direct youth participants completing the multi-dimensional curriculum.',
                'key_achievements' => '26 youth completed leadership formation; 3 community initiatives launched (Dakkakeen, Notice, Memory of the Eye).',
                'challenges' => 'Urban hostilities and military closures in Aleppo (early 2026); heavy university schedules impacting participant attendance.',
                'lessons_learned' => 'Shifted curriculum to 3-day weekly format; integrated structured mental wellbeing and holistic formation.',
                'next_period_plans' => 'Launch follow-on alumni mentoring network and prepare recruitment for LEAP Cohort 3.',
                'submit_date' => '2026-05-25',
            ],
            [
                'indicator' => 'Number of community-based initiatives successfully launched by youth leader teams',
                'project' => 'LEAP Aleppo',
                'planned_date' => '2026-03-31',
                'period_start' => '2025-10-01',
                'period_end' => '2026-03-31',
                'value' => '3',
                'narrative_report_notes' => 'Trainees designed and executed 3 community impact initiatives addressing local culture, youth awareness, and memory.',
                'executive_summary' => 'Implementation of participant-led community initiatives reaching 586 direct and indirect community members.',
                'key_achievements' => 'Public exhibition and community launch of Dakkakeen, Notice, and Memory of the Eye and the Word.',
                'challenges' => 'Logistical delays due to security curfews in central Aleppo during winter 2026.',
                'lessons_learned' => 'Early community needs assessments strongly enhanced local ownership and participant dedication.',
                'next_period_plans' => 'Support sustainability of initiatives through local community scout and youth groups.',
                'submit_date' => '2026-05-25',
            ],
            [
                'indicator' => 'Number of vulnerable households receiving monthly cash transfers',
                'project' => 'Family to Family Aleppo',
                'planned_date' => '2026-03-31',
                'period_start' => '2026-01-01',
                'period_end' => '2026-03-31',
                'value' => '3200',
                'narrative_report_notes' => 'Quarterly multi-purpose cash assistance distributions completed on schedule across verified vulnerable households.',
                'executive_summary' => '3,200 vulnerable families received regular monthly cash transfers to cover essential food, health, and winter heating.',
                'key_achievements' => '100% distribution rate achieved; 87% of families reported meeting priority living basic needs.',
                'challenges' => 'Liquidity constraints in local banking sector and high inflation impacting purchasing power.',
                'lessons_learned' => 'Direct bank and cash transfer mechanisms ensure recipient dignity and minimal overhead.',
                'next_period_plans' => 'Maintain monthly transfer cycle and update vulnerability targeting registers.',
                'submit_date' => '2026-04-15',
            ],
            [
                'indicator' => 'Number of micro-projects and small businesses funded and launched',
                'project' => 'Micro Projects Aleppo',
                'planned_date' => '2026-03-31',
                'period_start' => '2026-01-01',
                'period_end' => '2026-03-31',
                'value' => '250',
                'narrative_report_notes' => '250 micro-grants disbursed to artisans, small shop owners, and farmers to restore productive livelihood assets.',
                'executive_summary' => 'Disbursement and initial setup completed for 250 micro-enterprises across Aleppo, Damascus, and Homs.',
                'key_achievements' => '82% business sustainability rate at 6-month review; over 500 direct livelihoods sustained.',
                'challenges' => 'High cost of raw materials and erratic electricity supply affecting small manufacturing workshops.',
                'lessons_learned' => 'Pairing cash grants with technical mentorship significantly reduces business failure rates.',
                'next_period_plans' => 'Conduct quarterly follow-up monitoring and organize peer-to-peer business networking sessions.',
                'submit_date' => '2026-04-20',
            ],
            [
                'indicator' => 'Number of trainees completing specialized professional and digital training tracks',
                'project' => 'DISC Aleppo',
                'planned_date' => '2026-03-31',
                'period_start' => '2026-01-01',
                'period_end' => '2026-03-31',
                'value' => '300',
                'narrative_report_notes' => '300 university graduates completed intensive courses in IT networking, graphic design, and data analysis.',
                'executive_summary' => 'Quarterly vocational and digital capacity-building tracks successfully completed in Aleppo and Damascus centers.',
                'key_achievements' => '300 trainees graduated; 140 graduates connected to internships or full-time employment.',
                'challenges' => 'Power cuts and internet interruptions mitigated using backup generator and solar power systems.',
                'lessons_learned' => 'Integrating AI and modern digital marketing tools increased course appeal and hiring rates.',
                'next_period_plans' => 'Commence enrollment for advanced programming and accounting software cohorts.',
                'submit_date' => '2026-04-25',
            ],
            [
                'indicator' => 'Number of unique university students registered and utilizing study facilities',
                'project' => 'Study Zone Aleppo',
                'planned_date' => '2026-03-31',
                'period_start' => '2026-01-01',
                'period_end' => '2026-03-31',
                'value' => '7000',
                'narrative_report_notes' => '7,000 university students actively used the Study Zone facilities during university midterm and exam periods.',
                'executive_summary' => 'High utilization of Study Zone centers across Aleppo, Damascus, Homs, Latakia, Tartus, and Al-Wadi.',
                'key_achievements' => 'Over 7,000 students hosted; 86% satisfaction rating for uninterrupted power and study environments.',
                'challenges' => 'High overcrowding during peak exam weeks requiring shift-based desk reservations.',
                'lessons_learned' => 'Introduction of online seat booking managed attendance peaks effectively.',
                'next_period_plans' => 'Expand specialized technical library and architectural drafting resources in centers.',
                'submit_date' => '2026-04-10',
            ],
            [
                'indicator' => 'Number of children participating in non-formal education and interactive learning',
                'project' => 'Children University Aleppo',
                'planned_date' => '2026-03-31',
                'period_start' => '2026-01-01',
                'period_end' => '2026-03-31',
                'value' => '300',
                'narrative_report_notes' => '300 vulnerable children participated in weekly non-formal STEM, arts, and psychosocial learning activities.',
                'executive_summary' => 'Quarterly child developmental learning track completed in Aleppo and Homs centers.',
                'key_achievements' => '300 children reached; 78% demonstrated improved cognitive skills and positive social interaction.',
                'challenges' => 'Winter respiratory illnesses led to intermittent child absences.',
                'lessons_learned' => 'Parent engagement workshops greatly improved homework completion and home emotional support.',
                'next_period_plans' => 'Prepare summer camp curricula and specialized creative drama sessions.',
                'submit_date' => '2026-04-30',
            ],
            [
                'indicator' => 'Number of damaged schools undergoing physical and sanitary rehabilitation',
                'project' => 'School to School Aleppo',
                'planned_date' => '2026-03-31',
                'period_start' => '2026-01-01',
                'period_end' => '2026-03-31',
                'value' => '5',
                'narrative_report_notes' => 'Rehabilitation works completed across 5 damaged municipal primary schools in Aleppo governorate.',
                'executive_summary' => 'Structural, electrical, and sanitary repairs finalized, allowing safe return of over 2,500 students.',
                'key_achievements' => '5 schools fully rehabilitated; clean sanitary WASH units and winterized classrooms handed over.',
                'challenges' => 'Price fluctuations in construction supplies and diesel fuel during execution phase.',
                'lessons_learned' => 'Standardizing bill of quantities and local vendor contracting expedited repair timelines.',
                'next_period_plans' => 'Install solar photovoltaic panels on 10 additional school facilities.',
                'submit_date' => '2026-04-15',
            ],
            [
                'indicator' => 'Number of damaged residential houses and community facilities repaired and rehabilitated',
                'project' => 'Houses & Facility Rehabilitation',
                'planned_date' => '2026-03-31',
                'period_start' => '2026-01-01',
                'period_end' => '2026-03-31',
                'value' => '180',
                'narrative_report_notes' => '180 earthquake- and conflict-damaged homes repaired, enabling safe dignified return of displaced families.',
                'executive_summary' => 'Shelter rehabilitation quarterly milestone achieved in Aleppo city residential neighborhoods.',
                'key_achievements' => '180 residential units restored with new doors, windows, and reinforced structural masonry.',
                'challenges' => 'Extensive structural rubble removal required specialized municipal coordination.',
                'lessons_learned' => 'Engaging beneficiary homeowners in minor interior finishing fostered deep satisfaction.',
                'next_period_plans' => 'Commence second rehabilitation wave targeting 200 damaged homes.',
                'submit_date' => '2026-04-20',
            ],
        ];
    }
}
