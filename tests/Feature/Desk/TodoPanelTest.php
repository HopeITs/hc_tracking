<?php

use App\Models\User;
use App\Models\UserTodo;
use App\Support\Desk\Panels;
use Livewire\Livewire;

/**
 * The viewer's to-dos as the panel lists them: open ones in their order, then done ones.
 *
 * @return list<string>
 */
function todoTitles(User $user): array
{
    return $user->todos()->ordered()->pluck('title')->all();
}

test('the owner adds a to-do at the end of the open list, trimmed', function () {
    $user = User::factory()->create();
    UserTodo::factory()->for($user)->create(['title' => 'Call the donor', 'sort_order' => 0]);

    Livewire::actingAs($user)
        ->test('desk.panels.todo')
        ->call('add', '  Send the report  ')
        ->assertHasNoErrors()
        ->assertSee('Send the report');

    expect(todoTitles($user))->toBe(['Call the donor', 'Send the report']);
});

test('a to-do must hold 1 to 200 characters', function (string $title, string $message) {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('desk.panels.todo')
        ->call('add', $title)
        ->assertHasErrors(['title'])
        ->assertSee($message);

    expect($user->todos()->count())->toBe(0);
})->with([
    'empty' => ['', 'Write the to-do first.'],
    'only spaces' => ['    ', 'Write the to-do first.'],
    'too long' => [str_repeat('a', 201), 'A to-do can hold at most 200 characters.'],
]);

test('a to-do of exactly 200 characters is kept', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)->test('desk.panels.todo')->call('add', str_repeat('a', 200))->assertHasNoErrors();

    expect($user->todos()->count())->toBe(1);
});

test('a list holds at most MaxItems to-dos', function () {
    $user = User::factory()->create();
    UserTodo::factory()->for($user)->count(100)->create();

    Livewire::actingAs($user)
        ->test('desk.panels.todo')
        ->call('add', 'One more')
        ->assertHasErrors(['title'])
        ->assertSee('Your list is full: clear some to-dos first.');

    expect($user->todos()->count())->toBe(100);
});

test('ticking a to-do marks it done and sinks it below the open ones; ticking again brings it back last', function () {
    $this->travelTo('2026-11-04 10:00:00');
    $user = User::factory()->create();
    $first = UserTodo::factory()->for($user)->create(['title' => 'First', 'sort_order' => 0]);
    UserTodo::factory()->for($user)->create(['title' => 'Second', 'sort_order' => 1]);
    $panel = Livewire::actingAs($user)->test('desk.panels.todo');

    $panel->call('toggle', $first->id);

    expect($first->fresh()->is_done)->toBeTrue()
        ->and($first->fresh()->done_at?->toDateTimeString())->toBe('2026-11-04 10:00:00')
        ->and(todoTitles($user))->toBe(['Second', 'First']);

    $panel->call('toggle', $first->id);

    expect($first->fresh()->is_done)->toBeFalse()
        ->and($first->fresh()->done_at)->toBeNull()
        ->and(todoTitles($user))->toBe(['Second', 'First']);
});

test('the owner removes a to-do', function () {
    $user = User::factory()->create();
    $todo = UserTodo::factory()->for($user)->create();

    Livewire::actingAs($user)->test('desk.panels.todo')->call('remove', $todo->id);

    expect(UserTodo::query()->find($todo->id))->toBeNull();
});

test('reorder puts the open to-dos in the given order and ignores done and unknown ids', function () {
    $user = User::factory()->create();
    [$a, $b, $c] = UserTodo::factory()->for($user)->count(3)->sequence(
        ['title' => 'A', 'sort_order' => 0],
        ['title' => 'B', 'sort_order' => 1],
        ['title' => 'C', 'sort_order' => 2],
    )->create();
    $done = UserTodo::factory()->for($user)->done()->create(['title' => 'Done']);

    Livewire::actingAs($user)->test('desk.panels.todo')->call('reorder', [$c->id, $done->id, 999999, $a->id]);

    expect(todoTitles($user))->toBe(['C', 'A', 'B', 'Done']);
});

