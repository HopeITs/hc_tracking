<?php

use App\Enums\MediaCollection;
use App\Models\Account;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Media;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\TransfersTracking;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

/**
 * One donor funding one proposal: a budget of 731,942 and a pending transfer of
 * 123,457, figures no other fixture on these pages can produce.
 *
 * @return array{donor: Donor, proposal: Proposal, agreement: DonorProposal}
 */
function seedMoneyPagesFixture(): array
{
    $donor = Donor::factory()->create(['name' => 'Aurora Foundation']);
    $proposal = Proposal::factory()->create(['name' => 'Harbour Clean Water']);
    $agreement = DonorProposal::factory()->create([
        'donor_id' => $donor->id,
        'proposal_id' => $proposal->id,
        'budget' => '731942.00',
    ]);

    TransfersTracking::factory()->create([
        'donor_proposal_id' => $agreement->id,
        'payment_amount' => '123457.00',
        'amount_received' => '123457.00',
        'net_amount_received' => '123457.00',
        'scheduled_date' => now()->addMonth()->toDateString(),
    ]);

    return compact('donor', 'proposal', 'agreement');
}

test('Public Relations Manager sees donor names on the donor directory but no money', function () {
    seedMoneyPagesFixture();
    $page = fn (string $role) => Livewire::actingAs(userWithRole($role))->test('pages::donors.index');

    $page('Public Relations Manager')->assertOk()
        ->assertSee('Aurora Foundation')
        ->assertSee('Harbour Clean Water')
        ->assertDontSee('731,942')
        ->assertDontSee('731.9K')
        ->assertDontSee('123.5K')
        ->assertDontSeeHtml('data-wallet')
        ->assertDontSeeHtml('data-upcoming-transfers')
        ->assertDontSeeHtml('data-connection="money"')
        ->assertDontSeeHtml('data-connection="transfers"');

    $page('Financial')->assertOk()
        ->assertSee('Aurora Foundation')
        ->assertSee('731,942')
        ->assertSee('123.5K')
        ->assertSeeHtml('data-wallet')
        ->assertSeeHtml('data-upcoming-transfers');
});

test('Public Relations Manager sees the donor page without agreements, budgets or tranches', function () {
    ['donor' => $donor] = seedMoneyPagesFixture();
    $page = fn (string $role) => Livewire::actingAs(userWithRole($role))->test('pages::donors.show', ['donor' => $donor]);

    $publicRelations = $page('Public Relations Manager');
    $publicRelations->assertOk()
        ->assertSee('Aurora Foundation')
        ->assertDontSee('731,942')
        ->assertDontSee('123,457')
        ->assertDontSee('Harbour Clean Water')
        ->assertDontSeeHtml('data-money-river')
        ->assertDontSeeHtml('data-agreements');

    $instance = $publicRelations->instance();
    expect($instance->agreements)->toBeEmpty()
        ->and($instance->moneyByCurrency)->toBe([])
        ->and($instance->receivedElsewhere)->toBe([])
        ->and($instance->overdueTranches)->toBe([])
        ->and($instance->nextTranche)->toBeNull()
        ->and($instance->reportsByProposal)->toBe([])
        ->and($instance->calendar)->toBeNull();

    $page('Financial')->assertOk()
        ->assertSee('731,942.00')
        ->assertSee('123,457.00')
        ->assertSee('Harbour Clean Water')
        ->assertSeeHtml('data-money-river');
});

test('Public Relations Manager sees proposal names on the proposal directory but no money', function () {
    seedMoneyPagesFixture();
    $page = fn (string $role) => Livewire::actingAs(userWithRole($role))->test('pages::proposals.index');

    $publicRelations = $page('Public Relations Manager');
    $publicRelations->assertOk()
        ->assertSee('Harbour Clean Water')
        ->assertDontSee('Aurora Foundation')
        ->assertDontSee('731,942')
        ->assertDontSee('123,457')
        ->assertDontSeeHtml('data-funding-position')
        ->assertDontSeeHtml('data-funding-flow')
        ->assertDontSeeHtml('data-connection="funding"');

    $instance = $publicRelations->instance();
    expect($instance->fundingPosition)->toBeEmpty()
        ->and($instance->flow)->toBe(['currencies' => [], 'unfunded' => []])
        ->and(collect($instance->pipeline)->pluck('money')->filter()->all())->toBe([]);

    $page('Financial')->assertOk()
        ->assertSee('Harbour Clean Water')
        ->assertSee('Aurora Foundation')
        ->assertSee('731,942')
        ->assertSeeHtml('data-funding-position')
        ->assertSeeHtml('data-connection="funding"');
});

