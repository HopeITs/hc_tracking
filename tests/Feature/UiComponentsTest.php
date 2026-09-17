<?php

use App\Enums\Currency;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Enums\UserRole;
use App\Models\Result;
use App\Models\User;
use Carbon\CarbonImmutable;
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

test('the page header matches the directory title and count design without a description', function () {
    $html = Blade::render(
        '<x-ui.page-header title="Projects" :count="8" description="Delivery sites."><x-slot:filters><input aria-label="Search projects"></x-slot:filters></x-ui.page-header>'
    );

    expect($html)->toContain('Projects')
        ->and($html)->toContain('8')
        ->and($html)->toContain('text-3xl! font-bold! leading-none!')
        ->and($html)->toContain('bg-primary')
        ->and($html)->toContain('data-page-header-controls')
        ->and($html)->not->toContain('Delivery sites.');
});

test('the page header omits the count badge when no count is given', function () {
    $html = Blade::render('<x-ui.page-header title="Projects" />');

    expect($html)->toContain('Projects')
        ->and($html)->not->toContain('data-count-badge');
});

test('the page header keeps actions outside the filter grid', function () {
    $html = Blade::render(
        '<x-ui.page-header title="Projects"><x-slot:filters><input aria-label="Search projects"></x-slot:filters><x-slot:actions><button data-test="add-project">Add project</button></x-slot:actions></x-ui.page-header>'
    );
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//*[@data-page-header-filters]//input[@aria-label="Search projects"]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-page-header-filters]//*[@data-test="add-project"]')->length)->toBe(0)
        ->and($xpath->query('//*[@data-page-header-actions]//*[@data-test="add-project"]')->length)->toBe(1);
});

test('the header filter select renders the programs dropdown instead of a native select', function () {
    $html = Blade::render(<<<'BLADE'
        <x-ui.header-filter-select label="City" value="aleppo" wire:model.live="city">
            <flux:select.option value="">All cities</flux:select.option>
            <flux:select.option value="aleppo">Aleppo</flux:select.option>
            <flux:select.option value="raqqa">Ar-Raqqa</flux:select.option>
        </x-ui.header-filter-select>
    BLADE);
    $document = new DOMDocument;
    @$document->loadHTML($html);
    $xpath = new DOMXPath($document);

    expect($xpath->query('//*[@data-header-filter-select]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-header-filter-select]//select')->length)->toBe(0)
        ->and($xpath->query('//*[@data-header-filter-select]//*[@data-flux-menu-item]')->length)->toBe(3)
        ->and(trim($xpath->query('//*[@data-header-filter-select]//*[@data-selected="true"]')->item(0)->textContent))->toBe('Aleppo');
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

test('the tree renderer shows a subtitle when one is supplied', function () {
    $root = Result::factory()->create(['statement' => 'Root impact']);

    $html = Blade::render('<x-ui.tree-rows :nodes="$nodes" :label="$label" :subtitle="$subtitle" />', [
        'nodes' => Result::query()->whereKey($root->id)->with('childrenRecursive')->get(),
        'label' => fn (Result $result): string => $result->statement,
        'subtitle' => fn (Result $result): string => 'Extra detail',
    ]);

    expect($html)->toContain('Root impact')
        ->and($html)->toContain('Extra detail');
});

test('the progress ring shows a rounded percentage and an em dash when unset', function () {
    $withValue = Blade::render('<x-ui.progress-ring size="lg" :value="72.5" tone="good" label="Progress" />');
    $withoutValue = Blade::render('<x-ui.progress-ring size="lg" :value="null" label="Progress" />');

    expect($withValue)->toContain('73%')
        ->and($withValue)->toContain('role="progressbar"')
        ->and($withoutValue)->toContain('—')
        ->and($withoutValue)->not->toContain('aria-valuenow');
});

test('the schedule status reads overdue, due soon and future dates correctly', function () {
    Carbon\Carbon::setTestNow('2026-09-17');

    $overdue = Blade::render('<x-ui.schedule-status :planned="$planned" />', ['planned' => CarbonImmutable::parse('2026-09-10')]);
    $dueSoon = Blade::render('<x-ui.schedule-status :planned="$planned" />', ['planned' => CarbonImmutable::parse('2026-09-20')]);
    $dueLater = Blade::render('<x-ui.schedule-status :planned="$planned" />', ['planned' => CarbonImmutable::parse('2026-12-08')]);
    $unscheduled = Blade::render('<x-ui.schedule-status />');

    Carbon\Carbon::setTestNow();

    $textOf = fn (string $html): string => trim(preg_replace('/\s+/', ' ', strip_tags($html)));

    expect($textOf($overdue))->toBe('Overdue by 7 days')
        ->and($textOf($dueSoon))->toBe('Due in 3 days')
        ->and($textOf($dueLater))->not->toContain('-')
        ->and($textOf($unscheduled))->toBe('Unscheduled');
});

test('the capacity bar shows committed and received money and flags an overflow', function () {
    $withinBudget = Blade::render('<x-ui.capacity-bar :committed="1000" :used="400" currency="USD" />');
    $overBudget = Blade::render('<x-ui.capacity-bar :committed="1000" :used="1200" currency="USD" />');

    expect($withinBudget)->toContain('1,000.00')
        ->and($withinBudget)->toContain('400.00')
        ->and($overBudget)->toContain('Over by 20%');
});

test('the capacity bar throws without a currency', function () {
    try {
        Blade::render('<x-ui.capacity-bar :committed="1000" :used="400" :currency="null" />');

        $this->fail('Expected x-ui.capacity-bar to reject null currency.');
    } catch (ViewException $exception) {
        $causes = [];

        for ($cause = $exception->getPrevious(); $cause !== null; $cause = $cause->getPrevious()) {
            $causes[] = $cause;
        }

        expect(array_filter($causes, fn (Throwable $cause): bool => $cause instanceof InvalidArgumentException))
            ->not->toBeEmpty();
    }
});

test('the view toggle marks the active option and wires the others to set it', function () {
    $html = Blade::render('<x-ui.view-toggle wire-model="milestoneView" active="tree" :options="$options" />', [
        'options' => [
            ['key' => 'tree', 'label' => 'Tree'],
            ['key' => 'board', 'label' => 'Board'],
        ],
    ]);

    expect($html)->toContain('role="radiogroup"')
        ->and($html)->toContain('aria-checked="true"')
        ->and($html)->toContain("\$set('milestoneView', 'board')");
});
