<?php

declare(strict_types=1);

use App\Models\AiRequest;
use App\Models\User;
use App\Notifications\MonthlyDigestMail;
use App\Support\Ai\Agents\DigestBriefer;

/*
| The AI paragraph on top of the monthly digest email (AI trial, step 7).
*/

beforeEach(function () {
    config(['ai.digest_briefing' => true]);
    $this->reader = User::factory()->create();
});

function digestWith(array $sections): MonthlyDigestMail
{
    return new MonthlyDigestMail('2026-10', true, $sections);
}

test('the digest opens with the AI paragraph, written from its counts', function () {
    DigestBriefer::fake(['Three donor reports are overdue; chase them first.']);

    $html = (string) digestWith(['donor_reports' => ['due' => 1, 'overdue' => 3]])->toMail($this->reader)->render();

    expect($html)->toContain('Three donor reports are overdue; chase them first.')
        ->toContain(__('notifications.mail.monthly_digest.briefing_note'));

    DigestBriefer::assertPrompted(fn ($prompt): bool => $prompt->contains(__('notifications.digests.monthly.lines.donor_reports_overdue').': 3'));
});

test('readers with the same counts share one paragraph', function () {
    DigestBriefer::fake(['Two indicators are off track.']);
    $sections = ['indicators' => ['off_track' => 2]];

    digestWith($sections)->toMail($this->reader)->render();
    digestWith($sections)->toMail(User::factory()->create())->render();

    DigestBriefer::assertPromptedTimes(1);
    expect(AiRequest::query()->where('feature', 'monthly-digest-briefing')->count())->toBe(1);
});

test('the mail still goes out without the paragraph when the model fails', function () {
    DigestBriefer::fake(fn () => throw new RuntimeException('Connection refused'));

    expect((string) digestWith(['indicators' => ['off_track' => 2]])->toMail($this->reader)->render())
        ->not->toContain(__('notifications.mail.monthly_digest.briefing_note'))
        ->toContain(__('notifications.digests.monthly.lines.indicators_off_track'));
});

test('with the briefing switched off the model is never asked', function () {
    config(['ai.digest_briefing' => false]);
    DigestBriefer::fake(['Should not appear.']);

    expect((string) digestWith(['projects' => ['started' => 1, 'ended' => 0]])->toMail($this->reader)->render())->not->toContain('Should not appear.');
    DigestBriefer::assertNeverPrompted();
});

test('a quiet month asks the model nothing', function () {
    DigestBriefer::fake();

    digestWith([])->toMail($this->reader)->render();

    DigestBriefer::assertNeverPrompted();
});
