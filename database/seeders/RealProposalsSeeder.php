<?php

namespace Database\Seeders;

use App\Enums\ProposalStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * The organisation's real proposals, their donor funding agreements, the
 * projects they fund, and their status history.
 *
 * Deliberately NOT wired into DatabaseSeeder::run() — this is real
 * fundraising data, not demo data every fresh dev/test database should get.
 * Depends on RealDonorsSeeder and RealProjectsSeeder having already run,
 * since donors and projects are resolved by name rather than a hardcoded id.
 * Run it explicitly:
 *
 *     php artisan db:seed --class=RealProposalsSeeder
 *
 * `firstOrCreate` on `name` makes re-running it a no-op for proposals already
 * inserted, so it is safe to run again after adding a new row here.
 *
 * A proposal's `status` reflects the latest entry in its status history (see
 * .ai/rules/proposals.md — status only moves with an audit note). Most
 * proposals in the source sheet carry no status history beyond their initial
 * "approved" entry point in the Proposals tab, so those keep that status with
 * no note row; the handful with a fuller history in the Proposal Status Notes
 * tab get every note plus a status matching the last one.
 *
 * One correction to the source data: the Donor Proposal and Project Proposal
 * tabs' last rows both point GIZ / "Al-Wadi Training Center" at the proposal
 * "Micro Projects Homs - ACN" — an evident fill-down copy/paste artifact,
 * since the Proposals tab's own note for "Al-Wadi Training Center - GIZ"
 * already reads "Donor: GIZ, Budget: €172,944.89", exactly the GIZ donor
 * agreement amount. Both are linked to "Al-Wadi Training Center - GIZ" here
 * instead.
 */
