<?php

use App\Enums\ProposalStatus;
use App\Enums\ResultType;
use App\Models\Donor;
use App\Models\DonorProposal;
use App\Models\DonorReport;
use App\Models\Proposal;
use App\Models\ProposalAttentionDismissal;
use App\Models\ProposalStatusNote;
use App\Models\Result;
use App\Models\TransfersTracking;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo('2026-11-18');
    $this->user = User::factory()->role('Public Relations Manager')->create();
    $this->proposal = Proposal::factory()->create([
        'name' => 'Micro Projects - Latakia - ACN',
        'status' => ProposalStatus::ProposalDraft,
        'planned_date_begin' => '2026-01-01',
        'planned_date_end' => '2026-12-31',
        'requested_budget' => '156400.00',
        'requested_currency' => null,
    ]);
});

function proposalPageFor(?User $user = null): Testable
{
    return Livewire::actingAs($user ?? test()->user)->test('pages::proposals.show', ['proposal' => test()->proposal]);
}

function statusNoteOn(Proposal $proposal, ProposalStatus $status, string $date): ProposalStatusNote
{
    return ProposalStatusNote::factory()->create(['proposal_id' => $proposal->id, 'status' => $status, 'date' => $date, 'is_correction' => false]);
}

test('the head offers the next status as the primary button and the rest in the menu', function () {
    $actions = collect(proposalPageFor()->instance()->headActions);

    expect($actions->firstWhere('primary', true)['label'])->toBe('Move to “Proposal Review”')
        ->and($actions->pluck('key'))->toContain('edit', 'note')
        ->and($actions->firstWhere('key', 'note')['disabled'])->toBeTrue()
        ->and($actions->firstWhere('key', 'note')['why'])->toBe(__('proposals.page.why_note_submitted'));
});

test('a submitted proposal offers Approve as the primary button and Reject in the menu', function () {
    Proposal::query()->whereKey($this->proposal->id)->update(['status' => ProposalStatus::Submitted]);

    $actions = collect(proposalPageFor()->instance()->headActions);

    expect($actions->firstWhere('primary', true)['key'])->toBe('approve')
        ->and($actions->pluck('key'))->toContain('reject', 'hold');
});

test('a payroll draft moves straight to Submitted', function () {
    Proposal::query()->whereKey($this->proposal->id)->update(['kind' => 'payroll', 'narrative' => 'Salaries']);

    $primary = collect(proposalPageFor()->instance()->headActions)->firstWhere('primary', true);

    expect($primary['label'])->toBe('Move to “Submitted”');
});

test('the other moves the rules allow stay in the menu as Change status', function () {
    Proposal::query()->whereKey($this->proposal->id)->update(['status' => ProposalStatus::ProposalReview]);

    $actions = collect(proposalPageFor()->instance()->headActions);

    expect($actions->firstWhere('primary', true)['label'])->toBe('Move to “Submitted”')
        ->and($actions->pluck('key'))->toContain('change-status');
});

test('the move button is disabled with a reason while the proposal is on hold', function () {
    Proposal::query()->whereKey($this->proposal->id)->update(['status' => ProposalStatus::ConceptNoteSubmitted, 'on_hold_since' => '2026-11-01']);
    $user = User::factory()->role('Public Relations')->create();

    $primary = collect(proposalPageFor($user)->instance()->headActions)->firstWhere('primary', true);

    expect($primary['key'])->toBe('move')
        ->and($primary['disabled'])->toBeTrue()
        ->and($primary['why'])->toBe(__('proposals.page.why_on_hold'));
});

test('someone who may not update the proposal gets no actions and no editable fields', function () {
    $viewer = User::factory()->role('Team Member')->create();

    $page = proposalPageFor($viewer);

    expect($page->instance()->headActions)->toBe([])
        ->and($page->instance()->canEditCardField('title'))->toBeFalse();

    $page->call('editField', 'title')->assertSet('editingField', null);
});

