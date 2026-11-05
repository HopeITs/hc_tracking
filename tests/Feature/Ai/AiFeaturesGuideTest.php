<?php

declare(strict_types=1);

/*
| The AI features guide, reached from the Help menu in the top bar: every
| AI feature with what, where, how and who, marked for the viewer's role.
*/

test('the help menu links to the AI features guide', function () {
    $this->actingAs(userWithRole('Team Member'))
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee(route('help.ai-features'))
        ->assertSeeText(__('navigation.ai_features'));
});

test('everyone signed in reads every AI feature; guests are sent to sign in', function () {
    $this->get(route('help.ai-features'))->assertRedirect(route('login'));

    $page = $this->actingAs(userWithRole('Team Member'))->get(route('help.ai-features'))->assertOk();

    foreach (['draft', 'rate', 'check', 'brief', 'forecast', 'digest', 'usage'] as $feature) {
        $page->assertSeeText(__('ai.guide.items.'.$feature.'.name'))
            ->assertSeeText(__('ai.guide.items.'.$feature.'.how'));
    }
});

test('each feature says whether the viewer can use it and links only to what they can open', function () {
    $this->actingAs(userWithRole('MEAL'))
        ->get(route('help.ai-features'))
        ->assertSeeHtml('data-ai-feature-guide="draft"')
        ->assertSee(route('monthly-reports.index'))
        ->assertDontSee(route('admin.ai-usage.index'));

    $this->actingAs(userWithRole('Team Member'))
        ->get(route('help.ai-features'))
        ->assertSeeText(__('ai.guide.not_yours'))
        ->assertDontSeeHtml('data-ai-go="draft"');

    $this->actingAs(userWithRole('General Manager'))
        ->get(route('help.ai-features'))
        ->assertSeeHtml('data-ai-go="usage"');
});

test('the guide reads in Arabic', function () {
    $user = userWithRole('MEAL');
    $user->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($user)
        ->get(route('help.ai-features'))
        ->assertOk()
        ->assertSeeText('ميزات الذكاء الاصطناعي');
});
