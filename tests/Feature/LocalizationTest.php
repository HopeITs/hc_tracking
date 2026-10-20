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

test('arabic plural strings use the explicit ranges', function () {
    $allowed = [
        ['{0}', '{1}', '{2}', '[3,10]', '[11,*]'],
        ['{1}', '{2}', '[3,10]', '[11,*]'],
    ];

    $offPattern = [];
    foreach (langGroups() as $group) {
        foreach (langKeys('ar', $group) as $key => $text) {
            if (! is_string($text) || ! str_contains($text, '|')) {
                continue;
            }

            $heads = array_map(
                fn (string $segment): string => preg_match('/^(\{\d+\}|\[[^\]]+\])/', $segment, $range) === 1 ? $range[1] : '-',
                explode('|', $text),
            );

            if (! in_array($heads, $allowed, true)) {
                $offPattern[] = "{$group}.{$key}";
            }
        }
    }

    expect($offPattern)->toBe([]);
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
        ->assertSeeText('خصّص المكتب');

    $user->forceFill(['locale' => 'en'])->save();

    $this->actingAs($user)
        ->get(route('dashboard'))
        ->assertSee('<html lang="en" dir="ltr"', false)
        ->assertSeeText('Customize desk');
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

/**
 * Blade views under resources/views that have been swept against the T26 UI
 * checklist. A sweep appends its task's files (or whole folders, written with a
 * trailing slash) here; the first sweeps found the whole tree clean, so every
 * top-level folder is listed and new views are covered from the start; both view guards below read this one list.
 *
 * @return list<string> paths relative to resources/views
 */
function localizedViewFiles(): array
{
    $entries = [
        'components/',
        'errors/',
        'layouts/',
        'mail/',
        'partials/',
        'notifications/',
        'pages/',
    ];

    $files = [];
    foreach ($entries as $entry) {
        if (! str_ends_with($entry, '/')) {
            $files[] = $entry;

            continue;
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator(resource_path("views/{$entry}"), FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen(resource_path('views/'))));
            }
        }
    }

    sort($files);

    return array_values(array_unique($files));
}

/**
 * Physical direction utilities found in a view, as whole class tokens.
 * A centred `left-1/2` paired with `-translate-x-1/2` is symmetric and allowed.
 *
 * @return list<string>
 */
function physicalDirectionClasses(string $source): array
{

    preg_match_all('/(?<![\w-])(?:-?(?:ml|mr|pl|pr)-\S+|(?:left|right)-(?:\d|\[|px(?![\w-])|full(?![\w-])|auto(?![\w-]))[\w\/\[\]\.%-]*|text-(?:left|right)|rounded-(?:l|r|tl|tr|bl|br)(?:-\S+)?|border-(?:l|r)(?:-\S+)?)(?![\w-])/', $source, $matches);

    $found = [];
    foreach ($matches[0] as $token) {
        if ($token === 'left-1/2' && str_contains($source, '-translate-x-1/2')) {
            continue;
        }

        $found[] = $token;
    }

    return array_values(array_unique($found));
}

/**
 * Reviewed literal values that are not translatable UI text: format examples
 * and codes shown as input hints.
 *
 * @return list<string>
 */
function literalUiTextAllowlist(): array
{
    return ['OP1.2', 'https://', 'email@example.com'];
}

/**
 * Literal UI text in a Blade view: text nodes and literal label-like
 * attributes outside echoes, directives, PHP blocks, scripts and styles.
 *
 * @return list<string>
 */
function literalUiText(string $source): array
{
    $attributes = ['label', 'placeholder', 'title', 'aria-label', 'heading', 'description', 'alt'];

    $source = preg_replace('/\{\{--.*?--\}\}/s', '', $source) ?? '';
    $source = preg_replace('/@php\b.*?@endphp/s', '', $source) ?? '';
    $source = preg_replace('/<\?php.*?\?>/s', '', $source) ?? '';
    $source = preg_replace('/<(script|style)\b.*?<\/\1>/is', '', $source) ?? '';
    $source = preg_replace('/<!--.*?-->/s', '', $source) ?? '';
    $source = preg_replace('/<!doctype[^>]*>/i', '', $source) ?? '';
    $source = preg_replace('/&#?\w+;/', '', $source) ?? '';
    $source = preg_replace('/\{\{.*?\}\}|\{!!.*?!!\}/s', '§', $source) ?? '';
    $source = preg_replace('/@\w+\s*(\((?:[^()]++|(?1))*\))/s', '', $source) ?? '';
    $source = preg_replace('/@(?:else|endif|endforeach|endforelse|empty|endisset|endcan|endcannot|endunless|endfor|endwhile|endonce|endsection|endprops)(?![\w-])/', '', $source) ?? '';

    $found = [];

    $tag = '/<\/?[A-Za-z§][\w:.\-§]*(?:"[^"]*"|\'[^\']*\'|[^>"\'])*>/su';
    preg_match_all($tag, $source, $tags);
    foreach ($tags[0] as $element) {
        foreach ($attributes as $attribute) {
            if (preg_match_all('/\s'.preg_quote($attribute, '/').'="([^"]*)"/', $element, $values)) {
                foreach ($values[1] as $value) {
                    $value = preg_replace('/\((?:Ctrl|Shift|Alt|Cmd|Tab|Esc|Enter)[^)]*\)/', '', $value) ?? $value;
                    if (preg_match('/\p{L}/u', $value) === 1 && ! in_array($value, literalUiTextAllowlist(), true)) {
                        $found[] = "{$attribute}=\"{$value}\"";
                    }
                }
            }
        }
    }

    $text = preg_replace($tag, "\n", $source) ?? '';
    foreach (preg_split('/\R/', $text) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '@') || str_starts_with($line, '//') || str_starts_with($line, '*')) {
            continue;
        }
        if (preg_match('/\p{L}/u', $line) === 1) {
            $found[] = $line;
        }
    }

    return array_values(array_unique($found));
}

test('listed views use logical direction classes only', function () {
    $violations = [];
    foreach (localizedViewFiles() as $file) {
        expect(file_exists(resource_path("views/{$file}")))->toBeTrue("{$file} does not exist");

        // The Gantt keeps its timeline in left-to-right pixel space on purpose
        // (header comment of components/ui/gantt.blade.php); the one reviewed exemption.
        if ($file === 'components/ui/gantt.blade.php') {
            continue;
        }

        $found = physicalDirectionClasses(file_get_contents(resource_path("views/{$file}")));
        if ($found !== []) {
            $violations[$file] = $found;
        }
    }

    expect($violations)->toBe([]);
});

test('listed views contain no literal UI text', function () {
    $violations = [];
    foreach (localizedViewFiles() as $file) {
        $found = literalUiText(file_get_contents(resource_path("views/{$file}")));
        if ($found !== []) {
            $violations[$file] = $found;
        }
    }

    expect($violations)->toBe([]);
});