class RealProposalsSeeder extends Seeder
{
    public function run(): void
    {
        $proposalIds = collect($this->proposals())->mapWithKeys(function (array $proposal): array {
            $created = Proposal::query()->firstOrCreate(
                ['name' => $proposal['name']],
                [
                    'planned_date_begin' => $proposal['planned_date_begin'],
                    'planned_date_end' => $proposal['planned_date_end'],
                    'actual_date_begin' => null,
                    'actual_date_end' => null,
                    'status' => $proposal['status'],
                    'notes' => $proposal['notes'],
                ],
            );

            return [$proposal['name'] => $created->id];
        });

        $donorIds = Donor::query()->pluck('id', 'name');
        $projectIds = Project::query()->pluck('id', 'name');

        foreach ($this->donorProposals() as $row) {
            $donorId = $donorIds->get($row['donor']);
            $proposalId = $proposalIds->get($row['proposal']);

            if ($donorId === null || $proposalId === null) {
                throw new RuntimeException("Unknown donor \"{$row['donor']}\" or proposal \"{$row['proposal']}\" — run RealDonorsSeeder and check the proposal list first.");
            }

            DonorProposal::query()->firstOrCreate(
                ['donor_id' => $donorId, 'proposal_id' => $proposalId],
                [
                    'budget' => $row['budget'],
                    'currency' => $row['currency'],
                    'project_num' => $row['project_num'],
                ],
            );
        }

        foreach ($this->projectProposals() as $row) {
            $projectId = $projectIds->get($row['project']);
            $proposalId = $proposalIds->get($row['proposal']);

            if ($projectId === null || $proposalId === null) {
                throw new RuntimeException("Unknown project \"{$row['project']}\" or proposal \"{$row['proposal']}\" — run RealProjectsSeeder first.");
            }

            $proposal = Proposal::query()->find($proposalId);
            $proposal?->projects()->syncWithoutDetaching([$projectId]);
        }

        foreach ($this->statusNotes() as $note) {
            $proposalId = $proposalIds->get($note['proposal']);

            if ($proposalId === null) {
                throw new RuntimeException("Unknown proposal \"{$note['proposal']}\".");
            }

            ProposalStatusNote::query()->firstOrCreate(
                ['proposal_id' => $proposalId, 'status' => $note['status'], 'date' => $note['date']],
                ['context' => $note['context']],
            );
        }
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function proposals(): array
    {
        return [
            ['name' => 'Family to Family - CP', 'planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31', 'status' => ProposalStatus::Active, 'notes' => 'Donor: CP, Budget: €854,470.00'],
            ['name' => 'Micro Projects - CP', 'planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31', 'status' => ProposalStatus::Active, 'notes' => 'Donor: CP, Budget: €817,032.00'],
            ['name' => 'Children University Aleppo - CP', 'planned_date_begin' => '2026-07-01', 'planned_date_end' => '2027-03-31', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: CP, Budget: €43,905.00'],
            ['name' => 'School to School - CP', 'planned_date_begin' => '2026-04-01', 'planned_date_end' => '2026-07-31', 'status' => ProposalStatus::Active, 'notes' => 'Donor: CP, Budget: €60,802.00'],
            ['name' => 'Leadership Aleppo - MCC', 'planned_date_begin' => '2026-04-01', 'planned_date_end' => '2027-03-31', 'status' => ProposalStatus::Completed, 'notes' => 'Donor: MCC, Budget: $50,010.00'],
            ['name' => 'Children University Aleppo - MCC', 'planned_date_begin' => '2026-04-01', 'planned_date_end' => '2027-03-31', 'status' => ProposalStatus::Active, 'notes' => 'Donor: MCC, Budget: $30,000.00'],
            ['name' => 'ADISC - MCC', 'planned_date_begin' => '2026-04-01', 'planned_date_end' => '2027-03-31', 'status' => ProposalStatus::Active, 'notes' => 'Donor: MCC, Budget: $30,000.00'],
            ['name' => 'DDISC - MCC', 'planned_date_begin' => '2026-04-01', 'planned_date_end' => '2027-03-31', 'status' => ProposalStatus::Active, 'notes' => 'Donor: MCC, Budget: $30,000.00'],
            ['name' => 'Micro Projects - MCC', 'planned_date_begin' => '2025-07-01', 'planned_date_end' => '2026-06-30', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: MCC, Budget: $53,345.96'],
            ['name' => 'CHC Team Burnout Prevention and Mental Wellbeing Retreat - MCC', 'planned_date_begin' => '2026-03-01', 'planned_date_end' => '2026-04-30', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: MCC, Budget: $15,000.00'],
            ['name' => 'Study Zone Syria - CDCS', 'planned_date_begin' => '2025-11-01', 'planned_date_end' => '2026-12-31', 'status' => ProposalStatus::Active, 'notes' => 'Donor: CDCS, Budget: €322,180.00'],
            ['name' => 'Children University - Missio Munich', 'planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: Missio Munich, Budget: €27,000.00'],
            ['name' => 'Leadership Homs - Missio Achen', 'planned_date_begin' => '2026-04-01', 'planned_date_end' => '2027-03-31', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: Missio Achen, Budget: €50,592.28'],
            ['name' => 'Children University Homs - Missio Austria', 'planned_date_begin' => '2025-10-01', 'planned_date_end' => '2026-09-30', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: Missio Austria, Budget: €44,356.32'],
            ['name' => 'Hope Center Latakia - Missio Austria', 'planned_date_begin' => '2026-07-01', 'planned_date_end' => '2027-06-30', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: Missio Austria, Budget: €70,842.11'],
            ['name' => 'Leadership Homs - OO', 'planned_date_begin' => '2026-04-01', 'planned_date_end' => '2027-03-31', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: OO, Budget: €35,228.07'],
            ['name' => 'Hope Center Latakia - OO', 'planned_date_begin' => '2026-02-01', 'planned_date_end' => '2027-01-31', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: OO, Budget: €32,982.46'],
            ['name' => 'ADISC - OO', 'planned_date_begin' => '2026-04-01', 'planned_date_end' => '2027-03-30', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: OO, Budget: €58,970.00'],
            ['name' => 'Hope Center Damascus - OO', 'planned_date_begin' => '2025-08-01', 'planned_date_end' => '2026-07-31', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: OO, Budget: €152,196.08'],
            ['name' => 'Hope Center Homs - OO', 'planned_date_begin' => '2025-09-01', 'planned_date_end' => '2026-08-31', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: OO, Budget: €154,679.00'],
            ['name' => 'Study Zone Latakia - OO', 'planned_date_begin' => '2025-11-01', 'planned_date_end' => '2026-10-30', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: OO, Budget: €53,802.00'],
            ['name' => 'Al-Wadi Micro Projects Center - OO', 'planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: OO, Budget: €25,000.00'],
            ['name' => 'HDISC - IF', 'planned_date_begin' => '2025-07-01', 'planned_date_end' => '2026-06-30', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: IF, Budget: €99,413.33'],
            ['name' => 'DDISC - IF', 'planned_date_begin' => '2025-07-01', 'planned_date_end' => '2026-06-30', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: IF, Budget: €105,097.39'],
            ['name' => 'Science Zone Homs - IF', 'planned_date_begin' => '2025-08-01', 'planned_date_end' => '2026-07-31', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: IF, Budget: €66,529.48'],
            ['name' => 'Science Zone Aleppo - IF', 'planned_date_begin' => '2025-08-01', 'planned_date_end' => '2026-07-31', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: IF, Budget: €88,359.12'],
            ['name' => 'Houses Restoration - CGR', 'planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-07-30', 'status' => ProposalStatus::Active, 'notes' => 'Donor: CGR, Budget: $40,000.00'],
            ['name' => 'Managers Meeting - CGR', 'planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-08-31', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: CGR, Budget: $30,000.00'],
            ['name' => 'Educational equipment - Myriad', 'planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-03-31', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: Myriad, Budget: $21,473.00'],
            ['name' => 'Micro Projects Al-Wadi - ACN', 'planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: ACN, Budget: €156,400.00'],
            ['name' => 'Micro Projects Latakia - ACN', 'planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-12-31', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: ACN, Budget: €156,400.00'],
            ['name' => 'Micro Projects Damascus - ACN', 'planned_date_begin' => '2026-01-01', 'planned_date_end' => '2026-06-30', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: ACN, Budget: €172,200.00'],
            ['name' => 'Micro Projects Homs - ACN', 'planned_date_begin' => '2026-06-01', 'planned_date_end' => '2026-12-31', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: ACN, Budget: €165,000.00'],
            ['name' => 'Al-Wadi Training Center - GIZ', 'planned_date_begin' => '2025-12-01', 'planned_date_end' => '2026-11-30', 'status' => ProposalStatus::Approved, 'notes' => 'Donor: GIZ, Budget: €172,944.89'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function donorProposals(): array
    {
        return [
            ['donor' => 'Caritas Poland', 'proposal' => 'Family to Family - CP', 'budget' => '854470.00', 'currency' => 'EUR', 'project_num' => 'CP0000001'],
            ['donor' => 'Caritas Poland', 'proposal' => 'Micro Projects - CP', 'budget' => '817032.00', 'currency' => 'EUR', 'project_num' => '35/2025/CPZ'],
            ['donor' => 'Caritas Poland', 'proposal' => 'Children University Aleppo - CP', 'budget' => '43905.00', 'currency' => 'EUR', 'project_num' => '27/2025/CPZ'],
            ['donor' => 'Caritas Poland', 'proposal' => 'School to School - CP', 'budget' => '60802.00', 'currency' => 'EUR', 'project_num' => '20/2025/CPZ'],
            ['donor' => 'Mennonite Central Committee', 'proposal' => 'Leadership Aleppo - MCC', 'budget' => '50010.00', 'currency' => 'USD', 'project_num' => 'MCC0000001'],
            ['donor' => 'Mennonite Central Committee', 'proposal' => 'Children University Aleppo - MCC', 'budget' => '30000.00', 'currency' => 'USD', 'project_num' => 'MCC0000002'],
            ['donor' => 'Mennonite Central Committee', 'proposal' => 'ADISC - MCC', 'budget' => '30000.00', 'currency' => 'USD', 'project_num' => 'PRJ3389'],
            ['donor' => 'Mennonite Central Committee', 'proposal' => 'DDISC - MCC', 'budget' => '30000.00', 'currency' => 'USD', 'project_num' => 'PRJ3390'],
            ['donor' => 'Mennonite Central Committee', 'proposal' => 'Micro Projects - MCC', 'budget' => '53345.96', 'currency' => 'USD', 'project_num' => 'PRJ2567'],
            ['donor' => 'Mennonite Central Committee', 'proposal' => 'CHC Team Burnout Prevention and Mental Wellbeing Retreat - MCC', 'budget' => '15000.00', 'currency' => 'USD', 'project_num' => null],
            ['donor' => 'Centre de crise et de soutien (CDCS)', 'proposal' => 'Study Zone Syria - CDCS', 'budget' => '322180.00', 'currency' => 'EUR', 'project_num' => 'Project Casper 2024-27 / 94157'],
            ['donor' => 'MISSIO Munikh', 'proposal' => 'Children University - Missio Munich', 'budget' => '27000.00', 'currency' => 'EUR', 'project_num' => null],
            ['donor' => 'MISSO AACHEN', 'proposal' => 'Leadership Homs - Missio Achen', 'budget' => '50592.28', 'currency' => 'EUR', 'project_num' => 'MP/330/013/2024/002'],
            ['donor' => 'Missio (Austria)', 'proposal' => 'Children University Homs - Missio Austria', 'budget' => '44356.32', 'currency' => 'EUR', 'project_num' => '20210220'],
            ['donor' => 'Missio (Austria)', 'proposal' => 'Hope Center Latakia - Missio Austria', 'budget' => '70842.11', 'currency' => 'EUR', 'project_num' => '20240066'],
            ['donor' => 'L\'Oeuvre D\'Orients', 'proposal' => 'Leadership Homs - OO', 'budget' => '35228.07', 'currency' => 'EUR', 'project_num' => '94051'],
            ['donor' => 'L\'Oeuvre D\'Orients', 'proposal' => 'Hope Center Latakia - OO', 'budget' => '32982.46', 'currency' => 'EUR', 'project_num' => '94859'],
            ['donor' => 'L\'Oeuvre D\'Orients', 'proposal' => 'ADISC - OO', 'budget' => '58970.00', 'currency' => 'EUR', 'project_num' => '92243'],
            ['donor' => 'L\'Oeuvre D\'Orients', 'proposal' => 'Hope Center Damascus - OO', 'budget' => '152196.08', 'currency' => 'EUR', 'project_num' => '91219'],
            ['donor' => 'L\'Oeuvre D\'Orients', 'proposal' => 'Hope Center Homs - OO', 'budget' => '154679.00', 'currency' => 'EUR', 'project_num' => '91191'],
            ['donor' => 'L\'Oeuvre D\'Orients', 'proposal' => 'Study Zone Latakia - OO', 'budget' => '53802.00', 'currency' => 'EUR', 'project_num' => '93784'],
            ['donor' => 'L\'Oeuvre D\'Orients', 'proposal' => 'Al-Wadi Micro Projects Center - OO', 'budget' => '25000.00', 'currency' => 'EUR', 'project_num' => '90556'],
            ['donor' => 'IF Foundation', 'proposal' => 'HDISC - IF', 'budget' => '99413.33', 'currency' => 'EUR', 'project_num' => '92238'],
            ['donor' => 'IF Foundation', 'proposal' => 'DDISC - IF', 'budget' => '105097.39', 'currency' => 'EUR', 'project_num' => '91402'],
            ['donor' => 'IF Foundation', 'proposal' => 'Science Zone Homs - IF', 'budget' => '66529.48', 'currency' => 'EUR', 'project_num' => '93540'],
            ['donor' => 'IF Foundation', 'proposal' => 'Science Zone Aleppo - IF', 'budget' => '88359.12', 'currency' => 'EUR', 'project_num' => '93539'],
            ['donor' => 'CGR', 'proposal' => 'Houses Restoration - CGR', 'budget' => '40000.00', 'currency' => 'USD', 'project_num' => null],
            ['donor' => 'CGR', 'proposal' => 'Managers Meeting - CGR', 'budget' => '30000.00', 'currency' => 'USD', 'project_num' => null],
            ['donor' => 'Myriad', 'proposal' => 'Educational equipment - Myriad', 'budget' => '21473.00', 'currency' => 'USD', 'project_num' => null],
            ['donor' => 'Aid To The Church in Need', 'proposal' => 'Micro Projects Al-Wadi - ACN', 'budget' => '156400.00', 'currency' => 'EUR', 'project_num' => 'Syria/national 24/00842'],
            ['donor' => 'Aid To The Church in Need', 'proposal' => 'Micro Projects Latakia - ACN', 'budget' => '156400.00', 'currency' => 'EUR', 'project_num' => 'Syria/national 24/00839'],
            ['donor' => 'Aid To The Church in Need', 'proposal' => 'Micro Projects Damascus - ACN', 'budget' => '172200.00', 'currency' => 'EUR', 'project_num' => 'Syria/national 24/00837'],
            ['donor' => 'Aid To The Church in Need', 'proposal' => 'Micro Projects Homs - ACN', 'budget' => '165000.00', 'currency' => 'EUR', 'project_num' => 'Syria/national 24/00869'],
            ['donor' => 'GIZ', 'proposal' => 'Al-Wadi Training Center - GIZ', 'budget' => '172944.89', 'currency' => 'EUR', 'project_num' => null],
        ];
    }

    /**
     * @return array<int, array<string, string>>
     */
    protected function projectProposals(): array
    {
        return [
            ['project' => 'Family to Family Aleppo', 'proposal' => 'Family to Family - CP'],
            ['project' => 'Micro Projects Aleppo', 'proposal' => 'Micro Projects - CP'],
            ['project' => 'Micro Projects Damascus', 'proposal' => 'Micro Projects - CP'],
            ['project' => 'Micro Projects Latakia', 'proposal' => 'Micro Projects - CP'],
            ['project' => 'Micro Projects Al-Wadi', 'proposal' => 'Micro Projects - CP'],
            ['project' => 'Micro Projects Idlib', 'proposal' => 'Micro Projects - CP'],
            ['project' => 'Micro Projects Banyas', 'proposal' => 'Micro Projects - CP'],
            ['project' => 'Children University Aleppo', 'proposal' => 'Children University Aleppo - CP'],
            ['project' => 'School to School Aleppo', 'proposal' => 'School to School - CP'],
            ['project' => 'LEAP Aleppo', 'proposal' => 'Leadership Aleppo - MCC'],
            ['project' => 'Children University Aleppo', 'proposal' => 'Children University Aleppo - MCC'],
            ['project' => 'DISC Aleppo', 'proposal' => 'ADISC - MCC'],
            ['project' => 'DISC Damascus', 'proposal' => 'DDISC - MCC'],
            ['project' => 'Micro Projects Aleppo', 'proposal' => 'Micro Projects - MCC'],
            ['project' => 'LEAP Aleppo', 'proposal' => 'CHC Team Burnout Prevention and Mental Wellbeing Retreat - MCC'],
            ['project' => 'Study Zone Aleppo', 'proposal' => 'Study Zone Syria - CDCS'],
            ['project' => 'Study Zone Damascus', 'proposal' => 'Study Zone Syria - CDCS'],
            ['project' => 'Study Zone Homs', 'proposal' => 'Study Zone Syria - CDCS'],
            ['project' => 'Study Zone Latakia', 'proposal' => 'Study Zone Syria - CDCS'],
            ['project' => 'Study Zone Tartus', 'proposal' => 'Study Zone Syria - CDCS'],
            ['project' => 'Study Zone Al-Wadi', 'proposal' => 'Study Zone Syria - CDCS'],
            ['project' => 'LEAP Homs', 'proposal' => 'Leadership Homs - Missio Achen'],
            ['project' => 'Children University Homs', 'proposal' => 'Children University Homs - Missio Austria'],
            ['project' => 'Micro Projects Latakia', 'proposal' => 'Hope Center Latakia - Missio Austria'],
            ['project' => 'LEAP Homs', 'proposal' => 'Leadership Homs - OO'],
            ['project' => 'Micro Projects Latakia', 'proposal' => 'Hope Center Latakia - OO'],
            ['project' => 'DISC Aleppo', 'proposal' => 'ADISC - OO'],
            ['project' => 'Micro Projects Damascus', 'proposal' => 'Hope Center Damascus - OO'],
            ['project' => 'Micro Projects Homs', 'proposal' => 'Hope Center Homs - OO'],
            ['project' => 'Study Zone Latakia', 'proposal' => 'Study Zone Latakia - OO'],
            ['project' => 'Micro Projects Al-Wadi', 'proposal' => 'Al-Wadi Micro Projects Center - OO'],
            ['project' => 'DISC Homs', 'proposal' => 'HDISC - IF'],
            ['project' => 'DISC Damascus', 'proposal' => 'DDISC - IF'],
            ['project' => 'Science Zone Homs', 'proposal' => 'Science Zone Homs - IF'],
            ['project' => 'Science Zone Aleppo', 'proposal' => 'Science Zone Aleppo - IF'],
            ['project' => 'Houses & Facility Rehabilitation', 'proposal' => 'Houses Restoration - CGR'],
            ['project' => 'Houses & Facility Rehabilitation', 'proposal' => 'Managers Meeting - CGR'],
            ['project' => 'School to School Aleppo', 'proposal' => 'Educational equipment - Myriad'],
            ['project' => 'Micro Projects Al-Wadi', 'proposal' => 'Micro Projects Al-Wadi - ACN'],
            ['project' => 'Micro Projects Latakia', 'proposal' => 'Micro Projects Latakia - ACN'],
            ['project' => 'Micro Projects Damascus', 'proposal' => 'Micro Projects Damascus - ACN'],
            ['project' => 'Micro Projects Homs', 'proposal' => 'Micro Projects Homs - ACN'],
            ['project' => 'Al-Wadi Training Center', 'proposal' => 'Al-Wadi Training Center - GIZ'],
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    protected function statusNotes(): array
    {
        return [
            ['proposal' => 'Leadership Aleppo - MCC', 'status' => ProposalStatus::Approved, 'context' => 'Grant agreement signed with Mennonite Central Committee (MCC PRJ3042) for $44,985.', 'date' => '2024-01-01'],
            ['proposal' => 'Leadership Aleppo - MCC', 'status' => ProposalStatus::Active, 'context' => 'Implementation commenced with Cohort 2 recruitment and coach onboarding in Aleppo.', 'date' => '2024-01-15'],
            ['proposal' => 'Leadership Aleppo - MCC', 'status' => ProposalStatus::Completed, 'context' => 'All training modules and 3 community initiatives finalized; final completion narrative report submitted.', 'date' => '2026-05-25'],
            ['proposal' => 'Family to Family - CP', 'status' => ProposalStatus::Approved, 'context' => 'Grant agreement approved by Caritas Poland for multi-purpose cash assistance in Aleppo.', 'date' => '2024-12-15'],
            ['proposal' => 'Family to Family - CP', 'status' => ProposalStatus::Active, 'context' => 'Disbursements initiated for 3,200 verified vulnerable households across Aleppo neighborhoods.', 'date' => '2025-01-01'],
            ['proposal' => 'Micro Projects - CP', 'status' => ProposalStatus::Approved, 'context' => 'Caritas Poland approved micro-finance and small business recovery grant allocation.', 'date' => '2024-06-20'],
            ['proposal' => 'Micro Projects - CP', 'status' => ProposalStatus::Active, 'context' => 'Entrepreneur selection and business plan assessment bootcamps launched.', 'date' => '2024-07-01'],
            ['proposal' => 'ADISC - MCC', 'status' => ProposalStatus::Approved, 'context' => 'MCC approved vocational and digital employability grant (PRJ3389) for Aleppo center.', 'date' => '2024-06-15'],
            ['proposal' => 'ADISC - MCC', 'status' => ProposalStatus::Active, 'context' => 'Computer labs upgraded and first semester digital skills courses started.', 'date' => '2024-07-01'],
            ['proposal' => 'DDISC - MCC', 'status' => ProposalStatus::Approved, 'context' => 'MCC approved vocational and youth development project for Damascus center.', 'date' => '2024-06-15'],
            ['proposal' => 'DDISC - MCC', 'status' => ProposalStatus::Active, 'context' => 'Damascus center facilities equipped and student enrollments opened.', 'date' => '2024-07-01'],
            ['proposal' => 'Study Zone Syria - CDCS', 'status' => ProposalStatus::Approved, 'context' => 'CDCS consortium funding authorized for 6 study centers across Syria.', 'date' => '2024-02-15'],
            ['proposal' => 'Study Zone Syria - CDCS', 'status' => ProposalStatus::Active, 'context' => 'Centers opened to provide students uninterrupted power, high-speed internet, and study spaces.', 'date' => '2024-03-01'],
            ['proposal' => 'Children University Aleppo - MCC', 'status' => ProposalStatus::Approved, 'context' => 'MCC approved funding for non-formal child development and experiential learning center.', 'date' => '2025-03-10'],
            ['proposal' => 'Children University Aleppo - MCC', 'status' => ProposalStatus::Active, 'context' => 'Children University classrooms set up and facilitator training commenced.', 'date' => '2025-04-01'],
            ['proposal' => 'School to School - CP', 'status' => ProposalStatus::Approved, 'context' => 'Caritas Poland approved school physical and sanitary rehabilitation grant.', 'date' => '2025-02-20'],
            ['proposal' => 'School to School - CP', 'status' => ProposalStatus::Active, 'context' => 'Civil contractors mobilized for classroom, electrical, and WASH repairs across 5 schools.', 'date' => '2025-03-01'],
            ['proposal' => 'Houses Restoration - CGR', 'status' => ProposalStatus::Approved, 'context' => 'Funding confirmed for post-earthquake shelter and residential home repairs.', 'date' => '2025-03-15'],
            ['proposal' => 'Houses Restoration - CGR', 'status' => ProposalStatus::Active, 'context' => 'Engineering surveys completed and residential repair contracts awarded for 180 homes.', 'date' => '2025-04-01'],
        ];
    }
}
