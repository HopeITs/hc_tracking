<?php

use App\Enums\Locale;
use App\Enums\ProposalStatus;
use App\Enums\TaskStatus;
use App\Models\HrCity;
use App\Models\HrEmployee;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Arr;

/**
 * The keys of a lang group file, flattened to dot notation.
 *
 * @return array<string, mixed>
 */
function langKeys(string $locale, string $group): array
{
    return Arr::dot(require lang_path("{$locale}/{$group}.php"));
}

/**
 * Every lang group file name shipped in English.
 *
 * @return list<string>
 */
function langGroups(): array
{
    return array_map(fn (string $path): string => basename($path, '.php'), glob(lang_path('en/*.php')));
}

test('every english group has an arabic file with exactly the same keys', function () {
    foreach (langGroups() as $group) {
        expect(file_exists(lang_path("ar/{$group}.php")))->toBeTrue("lang/ar/{$group}.php is missing");

        $english = array_keys(array_filter(
            langKeys('en', $group),
            fn (string $key): bool => ! str_starts_with($key, 'attributes.'),
            ARRAY_FILTER_USE_KEY,
        ));
        $arabic = array_keys(array_filter(
            langKeys('ar', $group),
            fn (string $key): bool => ! str_starts_with($key, 'attributes.'),
            ARRAY_FILTER_USE_KEY,
        ));

        expect(array_values(array_diff($english, $arabic)))->toBe([], "keys missing from lang/ar/{$group}.php")
            ->and(array_values(array_diff($arabic, $english)))->toBe([], "extra keys in lang/ar/{$group}.php");
    }
});

test('arabic translations keep every placeholder of the english text', function () {
    foreach (langGroups() as $group) {
        $arabic = langKeys('ar', $group);

        foreach (langKeys('en', $group) as $key => $english) {
            if (! is_string($english) || ! isset($arabic[$key])) {
                continue;
            }

            preg_match_all('/:([a-z_]+)/i', $english, $needed);
            preg_match_all('/:([a-z_]+)/i', $arabic[$key], $present);

            expect(array_values(array_diff(array_unique($needed[1]), $present[1])))
                ->toBe([], "lang/ar/{$group}.php '{$key}' lost a placeholder");
        }
    }
});

test('every translation key used in the code exists in english and arabic', function () {
    $files = array_merge(
        glob(resource_path('views/**/*.blade.php')) ?: [],
        glob(resource_path('views/*/*/*.blade.php')) ?: [],
        glob(resource_path('views/*/*/*/*.blade.php')) ?: [],
        glob(app_path('**/*.php')) ?: [],
        glob(app_path('*/*/*.php')) ?: [],
        glob(app_path('*/*/*/*.php')) ?: [],
    );

    $keys = [];
    foreach (array_unique($files) as $file) {
        // A literal ending in "." is a prefix joined to a runtime value
        // (__('access.actions.'.$action)), not a key of its own.
        preg_match_all("/(?:__|trans_choice)\\('([a-z_]+\\.[a-z0-9_.]*[a-z0-9_])'/", file_get_contents($file), $matches);
        foreach ($matches[1] as $key) {
            $keys[$key] = true;
        }
    }

    expect(count($keys))->toBeGreaterThan(800);

    foreach (array_keys($keys) as $key) {
        foreach (Locale::values() as $locale) {
            expect(trans($key, [], $locale))->not->toBe($key, "{$key} is missing in {$locale}");
        }
    }
});

test('no lang group file shadows a plain word that flux translates', function () {
    $plainWords = ['results', 'of', 'to', 'files', 'showing'];

    expect(array_values(array_intersect(langGroups(), $plainWords)))->toBe([]);
});

test('switching language saves the choice on the user and in the session', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->from(route('dashboard'))
        ->post(route('locale.update'), ['locale' => 'ar'])
        ->assertRedirect(route('dashboard'))
        ->assertSessionHas('locale', 'ar');

    expect($user->refresh()->locale)->toBe('ar');
});

test('a guest can switch language for their session', function () {
    $this->from(route('login'))
        ->post(route('locale.update'), ['locale' => 'ar'])
        ->assertRedirect(route('login'))
        ->assertSessionHas('locale', 'ar');

    $this->get(route('login'))->assertSee('dir="rtl"', false);
});