test('the title is edited in place and saved', function () {
    proposalPageFor()
        ->call('editField', 'title')
        ->assertSet('fieldValue', 'Micro Projects - Latakia - ACN')
        ->set('fieldValue', 'Micro Projects - Latakia - ACN 2027')
        ->call('saveField')
        ->assertHasNoErrors()
        ->assertSet('editingField', null);

    expect($this->proposal->fresh()->name)->toBe('Micro Projects - Latakia - ACN 2027');
});

test('an empty title is refused inline and the field stays open', function () {
    proposalPageFor()
        ->call('editField', 'title')
        ->set('fieldValue', '  ')
        ->call('saveField')
        ->assertHasErrors('fieldValue')
        ->assertSet('editingField', 'title');

    expect($this->proposal->fresh()->name)->toBe('Micro Projects - Latakia - ACN');
});

test('an end date before the start date is refused', function () {
    proposalPageFor()
        ->call('editField', 'end')
        ->set('fieldValue', '2025-12-01')
        ->call('saveField')
        ->assertHasErrors(['fieldValue' => __('proposals.page.end_before_start')]);

    expect($this->proposal->fresh()->planned_date_end->toDateString())->toBe('2026-12-31');
});

test('the donor is changed from the card while the concept note is in the house', function () {
    Proposal::query()->whereKey($this->proposal->id)->update(['status' => ProposalStatus::ConceptNote]);
    $donor = Donor::factory()->create(['name' => 'Caritas Poland']);

    proposalPageFor()
        ->call('editField', 'donor')
        ->set('fieldValue', (string) $donor->id)
        ->call('saveField')
        ->assertHasNoErrors();

    expect($this->proposal->fresh()->donor_id)->toBe($donor->id);
});

test('the donor is locked once the concept note has left the house', function () {
    $page = proposalPageFor();

    expect($page->instance()->cardFieldLock('donor'))->toBe(__('proposals.the_donor_cannot_change_after_the_concept_note'));
    $page->call('editField', 'donor')->assertSet('editingField', null);
});

test('the title of an approved proposal cannot be edited in place', function () {
    Proposal::query()->whereKey($this->proposal->id)->update(['status' => ProposalStatus::Approved]);

    $page = proposalPageFor();

    expect($page->instance()->cardFieldLock('title'))->toBe(__('proposals.page.lock_use_change_request'));

    $page->call('editField', 'title')->assertSet('editingField', null);
});

test('the sent date shows the date it reached Submitted and can be corrected with a record', function () {
    Proposal::query()->whereKey($this->proposal->id)->update(['status' => ProposalStatus::Submitted]);
    $note = statusNoteOn($this->proposal, ProposalStatus::Submitted, '2026-11-10');

    $page = proposalPageFor();
    expect($page->instance()->cardDates['sent']->toDateString())->toBe('2026-11-10');

    $page->call('editField', 'sent')->set('fieldValue', '2026-11-09')->call('saveField')->assertHasNoErrors();

    expect($note->fresh()->date->toDateString())->toBe('2026-11-09')
        ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
    $this->assertDatabaseHas('activities', ['subject_type' => 'proposal', 'subject_id' => $this->proposal->id, 'event' => 'proposal.status_date_changed']);
});

test('the approved date cannot fall before the sent date', function () {
    Proposal::query()->whereKey($this->proposal->id)->update(['status' => ProposalStatus::Approved]);
    statusNoteOn($this->proposal, ProposalStatus::Submitted, '2026-11-10');
    $approved = statusNoteOn($this->proposal, ProposalStatus::Approved, '2026-11-15');

    proposalPageFor()
        ->call('editField', 'approved')
        ->set('fieldValue', '2026-11-05')
        ->call('saveField')
        ->assertHasErrors(['fieldValue' => __('proposals.page.approved_before_sent')]);

    expect($approved->fresh()->date->toDateString())->toBe('2026-11-15');
});

