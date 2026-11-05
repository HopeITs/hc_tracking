<?php

declare(strict_types=1);

use App\Enums\AiDraftRating;
use App\Models\AiDraft;
use App\Models\AiRequest;
use App\Models\MonthlyReportAnswer;
use App\Support\Ai\AiTrialStats;
use Livewire\Livewire;

/*
| The AI results page (AI trial): usage, reliability, speed and what
| officers said about the drafts, for holders of ai.view-usage.
*/

test('only holders of ai.view-usage open the AI results page', function () {
    $this->get(route('admin.ai-usage.index'))->assertRedirect(route('login'));

    $this->actingAs(userWithRole('MEAL'))->get(route('admin.ai-usage.index'))->assertForbidden();
    $this->actingAs(userWithRole('General Manager'))->get(route('admin.ai-usage.index'))->assertOk()->assertSeeText(__('ai.usage.title'));
});

test('the page shows usage, reliability, typical speed and the officers\' verdict', function () {
    $gm = userWithRole('General Manager');
    $officer = userWithRole('MEAL');

    AiRequest::factory()->create(['user_id' => $officer->id, 'feature' => 'monthly-report-narrative', 'duration_ms' => 12000]);
    AiRequest::factory()->create(['user_id' => $officer->id, 'feature' => 'project-12-summary', 'duration_ms' => 4000]);
    AiRequest::factory()->create(['user_id' => $gm->id, 'feature' => 'project-30-summary', 'duration_ms' => 6000]);
    AiRequest::factory()->failed()->create(['feature' => 'monthly-report-narrative']);

    $kept = MonthlyReportAnswer::factory()->create(['answer' => 'Sessions ran as planned.']);
    AiDraft::factory()->create(['monthly_report_answer_id' => $kept->id, 'drafted_text' => 'Sessions ran as planned.', 'rating' => AiDraftRating::Helpful]);
    AiDraft::factory()->create(['drafted_text' => 'Something else.', 'rating' => AiDraftRating::NotHelpful]);
    AiDraft::factory()->create(['drafted_text' => 'Not rated yet.']);

    Livewire::actingAs($gm)
        ->test('pages::admin.ai-usage')
        ->assertSeeHtml('data-ai-figure="calls">4<')
        ->assertSeeHtml('data-ai-figure="success">75%<')
        ->assertSeeHtml('data-ai-figure="speed">'.__('ai.usage.seconds', ['seconds' => 6]).'<')
        ->assertSeeHtml('data-ai-figure="helpful">50%<')
        ->assertSeeText(__('ai.usage.drafts_summary', ['made' => 3, 'rated' => 2]))
        ->assertSeeText(__('ai.usage.features.project-summary'))
        ->assertSeeHtml('data-ai-feature="project-summary"');
});

test('the last 30 days leave out older use; all time keeps it', function () {
    $gm = userWithRole('General Manager');
    AiRequest::factory()->create(['created_at' => now()->subDays(45)]);
    AiRequest::factory()->create();

    Livewire::actingAs($gm)
        ->test('pages::admin.ai-usage')
        ->assertSeeHtml('data-ai-figure="calls">1<')
        ->set('period', 'all')
        ->assertSeeHtml('data-ai-figure="calls">2<');
});

test('an empty trial says so instead of showing zeros', function () {
    Livewire::actingAs(userWithRole('General Manager'))
        ->test('pages::admin.ai-usage')
        ->assertSeeText(__('ai.usage.empty'));
});

test('record summaries report under one name per record type', function (string $feature, string $group) {
    expect(AiTrialStats::featureGroup($feature))->toBe($group);
})->with([
    ['project-12-summary', 'project-summary'],
    ['proposal-7-summary', 'proposal-summary'],
    ['monthly-report-narrative', 'monthly-report-narrative'],
]);
