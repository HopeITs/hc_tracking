<?php

use App\Actions\Programs\SaveProgram;
use App\Models\Activity;
use App\Models\Program;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;

test('a general manager adds a program from the programs page (D-68)', function () {
    $strategy = Strategy::factory()->create();
    $manager = insertHrEmployee();

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->assertSeeHtml('data-test="create-program-button"')
        ->call('openCreateModal')
        ->assertSet('showProgramModal', true)
        ->set('nameEn', '  Water Security ')
        ->set('nameAr', 'الأمن المائي')
        ->set('code', '011')
        ->set('strategyId', (string) $strategy->id)
        ->set('managerId', (string) $manager)
        ->call('saveProgram')
        ->assertHasNoErrors()
        ->assertSet('showProgramModal', false);

    $program = Program::query()->where('name_en', 'Water Security')->sole();

    expect($program)
        ->name_ar->toBe('الأمن المائي')
        ->code->toBe('011')
        ->slug->toBe('water-security')
        ->is_active->toBeTrue()
        ->strategy_id->toBe($strategy->id)
        ->manager_id->toBe($manager)
        ->and(DB::connection('hr')->table('programs')->count())->toBe(0)
        ->and(Activity::query()->where('event', 'program.created')->where('subject_id', $program->id)->exists())->toBeTrue();
});

test('renaming a program keeps its slug and records a rename', function () {
    $program = Program::factory()->create(['name_en' => 'Study Zone', 'code' => '004']);
    $slug = $program->slug;

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->call('openEditModal', $program->id)
        ->assertSet('nameEn', 'Study Zone')
        ->assertSet('code', '004')
        ->set('nameEn', 'Study Zone Plus')
        ->call('saveProgram')
        ->assertHasNoErrors();

    expect($program->refresh())
        ->name_en->toBe('Study Zone Plus')
        ->slug->toBe($slug)
        ->and(Activity::query()->where('event', 'program.renamed')->where('subject_id', $program->id)->exists())->toBeTrue();
});

test('a program needs an English name and a code no other program has', function () {
    Program::factory()->create(['code' => '007']);

    Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->call('openCreateModal')
        ->set('nameEn', ' ')
        ->set('code', '007')
        ->call('saveProgram')
        ->assertHasErrors(['nameEn', 'code'])
        ->assertSet('showProgramModal', true);

    expect(Program::query()->count())->toBe(1);
});

test('a program can be made inactive and the register filters by it', function () {
    $program = Program::factory()->create(['name_en' => 'Old Program']);
    Program::factory()->create(['name_en' => 'Live Program']);

    $page = Livewire::actingAs(User::factory()->role('General Manager')->create())
        ->test('pages::programs.index')
        ->call('openEditModal', $program->id)
        ->set('isActive', false)
        ->call('saveProgram')
        ->assertHasNoErrors();

    expect($program->refresh()->is_active)->toBeFalse();

    $page->assertSeeHtml('data-program-inactive')
        ->set('status', 'inactive')
        ->assertSee('Old Program')
        ->assertDontSee('Live Program')
        ->set('status', 'active')
        ->assertSee('Live Program')
        ->assertDontSee('Old Program');
});

test('only people with programs.create may add a program', function () {
    Livewire::actingAs(User::factory()->role('Team Member')->create())
        ->test('pages::programs.index')
        ->assertDontSeeHtml('data-test="create-program-button"')
        ->call('openCreateModal')
        ->assertForbidden();

    $this->actingAs(User::factory()->role('Team Member')->create());

    expect(fn () => app(SaveProgram::class)->handle(null, ['name_en' => 'Sneaky']))
        ->toThrow(AuthorizationException::class)
        ->and(Program::query()->count())->toBe(0);
});
