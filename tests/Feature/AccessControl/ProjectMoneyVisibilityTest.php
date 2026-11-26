<?php

use App\Enums\Currency;
use App\Enums\MediaCollection;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Media;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * One project funded by one proposal: a budget of 731,942 with 123,457 already
 * received, a pending 45,678 transfer and a donor report, figures no other
 * fixture on these pages can produce.
 *
 * @return array{project: Project, proposal: Proposal, agreement: DonorProposal, transfer: TransfersTracking, report: DonorReport}
 */
function seedProjectMoneyFixture(): array
{
    // Y-16, D-93: the page reads the money of the ONE proposal behind its row,
    // so the row is built on this fixture's proposal rather than the pivot.
    $project = Project::factory()->create(['start_date' => '2026-10-01', 'end_date' => '2027-06-30']);
    $proposal = Proposal::factory()->running()->create([
        'name' => 'Harbour Clean Water',
        'planned_date_begin' => '2026-06-01',
        'planned_date_end' => '2026-09-30',
    ]);
    rowFor($project, $proposal);
    $project->proposals()->attach($proposal);

    $fundingDonorId = Donor::factory()->create(['name' => 'Aurora Foundation'])->id;
    Proposal::query()->whereKey($proposal->id)->update(['donor_id' => $fundingDonorId]);
    $agreement = DonorProposal::factory()->create([
        'donor_id' => $fundingDonorId,
        'proposal_id' => $proposal->id,
        'currency' => Currency::Usd,
        'budget' => '731942.00',
    ]);
    TransfersTracking::factory()->received()->create([
        'donor_proposal_id' => $agreement->id,
        'net_amount_received' => '123457.00',
    ]);
    $transfer = TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'scheduled_date' => '2026-11-01',
        'payment_amount' => '45678.00',
        'amount_received' => '0.00',
        'net_amount_received' => '0.00',
    ]);
    $report = DonorReport::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'title' => 'Quarterly donor narrative',
        'due_on' => '2027-01-15',
    ]);

    return compact('project', 'proposal', 'agreement', 'transfer', 'report');
}

test('a Managers user sees no funding amounts on the project page but keeps the proposal and donor names', function () {
    ['project' => $project] = seedProjectMoneyFixture();

    $page = Livewire::actingAs(userWithRole('Managers'))
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->assertOk()
        ->assertDontSee('731,942')
        ->assertDontSee('123,457')
        ->assertDontSee('45,678')
        ->assertDontSee('Funding envelope');

    $page->set('tab', 'proposals')
        ->assertSee('Harbour Clean Water')
        ->assertSee('Aurora Foundation')
        ->assertDontSee('731,942')
        ->assertDontSee('123,457')
        ->assertDontSee('Donors funding')
        ->assertDontSeeHtml(route('proposals.show', Proposal::query()->firstOrFail()));

    $instance = $page->instance();
    expect($instance->fundingByCurrency)->toBeEmpty()
        ->and($instance->proposalsSummary)->toMatchArray(['donors' => 0, 'agreements' => 0])
        ->and($instance->attachedProposals->first()->relationLoaded('donorProposals'))->toBeFalse();
});

test('Financial and General Manager see the funding envelope, the amounts and a link to the proposal', function (string $role) {
    ['project' => $project, 'proposal' => $proposal] = seedProjectMoneyFixture();

    $page = Livewire::actingAs(userWithRole($role))
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->assertSee('Funding envelope')
        ->assertSee('731,942')
        ->assertSee('123,457');

    $page->set('tab', 'proposals')
        ->assertSee('Aurora Foundation')
        ->assertSee('731,942')
        ->assertSeeHtml('href="'.route('projects.show', ['project' => rowFor($project), 'proposal' => $proposal->id]).'#proposal"');

    expect($page->instance()->fundingByCurrency)->not->toBeEmpty();
})->with(['Financial', 'General Manager']);

test('a proposal editor without the money permissions links and unlinks proposals, choosing them by name', function () {
    $project = projectWithRow();
    $proposal = Proposal::factory()->create(['name' => 'Hilltop Schools']);
    $proposal->projects()->attach(projectWithRow());

    $page = Livewire::actingAs(userWithRole('Public Relations Manager'))
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->set('tab', 'proposals')
        ->assertSee('Hilltop Schools')
        ->set('proposalToAttach', (string) $proposal->id)
        ->call('attachProposal')
        ->assertHasNoErrors();

    expect($project->proposals()->count())->toBe(1);

    $page->call('detachProposal', $proposal->id);

    expect($project->proposals()->count())->toBe(0);
});

