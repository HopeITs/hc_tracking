<?php

use App\Models\Program;
use App\Models\Strategy;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Livewire\Livewire;

test('guests are redirected from the strategy portfolio', function () {
    $this->get(route('strategies.index'))->assertRedirect(route('login'));
});

test('a current strategy says which year of how many it is in, not "strategic period"', function () {
    $user = User::factory()->role('General Manager')->create();
    Strategy::factory()->create(['name' => 'Five Year Strategy', 'start_year' => 2026, 'end_year' => 2030]);

    $response = Date::withTestNow(
        '2026-06-15',
        fn () => $this->actingAs($user)->get(route('strategies.index')),
    );

    $response
        ->assertOk()
        ->assertSee('Year <bdi>1</bdi> of <bdi>5</bdi> · ends <bdi>Dec 2030</bdi>', false)
        ->assertDontSeeText('strategic period');
});

test('authenticated users see active upcoming and completed strategies in planning order', function () {
    $user = User::factory()->role('General Manager')->create();
    Strategy::factory()->create([
        'name' => 'Completed country strategy',
        'start_year' => 2021,
        'end_year' => 2025,
    ]);
    Strategy::factory()->create([
        'name' => 'Upcoming country strategy',
        'start_year' => 2031,
        'end_year' => 2035,
    ]);
    $active = Strategy::factory()->create([
        'name' => 'Active country strategy',
        'start_year' => 2026,
        'end_year' => 2030,
    ]);

    $response = Date::withTestNow(
        '2026-06-15',
        fn () => $this->actingAs($user)->get(route('strategies.index')),
    );

    $response
        ->assertOk()
        ->assertSeeText('Strategies')
        ->assertSee(route('strategies.index'), false)
        ->assertSeeTextInOrder([
            __('strategies.current_strategies'),
            'Active country strategy',
            __('strategies.upcoming_strategies'),
            'Upcoming country strategy',
            __('strategies.past_strategies'),
            'Completed country strategy',
        ])
        ->assertSeeText('Active')
        ->assertSeeText('Upcoming')
        ->assertSeeText('Completed')
        ->assertSee(route('strategies.show', $active), false)
        ->assertSeeText('2031–2035')
        ->assertSeeText('2021–2025');
});

test('the current year on the year line is marked "Now" in words, not by colour alone', function () {
    $user = User::factory()->role('General Manager')->create();
    $strategy = Strategy::factory()->create(['start_year' => 2026, 'end_year' => 2030]);
    Program::factory()->create(['strategy_id' => $strategy->id]);

    $response = Date::withTestNow(
        '2026-06-15',
        fn () => $this->actingAs($user)->get(route('strategies.index')),
    );

    $html = $response->assertOk()->getContent();

    expect(substr_count($html, 'aria-current="step"'))->toBe(1)
        ->and($html)->toContain('<span class="dk-yl-now">'.__('strategies.now').'</span>')
        ->and(substr_count($html, 'class="dk-done"'))->toBe(0);
});

test('the strategy portfolio search narrows the results', function () {
    $user = User::factory()->role('General Manager')->create();
    Strategy::factory()->create(['name' => 'Water Security Strategy', 'start_year' => 2026, 'end_year' => 2030]);
    Strategy::factory()->create(['name' => 'Shelter Strategy', 'start_year' => 2026, 'end_year' => 2030]);

    $this->actingAs($user)
        ->get(route('strategies.index', ['search' => 'Water']))
        ->assertOk()
        ->assertSeeText('Water Security Strategy')
        ->assertDontSeeText('Shelter Strategy');
});

test('the search box appears only once there are more than six strategies', function () {
    $user = User::factory()->role('General Manager')->create();
    Strategy::factory()->count(6)->create();

    $this->actingAs($user)
        ->get(route('strategies.index'))
        ->assertOk()
        ->assertDontSee('id="strategy-search"', false);

    Strategy::factory()->create();

    $this->actingAs($user)
        ->get(route('strategies.index'))
        ->assertOk()
        ->assertSee('id="strategy-search"', false);
});

