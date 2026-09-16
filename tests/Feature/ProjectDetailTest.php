<?php

use App\Enums\FileType;
use App\Enums\MediaCollection;
use App\Enums\ProjectStatus;
use App\Models\AnnualPlan;
use App\Models\Indicator;
use App\Models\Media;
use App\Models\Milestone;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Risk;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('guests are redirected from project detail', function () {
    $project = Project::factory()->create();

    $this->get(route('projects.show', $project))->assertRedirect(route('login'));
});

test('project detail shows the project its city program and owner', function () {
    $user = User::factory()->create();
    $owner = insertHrEmployee(firstNameEn: 'Rana', lastNameEn: 'Darwish');
    $project = Project::factory()->create(['name' => 'Aleppo Wells', 'user_id' => $owner]);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeText('Aleppo Wells')
        ->assertSeeText($project->city->name_en)
        ->assertSeeText($project->program->name)
        ->assertSeeText('Rana Darwish');
});

test('project detail preserves lifecycle and disagreement between headline and demographic targets', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'description' => 'Restores safe household water access.',
        'status' => ProjectStatus::Active,
        'start_date' => '2026-09-01',
        'end_date' => '2027-08-31',
        'target_beneficiaries' => 1000,
        'target_men' => 180,
        'target_women' => 220,
        'target_boys' => 140,
        'target_girls' => 160,
    ]);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeText('Active')
        ->assertSeeText('Sep 1, 2026')
        ->assertSeeText('Aug 31, 2027')
        ->assertSeeText('Restores safe household water access.')
        ->assertSeeTextInOrder(['Headline target', '1,000', 'Demographic total', '700'])
        ->assertSeeTextInOrder(['Men', '180', 'Women', '220', 'Boys', '140', 'Girls', '160']);
});

test('project detail displays an em dash when no demographic breakdown exists', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create([
        'target_beneficiaries' => 250,
        'target_men' => null,
        'target_women' => null,
        'target_boys' => null,
        'target_girls' => null,
    ]);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeTextInOrder(['Headline target', '250', 'Demographic total', '—']);
});

test('project detail renders its milestones as a tree', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $responsible = User::factory()->create();
    $parent = Milestone::factory()->create([
        'project_id' => $project->id,
        'responsible_user_id' => $responsible->id,
        'name' => 'Baseline assessment',
        'sequence' => 1,
    ]);
    Milestone::factory()->create([
        'project_id' => $project->id,
        'responsible_user_id' => $responsible->id,
        'parent_milestone_id' => $parent->id,
        'name' => 'Household survey',
        'sequence' => 1,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->assertSet('tab', 'milestones')
        ->assertSee('Baseline assessment')
        ->assertSee('Household survey')
        ->assertSee('data-tree-depth="1"', false);
});

test('project detail shows only its own risks', function () {
    $user = User::factory()->create();
    $subject = Project::factory()->create();
    $other = Project::factory()->create();
    Risk::factory()->create(['project_id' => $subject->id, 'name' => 'Access restrictions']);
    Risk::factory()->create(['project_id' => $other->id, 'name' => 'Currency collapse']);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $subject])
        ->set('tab', 'risks')
        ->assertSee('Access restrictions')
        ->assertDontSee('Currency collapse');
});

test('project detail shows targets naming this project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $indicator = Indicator::factory()->create(['name' => 'People reached']);
    PlanIndicator::factory()->create([
        'source_type' => 'annual_plan',
        'source_id' => AnnualPlan::factory()->create()->id,
        'indicator_id' => $indicator->id,
        'project_id' => $project->id,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'targets')
        ->assertSee('People reached');
});

test('a proposal can be attached to and detached from a project', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create(['name' => 'Emergency WASH Response']);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'proposals')
        ->set('proposalToAttach', (string) $proposal->id)
        ->call('attachProposal')
        ->assertHasNoErrors();

    expect($project->proposals()->whereKey($proposal->id)->exists())->toBeTrue();

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'proposals')
        ->call('detachProposal', $proposal->id);

    expect($project->proposals()->whereKey($proposal->id)->exists())->toBeFalse();
});

test('attaching the same proposal twice does not duplicate the pivot row', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create();
    $project->proposals()->attach($proposal->id);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'proposals')
        ->set('proposalToAttach', (string) $proposal->id)
        ->call('attachProposal');

    expect(DB::table('project_proposal')
        ->where('project_id', $project->id)
        ->where('proposal_id', $proposal->id)
        ->count())->toBe(1);
});

test('project files list only this projects metadata without upload or download controls', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create();
    $otherProject = Project::factory()->create();
    Media::factory()->inCollection(MediaCollection::Agreement)->create([
        'mediable_type' => 'project',
        'mediable_id' => $project->id,
        'original_name' => 'signed-agreement.pdf',
        'path' => 'media/private-storage-name.pdf',
        'type' => FileType::Pdf,
        'uploaded_at' => '2026-09-10',
    ]);
    Media::factory()->create([
        'mediable_type' => 'project',
        'mediable_id' => $otherProject->id,
        'original_name' => 'other-project-photo.jpg',
        'type' => FileType::Jpg,
    ]);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'files')
        ->assertSee('signed-agreement.pdf')
        ->assertSee('PDF')
        ->assertSee('Agreement')
        ->assertSee('Sep 10, 2026')
        ->assertDontSee('other-project-photo.jpg')
        ->assertDontSee('media/private-storage-name.pdf')
        ->assertDontSee('download', false)
        ->assertDontSee('upload', false)
        ->assertDontSee('href="media/private-storage-name.pdf"', false);
});

test('project detail escapes free text and offers no project delete action', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['description' => '<script>alert("project")</script>']);

    Livewire::actingAs($user)
        ->test('pages::projects.show', ['project' => $project])
        ->assertSee('&lt;script&gt;', false)
        ->assertDontSee('<script>alert("project")</script>', false)
        ->assertDontSee('deleteProject', false)
        ->assertDontSee('Delete project', false);
});

test('project detail renders a project with nothing attached', function () {
    $user = User::factory()->create();
    $project = Project::factory()->create(['name' => 'Bare Project']);

    $this->actingAs($user)
        ->get(route('projects.show', $project))
        ->assertOk()
        ->assertSeeText('Bare Project')
        ->assertSeeText('No milestones yet');
});