test('the proposal directory ignores the donor filter for someone who cannot see agreements', function () {
    ['donor' => $donor] = seedMoneyPagesFixture();
    Proposal::factory()->create(['name' => 'Unfunded Clinic Rebuild']);

    Livewire::actingAs(userWithRole('Public Relations Manager'))
        ->test('pages::proposals.index')
        ->call('toggleDonor', $donor->id)
        ->assertSet('donor', '')
        ->assertSee('Unfunded Clinic Rebuild')
        ->assertDontSee('Aurora Foundation');
});

test('Public Relations Manager sees the proposal page without a funding tab or any money', function () {
    ['proposal' => $proposal] = seedMoneyPagesFixture();
    $page = fn (string $role) => Livewire::actingAs(userWithRole($role))->test('pages::proposals.show', ['proposal' => $proposal]);

    $publicRelations = $page('Public Relations Manager');
    $publicRelations->assertOk()
        ->assertSee('Harbour Clean Water')
        ->assertDontSee('Aurora Foundation')
        ->assertDontSee('731,942')
        ->assertDontSee('123,457')
        ->assertDontSeeHtml('data-vital="money"')
        ->assertSeeHtml('data-vital="work"');

    expect(collect($publicRelations->instance()->tabs)->pluck('key')->all())->not->toContain('funding');

    $funding = $page('Public Relations Manager')->set('tab', 'funding');
    $funding->assertSet('tab', 'status')
        ->assertDontSee('731,942.00')
        ->assertDontSee('123,457.00');

    $instance = $funding->instance();
    expect($instance->agreements)->toBeEmpty()
        ->and($instance->agreementMoney)->toBe([])
        ->and($instance->moneyByCurrency)->toBe([])
        ->and($instance->donorReports)->toBeEmpty()
        ->and(collect($instance->warnings)->pluck('key')->all())->not->toContain('no-agreement')
        ->and($instance->arrivals['climbs'])->toBe([]);

    $page('Financial')->set('tab', 'funding')
        ->assertSet('tab', 'funding')
        ->assertSee('Aurora Foundation')
        ->assertSee('731,942.00')
        ->assertSee('123,457.00')
        ->assertSeeHtml('data-vital="money"');
});

test('someone who may see agreements but not transfers gets budgets without tranches', function () {
    ['donor' => $donor, 'proposal' => $proposal] = seedMoneyPagesFixture();
    $viewer = fn () => userWithRole('Public Relations Manager')->givePermissionTo('funding-agreements.view');

    $donorPage = Livewire::actingAs($viewer())->test('pages::donors.show', ['donor' => $donor]);
    $donorPage->assertSee('731,942.00')->assertDontSee('123,457');
    expect($donorPage->instance()->agreements->first()->transfers)->toBeEmpty();

    $proposalPage = Livewire::actingAs($viewer())->test('pages::proposals.show', ['proposal' => $proposal])->set('tab', 'funding');
    $proposalPage->assertSee('731,942.00')->assertDontSee('123,457.00')->assertDontSeeHtml('data-transfer=');
    expect($proposalPage->instance()->agreements->first()->transfers)->toBeEmpty();

    Livewire::actingAs($viewer())->test('pages::donors.index')
        ->assertSee('731,942')
        ->assertDontSee('123.5K')
        ->assertDontSeeHtml('data-upcoming-transfers');

    Livewire::actingAs($viewer())->test('pages::proposals.index')
        ->assertSee('731,942')
        ->assertDontSee('123,457');
});

/**
 * A concept note and a signed agreement on the proposal, and a signed MOU on a
 * project it funds, each with a stored PDF behind it.
 *
 * @return array{proposal: Proposal, note: Media, agreement: Media, mou: Media}
 */
function seedProposalPaperwork(): array
{
    Storage::fake('local');
    ['proposal' => $proposal] = seedMoneyPagesFixture();
    $project = Project::factory()->create();
    $proposal->projects()->attach($project);

    $file = fn (string $mediableType, int $mediableId, MediaCollection $collection, string $name): Media => Media::factory()->create([
        'mediable_type' => $mediableType,
        'mediable_id' => $mediableId,
        'collection' => $collection,
        'type' => 'pdf',
        'path' => 'media/'.$name,
        'original_name' => $name,
    ]);

    $paperwork = [
        'proposal' => $proposal,
        'note' => $file('proposal', $proposal->id, MediaCollection::Proposal, 'concept-note.pdf'),
        'agreement' => $file('proposal', $proposal->id, MediaCollection::Agreement, 'signed-agreement.pdf'),
        'mou' => $file('project', $project->id, MediaCollection::Mou, 'signed-mou.pdf'),
    ];

    foreach (['note', 'agreement', 'mou'] as $key) {
        Storage::disk('local')->put($paperwork[$key]->path, 'content');
    }

    return $paperwork;
}