test('the strategy portfolio status strip filters the results and counts correctly', function () {
    $user = User::factory()->role('General Manager')->create();
    Strategy::factory()->create(['name' => 'Active strategy', 'start_year' => 2026, 'end_year' => 2030]);
    Strategy::factory()->create(['name' => 'Upcoming strategy', 'start_year' => 2031, 'end_year' => 2035]);

    $response = Date::withTestNow(
        '2026-06-15',
        fn () => $this->actingAs($user)->get(route('strategies.index', ['status' => 'upcoming'])),
    );

    $response
        ->assertOk()
        ->assertSeeText('Upcoming strategy')
        ->assertDontSeeText('Active strategy')
        ->assertSee('aria-pressed="true" data-test="strategy-tab-upcoming"', false)
        ->assertSee(__('strategies.tab_all').' <span class="dk-num">2</span>', false)
        ->assertSee(__('strategies.upcoming').' <span class="dk-num">1</span>', false);
});

test('the Upcoming tab shows only the upcoming section', function () {
    $user = User::factory()->role('General Manager')->create();
    Strategy::factory()->create(['name' => 'Active strategy', 'start_year' => 2026, 'end_year' => 2030]);
    Strategy::factory()->create(['name' => 'Upcoming strategy', 'start_year' => 2031, 'end_year' => 2035]);
    Strategy::factory()->create(['name' => 'Past strategy', 'start_year' => 2016, 'end_year' => 2020]);

    Date::withTestNow('2026-06-15', function () use ($user) {
        Livewire::actingAs($user)
            ->test('pages::strategies.index')
            ->call('filterByStatus', 'upcoming')
            ->assertSeeHtml('data-test="upcoming-strategies"')
            ->assertDontSeeHtml('data-test="current-strategies"')
            ->assertDontSeeHtml('data-test="past-strategies"')
            ->assertSeeText('Upcoming strategy')
            ->assertDontSeeText('Active strategy')
            ->assertDontSeeText('Past strategy');
    });
});

test('the status tabs filter the list and ignore an unknown status', function () {
    $user = User::factory()->role('General Manager')->create();
    Strategy::factory()->create(['name' => 'Active strategy', 'start_year' => 2026, 'end_year' => 2030]);
    Strategy::factory()->create(['name' => 'Upcoming strategy', 'start_year' => 2031, 'end_year' => 2035]);

    Date::withTestNow('2026-06-15', function () use ($user) {
        Livewire::actingAs($user)
            ->test('pages::strategies.index')
            ->call('filterByStatus', 'upcoming')
            ->assertSet('status', 'upcoming')
            ->assertSeeText('Upcoming strategy')
            ->assertDontSeeText('Active strategy')
            // Anything outside the three known statuses falls back to "all".
            ->call('filterByStatus', 'not-a-status')
            ->assertSet('status', '')
            ->assertSeeText('Active strategy')
            ->assertSeeText('Upcoming strategy');
    });
});

test('the past strategies list paginates at 15 rows', function () {
    $user = User::factory()->role('General Manager')->create();
    Strategy::factory()->count(17)->sequence(fn ($sequence) => [
        'name' => 'Strategy '.str_pad((string) ($sequence->index + 1), 2, '0', STR_PAD_LEFT),
        'start_year' => 2000 + $sequence->index,
        'end_year' => 2001 + $sequence->index,
    ])->create();

    // All 17 are past and sort by end year, latest first, so "Strategy 01"
    // (the earliest) ranks last and falls on page 2.
    $this->actingAs($user)
        ->get(route('strategies.index'))
        ->assertOk()
        ->assertSee(__('strategies.tab_past').' <span class="dk-num">17</span>', false)
        ->assertSeeText('Strategy 17')
        ->assertDontSeeText('Strategy 01')
        ->assertSeeText('Showing 1 to 15 of 17 results');

    $this->actingAs($user)
        ->get(route('strategies.index', ['strategiesPage' => 2]))
        ->assertOk()
        ->assertSeeText('Strategy 01')
        ->assertSeeText('Showing 16 to 17 of 17 results');
});

