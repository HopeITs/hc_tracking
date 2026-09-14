<?php

use App\Enums\Currency;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Result;
use App\Models\User;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ViewErrorBag;
use Illuminate\View\ViewException;

test('the ui input carries the shared control classes', function () {
    // Rendered outside a request, so share the empty error bag the web middleware normally provides.
    View::share('errors', new ViewErrorBag);

    $html = Blade::render('<x-ui.input name="search" label="Search" />');

    expect($html)->toContain('border-secondary-light!')
        ->and($html)->toContain('bg-white!')
        ->and($html)->toContain('dark:bg-secondary-medium!');
});

test('the enum badge labels a case and tones it', function () {
    $html = Blade::render('<x-ui.enum-badge :case="$case" />', ['case' => TaskStatus::InProgress]);

    expect($html)->toContain('In Progress')
        ->and($html)->toContain('bg-secondary-dark');
});

test('the enum badge tones a terminal negative state with the primary colour', function () {
    $html = Blade::render('<x-ui.enum-badge :case="$case" />', ['case' => MilestoneStatus::Missed]);

    expect($html)->toContain('Missed')
        ->and($html)->toContain('bg-primary');
});

test('the enum badge renders an active project as a solid dark badge', function () {
    $html = Blade::render('<x-ui.enum-badge :case="$case" />', ['case' => ProjectStatus::Active]);

    expect($html)->toContain('Active')
        ->and($html)->toContain('bg-secondary-dark');
});

test('the enum badge renders other project states as outlined badges', function (ProjectStatus $status) {
    $html = Blade::render('<x-ui.enum-badge :case="$case" />', ['case' => $status]);

    expect($html)->toContain('border-secondary-light')
        ->and($html)->not->toContain('bg-secondary-dark')
        ->and($html)->not->toContain('bg-primary');
})->with([
    'draft' => ProjectStatus::Draft,
    'submitted' => ProjectStatus::Submitted,
    'approved' => ProjectStatus::Approved,
    'closed' => ProjectStatus::Closed,
    'archived' => ProjectStatus::Archived,
]);

test('the enum badge renders an em dash for a null case', function () {
    $html = Blade::render('<x-ui.enum-badge :case="$case" />', ['case' => null]);

    expect(trim(strip_tags($html)))->toBe('—');
});

test('money renders the amount and its currency', function () {
    $html = Blade::render('<x-ui.money :amount="$amount" :currency="$currency" />', [
        'amount' => '1500000.00',
        'currency' => Currency::Usd,
    ]);

    expect($html)->toContain('1,500,000.00')
        ->and($html)->toContain('USD')
        ->and($html)->toContain('tabular-nums');
});

test('money accepts a raw pivot currency string without comparing it to an enum case', function () {
    $html = Blade::render('<x-ui.money :amount="$amount" currency="USD" />', ['amount' => '10.00']);

    expect($html)->toContain('10.00')->and($html)->toContain('USD');
});

test('money refuses to render an amount with no currency', function () {
    try {
        Blade::render('<x-ui.money :amount="$amount" :currency="null" />', ['amount' => '10.00']);

        $this->fail('Expected x-ui.money to reject null currency.');
    } catch (ViewException $exception) {
        $causes = [];

        for ($cause = $exception->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            $causes[] = $cause;
        }

        expect(array_filter($causes, fn (Throwable $cause): bool => $cause instanceof InvalidArgumentException))
            ->not->toBeEmpty();
    }
});

test('the user chip shows name role and email', function () {
    $user = User::factory()->role(UserRole::Meal)->create([
        'name' => 'Layla Haddad',
        'email' => 'layla@example.com',
    ]);

    $html = Blade::render('<x-ui.user-chip :user="$user" />', ['user' => $user]);

    expect($html)->toContain('Layla Haddad')
        ->and($html)->toContain('MEAL')
        ->and($html)->toContain('layla@example.com');
});

test('the ui button renders one tone per variant', function (string $tone, string $expected) {
    $html = Blade::render('<x-ui.button :tone="$tone">Go</x-ui.button>', ['tone' => $tone]);

    expect($html)->toContain($expected);
})->with([
    'primary' => ['primary', 'bg-primary!'],
    'secondary' => ['secondary', 'bg-white!'],
    'ghost' => ['ghost', 'bg-transparent!'],
]);

