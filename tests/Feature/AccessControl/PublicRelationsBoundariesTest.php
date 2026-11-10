<?php

use App\Actions\Proposals\AddNegotiationNote;
use App\Actions\Proposals\SaveProposal;
use App\Actions\Proposals\TransitionProposal;
use App\Enums\Currency;
use App\Enums\ProposalStatus;
use App\Enums\StatusNoteKind;
use App\Models\Donor;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\ProposalLocation;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

/*
| UAT PR-01, PR-03 to PR-06: the fundraising officer works the proposal from
| concept note to negotiation, but never touches the money, the reviewers, a
| status correction or the AI usage log.
*/

beforeEach(function () {
    $this->fundraiser = userWithRole('Public Relations');
});

test('the fundraiser moves a proposal from review to submitted (PR-01)', function () {
    $proposal = Proposal::factory()->create([
        'status' => ProposalStatus::ProposalReview,
        'requested_budget' => '120000.00',
        'requested_currency' => Currency::Usd,
    ]);
    $proposal->projects()->attach(Project::factory()->create()->id, ['target_beneficiaries' => 400]);
    ProposalLocation::factory()->for($proposal)->complete()->create();

    $this->actingAs($this->fundraiser);
    app(TransitionProposal::class)->handle($proposal, ProposalStatus::Submitted, ProposalStatus::ProposalReview, ['effective_date' => '2026-10-13']);

    expect($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});

test('the fundraiser saves a city-only row, and a new row with no program or city is refused (PR-03)', function () {
    $city = insertHrCity('Aleppo', 'حلب');
    $data = fn (array $locations): array => [
        'name' => 'Winter Shelter Response',
        'internalProjectNumber' => 'HC-2026-961',
        'donorId' => Donor::factory()->create()->id,
        'locations' => $locations,
        'beneficiaries' => [],
        'plannedDateBegin' => '2026-11-01',
        'plannedDateEnd' => '2027-10-31',
    ];

    $proposal = app(SaveProposal::class)->handle(null, $data([['city_id' => $city]]), $this->fundraiser);

    expect(DB::table('proposal_locations')->where('proposal_id', $proposal->id)->sole())
        ->city_id->toBe($city)
        ->program_id->toBeNull();

    $refuse = function (array $row) use ($data): array {
        try {
            app(SaveProposal::class)->handle(null, $data([$row]), $this->fundraiser);
        } catch (ValidationException $exception) {
            return $exception->errors();
        }

        throw new RuntimeException('The save was not refused.');
    };

    expect($refuse(['city_id' => null]))->toHaveKey('locations.0.city_id')
        ->and($refuse(['city_id' => $city, 'is_new' => true]))->toHaveKey('locations.0.new_program_name_en')
        ->and(Proposal::query()->count())->toBe(1);
});

test('the fundraiser adds a negotiation note with a file after submission (PR-04)', function () {
    Storage::fake('local');
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);

    $this->actingAs($this->fundraiser);
    $note = app(AddNegotiationNote::class)->handle($proposal, ProposalStatus::Submitted, [
        'text' => 'The donor asked us to cut the travel budget by ten percent.',
        'file' => validUpload('donor-request.pdf'),
    ]);

    expect($note->kind)->toBe(StatusNoteKind::Negotiation)
        ->and($note->changed_by)->toBe($this->fundraiser->id)
        ->and($proposal->fresh()->status)->toBe(ProposalStatus::Submitted);
});

test('the fundraiser cannot manage reviewers, correct a status or approve (PR-05)', function () {
    $proposal = Proposal::factory()->create(['status' => ProposalStatus::Submitted]);
    $gate = Gate::forUser($this->fundraiser);

    expect($gate->allows('manageReviewers', $proposal))->toBeFalse()
        ->and($gate->allows('correctStatus', $proposal))->toBeFalse()
        ->and($gate->allows('approve', $proposal))->toBeFalse();
});

test('the fundraiser cannot write on the money pages by calling the actions directly (PR-05)', function () {
    $attempt = fn (string $page) => Livewire::actingAs($this->fundraiser)->test($page);

    $attempt('pages::transfers.index')->call('openCreateModal')->assertForbidden();
    $attempt('pages::funding-agreements.index')->call('openCreateModal')->assertForbidden();
});

test('the fundraiser is refused the Transfers and Accounts pages and the sidebar hides them (PR-05)', function () {
    $this->actingAs($this->fundraiser)->get(route('transfers.index'))->assertForbidden();
    $this->get(route('admin.accounts.index'))->assertForbidden();
    $this->get(route('dashboard'))->assertOk()
        ->assertDontSee(route('transfers.index'))
        ->assertDontSee(route('admin.accounts.index'));
});

test('the fundraiser cannot change the stored budget of a proposal (PR-05)', function () {
    $proposal = Proposal::factory()->create([
        'status' => ProposalStatus::ConceptNote,
        'requested_budget' => '120000.00',
        'requested_currency' => Currency::Usd,
    ]);

    expect(Gate::forUser($this->fundraiser)->allows('update', $proposal))->toBeTrue()
        ->and($this->fundraiser->can('funding-agreements.create'))->toBeFalse()
        ->and($this->fundraiser->can('transfers.view'))->toBeFalse();
});

test('the fundraiser may draft with AI but cannot see the AI usage log (PR-06)', function () {
    expect($this->fundraiser->can('ai.draft'))->toBeTrue()
        ->and($this->fundraiser->can('ai.view-usage'))->toBeFalse();
});
