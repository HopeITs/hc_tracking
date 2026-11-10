<?php

use App\Models\User;
use App\Support\Desk\DeskTab;

test('a page opened in a desk tab is drawn without its own frame', function () {
    $user = User::factory()->role('MEAL')->create();

    $this->actingAs($user)->get(route('admin.evidence-types.index', [DeskTab::Param => '1']))
        ->assertOk()
        ->assertSee('class="dk-in-tab"', false)
        ->assertSee('data-desk-tab-page', false)
        ->assertDontSee('data-side-nav', false)
        ->assertDontSee('data-desk-top-bar', false)
        ->assertDontSee('data-desk-ask-palette', false)
        ->assertDontSee('data-ask-index', false)
        ->assertDontSee('data-user-id=', false)
        ->assertDontSee('data-desk-footer', false)
        // The page itself is all there, with its own create button and "Add to desk".
        ->assertSee('data-quick-create', false)
        ->assertSee('data-add-to-desk', false);
});

test('a frame the browser loads (Sec-Fetch-Dest: iframe) is a desk tab too', function () {
    $this->actingAs(User::factory()->role('MEAL')->create())
        ->get(route('admin.evidence-types.index'), ['Sec-Fetch-Dest' => 'iframe'])
        ->assertOk()
        ->assertDontSee('data-side-nav', false)
        ->assertDontSee('data-desk-top-bar', false);
});

test('the same page on its own keeps its frame and the one live notification channel', function () {
    $user = User::factory()->role('MEAL')->create();

    $this->actingAs($user)->get(route('admin.evidence-types.index'))
        ->assertOk()
        ->assertDontSee('class="dk-in-tab"', false)
        ->assertSee('data-side-nav', false)
        ->assertSee('data-desk-top-bar', false)
        ->assertSee('data-desk-ask-palette', false)
        ->assertSee('data-ask-index', false)
        ->assertSee('data-user-id="'.$user->id.'"', false);
});

test('only my desk hosts tabs, and its sidebar lets pages be dragged or added onto it', function () {
    $user = User::factory()->role('MEAL')->create();

    $this->actingAs($user)->get(route('dashboard'))
        ->assertOk()
        ->assertSee('class="dk-tabs"', false)
        ->assertSee('data-desk-route="admin.evidence-types.index"', false)
        ->assertSee('data-side-add="admin.evidence-types.index"', false);

    $this->actingAs($user)->get(route('admin.evidence-types.index'))
        ->assertOk()
        ->assertDontSee('class="dk-tabs"', false)
        ->assertDontSee('data-side-add=', false);
});