test('Public Relations Manager opens the proposal from the project page, sees the agreement budget but not the transfers', function () {
    ['project' => $project, 'proposal' => $proposal] = seedProjectMoneyFixture();

    // The role holds funding-agreements.view but not transfers.view, so the
    // agreement budget is theirs to see and the received amount is not.
    // Before Y-16 this asserted both were hidden — only because the fixture's
    // proposal was a draft and so never reached the funding list. D-84 makes
    // the row's proposal approved, which is the state the screen really has.
    Livewire::actingAs(userWithRole('Public Relations Manager'))
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->assertDontSee('Funding envelope')
        ->set('tab', 'proposals')
        ->assertSeeHtml('href="'.route('projects.show', ['project' => rowFor($project), 'proposal' => $proposal->id]).'#proposal"')
        ->assertSee('Aurora Foundation')
        ->assertDontSee('123,457');
});

/**
 * A project holding an agreement, an MOU and a photo, all filed on it.
 *
 * @return array{project: Project, agreement: Media, mou: Media, photo: Media}
 */
function seedProjectFilesFixture(): array
{
    Storage::fake('local');
    $project = projectWithRow();
    $file = function (MediaCollection $collection, string $name) use ($project): Media {
        $path = 'media/projects/'.$project->id.'/'.$name;
        Storage::disk('local')->put($path, 'contents of '.$name);

        // Y-11's mirror keeps a file on both the project and its row while
        // both exist: the directory still reads the project's, the detail page
        // reads the row's. Y-18 leaves only the row.
        Media::factory()->inCollection($collection)->create([
            'mediable_type' => 'project',
            'mediable_id' => $project->id,
            'path' => $path,
            'original_name' => $name,
        ]);

        return Media::factory()->inCollection($collection)->create([
            'mediable_type' => 'proposal_location',
            'mediable_id' => rowFor($project)->id,
            'path' => $path,
            'original_name' => $name,
        ]);
    };

    return [
        'project' => $project,
        'agreement' => $file(MediaCollection::Agreement, 'signed-agreement.pdf'),
        'mou' => $file(MediaCollection::Mou, 'signed-mou.pdf'),
        'photo' => $file(MediaCollection::Photo, 'site-visit.pdf'),
    ];
}

test('a Managers user is not shown funding paper on the project files tab and cannot filter to it', function () {
    ['project' => $project] = seedProjectFilesFixture();

    $page = Livewire::actingAs(userWithRole('Managers'))
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->set('tab', 'files')
        ->assertSee('site-visit.pdf')
        ->assertDontSee('signed-agreement.pdf')
        ->assertDontSee('signed-mou.pdf')
        ->set('fileCategoryFilter', MediaCollection::Agreement->value)
        ->assertDontSee('signed-agreement.pdf');

    $instance = $page->instance();
    expect($instance->location->media_count)->toBe(1)
        ->and(collect($instance->fileCategoryCounts)->pluck('value')->all())->not->toContain('agreement', 'mou');
});

test('Financial sees the agreement and MOU files and counts them', function () {
    ['project' => $project] = seedProjectFilesFixture();

    $page = Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->set('tab', 'files')
        ->assertSee('signed-agreement.pdf')
        ->assertSee('signed-mou.pdf')
        ->assertSee('site-visit.pdf');

    expect($page->instance()->location->media_count)->toBe(3);
});

test('the project directory card counts funding paper only for viewers of funding agreements', function () {
    ['project' => $project] = seedProjectFilesFixture();
    $fileCount = fn (string $role): int => Livewire::actingAs(userWithRole($role))
        ->test('pages::projects.index')
        ->instance()
        ->projects
        ->firstWhere('id', rowFor($project)->id)
        ->media_count;

    expect($fileCount('Managers'))->toBe(1)
        ->and($fileCount('Financial'))->toBe(3);
});