test('the sent date cannot be set before the proposal was sent', function () {
    $page = proposalPageFor();

    expect($page->instance()->cardFieldLock('sent'))->toBe(__('proposals.page.not_sent_yet'));
    $page->call('editField', 'sent')->assertSet('editingField', null);
});

test('needs attention lists the before-review items while the proposal is a draft', function () {
    $keys = collect(proposalPageFor()->instance()->attentionItems)->pluck('key');

    expect($keys)->toContain('no-currency', 'no-reviewers', 'no-need-assessment', 'no-file');

    $items = collect(proposalPageFor()->instance()->attentionItems)->keyBy('key');
    expect($items['no-need-assessment']['late'])->toBeFalse()
        ->and($items['no-file']['late'])->toBeTrue()
        ->and($items['no-file']['cat'])->toBe('ready');
});

test('the before-review items disappear once the proposal is submitted', function () {
    Proposal::query()->whereKey($this->proposal->id)->update(['status' => ProposalStatus::Submitted]);

    $keys = collect(proposalPageFor()->instance()->attentionItems)->pluck('key');

    expect($keys)->not->toContain('no-currency', 'no-reviewers', 'no-file');
});

test('the currency item is gone once a currency is chosen', function () {
    Proposal::query()->whereKey($this->proposal->id)->update(['requested_currency' => 'USD']);

    expect(collect(proposalPageFor()->instance()->attentionItems)->pluck('key'))->not->toContain('no-currency');
});

test('hiding an item hides it for that person only and it can be shown again', function () {
    $page = proposalPageFor()->call('hideAttentionItem', 'no-file');

    expect($page->instance()->hiddenAttentionKeys)->toBe(['no-file']);

    $other = User::factory()->role('Public Relations Manager')->create();
    expect(proposalPageFor($other)->instance()->hiddenAttentionKeys)->toBe([]);

    Livewire::actingAs($this->user);
    $page->call('showHiddenAttention');
    expect(ProposalAttentionDismissal::query()->count())->toBe(0);
});

test('a proposal sent back from review shows the reason in needs attention until it moves on', function () {
    statusNoteOn($this->proposal, ProposalStatus::ProposalReview, '2026-11-10');
    ProposalStatusNote::factory()->create(['proposal_id' => $this->proposal->id, 'status' => ProposalStatus::ProposalDraft, 'date' => '2026-11-12', 'is_correction' => false, 'context' => 'Rework the budget lines.']);

    $item = collect(proposalPageFor()->instance()->attentionItems)->first(fn (array $item): bool => str_starts_with($item['key'], 'returned-'));

    expect($item['text'])->toBe('Sent back from review: “Rework the budget lines.”')
        ->and($item['cat'])->toBe('ready')
        ->and($item['late'])->toBeTrue();

    Proposal::query()->whereKey($this->proposal->id)->update(['status' => ProposalStatus::ProposalReview]);
    statusNoteOn($this->proposal, ProposalStatus::ProposalReview, '2026-11-14');

    expect(collect(proposalPageFor()->instance()->attentionItems)->pluck('key')->filter(fn (string $key): bool => str_starts_with($key, 'returned-')))->toBeEmpty();
});

test('a draft that was never reviewed has no sent-back item', function () {
    statusNoteOn($this->proposal, ProposalStatus::ConceptNote, '2026-11-10');
    statusNoteOn($this->proposal, ProposalStatus::ProposalDraft, '2026-11-12');

    expect(collect(proposalPageFor()->instance()->attentionItems)->pluck('key')->filter(fn (string $key): bool => str_starts_with($key, 'returned-')))->toBeEmpty();
});

test('an agreement on a proposal no longer approved and a pending donor show in needs attention', function () {
    $donor = Donor::factory()->pending()->create();
    Proposal::query()->whereKey($this->proposal->id)->update(['donor_id' => $donor->id]);
    DonorProposal::factory()->create(['proposal_id' => $this->proposal->id]);

    $items = collect(proposalPageFor()->instance()->attentionItems)->keyBy('key');

    expect($items['agreement-status-inconsistent']['text'])->toBe(__('funding_agreements.proposal_status_inconsistent_with_agreement'))
        ->and($items['agreement-status-inconsistent']['section'])->toBe('history')
        ->and($items['donor-pending']['text'])->toBe(__('proposals.donor_request_pending'))
        ->and($items['donor-pending']['late'])->toBeFalse();
});