test('a current strategy links to its page and to its programs, with their count', function () {
    $user = User::factory()->role('General Manager')->create();
    $strategy = Strategy::factory()->create(['name' => 'Water Security Strategy', 'start_year' => 2026, 'end_year' => 2030]);
    Program::factory()->create(['strategy_id' => $strategy->id]);
    Program::factory()->create(['strategy_id' => $strategy->id]);

    $response = Date::withTestNow(
        '2026-06-15',
        fn () => $this->actingAs($user)->get(route('strategies.index')),
    );

    $response
        ->assertOk()
        ->assertSee(route('strategies.show', $strategy), false)
        ->assertSeeText(__('strategies.open_strategy'))
        ->assertSee('href="'.e(route('programs.index', ['strategy' => $strategy->id])).'" wire:navigate.hover aria-label="'.trans_choice('strategies.programs_link_label', 2, ['count' => 2]).'" data-test="strategy-programs-link">2</a>', false);
});

test('several active strategies each get their own block with their year', function () {
    $user = User::factory()->role('General Manager')->create();
    $country = Strategy::factory()->create(['name' => 'Country Strategy', 'start_year' => 2025, 'end_year' => 2029]);
    $regional = Strategy::factory()->create(['name' => 'Regional Strategy', 'start_year' => 2026, 'end_year' => 2028]);

    Date::withTestNow('2026-06-15', function () use ($user, $country, $regional) {
        $html = Livewire::actingAs($user)->test('pages::strategies.index')->html();

        foreach ([[$country, 2, 5], [$regional, 1, 3]] as [$strategy, $year, $total]) {
            $block = str($html)->after('data-test="current-strategy-'.$strategy->id.'"')->before('</article>')->toString();

            expect($block)
                ->toContain($strategy->name)
                ->toContain('Year <bdi>'.$year.'</bdi> of <bdi>'.$total.'</bdi>');
        }
    });
});

test('with no active strategy the page says when the last one ended and offers to start the next', function () {
    Strategy::factory()->create(['start_year' => 2019, 'end_year' => 2023]);
    Strategy::factory()->create(['start_year' => 2021, 'end_year' => 2025]);

    $response = Date::withTestNow(
        '2026-01-01',
        fn () => $this->actingAs(User::factory()->role('General Manager')->create())->get(route('strategies.index')),
    );

    $response
        ->assertOk()
        ->assertSeeText(__('strategies.no_active_strategy'))
        ->assertSee(__('strategies.last_one_ended', ['date' => '<bdi>Dec 2025</bdi>']), false)
        ->assertSee('data-test="start-next-strategy"', false)
        ->assertSeeText(__('strategies.start_next_strategy'));

    $teamView = Date::withTestNow(
        '2026-01-01',
        fn () => $this->actingAs(User::factory()->role('Team Member')->create())->get(route('strategies.index')),
    );

    $teamView->assertOk()
        ->assertSeeText(__('strategies.no_active_strategy'))
        ->assertDontSee('data-test="start-next-strategy"', false);
});

test('a strategy remains active through its ending year', function () {
    $user = User::factory()->role('General Manager')->create();
    $strategy = Strategy::factory()->create([
        'name' => 'Final-year country strategy',
        'start_year' => 2022,
        'end_year' => 2026,
    ]);

    $response = Date::withTestNow(
        '2026-12-31',
        fn () => $this->actingAs($user)->get(route('strategies.index')),
    );

    $response
        ->assertOk()
        ->assertSee('data-test="current-strategy-'.$strategy->id.'"', false)
        ->assertSee('Year <bdi>5</bdi> of <bdi>5</bdi>', false)
        ->assertDontSeeText(__('strategies.no_active_strategy'));
});

