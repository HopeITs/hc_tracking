<?php

use App\Models\User;
use Illuminate\Support\Facades\File;
use Livewire\Livewire;

test('guests are redirected away from the employee picker options', function () {
    $this->getJson(route('employees.picker-options'))->assertUnauthorized();
});

test('the employee picker options are sorted for the interface language and cached by the browser', function () {
    $user = User::factory()->role('General Manager')->create();
    $zed = insertHrEmployee(firstNameEn: 'Zed', lastNameEn: 'Adams', firstNameAr: 'أحمد', lastNameAr: 'علي');
    $amy = insertHrEmployee(firstNameEn: 'Amy', lastNameEn: 'Zane', firstNameAr: 'يوسف', lastNameAr: 'علي');

    $english = $this->actingAs($user)
        ->withSession(['locale' => 'en'])
        ->getJson(route('employees.picker-options', ['l' => 'en']))
        ->assertOk()
        ->assertHeader('Cache-Control', 'max-age=300, private');

    $arabic = $this->actingAs($user)
        ->withSession(['locale' => 'ar'])
        ->getJson(route('employees.picker-options', ['l' => 'ar']))
        ->assertOk();

    $order = fn ($response): array => collect($response->json())
        ->pluck('value')
        ->filter(fn (string $id): bool => in_array((int) $id, [$zed, $amy], true))
        ->map(fn (string $id): int => (int) $id)
        ->values()
        ->all();

    expect($order($english))->toBe([$amy, $zed])
        ->and($order($arabic))->toBe([$zed, $amy]);
});

test('an employees dropdown renders only the picked names on the server', function () {
    $user = User::factory()->role('General Manager')->create();
    $picked = insertHrEmployee(firstNameEn: 'Pia', lastNameEn: 'Picked');
    insertHrEmployee(firstNameEn: 'Olga', lastNameEn: 'Other');

    $this->actingAs($user)
        ->withViewErrors([])
        ->blade(
            '<x-ui.form-multiselect wire:model="assignees" label="Assignees" placeholder="Select" :value="$value" employees />',
            ['value' => [(string) $picked]],
        )
        ->assertSee('Pia Picked', false)
        ->assertDontSee('Olga Other', false)
        ->assertSee('x-ref="employeeList"', false)
        ->assertSee('picker-options', false);
});

test('person dropdowns and filters on the task and calendar pages read the shared employee list', function (string $page) {
    $user = User::factory()->role('General Manager')->create();
    insertHrEmployee(firstNameEn: 'Olga', lastNameEn: 'Other');

    $html = Livewire::actingAs($user)->test($page)->html();

    expect($html)->toContain('picker-options')
        ->and($html)->not->toContain('@js(')
        ->and($html)->not->toContain('Olga Other');
})->with(['pages::tasks.index', 'pages::calendar.index', 'pages::milestones.index', 'pages::projects.index']);

test('a person filter hands its employee list url to the filter menu', function () {
    $user = User::factory()->role('General Manager')->create();

    $html = Livewire::actingAs($user)->test('pages::projects.index')->html();

    expect($html)->toContain('data-employees-url="'.route('employees.picker-options', ['l' => app()->getLocale()]).'"')
        ->and($html)->not->toContain('@js(');
});

test('no page renders the whole employee list into server-side options', function () {
    // Person pickers use the `employees` prop (one shared, cached list drawn in
    // the browser). These list a short, filtered set of people (or, for the
    // projects index, cities that share the name · secondary_name markup) on purpose.
    $allowed = [
        'annual-plans/⚡index.blade.php' => 'only employees who already own a plan',
        'projects/⚡index.blade.php' => 'the city filter, not people',
        'projects/_index-project-modal.blade.php' => 'only the program managers a project owner may be picked from',
    ];

    $offenders = [];

    foreach (File::allFiles(resource_path('views/pages')) as $file) {
        $path = str_replace('\\', '/', $file->getRelativePathname());

        if (array_key_exists($path, $allowed)) {
            continue;
        }

        $source = $file->getContents();

        if (preg_match('/<option[^>]*>[^<]*\{\{\s*\$\w+->name\s*\}\}\s*·\s*\{\{\s*\$\w+->secondary_name/', $source) === 1
            || preg_match('/@foreach\s*\(\$this->(?:user|employee|manager|assignee)Options\b/', $source) === 1) {
            $offenders[] = $path;
        }
    }

    expect($offenders)->toBe([]);
});

test('fixed options such as Anyone and Not assigned come before the employee list', function () {
    $user = User::factory()->role('General Manager')->create();
    insertHrEmployee(firstNameEn: 'Pia', lastNameEn: 'Picked');

    $filter = $this->actingAs($user)
        ->withViewErrors([])
        ->blade('<x-ui.header-filter-select label="Assignee" wire:model.live="assignee" value="" employees><option value="">Anyone</option></x-ui.header-filter-select>')
        ->assertSee('Anyone')
        ->__toString();

    $form = $this->actingAs($user)
        ->withViewErrors([])
        ->blade('<x-ui.form-select wire:model="managerId" label="Manager" employees><option value="">Not assigned</option></x-ui.form-select>')
        ->__toString();

    expect(strpos($filter, 'Anyone'))->toBeLessThan(strpos($filter, 'x-ref="employeeList"'))
        ->and(strpos($form, 'data-option-value=""'))->toBeLessThan(strpos($form, 'x-ref="employeeList"'));
});

test('a person dropdown with the empty option selected is labelled by that option', function () {
    $user = User::factory()->role('General Manager')->create();
    insertHrEmployee(firstNameEn: 'Pia', lastNameEn: 'Picked');

    $html = $this->actingAs($user)
        ->withViewErrors([])
        ->blade('<x-ui.form-select wire:model="managerId" label="Manager" placeholder="Select" :value="\'\'" employees><option value="">Not assigned</option></x-ui.form-select>')
        ->__toString();

    expect($html)->toMatch('/data-trigger-label[^>]*>\s*Not assigned\s*</');
});
