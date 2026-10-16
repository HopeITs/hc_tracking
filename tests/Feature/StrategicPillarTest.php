<?php

use App\Actions\Strategies\AssignProgramToPillar;
use App\Actions\Strategies\DeleteStrategicPillar;
use App\Actions\Strategies\SaveStrategicPillar;
use App\Models\Program;
use App\Models\StrategicPillar;
use App\Models\Strategy;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->strategy = Strategy::factory()->create();
    $this->manager = userWithRole('Managers');
});

/**
 * The strategy page, as the given user.
 */
function strategyPage($user, Strategy $strategy)
{
    return Livewire::actingAs($user)->test('pages::strategies.show', ['strategy' => $strategy]);
}

describe('assigning a program', function () {
    it('refuses a pillar of another strategy', function () {
        $program = Program::factory()->create(['strategy_id' => $this->strategy->id]);
        $foreign = StrategicPillar::factory()->create();

        $this->actingAs($this->manager);

        expect(fn () => app(AssignProgramToPillar::class)->handle($program->id, $foreign->id))
            ->toThrow(ValidationException::class, __('strategies.pillar_other_strategy'));

        expect($program->fresh()->strategic_pillar_id)->toBeNull();
    });

    it('puts a program of this strategy under the pillar and takes it out again', function () {
        $program = Program::factory()->create(['strategy_id' => $this->strategy->id]);
        $pillar = StrategicPillar::factory()->for($this->strategy)->create();

        $this->actingAs($this->manager);
        app(AssignProgramToPillar::class)->handle($program->id, $pillar->id);
        expect($program->fresh()->strategic_pillar_id)->toBe($pillar->id);

        app(AssignProgramToPillar::class)->handle($program->id, null);
        expect($program->fresh()->strategic_pillar_id)->toBeNull();
    });

    it('gives a program without a strategy the strategy of the pillar', function () {
        $program = Program::factory()->create(['strategy_id' => null]);
        $pillar = StrategicPillar::factory()->for($this->strategy)->create();

        $this->actingAs($this->manager);
        app(AssignProgramToPillar::class)->handle($program->id, $pillar->id);

        expect($program->fresh())
            ->strategic_pillar_id->toBe($pillar->id)
            ->strategy_id->toBe($this->strategy->id);
    });
});

describe('saving and deleting a pillar', function () {
    it('creates and edits a pillar', function () {
        $this->actingAs($this->manager);

        $pillar = app(SaveStrategicPillar::class)->handle($this->strategy->id, null, [
            'code' => 'P1', 'name_ar' => 'التمكين الاقتصادي', 'name_en' => 'Economic empowerment',
            'goal_ar' => null, 'goal_en' => 'A goal', 'sort_order' => 1,
        ]);

        expect($pillar->strategy_id)->toBe($this->strategy->id)->and($pillar->code)->toBe('P1');

        $edited = app(SaveStrategicPillar::class)->handle($this->strategy->id, $pillar->id, [
            'code' => 'P1', 'name_ar' => 'التمكين', 'name_en' => null, 'goal_ar' => null, 'goal_en' => null, 'sort_order' => 2,
        ]);

        expect($edited)->id->toBe($pillar->id)->name_ar->toBe('التمكين')->sort_order->toBe(2);
        expect(StrategicPillar::query()->count())->toBe(1);
    });

    it('validates the fields and keeps the code unique per strategy', function (array $input, string $key) {
        StrategicPillar::factory()->for($this->strategy)->create(['code' => 'P1']);
        $this->actingAs($this->manager);

        $input += ['code' => 'P2', 'name_ar' => 'محور', 'name_en' => null, 'goal_ar' => null, 'goal_en' => null, 'sort_order' => 0];

        expect(fn () => app(SaveStrategicPillar::class)->handle($this->strategy->id, null, $input))
            ->toThrow(fn (ValidationException $e) => expect($e->errors())->toHaveKey($key));
    })->with([
        'name_ar missing' => [['name_ar' => ''], 'name_ar'],
        'name_ar too long' => [['name_ar' => str_repeat('م', 256)], 'name_ar'],
        'name_en too long' => [['name_en' => str_repeat('a', 256)], 'name_en'],
        'goal too long' => [['goal_en' => str_repeat('a', 2001)], 'goal_en'],
        'code missing' => [['code' => ''], 'code'],
        'code too long' => [['code' => str_repeat('a', 21)], 'code'],
        'code taken' => [['code' => 'P1'], 'code'],
    ]);

    it('allows the same code in another strategy', function () {
        StrategicPillar::factory()->create(['code' => 'P1']);
        $this->actingAs($this->manager);

        $pillar = app(SaveStrategicPillar::class)->handle($this->strategy->id, null, [
            'code' => 'P1', 'name_ar' => 'محور', 'name_en' => null, 'goal_ar' => null, 'goal_en' => null, 'sort_order' => 0,
        ]);

        expect($pillar->exists)->toBeTrue();
    });

    it('refuses to delete a pillar while programs are assigned', function () {
        $pillar = StrategicPillar::factory()->for($this->strategy)->create();
        Program::factory()->create(['strategy_id' => $this->strategy->id, 'strategic_pillar_id' => $pillar->id]);

        $this->actingAs($this->manager);

        expect(fn () => app(DeleteStrategicPillar::class)->handle($pillar->id))
            ->toThrow(ValidationException::class, __('strategies.pillar_has_programs'));
        expect(StrategicPillar::query()->count())->toBe(1);
    });

    it('deletes an empty pillar', function () {
        $pillar = StrategicPillar::factory()->for($this->strategy)->create();

        $this->actingAs($this->manager);
        app(DeleteStrategicPillar::class)->handle($pillar->id);

        expect(StrategicPillar::query()->count())->toBe(0);
    });

    it('edits a pillar only through its own strategy', function () {
        $foreign = StrategicPillar::factory()->create();
        $this->actingAs($this->manager);

        expect(fn () => app(SaveStrategicPillar::class)->handle($this->strategy->id, $foreign->id, [
            'code' => 'X', 'name_ar' => 'س', 'name_en' => null, 'goal_ar' => null, 'goal_en' => null, 'sort_order' => 0,
        ]))->toThrow(ModelNotFoundException::class);
    });
});

