<?php

use App\Models\Activity;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;

/*
| Plain failures (M-05): an unexpected exception is still reported, with a
| reference id, but the person sees a short sentence and that id, never the
| exception text. 403, 404 and 419 are left to their own pages.
*/

beforeEach(function () {
    config(['app.debug' => false]);

    Route::middleware('web')->get('/__boom', function (): never {
        throw new RuntimeException('SQLSTATE secret detail in storage/app/private');
    });
});

test('a Livewire request that fails gets a plain message and a reference, not the exception text', function () {
    Log::spy();

    $response = $this->actingAs(userWithRole('Team Member'))
        ->withHeaders(['X-Livewire' => 'true', 'Accept' => 'application/json'])
        ->get('/__boom')
        ->assertStatus(500)
        ->assertJsonPath('message', __('errors.request_failed'))
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('storage/app');

    $reference = $response->headers->get('X-Error-Reference');

    expect($reference)->toMatch('/^[A-Z0-9]{8}$/')
        ->and($response->json('reference'))->toBe($reference);

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => str_contains($message, 'SQLSTATE secret')
        && ($context['error_reference'] ?? null) === $reference
        && ($context['exception'] ?? null) instanceof RuntimeException)->once();
});

test('a page request that fails renders the plain 500 page with the reference', function () {
    Log::spy();

    $response = $this->actingAs(userWithRole('Team Member'))
        ->get('/__boom')
        ->assertStatus(500)
        ->assertSee(__('errors.500_heading'))
        ->assertSee(__('errors.500_body'))
        ->assertDontSee('SQLSTATE')
        ->assertDontSee('storage/app');

    preg_match('/'.preg_quote(__('errors.reference', ['reference' => '']), '/').'\s*([A-Z0-9]{8})/', strip_tags($response->getContent()), $found);

    expect($found[1] ?? null)->not->toBeNull();

    Log::shouldHaveReceived('error')->withArgs(fn (string $message, array $context): bool => ($context['error_reference'] ?? null) === $found[1])->once();
});

test('the plain wording is in Arabic for an Arabic user', function () {
    $user = userWithRole('Team Member');
    $user->forceFill(['locale' => 'ar'])->save();

    $this->actingAs($user)
        ->get('/__boom')
        ->assertStatus(500)
        ->assertSee(trans('errors.500_heading', [], 'ar'))
        ->assertDontSee('SQLSTATE');

    expect(trans('errors.request_failed', [], 'ar'))->not->toBe('errors.request_failed');
});

test('with debug on the details are still shown', function () {
    config(['app.debug' => true]);

    $this->actingAs(userWithRole('Team Member'))
        ->get('/__boom')
        ->assertStatus(500)
        ->assertSee('SQLSTATE secret detail', false);
});

test('403, 404 and 419 keep their own pages and the 403 is still audited', function () {
    $this->get('/this-route-does-not-exist')->assertNotFound()->assertSee(__('errors.404_heading'));

    Route::middleware('web')->get('/__expired', fn () => abort(419));
    $this->get('/__expired')->assertStatus(419)->assertSee(__('errors.419_heading'));

    $manager = userWithRole('Team Member');

    $this->actingAs($manager)->get(route('activity.index'))
        ->assertForbidden()
        ->assertSee(__('errors.403_heading'))
        ->assertHeaderMissing('X-Error-Reference');

    expect(Activity::query()->where('event', 'user.access_denied')->count())->toBe(1);
});