test('the ui button rejects an unknown tone', function () {
    try {
        Blade::render('<x-ui.button tone="rainbow">Go</x-ui.button>');

        $this->fail('Expected x-ui.button to reject the tone [rainbow].');
    } catch (ViewException $exception) {
        $causes = [];

        for ($cause = $exception->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            $causes[] = $cause;
        }

        expect($causes)->toContainOnlyInstancesOf(Throwable::class)
            ->and(array_filter($causes, fn (Throwable $cause): bool => $cause instanceof InvalidArgumentException))
            ->not->toBeEmpty()
            ->and($exception->getMessage())->toContain('Unknown x-ui.button tone [rainbow].');
    }
});

test('the page header shows a title with a count badge and a description', function () {
    $html = Blade::render(
        '<x-ui.page-header title="Projects" :count="8" description="Delivery sites." />'
    );

    expect($html)->toContain('Projects')
        ->and($html)->toContain('8')
        ->and($html)->toContain('Delivery sites.');
});

test('the page header omits the count badge when no count is given', function () {
    $html = Blade::render('<x-ui.page-header title="Projects" />');

    expect($html)->toContain('Projects')
        ->and($html)->not->toContain('data-count-badge');
});

test('the stat tile shows a value over a denominator', function () {
    $html = Blade::render('<x-ui.stat-tile label="Targeted" :value="6" :denominator="8" unit="projects" />');

    expect($html)->toContain('Targeted')
        ->and($html)->toContain('6')
        ->and($html)->toContain('8')
        ->and($html)->toContain('projects');
});

test('the empty state carries different copy when filters are active', function () {
    $filtered = Blade::render(
        '<x-ui.empty-state icon="rectangle-stack" heading="No matching projects" body="Change the filters." />'
    );

    $unfiltered = Blade::render(
        '<x-ui.empty-state icon="rectangle-stack" heading="No projects yet" body="Create the first one." />'
    );

    expect($filtered)->toContain('No matching projects')
        ->and($filtered)->toContain('Change the filters.')
        ->and($unfiltered)->toContain('No projects yet')
        ->and($unfiltered)->toContain('Create the first one.')
        ->and($filtered)->not->toBe($unfiltered);
});

test('the panel renders its title and body', function () {
    $html = Blade::render('<x-ui.panel title="Risks">Body text</x-ui.panel>');

    expect($html)->toContain('Risks')->and($html)->toContain('Body text');
});

test('the detail header links every breadcrumb but the last', function () {
    $html = Blade::render('<x-ui.detail-header :breadcrumbs="$crumbs" title="Water Security" />', [
        'crumbs' => [
            ['label' => 'Programs', 'url' => 'http://localhost/programs'],
            ['label' => 'Water Security'],
        ],
    ]);

    expect($html)->toContain('http://localhost/programs')
        ->and(substr_count($html, '<a '))->toBe(1);
});

test('the relation tabs mark the active tab', function () {
    $html = Blade::render('<x-ui.relation-tabs :tabs="$tabs" active="risks" wire-target="tab" />', [
        'tabs' => [
            ['key' => 'milestones', 'label' => 'Milestones', 'count' => 3],
            ['key' => 'risks', 'label' => 'Risks', 'count' => 2],
        ],
    ]);

    expect($html)->toContain('Milestones')
        ->and($html)->toContain('Risks')
        ->and($html)->toContain('aria-selected="true"');
});

test('the tree renderer indents a loaded subtree without querying per node', function () {
    $firstRoot = Result::factory()->create(['statement' => 'Root impact']);
    $secondRoot = Result::factory()->create(['statement' => 'Second impact']);
    Result::factory()->create(['statement' => 'Child outcome', 'parent_id' => $firstRoot->id]);
    Result::factory()->create(['statement' => 'Second child outcome', 'parent_id' => $secondRoot->id]);

    $loaded = Result::query()
        ->whereKey([$firstRoot->id, $secondRoot->id])
        ->with('childrenRecursive')
        ->get();

    expect($loaded)->toHaveCount(2);

    DB::connection()->flushQueryLog();
    DB::connection()->enableQueryLog();

    $html = Blade::render('<x-ui.tree-rows :nodes="$nodes" :label="$label" />', [
        'nodes' => $loaded,
        'label' => fn (Result $result): string => $result->statement,
    ]);

    $queries = DB::connection()->getQueryLog();
    DB::connection()->disableQueryLog();

    expect($queries)->toBeEmpty()
        ->and($html)->toContain('Root impact')
        ->and($html)->toContain('Second impact')
        ->and($html)->toContain('Child outcome')
        ->and($html)->toContain('Second child outcome')
        ->and($html)->toContain('data-tree-depth="1"');
});