test('a received tranche with no net amount shows in needs attention only to money viewers', function () {
    $agreement = DonorProposal::factory()->create(['proposal_id' => $this->proposal->id]);
    TransfersTracking::factory()->received()->create(['donor_proposal_id' => $agreement->id, 'net_amount_received' => null]);

    $financeKeys = collect(proposalPageFor(User::factory()->role('Financial')->create())->instance()->attentionItems)->keyBy('key');

    expect($financeKeys['missing-net']['text'])->toBe('1 received tranche has no net amount recorded')
        ->and($financeKeys['missing-net']['cat'])->toBe('money');

    $mediaKeys = collect(proposalPageFor(User::factory()->role('Media Team')->create())->instance()->attentionItems)->pluck('key');

    expect($mediaKeys)->not->toContain('missing-net');
});

test('the money section shows the currency line and the agreement with its tranches', function () {
    $dollars = DonorProposal::factory()->create(['proposal_id' => $this->proposal->id, 'budget' => 1000, 'currency' => 'USD']);
    TransfersTracking::factory()->received()->create(['donor_proposal_id' => $dollars->id, 'payment_amount' => 400, 'amount_received' => 400, 'scheduled_date' => '2026-10-01']);
    TransfersTracking::factory()->create(['donor_proposal_id' => $dollars->id, 'payment_amount' => 300, 'amount_received' => null, 'net_amount_received' => null, 'scheduled_date' => '2026-11-01']);

    proposalPageFor(User::factory()->role('Financial')->create())
        ->call('openSection', 'money')
        ->assertSeeHtml('data-money-line="USD"')
        ->assertSeeHtml('data-tranche-state="received"')
        ->assertSeeHtml('data-tranche-state="late"')
        ->assertSee('1 of 2 arrived')
        ->assertSee('1 late')
        ->assertSee('Not planned');
});

test('someone who sees agreements but not transfers gets no tranche money in the money section', function () {
    $agreement = DonorProposal::factory()->create(['proposal_id' => $this->proposal->id, 'budget' => 1000]);
    TransfersTracking::factory()->received()->create(['donor_proposal_id' => $agreement->id]);

    proposalPageFor()
        ->call('openSection', 'money')
        ->assertSeeHtml('data-agreement="'.$agreement->id.'"')
        ->assertDontSeeHtml('data-transfer=')
        ->assertDontSee('arrived');
});

test('places can be edited from the projects section only before the donor approves', function () {
    proposalPageFor()->call('openSection', 'projects')->assertSeeHtml('data-test="edit-locations"');

    Proposal::query()->whereKey($this->proposal->id)->update(['status' => ProposalStatus::Approved]);

    proposalPageFor()->call('openSection', 'projects')->assertDontSeeHtml('data-test="edit-locations"');
});

test('the reports tally counts donor reports as filed once sent and as owed or late until then', function () {
    $agreement = DonorProposal::factory()->create(['proposal_id' => $this->proposal->id]);
    DonorReport::factory()->submitted()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Interim narrative report', 'due_on' => '2026-09-18']);
    DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Interim financial report', 'due_on' => '2026-12-18']);
    DonorReport::factory()->create(['donor_proposal_id' => $agreement->id, 'title' => 'Late field report', 'due_on' => '2026-11-01']);

    $page = proposalPageFor(User::factory()->role('Financial')->create())->call('openSection', 'reports');

    $html = $page->html();

    foreach (['filed' => 1, 'owed' => 1, 'late' => 1, 'review' => 0] as $key => $count) {
        expect($html)->toMatch('/data-report-tally-item="'.$key.'">\s*<b>'.$count.'<\/b>/');
    }

    $page->assertSee('Interim financial report')
        ->assertSee('1 of 3 sent')
        ->assertSee(__('proposals.sections.no_target_reports'));
});

