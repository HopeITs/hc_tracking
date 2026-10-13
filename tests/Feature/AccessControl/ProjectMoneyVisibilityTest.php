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
use Illuminate\Support\Facades\DB;
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
    $project = Project::factory()->create(['start_date' => '2026-10-01', 'end_date' => '2027-06-30']);
    $proposal = Proposal::factory()->create([
        'name' => 'Harbour Clean Water',
        'planned_date_begin' => '2026-06-01',
        'planned_date_end' => '2026-09-30',
    ]);
    $project->proposals()->attach($proposal);

    $agreement = DonorProposal::factory()->create([
        'donor_id' => Donor::factory()->create(['name' => 'Aurora Foundation'])->id,
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
        ->test('pages::projects.show', ['project' => $project])
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
        ->test('pages::projects.show', ['project' => $project])
        ->assertSee('Funding envelope')
        ->assertSee('731,942')
        ->assertSee('123,457');

    $page->set('tab', 'proposals')
        ->assertSee('Aurora Foundation')
        ->assertSee('731,942')
        ->assertSeeHtml('href="'.route('proposals.show', $proposal).'"');

    expect($page->instance()->fundingByCurrency)->not->toBeEmpty();
})->with(['Financial', 'General Manager']);

test('a Managers user can still link and unlink proposals, choosing them by name', function () {
    $project = Project::factory()->create();
    $proposal = Proposal::factory()->create(['name' => 'Hilltop Schools']);

    $page = Livewire::actingAs(userWithRole('Managers'))
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'proposals')
        ->assertSee('Hilltop Schools')
        ->set('proposalToAttach', (string) $proposal->id)
        ->call('attachProposal')
        ->assertHasNoErrors();

    expect($project->proposals()->count())->toBe(1);

    $page->call('detachProposal', $proposal->id);

    expect($project->proposals()->count())->toBe(0);
});

test('Public Relations Manager opens the proposal from the project page but sees no funding figures', function () {
    ['project' => $project, 'proposal' => $proposal] = seedProjectMoneyFixture();

    Livewire::actingAs(userWithRole('Public Relations Manager'))
        ->test('pages::projects.show', ['project' => $project])
        ->assertDontSee('Funding envelope')
        ->set('tab', 'proposals')
        ->assertSeeHtml('href="'.route('proposals.show', $proposal).'"')
        ->assertSee('Aurora Foundation')
        ->assertDontSee('731,942')
        ->assertDontSee('123,457');
});

test('the project Plan gantt shows transfer and donor report rows only to viewers of transfers and agreements', function () {
    ['project' => $project, 'proposal' => $proposal, 'transfer' => $transfer, 'report' => $report] = seedProjectMoneyFixture();
    $rows = fn (string $role): array => collect(
        Livewire::actingAs(userWithRole($role))
            ->test('pages::projects.show', ['project' => $project])
            ->instance()
            ->ganttPayload['rows']
    )->keyBy('id')->all();

    $managers = $rows('Managers');
    expect($managers)->not->toHaveKeys(['obligations', 'transfer-'.$transfer->id, 'report-'.$report->id])
        ->and($managers['proposal-'.$proposal->id]['name'])->toBe('Harbour Clean Water')
        ->and($managers['proposal-'.$proposal->id]['url'])->toBeNull();

    $fundraiser = $rows(fundraiserWithoutAgreements());
    expect($fundraiser)->not->toHaveKeys(['obligations', 'transfer-'.$transfer->id, 'report-'.$report->id])
        ->and($fundraiser['proposal-'.$proposal->id]['url'])->toBe(route('proposals.show', $proposal));

    foreach (['Financial', 'General Manager'] as $role) {
        $money = $rows($role);

        expect($money)->toHaveKeys(['obligations', 'transfer-'.$transfer->id, 'report-'.$report->id])
            ->and($money['transfer-'.$transfer->id]['money'])->toBe('45,678 USD')
            ->and($money['transfer-'.$transfer->id]['url'])->toBe(route('transfers.index'))
            ->and($money['proposal-'.$proposal->id]['url'])->toBe(route('proposals.show', $proposal));
    }
});

test('the obligations group carries only the half the viewer may see', function () {
    ['project' => $project, 'transfer' => $transfer, 'report' => $report] = seedProjectMoneyFixture();
    $viewer = userWithRole('Managers');

    $rowsFor = function () use ($project, $viewer): array {
        return collect(
            Livewire::actingAs($viewer)
                ->test('pages::projects.show', ['project' => $project])
                ->instance()
                ->ganttPayload['rows']
        )->keyBy('id')->all();
    };

    $viewer->givePermissionTo('transfers.view');
    expect($rowsFor())->toHaveKeys(['obligations', 'transfer-'.$transfer->id])
        ->and($rowsFor())->not->toHaveKey('report-'.$report->id);

    $viewer->revokePermissionTo('transfers.view');
    $viewer->givePermissionTo('funding-agreements.view');
    expect($rowsFor())->toHaveKeys(['obligations', 'report-'.$report->id])
        ->and($rowsFor())->not->toHaveKey('transfer-'.$transfer->id);
});

