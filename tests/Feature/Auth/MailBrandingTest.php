<?php

use App\Http\UserAgent;
use App\Models\User;
use App\Notifications\EmailLoginCode;
use App\Notifications\NewDeviceSignIn;
use Carbon\CarbonImmutable;
use Symfony\Component\Mime\Email;

/**
 * Send the notification through the array mailer and return the email.
 */
function sentEmail(User $user, object $notification): Email
{
    config(['mail.default' => 'array']);

    $user->notifyNow($notification);

    return app('mailer')->getSymfonyTransport()->messages()->last()->getOriginalMessage();
}

test('the sign-in code email shows the code but keeps it out of the inbox preview', function () {
    $html = (string) (new EmailLoginCode('482913', 10))->toMail(User::factory()->make())->render();

    expect($html)
        ->toContain(__('security.login_code_heading'))
        ->toContain('482913')
        ->toContain(__('security.login_code_if_it_was_not_you'));

    preg_match('/<div class="preheader"[^>]*>(.*?)<\/div>/s', $html, $preheader);

    expect($preheader[1])
        ->toContain(__('security.login_code_preheader', ['minutes' => 10]))
        ->not->toContain('482913');
});

test('sent emails embed the logo instead of loading it from the web', function () {
    $user = User::factory()->create();

    foreach ([
        new EmailLoginCode('482913', 10),
        new NewDeviceSignIn(new UserAgent('Chrome', 'Windows'), '203.0.113.9', CarbonImmutable::now()),
    ] as $notification) {
        $email = sentEmail($user, $notification);

        expect($email->getHtmlBody())
            ->toMatch('/<img src="cid:[^"]+"[^>]*class="logo"/')
            ->not->toContain(asset('logo-tracking-mail.png'))
            ->and($email->getAttachments())->toHaveCount(1)
            ->and($email->getAttachments()[0]->getDisposition())->toBe('inline');
    }
});

test('the plain-text sign-in code email carries the code without markup', function () {
    $email = sentEmail(User::factory()->create(), new EmailLoginCode('482913', 10));

    expect($email->getTextBody())
        ->toContain('482913')
        ->toContain(__('security.login_code_expires', ['minutes' => 10]))
        ->not->toContain('<table')
        ->not->toContain('<p');
});