test('an upcoming strategy says when it starts and how many programs are ready', function () {
    $user = User::factory()->role('General Manager')->create();
    $strategy = Strategy::factory()->create(['name' => 'Next Strategy', 'start_year' => 2027, 'end_year' => 2031]);
    Strategy::factory()->create(['name' => 'Empty Strategy', 'start_year' => 2028, 'end_year' => 2032]);
    Program::factory()->count(2)->create(['strategy_id' => $strategy->id]);

    $response = Date::withTestNow(
        '2026-06-15',
        fn () => $this->actingAs($user)->get(route('strategies.index')),
    );

    $response
        ->assertOk()
        ->assertSeeText(__('strategies.starts_on', ['date' => 'Jan 2027']).' · '.trans_choice('strategies.programs_ready', 2, ['planned' => 0, 'total' => 2]))
        ->assertSeeText(__('strategies.starts_on', ['date' => 'Jan 2028']).' · '.__('strategies.no_programs_linked_yet'));
});

test('the strategy portfolio has a clear empty state', function () {
    $user = User::factory()->role('General Manager')->create();

    $this->actingAs($user)
        ->get(route('strategies.index'))
        ->assertOk()
        ->assertSeeText('No strategic periods yet')
        // With no strategies at all, "no active strategy" is meaningless noise.
        ->assertDontSeeText(__('strategies.no_active_strategy'));
});

test('strategy names are escaped in the portfolio', function () {
    $user = User::factory()->role('General Manager')->create();
    $unsafeName = '<script>alert("strategy")</script>';
    Strategy::factory()->create(['name' => $unsafeName]);

    $this->actingAs($user)
        ->get(route('strategies.index'))
        ->assertOk()
        ->assertSee($unsafeName)
        ->assertDontSee($unsafeName, false);
});

/*
| Page review, 6 Nov 2026: Country Strategy 2026-2030 had no programs and no
| pillars, yet showed a five-year line, three zeros, "No targets have progress
| reported yet" and "Nothing needs your attention right now".
*/

test('a current strategy with no programs says it is not set up instead of showing zeros and "nothing needs attention"', function () {
    $strategy = Strategy::factory()->create(['name' => 'Empty Strategy', 'start_year' => 2026, 'end_year' => 2030]);

    Date::withTestNow('2026-06-15', function () use ($strategy) {
        $viewer = Livewire::actingAs(User::factory()->role('Team Member')->create())->test('pages::strategies.index')->html();
        $block = str($viewer)->after('data-test="current-strategy-'.$strategy->id.'"')->before('</article>')->toString();

        expect($block)
            ->toContain('data-test="strategy-not-set-up"')
            ->toContain(__('strategies.not_set_up'))
            ->toContain(__('strategies.not_set_up_viewer'))
            ->not->toContain(__('strategies.nothing_needs_attention'))
            ->not->toContain(__('strategies.verdict_none'))
            ->not->toContain('aria-current="step"')
            ->not->toContain('data-test="strategy-programs-link"')
            ->not->toContain('openProgramsModal');

        $editor = Livewire::actingAs(User::factory()->role('General Manager')->create())->test('pages::strategies.index')->html();

        expect(str($editor)->after('data-test="current-strategy-'.$strategy->id.'"')->before('</article>')->toString())
            ->toContain('wire:click="openProgramsModal('.$strategy->id.')" data-test="strategy-empty-link-programs"')
            ->not->toContain(__('strategies.not_set_up_viewer'));
    });
});

test('an empty tab says what is missing, without blaming a search or offering to reset filters', function () {
    Strategy::factory()->create(['start_year' => 2021, 'end_year' => 2025]);

    Date::withTestNow('2026-06-15', fn () => $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.index', ['status' => 'upcoming']))
        ->assertOk()
        ->assertSeeText(__('strategies.empty_tab.upcoming'))
        ->assertDontSeeText(__('strategies.no_matching_strategic_periods'))
        ->assertDontSeeText(__('common.reset_filters')));
});

test('a single page of past strategies shows no pager', function () {
    Strategy::factory()->create(['name' => 'Old Strategy', 'start_year' => 2021, 'end_year' => 2025]);

    Date::withTestNow('2026-06-15', fn () => $this->actingAs(User::factory()->role('General Manager')->create())
        ->get(route('strategies.index'))
        ->assertOk()
        ->assertSeeText('Old Strategy')
        ->assertDontSeeText('Showing 1 to 1 of 1 results'));
});
