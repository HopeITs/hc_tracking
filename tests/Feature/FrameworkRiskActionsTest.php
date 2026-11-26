<?php

use App\Actions\Risks\DeleteFrameworkRisk;
use App\Actions\Risks\SaveFrameworkRisk;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Proposal;
use App\Models\Risk;
use App\Models\RiskLibrary;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->program = Program::factory()->create();
    $this->proposal = Proposal::factory()->create();
    $this->meal = mealManager();
    $this->input = [
        'name' => 'تأخر التمويل',
        'name_en' => 'Funding delay',
        'likelihood' => 2,
        'severity' => 3,
        'solution' => 'Keep a reserve.',
    ];
});

test('MEAL types a program risk and the library remembers its titles', function () {
    $this->actingAs($this->meal);

    $risk = app(SaveFrameworkRisk::class)->handle($this->program, null, $this->input);

    $entry = RiskLibrary::query()->sole();

    expect($risk->program_id)->toBe($this->program->id)
        ->and($risk->proposal_id)->toBeNull()
        ->and($risk->proposal_location_id)->toBeNull()
        ->and($risk->name)->toBe('تأخر التمويل')
        ->and($risk->name_en)->toBe('Funding delay')
        ->and($risk->likelihood)->toBe(2)
        ->and($risk->severity)->toBe(3)
        ->and($risk->solution)->toBe('Keep a reserve.')
        ->and($risk->library_risk_id)->toBe($entry->id)
        ->and($entry->title_en)->toBe('Funding delay')
        ->and($entry->is_active)->toBeTrue();
});

test('the same title in other case and spacing reuses the library entry', function () {
    $this->actingAs($this->meal);
    $first = app(SaveFrameworkRisk::class)->handle($this->program, null, $this->input);

    $second = app(SaveFrameworkRisk::class)->handle($this->proposal, null, [
        ...$this->input,
        'name' => 'مختلف تمامًا',
        'name_en' => '  FUNDING   delay ',
    ]);

    expect(RiskLibrary::query()->count())->toBe(1)
        ->and($second->library_risk_id)->toBe($first->library_risk_id);
});

test('library entries are appended in order and suggested while active', function () {
    $this->actingAs($this->meal);
    app(SaveFrameworkRisk::class)->handle($this->program, null, $this->input);
    app(SaveFrameworkRisk::class)->handle($this->program, null, [...$this->input, 'name' => 'نزوح', 'name_en' => 'Displacement']);
    RiskLibrary::query()->where('title_en', 'Funding delay')->update(['is_active' => false]);

    $suggestions = RiskLibrary::suggestions();

    expect($suggestions->pluck('title_en')->all())->toBe(['Displacement'])
        ->and(RiskLibrary::query()->orderBy('sort_order')->pluck('title_en')->all())->toBe(['Funding delay', 'Displacement']);
});

test('MEAL creates and edits a proposal-specific risk', function () {
    $this->actingAs($this->meal);

    $risk = app(SaveFrameworkRisk::class)->handle($this->proposal, null, $this->input);

    expect($risk->proposal_id)->toBe($this->proposal->id)
        ->and($risk->proposal_location_id)->toBeNull()
        ->and($risk->program_id)->toBeNull()
        ->and(Activity::query()->where('event', 'proposal.risk_attached')->exists())->toBeTrue();

    $edited = app(SaveFrameworkRisk::class)->handle($this->proposal, $risk->id, [...$this->input, 'severity' => 1, 'solution' => null]);

    expect($edited->id)->toBe($risk->id)
        ->and($edited->fresh()->severity)->toBe(1)
        ->and($edited->fresh()->solution)->toBeNull();
});

test('a copied proposal risk can only be removed', function () {
    $this->actingAs($this->meal);
    $source = Risk::factory()->onProgram($this->program)->create();
    $copy = Risk::factory()->onProposalFramework($this->proposal)->create(['copied_from_id' => $source->id]);

    expect(fn () => app(SaveFrameworkRisk::class)->handle($this->proposal, $copy->id, $this->input))
        ->toThrow(ValidationException::class);

    app(DeleteFrameworkRisk::class)->handle($this->proposal, $copy->id);

    expect(Risk::query()->whereKey($copy->id)->exists())->toBeFalse()
        ->and(Risk::query()->whereKey($source->id)->exists())->toBeTrue();
});

test('MEAL deletes a program risk', function () {
    $this->actingAs($this->meal);
    $risk = Risk::factory()->onProgram($this->program)->create();

    app(DeleteFrameworkRisk::class)->handle($this->program, $risk->id);

    expect(Risk::query()->whereKey($risk->id)->exists())->toBeFalse();
});

test('a user without results.update cannot save or delete framework risks', function () {
    $outsider = User::factory()->create();
    $programRisk = Risk::factory()->onProgram($this->program)->create();
    $proposalRisk = Risk::factory()->onProposalFramework($this->proposal)->create();

    $this->actingAs($outsider);

    expect(fn () => app(SaveFrameworkRisk::class)->handle($this->program, null, $this->input))->toThrow(AuthorizationException::class)
        ->and(fn () => app(SaveFrameworkRisk::class)->handle($this->proposal, $proposalRisk->id, $this->input))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteFrameworkRisk::class)->handle($this->program, $programRisk->id))->toThrow(AuthorizationException::class)
        ->and(fn () => app(DeleteFrameworkRisk::class)->handle($this->proposal, $proposalRisk->id))->toThrow(AuthorizationException::class);

    expect(Risk::query()->count())->toBe(2)
        ->and(RiskLibrary::query()->count())->toBe(0);
});

test('a risk of another program or proposal is never reached', function () {
    $this->actingAs($this->meal);
    $otherProgram = Program::factory()->create();
    $otherProposal = Proposal::factory()->create();
    $foreignProgramRisk = Risk::factory()->onProgram($otherProgram)->create();
    $foreignProposalRisk = Risk::factory()->onProposalFramework($otherProposal)->create();

    expect(fn () => app(SaveFrameworkRisk::class)->handle($this->program, $foreignProgramRisk->id, $this->input))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(SaveFrameworkRisk::class)->handle($this->proposal, $foreignProposalRisk->id, $this->input))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(DeleteFrameworkRisk::class)->handle($this->program, $foreignProgramRisk->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(DeleteFrameworkRisk::class)->handle($this->proposal, $foreignProposalRisk->id))->toThrow(ModelNotFoundException::class)
        ->and(fn () => app(DeleteFrameworkRisk::class)->handle($this->proposal, $foreignProgramRisk->id))->toThrow(ModelNotFoundException::class);

    expect(Risk::query()->count())->toBe(2);
});

test('invalid ratings and blank titles are refused', function (array $override, string $field) {
    $this->actingAs($this->meal);

    try {
        app(SaveFrameworkRisk::class)->handle($this->program, null, [...$this->input, ...$override]);
        $this->fail('Expected a validation error.');
    } catch (ValidationException $e) {
        expect($e->errors())->toHaveKey($field);
    }

    expect(Risk::query()->count())->toBe(0)
        ->and(RiskLibrary::query()->count())->toBe(0);
})->with([
    'likelihood 4' => [['likelihood' => 4], 'likelihood'],
    'severity 0' => [['severity' => 0], 'severity'],
    'blank English title' => [['name_en' => ''], 'name_en'],
    'blank Arabic title' => [['name' => '  '], 'name'],
    'long solution' => [['solution' => str_repeat('a', 5001)], 'solution'],
]);