describe('authorization', function () {
    it('refuses every action without strategies.update', function () {
        $user = userWithRole('Team Member');
        $program = Program::factory()->create(['strategy_id' => $this->strategy->id]);
        $pillar = StrategicPillar::factory()->for($this->strategy)->create();
        $this->actingAs($user);

        expect(fn () => app(AssignProgramToPillar::class)->handle($program->id, $pillar->id))->toThrow(AuthorizationException::class);
        expect(fn () => app(DeleteStrategicPillar::class)->handle($pillar->id))->toThrow(AuthorizationException::class);
        expect(fn () => app(SaveStrategicPillar::class)->handle($this->strategy->id, null, [
            'code' => 'P9', 'name_ar' => 'س', 'name_en' => null, 'goal_ar' => null, 'goal_en' => null, 'sort_order' => 0,
        ]))->toThrow(AuthorizationException::class);
    });

    it('is forbidden on the page for a viewer', function () {
        $viewer = userWithRole('Team Member');
        $program = Program::factory()->create(['strategy_id' => $this->strategy->id]);
        $pillar = StrategicPillar::factory()->for($this->strategy)->create();

        strategyPage($viewer, $this->strategy)->call('openCreatePillarModal')->assertForbidden();
        strategyPage($viewer, $this->strategy)->call('openAssignModal', $program->id)->assertForbidden();
        strategyPage($viewer, $this->strategy)->call('confirmDeletePillar', $pillar->id)->assertForbidden();
    });
});

describe('the strategy page', function () {
    it('lists the pillars with their programs and the programs without a pillar', function () {
        $pillar = StrategicPillar::factory()->for($this->strategy)->create(['code' => 'P1', 'name_en' => 'Economic empowerment']);
        Program::factory()->create(['name' => 'Alpha Program', 'strategy_id' => $this->strategy->id, 'strategic_pillar_id' => $pillar->id]);
        Program::factory()->create(['name' => 'Loose Program', 'strategy_id' => $this->strategy->id]);

        strategyPage($this->manager, $this->strategy)
            ->assertSee('Economic empowerment')
            ->assertSee('Alpha Program')
            ->assertSee('Loose Program')
            ->assertSee(__('strategies.no_pillar'));
    });

    it('creates, edits, assigns and deletes through the dialogs', function () {
        $program = Program::factory()->create(['strategy_id' => $this->strategy->id]);

        $page = strategyPage($this->manager, $this->strategy)
            ->call('openCreatePillarModal')
            ->assertSet('showPillarModal', true)
            ->set('pillarCode', 'P1')
            ->set('pillarNameAr', 'محور')
            ->set('pillarNameEn', 'Pillar')
            ->call('savePillar')
            ->assertHasNoErrors()
            ->assertSet('showPillarModal', false);

        $pillar = StrategicPillar::query()->sole();

        $page->call('openAssignModal', $program->id)
            ->assertSet('showAssignModal', true)
            ->set('assignPillarId', $pillar->id)
            ->call('assignProgram')
            ->assertHasNoErrors()
            ->assertSet('showAssignModal', false);

        expect($program->fresh()->strategic_pillar_id)->toBe($pillar->id);

        $page->call('confirmDeletePillar', $pillar->id)->call('deletePillar');
        expect(StrategicPillar::query()->count())->toBe(1);

        $page->call('openAssignModal', $program->id)->set('assignPillarId', null)->call('assignProgram');
        $page->call('confirmDeletePillar', $pillar->id)->call('deletePillar');
        expect(StrategicPillar::query()->count())->toBe(0);
    });

    it('shows the pillars read-only to a viewer', function () {
        StrategicPillar::factory()->for($this->strategy)->create(['name_en' => 'Visible pillar']);

        strategyPage(userWithRole('Team Member'), $this->strategy)
            ->assertSee('Visible pillar')
            ->assertDontSeeHtml('data-test="create-pillar"');
    });
});

it('keeps a strategy that still has pillars, with a message instead of a database error', function () {
    StrategicPillar::factory()->for($this->strategy)->create();

    Livewire::actingAs($this->manager)
        ->test('pages::strategies.index')
        ->call('confirmDelete', $this->strategy->id)
        ->call('deleteStrategy')
        ->assertDispatched('toast-show', fn (string $event, array $params): bool => ($params['slots']['text'] ?? null) === __('strategies.strategy_has_pillars'));

    expect(Strategy::query()->whereKey($this->strategy->id)->exists())->toBeTrue();
});
