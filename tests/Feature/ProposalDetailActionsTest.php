<?php

use App\Enums\ArStatus;
use App\Enums\Currency;
use App\Enums\MediaCollection;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\Account;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\Media;
use App\Models\PlanIndicator;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Report;
use App\Models\Task;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo('2026-06-15');
    $this->user = User::factory()->role('Financial')->create();
    $this->proposal = Proposal::factory()->create([
        'status' => ProposalStatus::Active,
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
        'actual_date_begin' => null,
        'actual_date_end' => null,
    ]);
});

function proposalPage(): Testable
{
    return Livewire::actingAs(test()->user)->test('pages::proposals.show', ['proposal' => test()->proposal]);
}

function usdAgreement(Proposal $proposal, string $budget = '1000.00'): DonorProposal
{
    return DonorProposal::factory()->create([
        'proposal_id' => $proposal->id,
        'donor_id' => Donor::factory()->create(['name' => 'ECHO'])->id,
        'budget' => $budget,
        'currency' => Currency::Usd,
    ]);
}

test('a tranche due today is not overdue, and one due yesterday is, everywhere on the page', function () {
    $agreement = usdAgreement($this->proposal);
    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'payment_amount' => '100.00', 'amount_transfered_currency' => Currency::Usd, 'scheduled_date' => '2026-06-15', 'ar_status' => ArStatus::Pending]);

    $page = proposalPage();
    expect($page->instance()->moneyByCurrency['USD']['overdueCount'])->toBe(0)
        ->and(collect($page->instance()->warnings)->pluck('key'))->not->toContain('overdue-money-USD');
    $page->set('tab', 'funding')->assertDontSeeText('Overdue');

    $this->travelTo('2026-06-16');

    $page = proposalPage();
    expect($page->instance()->moneyByCurrency['USD']['overdueCount'])->toBe(1)
        ->and(collect($page->instance()->warnings)->pluck('key'))->toContain('overdue-money-USD');
    $page->set('tab', 'funding')->assertSeeText('Overdue');
});

test('a tranche received in another currency is left out of the agreement money and named instead', function () {
    $agreement = usdAgreement($this->proposal);
    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'ar_status' => ArStatus::Received, 'net_amount_received' => '400.00', 'amount_received_currency' => Currency::Usd, 'receive_date' => '2026-03-01']);
    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'ar_status' => ArStatus::Received, 'net_amount_received' => '900.00', 'amount_received_currency' => Currency::Eur, 'receive_date' => '2026-04-01']);

    $page = proposalPage();

    expect($page->instance()->agreementMoney[$agreement->id])
        ->received->toBe(400.0)
        ->foreignCount->toBe(1);
    $page->set('tab', 'funding')->assertSeeHtml('data-foreign-tranches');
});

test('the details form edits name, dates and notes but never the status', function () {
    proposalPage()
        ->call('openDetailsModal')
        ->set('detailsName', '  Renamed grant  ')
        ->set('detailsPlannedEnd', '2027-03-31')
        ->set('detailsNotes', 'Extension agreed by phone')
        ->call('saveDetails')
        ->assertHasNoErrors()
        ->assertSet('showDetailsModal', false);

    $this->proposal->refresh();

    expect($this->proposal->name)->toBe('Renamed grant')
        ->and($this->proposal->planned_date_end->toDateString())->toBe('2027-03-31')
        ->and($this->proposal->notes)->toBe('Extension agreed by phone')
        ->and($this->proposal->status)->toBe(ProposalStatus::Active);
});

test('the details form rejects an end before its start', function () {
    proposalPage()
        ->call('openDetailsModal')
        ->set('detailsPlannedEnd', '2025-01-01')
        ->call('saveDetails')
        ->assertHasErrors(['detailsPlannedEnd']);
});

test('an agreement recorded on the page is filed against this proposal even if the payload names another', function () {
    $other = Proposal::factory()->create();
    $donor = Donor::factory()->create();

    proposalPage()
        ->call('openAgreementCreate')
        ->set('agreementDonorId', (string) $donor->id)
        ->set('agreementProposalId', (string) $other->id)
        ->set('budget', '5000')
        ->set('agreementCurrency', 'EUR')
        ->call('saveAgreement')
        ->assertHasNoErrors();

    expect(DonorProposal::query()->where('donor_id', $donor->id)->sole()->proposal_id)->toBe($this->proposal->id)
        ->and($other->donorProposals()->count())->toBe(0);
});

test('the page cannot open or edit a tranche belonging to another proposal', function () {
    $foreign = TransfersTracking::factory()->create();

    proposalPage()->call('openTransferEdit', $foreign->id)->assertNotFound();
});