test('the need assessment dialog opens from needs attention while history is folded', function () {
    $item = collect(proposalPageFor()->instance()->attentionItems)->firstWhere('key', 'no-need-assessment');

    expect($item['fix']['call'])->toBe('openNeedAssessmentModal');

    proposalPageFor()
        ->assertSet('openSections', [])
        ->call('openNeedAssessmentModal')
        ->assertSet('showNeedAssessmentModal', true)
        ->assertSeeHtml('data-test="save-need-assessment"');
});

test('loose activities offer Sort them, which opens the activity board for anyone who sees the item', function () {
    $output = Result::factory()->create(['type' => ResultType::Output, 'proposal_id' => $this->proposal->id]);
    Result::factory()->create(['type' => ResultType::Activity, 'proposal_id' => $this->proposal->id, 'parent_id' => null, 'program_id' => $output->program_id]);
    $viewer = User::factory()->role('Media Team')->create();

    $item = collect(proposalPageFor($viewer)->instance()->attentionItems)->firstWhere('key', 'loose-activities');

    expect($item['fix']['label'])->toBe(__('proposals.attention.fix_sort_them'))
        ->and($item['fix']['call'])->toBe("\$dispatch('framework-go', { key: 'act' })");

    Livewire::actingAs($viewer)->test('proposals.logframe', ['proposalId' => $this->proposal->id])
        ->dispatch('framework-go', key: 'act')
        ->assertSet('layout', 'board')
        ->assertDispatched('pp-flash', target: 'lf');
});

test('an unknown item key is not stored', function () {
    proposalPageFor()->call('hideAttentionItem', 'made-up');

    expect(ProposalAttentionDismissal::query()->count())->toBe(0);
});

test('the page renders the head, the attention bar and the card', function () {
    proposalPageFor()
        ->assertSeeHtml('data-test="proposal-head"')
        ->assertSeeHtml('data-test="attention"')
        ->assertSeeHtml('data-test="proposal-card"')
        ->assertSee('things need attention')
        ->assertSee('Not set yet')
        ->assertSeeHtml('data-test="more-sections"')
        ->assertDontSeeHtml('data-record-activity')
        ->call('toggleSection', 'history')
        ->assertSeeHtml('data-record-activity');
});

test('sections start folded, open and close, and stay open for the person', function () {
    proposalPageFor()
        ->assertSet('openSections', [])
        ->call('toggleSection', 'docs')
        ->assertSeeHtml('data-tab-panel="documents"');

    proposalPageFor()->assertSet('openSections', ['docs'])->call('closeAllSections')->assertSet('openSections', []);
    proposalPageFor()->assertSet('openSections', [])->call('openAllSections');

    expect(proposalPageFor()->instance()->openSections)->toContain('history', 'docs', 'coming');
});

test('Open all is dimmed when every section is open, Close all when none is', function () {
    proposalPageFor()
        ->assertSeeHtml('data-test="sections-close-all"')
        ->assertSeeHtmlInOrder(['wire:click="closeAllSections" disabled', 'data-test="sections-close-all"'])
        ->assertDontSeeHtml('wire:click="openAllSections" disabled')
        ->call('openAllSections')
        ->assertSeeHtml('wire:click="openAllSections" disabled')
        ->assertDontSeeHtml('wire:click="closeAllSections" disabled');
});

test('a payroll proposal shows only money, coming up, documents and history', function () {
    Proposal::query()->whereKey($this->proposal->id)->update(['kind' => 'payroll', 'narrative' => 'Salaries']);

    expect(array_column(proposalPageFor()->instance()->sections, 'key'))->toBe(['money', 'coming', 'docs', 'history']);
});

test('an old tab link opens its section', function () {
    Livewire::actingAs($this->user)->withQueryParams(['ptab' => 'plan'])
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->assertSet('openSections', ['plan']);
});
