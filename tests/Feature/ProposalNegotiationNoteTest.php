<?php

use App\Actions\Proposals\AddNegotiationNote;
use App\Enums\MediaCollection;
use App\Enums\ProposalStatus;
use App\Enums\StatusNoteKind;
use App\Models\Media;
use App\Models\Proposal;
use App\Models\ProposalStatusNote;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('local');
    $this->user = User::factory()->role('Public Relations Manager')->create();
    $this->proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);
});

function addNegotiationNote(array $input = [], ?ProposalStatus $expected = null, ?User $actor = null): ProposalStatusNote
{
    test()->actingAs($actor ?? test()->user);

    return app(AddNegotiationNote::class)->handle(
        test()->proposal,
        $expected ?? ProposalStatus::Submitted,
        ['text' => 'The donor asked us to cut the travel budget by ten percent.', ...$input],
    );
}

test('a negotiation note is saved with its kind, author and file, and the status stays put', function () {
    $note = addNegotiationNote(['file' => validUpload('donor-request.pdf')]);

    expect($note->kind)->toBe(StatusNoteKind::Negotiation)
        ->and($note->status)->toBe(ProposalStatus::Submitted)
        ->and($note->is_correction)->toBeFalse()
        ->and($note->changed_by)->toBe($this->user->id)
        ->and($note->context)->toBe('The donor asked us to cut the travel budget by ten percent.')
        ->and($this->proposal->fresh()->status)->toBe(ProposalStatus::Submitted);

    $media = Media::query()->sole();

    expect($media->collection)->toBe(MediaCollection::Negotiation)
        ->and($media->mediable_type)->toBe('proposal_status_note')
        ->and($media->mediable_id)->toBe($note->id)
        ->and($media->encryption_key_id)->not->toBeNull();
});

test('a negotiation note needs no file', function () {
    $note = addNegotiationNote();

    expect($note->kind)->toBe(StatusNoteKind::Negotiation)
        ->and(Media::query()->count())->toBe(0);
});

test('a negotiation note is refused outside submitted', function (ProposalStatus $status) {
    $this->proposal->forceFill(['status' => $status])->save();

    expect(fn () => addNegotiationNote(expected: $status))
        ->toThrow(ValidationException::class, __('proposals.negotiation_only_submitted'));
    expect(ProposalStatusNote::query()->count())->toBe(0);
})->with([ProposalStatus::ProposalDraft, ProposalStatus::Approved, ProposalStatus::Active]);

test('a negotiation note shorter than ten characters is refused', function () {
    expect(fn () => addNegotiationNote(['text' => 'too short']))->toThrow(ValidationException::class);
    expect(ProposalStatusNote::query()->count())->toBe(0);
});

test('a stale expected status is refused and nothing is written', function () {
    $this->proposal->forceFill(['status' => ProposalStatus::OnHold])->save();

    expect(fn () => addNegotiationNote(expected: ProposalStatus::Submitted))
        ->toThrow(ValidationException::class, __('proposals.stale_status'));
    expect(ProposalStatusNote::query()->count())->toBe(0);
});

test('a team member cannot add a negotiation note', function () {
    $member = User::factory()->role('Team Member')->create();

    expect(fn () => addNegotiationNote(actor: $member))->toThrow(AuthorizationException::class);

    Livewire::actingAs($member)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->call('saveNegotiationNote')
        ->assertForbidden();
    expect(ProposalStatusNote::query()->count())->toBe(0);
});

test('the activity names the file but never repeats the text', function () {
    addNegotiationNote(['file' => validUpload('donor-request.pdf')]);

    $activity = DB::table('activities')->where('event', 'proposal.negotiation_noted')->sole();

    expect($activity->properties)->toContain('donor-request.pdf')
        ->and($activity->properties)->not->toContain('travel budget');
});

test('the migration backfills corrections', function () {
    $correction = ProposalStatusNote::factory()->create(['proposal_id' => $this->proposal->id, 'is_correction' => true]);
    $plain = ProposalStatusNote::factory()->create(['proposal_id' => $this->proposal->id]);
    DB::table('proposal_status_notes')->update(['kind' => 'transition']);

    $migration = require base_path('database/migrations/'.collect(scandir(database_path('migrations')))->first(fn (string $f) => str_contains($f, 'add_kind_to_proposal_status_notes')));
    $migration->backfillKinds();

    expect(DB::table('proposal_status_notes')->find($correction->id)->kind)->toBe('correction')
        ->and(DB::table('proposal_status_notes')->find($plain->id)->kind)->toBe('transition');
});

test('the status tab offers the form only while submitted', function () {
    Livewire::actingAs($this->user)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->assertSeeHtml('data-test="add-negotiation-note"');

    $this->proposal->forceFill(['status' => ProposalStatus::ProposalDraft])->save();

    Livewire::actingAs($this->user)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->assertDontSeeHtml('data-test="add-negotiation-note"');
});

test('the page saves a negotiation note and the file downloads through the proposal', function () {
    Livewire::actingAs($this->user)
        ->test('pages::proposals.show', ['proposal' => $this->proposal])
        ->call('openNegotiationModal')
        ->set('negotiationText', 'The donor wants the logframe indicators reduced to ten.')
        ->set('negotiationFile', validUpload('request.pdf'))
        ->call('saveNegotiationNote')
        ->assertHasNoErrors()
        ->assertSee('The donor wants the logframe indicators reduced to ten.')
        ->assertSee(StatusNoteKind::Negotiation->label());

    $media = Media::query()->sole();

    $this->actingAs($this->user)->get(route('proposals.files.download', [$this->proposal, $media]))->assertOk();
    $this->actingAs($this->user)->get(route('proposals.files.download', [Proposal::factory()->create(), $media]))->assertNotFound();
});

test('kind labels exist in both languages', function (string $locale) {
    app()->setLocale($locale);

    foreach (StatusNoteKind::cases() as $kind) {
        expect($kind->label())->not->toBe('enums.status_note_kind.'.$kind->value);
    }
})->with(['en', 'ar']);