test('a Managers user cannot edit, delete, or file a document into the funding paper categories', function () {
    ['project' => $project, 'agreement' => $agreement, 'photo' => $photo] = seedProjectFilesFixture();
    $fresh = fn () => Livewire::actingAs(userWithRole('Managers'))->test('pages::projects.show', ['project' => rowFor($project)->slug]);

    $fresh()->call('openEditFileModal', $agreement->id)->assertForbidden();
    $fresh()->call('confirmDeleteFile', $agreement->id)->assertForbidden();

    $fresh()->call('openCreateFileModal')
        ->set('newFile', validUpload('sneaky.pdf', 100))
        ->set('fileCategory', MediaCollection::Mou->value)
        ->call('saveFile')
        ->assertForbidden();

    $fresh()->call('openEditFileModal', $photo->id)
        ->set('fileCategory', MediaCollection::Agreement->value)
        ->call('saveFile')
        ->assertForbidden();

    expect(Media::query()->where('mediable_type', 'project')->count())->toBe(3)
        ->and($photo->fresh()->collection)->toBe(MediaCollection::Photo)
        ->and($agreement->fresh())->not->toBeNull();

    $fresh()->call('openCreateFileModal')
        ->set('newFile', validUpload('allowed.pdf', 100))
        ->set('fileCategory', MediaCollection::Photo->value)
        ->call('saveFile')
        ->assertHasNoErrors();

    expect(Media::query()->where('original_name', 'allowed.pdf')->exists())->toBeTrue();
});

test('General Manager may still edit and delete funding paper on a project', function () {
    ['project' => $project, 'agreement' => $agreement] = seedProjectFilesFixture();

    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->call('confirmDeleteFile', $agreement->id)
        ->assertOk()
        ->call('deleteFile')
        ->assertOk();

    expect(Media::query()->find($agreement->id))->toBeNull();
});

test('the project file download refuses funding paper to a Managers user and serves it to Financial', function () {
    ['project' => $project, 'agreement' => $agreement, 'mou' => $mou, 'photo' => $photo] = seedProjectFilesFixture();
    $manager = userWithRole('Managers');

    $this->actingAs($manager)->get(route('projects.files.download', [rowFor($project), $agreement]))->assertForbidden();
    $this->actingAs($manager)->get(route('projects.files.download', [rowFor($project), $mou]))->assertForbidden();
    $this->actingAs($manager)->get(route('projects.files.download', [rowFor($project), $photo]))->assertOk();

    $this->actingAs(userWithRole('Financial'))->get(route('projects.files.download', [rowFor($project), $agreement]))->assertOk();
});

test('the projects directory shows committed budgets in every view only to viewers of funding agreements, and keeps the donor and proposal names', function (string $view) {
    ['project' => $project] = seedProjectMoneyFixture();

    $managers = Livewire::actingAs(userWithRole('Managers'))
        ->test('pages::projects.index')
        ->set('view', $view)
        ->assertOk()
        ->assertDontSee('731,942')
        ->assertDontSee('Committed funding');

    expect($managers->instance()->committedFunding)->toBe([])
        ->and($managers->instance()->fundingByProject[rowFor($project)->id])->toMatchArray([
            'currencies' => [],
            'donors' => ['Aurora Foundation'],
            'proposals' => ['Harbour Clean Water'],
        ]);

    if ($view === 'list') {
        $managers->assertSee('Aurora Foundation')->assertSee('Harbour Clean Water');
    }

    $financial = Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::projects.index')
        ->set('view', $view)
        ->assertSee('731,942');

    expect($financial->instance()->committedFunding)->toBe(['USD' => 731942.0]);
})->with(['list', 'timeline']);

test('the projects directory keeps the beneficiaries figure when the committed funding figure is hidden', function () {
    seedProjectMoneyFixture();

    Livewire::actingAs(userWithRole('Team Member'))
        ->test('pages::projects.index')
        ->assertSee(__('projects.tile_beneficiaries'))
        ->assertDontSee(__('projects.tile_committed_funding'));
});

test('the project page loads no budget pivot for a viewer without funding agreements', function () {
    ['project' => $project] = seedProjectMoneyFixture();

    $proposal = Livewire::actingAs(userWithRole('Managers'))
        ->test('pages::projects.show', ['project' => rowFor($project)->slug])
        ->instance()
        ->attachedProposals
        ->first();

    expect($proposal->relationLoaded('donors'))->toBeFalse()
        ->and($proposal->donorNames->pluck('name')->all())->toBe(['Aurora Foundation'])
        ->and($proposal->donorNames->first()->pivot->getAttributes())->not->toHaveKey('budget');
});