test('a new tranche opened against an agreement starts in its currency and saves against it', function () {
    $agreement = DonorProposal::factory()->create(['proposal_id' => $this->proposal->id, 'currency' => Currency::Eur]);

    proposalPage()
        ->call('openTransferCreate', $agreement->id)
        ->assertSet('transferAgreementId', (string) $agreement->id)
        ->assertSet('transferredCurrency', 'EUR')
        ->set('paymentAmount', '250')
        ->set('scheduledDate', '2026-07-01')
        ->call('saveTransfer')
        ->assertHasNoErrors();

    expect($agreement->transfers()->sole()->payment_amount)->toBe('250.00');
});

test('a tranche cannot be pointed at a closed account, but one already on it may keep it', function () {
    $agreement = usdAgreement($this->proposal);
    $closed = Account::factory()->closed()->create();

    proposalPage()
        ->call('openTransferCreate', $agreement->id)
        ->set('paymentAmount', '100')
        ->set('transferAccountId', (string) $closed->id)
        ->call('saveTransfer')
        ->assertHasErrors(['transferAccountId']);

    $existing = TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'account_id' => $closed->id]);

    proposalPage()
        ->call('openTransferEdit', $existing->id)
        ->set('paymentAmount', '120')
        ->call('saveTransfer')
        ->assertHasNoErrors();
});

test('a project can be linked and unlinked from the page', function () {
    $project = Project::factory()->create(['name' => 'Aleppo clinics']);

    $page = proposalPage()
        ->set('projectToLink', (string) $project->id)
        ->call('linkProject')
        ->assertHasNoErrors();

    expect($this->proposal->projects()->pluck('projects.id')->all())->toBe([$project->id]);

    $page->call('confirmUnlinkProject', $project->id)
        ->assertSet('showUnlinkModal', true)
        ->call('unlinkProject');

    expect($this->proposal->projects()->count())->toBe(0);
});

test('a file uploads to the proposal and downloads only through its own proposal', function () {
    Storage::fake('local');

    proposalPage()
        ->call('openCreateFileModal')
        ->set('newFile', validUpload('signed.pdf', 100))
        ->set('fileCategory', MediaCollection::Agreement->value)
        ->set('fileDisplayName', 'ECHO agreement')
        ->call('saveFile')
        ->assertHasNoErrors();

    $media = Media::query()->where('mediable_type', 'proposal')->sole();

    expect($media->mediable_id)->toBe($this->proposal->id)
        ->and($media->original_name)->toBe('ECHO agreement.pdf');

    $this->actingAs($this->user)->get(route('proposals.files.download', [$this->proposal, $media]))
        ->assertOk()
        ->assertHeader('X-Content-Type-Options', 'nosniff');
    $this->actingAs($this->user)->get(route('proposals.files.download', [Proposal::factory()->create(), $media]))->assertNotFound();
});

test('grant paperwork on funded projects is listed, their photos are not', function () {
    $project = Project::factory()->create();
    $this->proposal->projects()->attach($project);
    Media::factory()->create(['mediable_type' => 'project', 'mediable_id' => $project->id, 'collection' => MediaCollection::Mou, 'original_name' => 'mou.pdf']);
    Media::factory()->create(['mediable_type' => 'project', 'mediable_id' => $project->id, 'collection' => MediaCollection::Photo, 'original_name' => 'site.jpg']);

    proposalPage()->set('tab', 'documents')
        ->assertSeeText('mou.pdf')
        ->assertDontSeeText('site.jpg');
});

test('deleting a proposal removes its file rows', function () {
    Media::factory()->create(['mediable_type' => 'proposal', 'mediable_id' => $this->proposal->id]);

    $this->proposal->delete();

    expect(Media::query()->where('mediable_type', 'proposal')->count())->toBe(0);
});

test('deadlines list only open items in date order and group them by urgency', function () {
    // A viewer who sees every department's tasks, so the task deadline counts.
    $this->user = User::factory()->role('General Manager')->create();
    $agreement = usdAgreement($this->proposal);
    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'scheduled_date' => '2026-06-01', 'ar_status' => ArStatus::Pending]);
    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'scheduled_date' => '2026-06-02', 'ar_status' => ArStatus::Received]);
    Task::factory()->create(['source_type' => 'proposal', 'source_id' => $this->proposal->id, 'due_date' => '2026-06-20', 'status' => TaskStatus::Todo]);
    Task::factory()->create(['source_type' => 'proposal', 'source_id' => $this->proposal->id, 'due_date' => '2026-06-10', 'status' => TaskStatus::Done]);

    $deadlines = collect(proposalPage()->instance()->deadlines);

    expect($deadlines->pluck('kind')->all())->toBe(['transfer', 'task', 'grant'])
        ->and($deadlines->pluck('state')->all())->toBe(['overdue', 'soon', 'later']);
});