test('dragging a to-do moves it to the dropped position', function () {
    $user = User::factory()->create();
    [$a] = UserTodo::factory()->for($user)->count(3)->sequence(
        ['title' => 'A', 'sort_order' => 0],
        ['title' => 'B', 'sort_order' => 1],
        ['title' => 'C', 'sort_order' => 2],
    )->create();

    Livewire::actingAs($user)->test('desk.panels.todo')->call('move', $a->id, 2);

    expect(todoTitles($user))->toBe(['B', 'C', 'A']);
});

test('clear done removes only the owner\'s done to-dos', function () {
    $user = User::factory()->create();
    UserTodo::factory()->for($user)->create(['title' => 'Open']);
    UserTodo::factory()->for($user)->done()->count(2)->create();
    $othersDone = UserTodo::factory()->done()->create();

    Livewire::actingAs($user)->test('desk.panels.todo')->call('clearDone');

    expect(todoTitles($user))->toBe(['Open'])
        ->and($othersDone->fresh())->not->toBeNull();
});

test('another user\'s to-do cannot be ticked, removed, moved or reordered', function (string $method) {
    $owner = User::factory()->create();
    $todo = UserTodo::factory()->for($owner)->create(['title' => 'Mine', 'sort_order' => 0]);
    UserTodo::factory()->for($owner)->create(['title' => 'Also mine', 'sort_order' => 1]);
    $intruder = User::factory()->create();

    $panel = Livewire::actingAs($intruder)->test('desk.panels.todo');

    match ($method) {
        'reorder' => $panel->call('reorder', [$owner->todos()->latest('id')->value('id'), $todo->id]),
        'move' => $panel->call('move', $todo->id, 1)->assertNotFound(),
        default => $panel->call($method, $todo->id)->assertNotFound(),
    };

    expect($todo->fresh())->not->toBeNull()
        ->and($todo->fresh()->is_done)->toBeFalse()
        ->and(todoTitles($owner))->toBe(['Mine', 'Also mine']);
})->with(['toggle', 'remove', 'move', 'reorder']);

test('the panel lists only the viewer\'s to-dos and counts the open ones in its header', function () {
    $user = User::factory()->create();
    UserTodo::factory()->for($user)->count(2)->sequence(['title' => 'Buy paper'], ['title' => 'Book the hall'])->create();
    UserTodo::factory()->for($user)->done()->create(['title' => 'Old one']);
    UserTodo::factory()->create(['title' => 'Someone else\'s']);

    Livewire::actingAs($user)
        ->test('desk.panels.todo')
        ->assertSee(['Buy paper', 'Book the hall', 'Old one'])
        ->assertDontSee('Someone else\'s')
        ->assertSeeHtml('<span class="dk-pc dk-num" data-panel-count>2</span>')
        ->assertSeeHtml('role="checkbox" aria-checked="true"')
        ->assertSee(__('desk-cards.todo.clear_done'));
});

test('an empty list says so and focuses the input', function () {
    Livewire::actingAs(User::factory()->create())
        ->test('desk.panels.todo')
        ->assertSee(__('desk-cards.todo.empty'))
        ->assertSeeHtml('data-todo-autofocus')
        ->assertDontSee(__('desk-cards.todo.clear_done'));
});

test('a deleted user takes their to-dos with them', function () {
    $todo = UserTodo::factory()->create();

    $todo->user->delete();

    expect(UserTodo::query()->find($todo->id))->toBeNull();
});

test('the to-do panel is open to everyone but in no starting or ready layout', function (string $role) {
    $user = User::factory()->role($role)->create();
    $panels = app(Panels::class);

    expect($panels->availableTo($user))->toHaveKey('todo')
        ->and(array_column($panels->layoutFor($user), 'panel'))->not->toContain('todo')
        ->and(collect($panels->readyCatalogue())->flatMap(fn (array $ready): array => array_merge(...$ready['rows']))->pluck(0))->not->toContain('todo');
})->with(['Team Member', 'General Manager', 'MEAL', 'Financial', 'Managers', 'Media Team', 'Public Relations', 'Public Relations Manager']);

test('the to-do panel is a small card', function () {
    $panels = app(Panels::class);

    expect($panels->availableTo(User::factory()->create())['todo']['size'])->toBe('S')
        ->and($panels->rowsOf('todo'))->toBe(4)
        ->and($panels->iconOf('todo'))->toBe('check');
});
