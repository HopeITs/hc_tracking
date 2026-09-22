<?php

use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Media;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Program;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Risk;
use App\Models\Strategy;
use App\Models\User;

test('temp preview render', function () {
    $user = User::factory()->create();

    $strategy = Strategy::factory()->create(['name' => 'Resilient Communities 2026-2030']);
    $program = Program::factory()->create(['name' => 'Water Security', 'strategy_id' => $strategy->id]);
    $aleppo = insertHrCity('Aleppo', 'حلب');
    $owner = insertHrEmployee(firstNameEn: 'Layla', lastNameEn: 'Haddad');

    $donor = Donor::factory()->create(['name' => 'UNICEF']);
    $proposal = Proposal::factory()->create(['name' => 'Emergency WASH Response 2026']);
    DonorProposal::factory()->create(['donor_id' => $donor->id, 'proposal_id' => $proposal->id, 'budget' => '1250000.00']);

    $names = [
        'Aleppo Wells Rehabilitation' => ['active', -400, 30],
        'Idlib Latrine Block Construction' => ['active', -600, -20],
        'Homs Cash Transfer Expansion' => ['approved', 20, 400],
        'Hama Mobile Clinic' => ['submitted', null, null],
        'Deir ez-Zor Water Network' => ['draft', 60, 500],
        'Tartus Shelter Upgrade' => ['closed', -900, -300],
    ];

    foreach ($names as $name => [$status, $startOffset, $endOffset]) {
        $project = Project::factory()->status(ProjectStatus::from($status))->create([
            'name' => $name,
            'city_id' => $aleppo,
            'program_id' => $program->id,
            'user_id' => $owner,
            'start_date' => $startOffset === null ? null : today()->addDays($startOffset),
            'end_date' => $endOffset === null ? null : today()->addDays($endOffset),
            'target_beneficiaries' => 12400,
            'target_men' => 3100,
            'target_women' => 4200,
            'target_boys' => 2400,
            'target_girls' => 2300,
        ]);

        if ($name !== 'Hama Mobile Clinic') {
            $project->proposals()->attach($proposal);
        }

        Milestone::factory()->reached()->create(['project_id' => $project->id, 'weight_percentage' => '40.00', 'planned_start_date' => today()->subDays(120)]);
        Milestone::factory()->create(['project_id' => $project->id, 'weight_percentage' => '35.00', 'planned_start_date' => today()->subDays(10), 'status' => MilestoneStatus::Planned]);
        Milestone::factory()->create(['project_id' => $project->id, 'weight_percentage' => '25.00', 'planned_start_date' => today()->addDays(60)]);

        Risk::factory()->count(2)->create(['project_id' => $project->id]);
        Risk::factory()->happened()->create(['project_id' => $project->id]);
        PlanIndicator::factory()->count(2)->create(['project_id' => $project->id]);
        Media::factory()->create(['mediable_type' => 'project', 'mediable_id' => $project->id, 'uploaded_by' => $user->id]);
    }

    foreach (['board', 'table', 'timeline'] as $view) {
        $html = $this->actingAs($user)->get(route('projects.index', ['view' => $view]))->getContent();
        $html = str_replace(['href="/build/', 'src="/build/'], ['href="http://localhost:8000/build/', 'src="http://localhost:8000/build/'], $html);
        file_put_contents(storage_path('app/preview-'.$view.'.html'), $html);
    }

    expect(true)->toBeTrue();
});