test('a draft report past its due date is owed, late and raised as a warning', function () {
    $target = PlanIndicator::factory()->forProposal($this->proposal)->create();
    Report::factory()->create(['plan_indicator_id' => $target->id, 'planned_date' => '2026-05-31']);
    Report::factory()->approved()->create(['plan_indicator_id' => $target->id, 'planned_date' => '2026-03-31']);

    $page = proposalPage();

    expect(collect($page->instance()->warnings)->pluck('key'))->toContain('overdue-reports');
    $page->set('tab', 'reports')->assertSeeHtml('data-report-tally-item="late"');
    expect($page->instance()->reports)->toHaveCount(2);
});

test('money labels that would collide on the timeline climb into separate lanes', function () {
    $agreement = usdAgreement($this->proposal);
    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'scheduled_date' => '2026-06-20', 'ar_status' => ArStatus::Pending]);
    TransfersTracking::factory()->create(['donor_proposal_id' => $agreement->id, 'scheduled_date' => '2026-06-22', 'ar_status' => ArStatus::Pending]);

    $lanes = collect(proposalPage()->instance()->chronology['money'])->pluck('lane')->all();

    expect($lanes)->toBe([0, 1]);
});

test('scheduling a tranche on a proposal with a single agreement preselects it', function () {
    $agreement = usdAgreement($this->proposal);

    proposalPage()
        ->call('openTransferCreate')
        ->assertSet('transferAgreementId', (string) $agreement->id);
});

test('an upload that is missing, too large or of a disallowed type is refused and nothing is stored', function (?Closure $makeFile) {
    Storage::fake('local');

    proposalPage()
        ->call('openCreateFileModal')
        ->set('newFile', $makeFile === null ? null : $makeFile())
        ->set('fileCategory', MediaCollection::Proposal->value)
        ->call('saveFile')
        ->assertHasErrors('newFile');

    expect(Media::query()->where('mediable_type', 'proposal')->exists())->toBeFalse()
        ->and(Storage::disk('local')->allFiles('media'))->toBe([]);
})->with([
    'missing' => [null],
    'too large' => [fn () => UploadedFile::fake()->create('big.pdf', 5121, 'application/pdf')],
    'disallowed type' => [fn () => UploadedFile::fake()->create('script.exe', 10, 'application/x-msdownload')],
]);

test('replacing a file stores the new one and removes the old one from disk', function () {
    Storage::fake('local');

    $page = proposalPage()
        ->call('openCreateFileModal')
        ->set('newFile', validUpload('draft.docx', 50))
        ->set('fileCategory', MediaCollection::Proposal->value)
        ->call('saveFile');

    $media = Media::query()->where('mediable_type', 'proposal')->sole();
    $oldPath = $media->path;

    $page->call('openEditFileModal', $media->id)
        ->set('newFile', validUpload('final.pdf', 80))
        ->set('fileDisplayName', 'Final proposal')
        ->call('saveFile')
        ->assertHasNoErrors();

    $media->refresh();

    expect($media->original_name)->toBe('Final proposal.pdf')
        ->and($media->type->value)->toBe('pdf');
    Storage::disk('local')->assertMissing($oldPath);
    Storage::disk('local')->assertExists($media->path);
});

test('the preview serves a proposal pdf and a funded project file inline, and nothing else', function () {
    Storage::fake('local');
    $funded = Project::factory()->create();
    $this->proposal->projects()->attach($funded);

    $own = Media::factory()->create(['mediable_type' => 'proposal', 'mediable_id' => $this->proposal->id, 'type' => 'pdf', 'path' => 'media/own.pdf']);
    $projectFile = Media::factory()->create(['mediable_type' => 'project', 'mediable_id' => $funded->id, 'collection' => MediaCollection::Agreement, 'type' => 'png', 'path' => 'media/a.png']);
    $unfunded = Media::factory()->create(['mediable_type' => 'project', 'mediable_id' => Project::factory()->create()->id, 'type' => 'pdf', 'path' => 'media/x.pdf']);
    $word = Media::factory()->create(['mediable_type' => 'proposal', 'mediable_id' => $this->proposal->id, 'type' => 'docx', 'path' => 'media/w.docx']);
    foreach ([$own, $projectFile, $unfunded, $word] as $media) {
        Storage::disk('local')->put($media->path, 'content');
    }

    $this->actingAs($this->user)->get(route('proposals.files.preview', [$this->proposal, $own]))
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf')
        ->assertHeader('X-Content-Type-Options', 'nosniff')
        ->assertHeaderMissing('Content-Security-Policy');
    $this->actingAs($this->user)->get(route('proposals.files.preview', [$this->proposal, $projectFile]))
        ->assertOk()
        ->assertHeader('Content-Security-Policy', 'sandbox');
    $this->actingAs($this->user)->get(route('proposals.files.preview', [$this->proposal, $unfunded]))->assertNotFound();
    $this->actingAs($this->user)->get(route('proposals.files.preview', [$this->proposal, $word]))->assertNotFound();
});