test('a Managers user sees no proposal rows and no pipeline lens on the timeline, and the pipeline value is ignored', function () {
    ['project' => $project] = seedProjectMoneyFixture();
    $manager = userWithRole('Managers');

    $connected = Livewire::actingAs($manager)
        ->test('pages::timeline.index')
        ->set('view', 'connected')
        ->set('program', (string) $project->program_id)
        ->assertDontSee('Harbour Clean Water')
        ->assertDontSee('Aurora Foundation')
        ->assertDontSee('731,942')
        ->assertDontSee('Fundraising pipeline');

    $rows = collect($connected->instance()->payload['rows']);
    expect($rows->pluck('kind')->all())->not->toContain('proposal', 'transfer', 'report')
        ->and($connected->instance()->payload['relations'])->toBe([]);

    $pipeline = Livewire::actingAs($manager)
        ->test('pages::timeline.index')
        ->set('view', 'pipeline')
        ->assertSet('view', 'portfolio')
        ->assertDontSee('Harbour Clean Water');

    expect(collect($pipeline->instance()->payload['rows'])->pluck('kind')->all())->not->toContain('proposal');

    Livewire::actingAs($manager)
        ->withQueryParams(['view' => 'pipeline'])
        ->test('pages::timeline.index')
        ->assertSet('view', 'portfolio')
        ->assertDontSee('Harbour Clean Water');
});

test('a fundraiser without agreements sees the proposals and donor names on the timeline but no amounts or obligations', function () {
    ['project' => $project, 'proposal' => $proposal, 'transfer' => $transfer] = seedProjectMoneyFixture();

    $connected = Livewire::actingAs(userWithRole(fundraiserWithoutAgreements()))
        ->test('pages::timeline.index')
        ->set('view', 'connected')
        ->set('program', (string) $project->program_id);
    $rows = collect($connected->instance()->payload['rows'])->keyBy('id');

    expect($rows['proposal-'.$proposal->id])->toMatchArray(['owner' => 'Aurora Foundation', 'money' => null])
        ->and($rows)->not->toHaveKey('transfer-'.$transfer->id);

    $pipeline = Livewire::actingAs(userWithRole(fundraiserWithoutAgreements()))
        ->test('pages::timeline.index')
        ->set('view', 'pipeline')
        ->assertSet('view', 'pipeline')
        ->assertSee('Harbour Clean Water');

    expect(collect($pipeline->instance()->payload['rows'])->firstWhere('id', 'proposal-'.$proposal->id)['money'])->toBeNull();
});

test('Financial and General Manager get the proposal rows, amounts, obligations and the pipeline lens on the timeline', function (string $role) {
    ['project' => $project, 'proposal' => $proposal, 'transfer' => $transfer, 'report' => $report] = seedProjectMoneyFixture();

    $connected = Livewire::actingAs(userWithRole($role))
        ->test('pages::timeline.index')
        ->set('view', 'connected')
        ->set('program', (string) $project->program_id)
        ->assertSee('Fundraising pipeline');
    $rows = collect($connected->instance()->payload['rows'])->keyBy('id');

    expect($rows['proposal-'.$proposal->id])->toMatchArray(['owner' => 'Aurora Foundation', 'money' => '731,942 USD'])
        ->and($rows)->toHaveKeys(['transfer-'.$transfer->id, 'report-'.$report->id]);

    $pipeline = Livewire::actingAs(userWithRole($role))
        ->test('pages::timeline.index')
        ->set('view', 'pipeline')
        ->assertSet('view', 'pipeline')
        ->assertSee('Harbour Clean Water');

    expect(collect($pipeline->instance()->payload['rows'])->pluck('id'))->toContain('proposal-'.$proposal->id);
})->with(['Financial', 'General Manager']);

/**
 * A project holding an agreement, an MOU and a photo, all filed on it.
 *
 * @return array{project: Project, agreement: Media, mou: Media, photo: Media}
 */