test('switching language is limited per user, not shared with a colleague on the same ip', function () {
    $this->freezeTime();
    $user = User::factory()->create();
    $colleague = User::factory()->create();

    foreach (range(1, 10) as $attempt) {
        $this->actingAs($user)
            ->withServerVariables(['REMOTE_ADDR' => '10.0.7.1'])
            ->post(route('locale.update'), ['locale' => 'ar']);
    }

    $this->actingAs($user)
        ->withServerVariables(['REMOTE_ADDR' => '10.0.7.1'])
        ->post(route('locale.update'), ['locale' => 'en'])
        ->assertStatus(429);

    $this->actingAs($colleague)
        ->withServerVariables(['REMOTE_ADDR' => '10.0.7.1'])
        ->post(route('locale.update'), ['locale' => 'ar'])
        ->assertSessionHas('locale', 'ar');
});

test('only supported languages are accepted', function () {
    $user = User::factory()->create();

    $this->actingAs($user)
        ->post(route('locale.update'), ['locale' => 'fr'])
        ->assertSessionHasErrors('locale');

    expect($user->refresh()->locale)->toBeNull();
});

test('pages render right to left in arabic and left to right in english', function () {
    $user = User::factory()->create(['locale' => 'ar']);

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertOk()
        ->assertSee('<html lang="ar" dir="rtl"', false)
        ->assertSeeText('لوحة التحكم');

    $user->forceFill(['locale' => 'en'])->save();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee('<html lang="en" dir="ltr"', false)
        ->assertSeeText('Dashboard');
});

test('someone who has not chosen a language gets english', function () {
    $this->actingAs(User::factory()->create())
        ->get(route('dashboard'))
        ->assertSee('<html lang="en" dir="ltr"', false);
});

test('the sidebar offers the theme toggle and the other language', function () {
    $this->actingAs(User::factory()->create(['locale' => 'en']))
        ->get(route('dashboard'))
        ->assertSee('data-theme-toggle', false)
        ->assertSee('data-locale-toggle', false)
        ->assertSee('name="locale" value="ar"', false);

    $this->actingAs(User::factory()->create(['locale' => 'ar']))
        ->get(route('dashboard'))
        ->assertSee('name="locale" value="en"', false);
});

test('stored enum values stay english while their labels follow the language', function () {
    app()->setLocale('en');
    expect(TaskStatus::InProgress->label())->toBe('In Progress')
        ->and(ProposalStatus::ConceptNote->label())->toBe('Concept Note');

    app()->setLocale('ar');
    expect(TaskStatus::InProgress->label())->toBe('قيد التنفيذ')
        ->and(TaskStatus::InProgress->value)->toBe('in progress');
});

test('every enum case has an arabic label', function () {
    foreach (glob(app_path('Enums/*.php')) as $file) {
        $enum = 'App\\Enums\\'.basename($file, '.php');

        if (! method_exists($enum, 'label')) {
            continue;
        }

        foreach ($enum::cases() as $case) {
            app()->setLocale('ar');
            $label = $case->label();

            expect($label)->not->toStartWith('enums.', "{$enum}::{$case->name} has no label");
        }
    }
});

test('hr names show arabic first in arabic and english first in english', function () {
    $employee = HrEmployee::find(insertHrEmployee(firstNameEn: 'Sami', lastNameEn: 'Haddad', firstNameAr: 'سامي', lastNameAr: 'حداد'));
    $city = HrCity::find(insertHrCity('Aleppo', 'حلب'));

    app()->setLocale('en');
    expect($employee->name)->toBe('Sami Haddad')
        ->and($employee->secondary_name)->toBe('سامي حداد')
        ->and($city->name)->toBe('Aleppo');

    app()->setLocale('ar');
    expect($employee->name)->toBe('سامي حداد')
        ->and($employee->secondary_name)->toBe('Sami Haddad')
        ->and($city->name)->toBe('حلب')
        ->and($city->secondary_name)->toBe('Aleppo');
});

test('dates use syrian month names with western digits in arabic', function () {
    $user = User::factory()->create(['locale' => 'ar']);

    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect(CarbonImmutable::parse('2026-09-14')->translatedFormat(__('formats.date')))->toBe('14 أيلول 2026');

    $user->forceFill(['locale' => 'en'])->save();
    $this->actingAs($user)->get(route('dashboard'))->assertOk();

    expect(CarbonImmutable::parse('2026-09-14')->translatedFormat(__('formats.date')))->toBe('Sep 14, 2026');
});

test('blaze stays disabled so compiled views never bake in one language', function () {
    expect(config('blaze.enabled'))->toBeFalse();
});

test('a date is formatted with levantine month names without changing the caller locale', function () {
    $date = CarbonImmutable::parse('2026-09-05')->locale('en');

    $formatted = Locale::Arabic->formatDate($date, 'F Y');

    expect($formatted)->toBe('أيلول 2026')
        ->and($date->locale())->toBe('en');
});