test('the preview steps through every document on the tab and wraps around', function () {
    Storage::fake('local');
    $funded = Project::factory()->create();
    $this->proposal->projects()->attach($funded);
    $own = Media::factory()->create(['mediable_type' => 'proposal', 'mediable_id' => $this->proposal->id]);
    $projectFile = Media::factory()->create(['mediable_type' => 'project', 'mediable_id' => $funded->id, 'collection' => MediaCollection::Mou]);
    $unrelated = Media::factory()->create();

    proposalPage()
        ->call('openPreview', $unrelated->id)
        ->assertSet('showPreviewModal', false)
        ->call('openPreview', $own->id)
        ->assertSet('showPreviewModal', true)
        ->call('previewAdjacent', 1)
        ->assertSet('previewingFileId', $projectFile->id)
        ->call('previewAdjacent', 1)
        ->assertSet('previewingFileId', $own->id);
});

test('a file with nothing stored behind it is marked reference only and offers no preview', function () {
    Storage::fake('local');
    Media::factory()->create(['mediable_type' => 'proposal', 'mediable_id' => $this->proposal->id, 'original_name' => 'legacy.pdf']);

    proposalPage()->set('tab', 'documents')
        ->assertSeeText('Reference only')
        ->assertDontSeeHtml('data-test="preview-file-name"');
});

test('the money reading names every currency after the one its figure leads with', function () {
    usdAgreement($this->proposal, '5000.00');
    $euro = DonorProposal::factory()->create(['proposal_id' => $this->proposal->id, 'budget' => '1000.00', 'currency' => Currency::Eur]);
    TransfersTracking::factory()->create(['donor_proposal_id' => $euro->id, 'ar_status' => ArStatus::Received, 'net_amount_received' => '250.00', 'amount_received_currency' => Currency::Eur, 'receive_date' => '2026-04-01']);

    $money = collect(proposalPage()->instance()->vitals)->firstWhere('key', 'money');

    expect($money['caption'])->toContain('USD')
        ->and($money['others'])->toHaveCount(1)
        ->and($money['others'][0]['currency'])->toBe('EUR')
        ->and($money['others'][0]['text'])->toBe('25% — 250 of 1,000 EUR');
});

test('a user without proposal permissions sees no edit controls and cannot change anything', function () {
    $viewer = User::factory()->role('Team Member')->create()->givePermissionTo('proposals.view');
    $agreement = usdAgreement($this->proposal);
    $media = Media::factory()->create(['mediable_type' => 'proposal', 'mediable_id' => $this->proposal->id]);
    $page = Livewire::actingAs($viewer)->test('pages::proposals.show', ['proposal' => $this->proposal]);

    $page->assertDontSeeHtml('data-test="open-status-modal"')
        ->assertDontSeeHtml('data-test="open-details-modal"');
    $page->set('tab', 'funding')
        ->assertDontSeeHtml('data-test="record-agreement"')
        ->assertDontSeeHtml('data-test="edit-agreement-'.$agreement->id.'"');
    $page->set('tab', 'documents')
        ->assertDontSeeHtml('data-test="upload-proposal-file"')
        ->assertDontSeeHtml('data-test="delete-file-'.$media->id.'"');

    $fresh = fn () => Livewire::actingAs($viewer)->test('pages::proposals.show', ['proposal' => $this->proposal]);

    $fresh()->call('openStatusModal')->assertForbidden();
    $fresh()->set('newStatus', ProposalStatus::Completed->value)->set('statusContext', 'Done')->set('statusDate', '2026-06-15')->call('changeStatus')->assertForbidden();
    $fresh()->call('confirmDeleteFile', $media->id)->assertForbidden();
    expect(fn () => $fresh()->set('deletingFileId', $media->id))->toThrow(CannotUpdateLockedPropertyException::class);
    $fresh()->call('openAgreementEdit', $agreement->id)->assertForbidden();
    $fresh()->call('openTransferCreate', $agreement->id)->assertForbidden();

    expect($this->proposal->fresh()->status)->toBe(ProposalStatus::Active)
        ->and(Media::query()->whereKey($media->id)->exists())->toBeTrue();
});