function seedProjectFilesFixture(): array
{
    Storage::fake('local');
    $project = Project::factory()->create();
    $file = function (MediaCollection $collection, string $name) use ($project): Media {
        $path = 'media/projects/'.$project->id.'/'.$name;
        Storage::disk('local')->put($path, 'contents of '.$name);

        return Media::factory()->inCollection($collection)->create([
            'mediable_type' => 'project',
            'mediable_id' => $project->id,
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
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'files')
        ->assertSee('site-visit.pdf')
        ->assertDontSee('signed-agreement.pdf')
        ->assertDontSee('signed-mou.pdf')
        ->set('fileCategoryFilter', MediaCollection::Agreement->value)
        ->assertDontSee('signed-agreement.pdf');

    $instance = $page->instance();
    expect($instance->project->media_count)->toBe(1)
        ->and(collect($instance->fileCategoryCounts)->pluck('value')->all())->not->toContain('agreement', 'mou')
        ->and(collect($instance->tabs)->firstWhere('key', 'files')['count'])->toBe(1);
});

test('Financial sees the agreement and MOU files and counts them', function () {
    ['project' => $project] = seedProjectFilesFixture();

    $page = Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::projects.show', ['project' => $project])
        ->set('tab', 'files')
        ->assertSee('signed-agreement.pdf')
        ->assertSee('signed-mou.pdf')
        ->assertSee('site-visit.pdf');

    expect($page->instance()->project->media_count)->toBe(3);
});

test('the project directory card counts funding paper only for viewers of funding agreements', function () {
    ['project' => $project] = seedProjectFilesFixture();
    $fileCount = fn (string $role): int => Livewire::actingAs(userWithRole($role))
        ->test('pages::projects.index')
        ->instance()
        ->projects
        ->firstWhere('id', $project->id)
        ->media_count;

    expect($fileCount('Managers'))->toBe(1)
        ->and($fileCount('Financial'))->toBe(3);
});

test('a Managers user cannot edit, delete, or file a document into the funding paper categories', function () {
    ['project' => $project, 'agreement' => $agreement, 'photo' => $photo] = seedProjectFilesFixture();
    $fresh = fn () => Livewire::actingAs(userWithRole('Managers'))->test('pages::projects.show', ['project' => $project]);

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
        ->test('pages::projects.show', ['project' => $project])
        ->call('confirmDeleteFile', $agreement->id)
        ->assertOk()
        ->call('deleteFile')
        ->assertOk();

    expect(Media::query()->find($agreement->id))->toBeNull();
});

test('the project file download refuses funding paper to a Managers user and serves it to Financial', function () {
    ['project' => $project, 'agreement' => $agreement, 'mou' => $mou, 'photo' => $photo] = seedProjectFilesFixture();
    $manager = userWithRole('Managers');

    $this->actingAs($manager)->get(route('projects.files.download', [$project, $agreement]))->assertForbidden();
    $this->actingAs($manager)->get(route('projects.files.download', [$project, $mou]))->assertForbidden();
    $this->actingAs($manager)->get(route('projects.files.download', [$project, $photo]))->assertOk();

    $this->actingAs(userWithRole('Financial'))->get(route('projects.files.download', [$project, $agreement]))->assertOk();
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
        ->and($managers->instance()->fundingByProject[$project->id])->toMatchArray([
            'currencies' => [],
            'donors' => ['Aurora Foundation'],
            'proposals' => ['Harbour Clean Water'],
        ]);

    if ($view === 'board') {
        $managers->assertSee('Aurora Foundation')->assertSee('Harbour Clean Water');
    }

    $financial = Livewire::actingAs(userWithRole('Financial'))
        ->test('pages::projects.index')
        ->set('view', $view)
        ->assertSee('731,942');

    expect($financial->instance()->committedFunding)->toBe(['USD' => 731942.0]);
})->with(['board', 'table', 'timeline']);

test('the projects directory keeps the portfolio tile row whole when the committed funding tile is hidden', function () {
    seedProjectMoneyFixture();

    Livewire::actingAs(userWithRole('Team Member'))
        ->test('pages::projects.index')
        ->assertSee(__('projects.tile_beneficiaries'))
        ->assertDontSee(__('projects.tile_committed_funding'))
        ->assertSeeHtml('dark:bg-line grid-cols-1');
});

test('the project page loads no budget pivot for a viewer without funding agreements', function () {
    ['project' => $project] = seedProjectMoneyFixture();

    $proposal = Livewire::actingAs(userWithRole('Managers'))
        ->test('pages::projects.show', ['project' => $project])
        ->instance()
        ->attachedProposals
        ->first();

    expect($proposal->relationLoaded('donors'))->toBeFalse()
        ->and($proposal->donorNames->pluck('name')->all())->toBe(['Aurora Foundation'])
        ->and($proposal->donorNames->first()->pivot->getAttributes())->not->toHaveKey('budget');
});

test('the timeline never reads a budget pivot for a viewer without funding agreements', function (string $role, bool $readsBudget) {
    ['project' => $project] = seedProjectMoneyFixture();

    DB::enableQueryLog();
    Livewire::actingAs(userWithRole($role))
        ->test('pages::timeline.index')
        ->set('view', 'connected')
        ->set('program', (string) $project->program_id);
    $queries = collect(DB::getQueryLog())->pluck('query')->implode("\n");
    DB::disableQueryLog();

    expect(str_contains($queries, 'pivot_budget'))->toBe($readsBudget);
})->with([
    'a fundraiser without agreements' => [fn (): string => fundraiserWithoutAgreements(), false],
    'Financial' => ['Financial', true],
]);