test('Public Relations Manager does not see agreement or MOU files on the proposal page', function () {
    $paperwork = seedProposalPaperwork();
    $page = fn (string $role) => Livewire::actingAs(userWithRole($role))
        ->test('pages::proposals.show', ['proposal' => $paperwork['proposal']])
        ->set('tab', 'documents');

    $publicRelations = $page('Public Relations Manager');
    $publicRelations->assertSee('concept-note.pdf')
        ->assertDontSee('signed-agreement.pdf')
        ->assertDontSee('signed-mou.pdf');

    $documents = $publicRelations->instance()->documents;
    expect($documents->pluck('id')->all())->toBe([$paperwork['note']->id])
        ->and(collect($publicRelations->instance()->tabs)->firstWhere('key', 'documents')['count'])->toBe(1);

    $publicRelations->call('openPreview', $paperwork['agreement']->id)->assertSet('previewingFileId', null);
    $publicRelations->call('openPreview', $paperwork['mou']->id)->assertSet('previewingFileId', null);

    $page('Financial')
        ->assertSee('concept-note.pdf')
        ->assertSee('signed-agreement.pdf')
        ->assertSee('signed-mou.pdf');
});

test('Public Relations Manager cannot upload, edit or delete agreement or MOU files', function () {
    $paperwork = seedProposalPaperwork();
    $fresh = fn () => Livewire::actingAs(userWithRole('Public Relations Manager'))
        ->test('pages::proposals.show', ['proposal' => $paperwork['proposal']]);

    $fresh()->call('openEditFileModal', $paperwork['agreement']->id)->assertForbidden();
    $fresh()->call('confirmDeleteFile', $paperwork['agreement']->id)->assertForbidden();

    foreach ([MediaCollection::Agreement, MediaCollection::Mou] as $collection) {
        $fresh()
            ->call('openCreateFileModal')
            ->set('newFile', validUpload('sneaky.pdf', 100))
            ->set('fileCategory', $collection->value)
            ->call('saveFile')
            ->assertForbidden();
    }

    expect(Media::query()->where('original_name', 'sneaky.pdf')->exists())->toBeFalse()
        ->and(Media::query()->whereKey($paperwork['agreement']->id)->exists())->toBeTrue();

    $fresh()
        ->call('openCreateFileModal')
        ->set('newFile', validUpload('notes.pdf', 100))
        ->set('fileCategory', MediaCollection::Proposal->value)
        ->call('saveFile')
        ->assertHasNoErrors();

    expect(Media::query()->where('mediable_type', 'proposal')->where('collection', MediaCollection::Proposal->value)->count())->toBe(2);
});

test('the proposal file routes refuse agreement and MOU files to Public Relations Manager only', function () {
    $paperwork = seedProposalPaperwork();
    $publicRelations = userWithRole('Public Relations Manager');
    $financial = userWithRole('Financial');
    $proposal = $paperwork['proposal'];

    foreach (['agreement', 'mou'] as $key) {
        $this->actingAs($publicRelations)->get(route('proposals.files.preview', [$proposal, $paperwork[$key]]))->assertForbidden();
        $this->actingAs($financial)->get(route('proposals.files.preview', [$proposal, $paperwork[$key]]))->assertOk();
    }

    $this->actingAs($publicRelations)->get(route('proposals.files.download', [$proposal, $paperwork['agreement']]))->assertForbidden();
    $this->actingAs($financial)->get(route('proposals.files.download', [$proposal, $paperwork['agreement']]))->assertOk();

    $this->actingAs($publicRelations)->get(route('proposals.files.download', [$proposal, $paperwork['note']]))->assertOk();
    $this->actingAs($publicRelations)->get(route('proposals.files.preview', [$proposal, $paperwork['note']]))->assertOk();
});

test('the proposal page transfer modal builds no budgets or account names for someone who cannot record transfers', function () {
    ['proposal' => $proposal] = seedMoneyPagesFixture();
    Account::factory()->create(['account_name' => 'Zephyr Escrow Account']);
    $page = fn (string $role) => Livewire::actingAs(userWithRole($role))->test('pages::proposals.show', ['proposal' => $proposal]);

    $publicRelations = $page('Public Relations Manager')->set('showTransferModal', true);
    $publicRelations->assertDontSee('731,942')
        ->assertDontSee('Zephyr Escrow Account');

    expect($publicRelations->instance()->agreementOptions)->toBeEmpty()
        ->and($publicRelations->instance()->accountOptions)->toBeEmpty();

    $page('Financial')->set('showTransferModal', true)
        ->assertSee('731,942')
        ->assertSee('Zephyr Escrow Account');
});
