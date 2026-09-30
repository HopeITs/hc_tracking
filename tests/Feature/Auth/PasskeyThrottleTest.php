<?php

test('one ip is limited to 60 passkey requests a minute, however often it drops its session', function () {
    $this->freezeTime();

    // No cookie is sent back, so every request arrives with a brand-new session.
    foreach (range(1, 60) as $attempt) {
        $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.1'])
            ->getJson(route('passkey.login-options'))
            ->assertOk();
    }

    $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.1'])
        ->getJson(route('passkey.login-options'))
        ->assertTooManyRequests();

    $this->withServerVariables(['REMOTE_ADDR' => '10.20.0.2'])
        ->getJson(route('passkey.login-options'))
        ->assertOk();
});
