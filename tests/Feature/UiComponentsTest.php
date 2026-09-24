<?php

use App\Enums\Currency;
use App\Enums\MilestoneStatus;
use App\Enums\ProjectStatus;
use App\Enums\TaskStatus;
use App\Models\Result;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\View;
use Illuminate\Support\MessageBag;
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

test('the enum badge tones a stalled state with the primary colour', function () {
    $html = Blade::render('<x-ui.enum-badge :case="$case" />', ['case' => MilestoneStatus::Postponed]);

    expect($html)->toContain('Postponed')
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
    $user = User::factory()->role('MEAL')->create([
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
    'neutral' => ['neutral', 'bg-secondary-dark!'],
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

/**
 * Parse rendered component HTML so tests can query it with XPath.
 */
function formXPath(string $html): DOMXPath
{
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);

    return new DOMXPath($document);
}

test('the form input pairs its label with the filter-look box', function () {
    View::share('errors', new ViewErrorBag);

    $xpath = formXPath(Blade::render('<x-ui.form-input wire:model="name" label="Project name" required maxlength="255" class="col-span-2" />'));
    $input = $xpath->query('//input')->item(0);

    expect($xpath->query('//*[@data-form-field and contains(@class, "col-span-2")]')->length)->toBe(1)
        ->and($xpath->query('//label[@for="'.$input->getAttribute('id').'"]//*[contains(@class, "form-required")]')->length)->toBe(1)
        ->and($xpath->query('//div[contains(concat(" ", normalize-space(@class), " "), " form-box ")]/input')->length)->toBe(1)
        ->and($input->getAttribute('wire:model'))->toBe('name')
        ->and($input->getAttribute('maxlength'))->toBe('255')
        ->and($input->hasAttribute('required'))->toBeTrue()
        ->and($input->getAttribute('class'))->not->toContain('col-span-2')
        ->and($input->hasAttribute('aria-invalid'))->toBeFalse();
});

test('the form input shows its validation message and marks itself invalid', function () {
    View::share('errors', (new ViewErrorBag)->put('default', new MessageBag(['name' => ['Enter a project name.']])));

    $xpath = formXPath(Blade::render('<x-ui.form-input wire:model="name" label="Project name" />'));
    $input = $xpath->query('//input')->item(0);
    $errorId = $input->getAttribute('id').'-error';

    expect($input->getAttribute('aria-invalid'))->toBe('true')
        ->and($input->getAttribute('aria-describedby'))->toContain($errorId)
        ->and(trim($xpath->query('//*[@id="'.$errorId.'"]')->item(0)->textContent))->toBe('Enter a project name.')
        ->and($xpath->query('//div[@data-invalid]/input')->length)->toBe(1);
});

test('the form input shows a unit beside the value and a help line under it', function () {
    View::share('errors', new ViewErrorBag);

    $xpath = formXPath(Blade::render('<x-ui.form-input wire:model="amount" label="Amount" type="number" prefix="USD" help="Net of fees." />'));
    $input = $xpath->query('//input')->item(0);
    $id = $input->getAttribute('id');

    expect($input->getAttribute('type'))->toBe('number')
        ->and($input->getAttribute('class'))->toContain('tabular-nums')
        ->and(trim($xpath->query('//*[@id="'.$id.'-prefix"]')->item(0)->textContent))->toBe('USD')
        ->and(trim($xpath->query('//*[@id="'.$id.'-help"]')->item(0)->textContent))->toBe('Net of fees.')
        ->and($input->getAttribute('aria-describedby'))->toBe($id.'-prefix '.$id.'-help');
});

test('the form textarea shares the box and defaults to three rows', function () {
    View::share('errors', (new ViewErrorBag)->put('default', new MessageBag(['description' => ['Keep it shorter.']])));

    $xpath = formXPath(Blade::render('<x-ui.form-textarea wire:model="description" label="Description" />'));
    $textarea = $xpath->query('//textarea')->item(0);

    expect($textarea->getAttribute('rows'))->toBe('3')
        ->and($textarea->getAttribute('wire:model'))->toBe('description')
        ->and($textarea->getAttribute('aria-invalid'))->toBe('true')
        ->and($xpath->query('//div[@data-invalid]/textarea')->length)->toBe(1)
        ->and($xpath->query('//label[@for="'.$textarea->getAttribute('id').'"]')->length)->toBe(1);
});

test('the form select is the header filter picker, showing the chosen label with a check beside it', function () {
    View::share('errors', new ViewErrorBag);

    $xpath = formXPath(Blade::render(<<<'BLADE'
        <x-ui.form-select wire:model="cityId" label="City" value="2" placeholder="Select a city" required>
            <flux:select.option value="1">Aleppo</flux:select.option>
            <flux:select.option value="2">Homs</flux:select.option>
        </x-ui.form-select>
    BLADE));
    $picker = '//*[@data-form-select="cityId"]';

    expect($xpath->query($picker.'//select')->length)->toBe(0)
        ->and($xpath->query($picker.'//*[contains(@class, "filter-picker")]//*[@data-flux-menu-item]')->length)->toBe(2)
        ->and(trim($xpath->query($picker.'//*[@data-trigger-label]')->item(0)->textContent))->toBe('Homs')
        ->and($xpath->query($picker.'//*[@data-option-value="2"]//svg[not(contains(@style, "display: none"))]')->length)->toBe(1)
        ->and($xpath->query($picker.'//*[@data-option-value="1"]//svg[contains(@style, "display: none")]')->length)->toBe(1)
        ->and($xpath->query($picker.'//*[@x-modelable="value" and @*[name()="wire:model"]="cityId"]')->length)->toBe(1)
        ->and($xpath->query($picker.'//label//*[contains(@class, "form-required")]')->length)->toBe(1)
        ->and($xpath->query($picker.'//input[@type="search"]')->length)->toBe(0);
});

test('the form select shows its placeholder until a value is chosen, but treats an empty option as a real choice', function () {
    View::share('errors', new ViewErrorBag);

    $placeholder = formXPath(Blade::render(<<<'BLADE'
        <x-ui.form-select wire:model="programId" label="Program" value="" placeholder="Select a program">
            <flux:select.option value="4">Water</flux:select.option>
        </x-ui.form-select>
    BLADE))->query('//*[@data-trigger-label]')->item(0);

    $topLevel = formXPath(Blade::render(<<<'BLADE'
        <x-ui.form-select wire:model="parentId" label="Parent" value="">
            <flux:select.option value="">Top level</flux:select.option>
            <flux:select.option value="9">Outcome 1</flux:select.option>
        </x-ui.form-select>
    BLADE))->query('//*[@data-trigger-label]')->item(0);

    expect(trim($placeholder->textContent))->toBe('Select a program')
        ->and($placeholder->getAttribute('class'))->toContain('form-select-placeholder')
        ->and(trim($topLevel->textContent))->toBe('Top level')
        ->and($topLevel->getAttribute('class'))->not->toContain('form-select-placeholder');
});

test('the form select offers a search box only when the list is longer than seven', function (int $count, int $searchBoxes) {
    View::share('errors', new ViewErrorBag);

    $options = collect(range(1, $count))
        ->map(fn (int $number): string => '<flux:select.option value="'.$number.'">Option '.$number.'</flux:select.option>')
        ->implode('');

    $html = Blade::render('<x-ui.form-select wire:model="userId" label="Owner" value="">'.$options.'</x-ui.form-select>');

    expect(formXPath($html)->query('//input[@type="search"]')->length)->toBe($searchBoxes);
})->with([
    'seven options' => [7, 0],
    'eight options' => [8, 1],
]);

test('a locked form select says why it cannot change', function () {
    View::share('errors', new ViewErrorBag);

    $xpath = formXPath(Blade::render(<<<'BLADE'
        <x-ui.form-select wire:model="projectId" label="Project" value="3" disabled locked-reason="A milestone cannot move to another project.">
            <flux:select.option value="3">Water access</flux:select.option>
        </x-ui.form-select>
    BLADE));
    $trigger = $xpath->query('//button[@data-flux-button]')->item(0);
    $id = $trigger->getAttribute('id');

    expect($trigger->hasAttribute('disabled'))->toBeTrue()
        ->and(trim($xpath->query('//*[@id="'.$id.'-help"]')->item(0)->textContent))->toBe('A milestone cannot move to another project.')
        ->and($trigger->getAttribute('aria-describedby'))->toContain($id.'-help')
        ->and(trim($xpath->query('//*[@data-trigger-label]')->item(0)->textContent))->toBe('Water access');
});

test('an invalid form select turns its trigger red and names the problem', function () {
    View::share('errors', (new ViewErrorBag)->put('default', new MessageBag(['cityId' => ['Select a city.']])));

    $xpath = formXPath(Blade::render(<<<'BLADE'
        <x-ui.form-select wire:model="cityId" label="City" value="" placeholder="Select a city">
            <flux:select.option value="1">Aleppo</flux:select.option>
        </x-ui.form-select>
    BLADE));
    $trigger = $xpath->query('//button[@data-flux-button]')->item(0);

    expect($trigger->getAttribute('aria-invalid'))->toBe('true')
        ->and(trim($xpath->query('//*[@id="'.$trigger->getAttribute('id').'-error"]')->item(0)->textContent))->toBe('Select a city.');
});

test('the form select refuses to render without a wire:model binding', function () {
    try {
        Blade::render('<x-ui.form-select label="City"><flux:select.option value="1">Aleppo</flux:select.option></x-ui.form-select>');

        $this->fail('Expected x-ui.form-select to require a wire:model binding.');
    } catch (ViewException $exception) {
        expect($exception->getMessage())->toContain('requires a wire:model binding');
    }
});

test('the modal shell pins a titled header and a footer with cancel around a submitting body', function () {
    View::share('errors', new ViewErrorBag);

    $xpath = formXPath(Blade::render(<<<'BLADE'
        <x-ui.modal wire:model="showProjectModal" size="xl" title="Add project" description="Who runs it and when." submit="saveProject" close="closeProjectModal" required-note>
            <p>Body copy</p>

            <x-slot:actions>
                <x-ui.button type="submit" tone="primary" data-test="save-project-button">Create project</x-ui.button>
            </x-slot:actions>
        </x-ui.modal>
    BLADE));
    $dialog = '//dialog[contains(@class, "modal-shell")]';

    expect($xpath->query($dialog.'[contains(@class, "modal-shell-xl") and contains(@class, "max-w-5xl")]')->length)->toBe(1)
        ->and($xpath->query($dialog.'[@*[name()="wire:close"]="closeProjectModal"]')->length)->toBe(1)
        ->and($xpath->query($dialog.'//form[@*[name()="wire:submit"]="saveProject" and @novalidate]')->length)->toBe(1)
        ->and(trim($xpath->query($dialog.'//h2')->item(0)->textContent))->toBe('Add project')
        ->and(trim($xpath->query($dialog.'//*[contains(@class, "modal-shell-description")]')->item(0)->textContent))->toBe('Who runs it and when.')
        ->and($xpath->query($dialog.'//header//button[@aria-label="Close"]')->length)->toBe(1)
        ->and(trim($xpath->query($dialog.'//*[contains(@class, "modal-shell-body")]')->item(0)->textContent))->toBe('Body copy')
        ->and($xpath->query($dialog.'//footer//button[normalize-space()="Cancel"]')->length)->toBe(1)
        ->and($xpath->query($dialog.'//footer//fieldset//*[@data-test="save-project-button"]')->length)->toBe(1)
        ->and(trim($xpath->query($dialog.'//*[contains(@class, "modal-shell-meta")]')->item(0)->textContent))->toContain('Required')
        ->and($xpath->query($dialog.'//ui-close')->length)->toBe(0);
});

test('the modal shell leaves out the body when there is nothing to put in it', function () {
    View::share('errors', new ViewErrorBag);

    $xpath = formXPath(Blade::render('<x-ui.modal wire:model="showDeleteModal" size="sm" title="Delete this risk?" close="cancelDelete" />'));

    expect($xpath->query('//*[contains(@class, "modal-shell-body")]')->length)->toBe(0)
        ->and($xpath->query('//dialog//form')->length)->toBe(0)
        ->and($xpath->query('//dialog[contains(@class, "max-w-md")]')->length)->toBe(1);
});

test('the modal shell rejects an unknown size', function () {
    try {
        Blade::render('<x-ui.modal size="huge" title="Add project" />');

        $this->fail('Expected x-ui.modal to reject the size [huge].');
    } catch (ViewException $exception) {
        expect($exception->getMessage())->toContain('Unknown x-ui.modal size [huge].');
    }
});

test('a form section names itself in the rail with its hint', function () {
    View::share('errors', new ViewErrorBag);

    $xpath = formXPath(Blade::render('<x-ui.form-section title="Details" hint="Name and dates" :fields="[\'name\']"><p>Fields</p></x-ui.form-section>'));

    expect(trim($xpath->query('//*[contains(@class, "form-section-heading")]')->item(0)->textContent))->toBe('Details')
        ->and(trim($xpath->query('//*[contains(@class, "form-section-hint")]')->item(0)->textContent))->toBe('Name and dates')
        ->and($xpath->query('//section[@data-invalid]')->length)->toBe(0)
        ->and(trim($xpath->query('//*[contains(@class, "form-section-fields")]')->item(0)->textContent))->toBe('Fields');
});

test('a form section with invalid fields turns its dot red and counts them instead of its hint', function () {
    View::share('errors', (new ViewErrorBag)->put('default', new MessageBag([
        'cityId' => ['Select a city.'],
        'userId' => ['Select an owner.'],
        'name' => ['Enter a name.'],
    ])));

    $xpath = formXPath(Blade::render('<x-ui.form-section title="Placement" hint="Where it runs" :fields="[\'cityId\', \'programId\', \'userId\']"><p>Fields</p></x-ui.form-section>'));

    expect($xpath->query('//section[@data-invalid]')->length)->toBe(1)
        ->and(trim($xpath->query('//*[contains(@class, "form-section-hint")]')->item(0)->textContent))->toBe('2 fields need attention');
});

test('the attention count reads naturally in arabic', function () {
    app()->setLocale('ar');

    expect(trans_choice('common.fields_need_attention', 1, ['count' => 1]))->toBe('حقل واحد يحتاج إلى مراجعة')
        ->and(trans_choice('common.fields_need_attention', 2, ['count' => 2]))->toBe('حقلان يحتاجان إلى مراجعة')
        ->and(trans_choice('common.fields_need_attention', 5, ['count' => 5]))->toBe('5 حقول تحتاج إلى مراجعة');
});

test('the header filter search box keeps its keystrokes away from the menu type-ahead', function () {
    View::share('errors', new ViewErrorBag);

    $options = collect(range(1, 8))
        ->map(fn (int $number): string => '<flux:select.option value="'.$number.'">City '.$number.'</flux:select.option>')
        ->implode('');

    $search = formXPath(Blade::render('<x-ui.header-filter-select label="City" value="" wire:model.live="city">'.$options.'</x-ui.header-filter-select>'))
        ->query('//input[@type="search"]')
        ->item(0);

    expect($search->getAttribute('x-on:keydown'))->toBe('$event.key.length === 1 && $event.stopPropagation()');
});

test('a danger confirmation carries a red mark and a red action wired to its method', function () {
    View::share('errors', new ViewErrorBag);

    $xpath = formXPath(Blade::render(<<<'BLADE'
        <x-ui.confirm-modal wire:model="showDeleteModal" title="Delete this risk?" message="The risk and its mitigation notes are removed." confirm="deleteRisk" confirm-label="Delete risk" confirm-test="confirm-delete-risk" close="cancelDelete" />
    BLADE));
    $action = $xpath->query('//*[@data-test="confirm-delete-risk"]')->item(0);

    expect($xpath->query('//dialog[contains(@class, "max-w-md")]')->length)->toBe(1)
        ->and($xpath->query('//header//*[contains(@class, "confirm-mark") and contains(@class, "bg-primary/10")]')->length)->toBe(1)
        ->and(trim($xpath->query('//h2')->item(0)->textContent))->toBe('Delete this risk?')
        ->and(trim($xpath->query('//*[contains(@class, "modal-shell-description")]')->item(0)->textContent))->toBe('The risk and its mitigation notes are removed.')
        ->and($action->getAttribute('wire:click'))->toBe('deleteRisk')
        ->and($action->getAttribute('class'))->toContain('bg-primary!')
        ->and(trim($action->textContent))->toBe('Delete risk')
        ->and($xpath->query('//*[contains(@class, "modal-shell-body")]')->length)->toBe(0);
});

test('a neutral confirmation uses a grey mark and the dark action', function () {
    View::share('errors', new ViewErrorBag);

    $xpath = formXPath(Blade::render(<<<'BLADE'
        <x-ui.confirm-modal wire:model="showArchiveModal" tone="neutral" title="Archive this project?" confirm="archiveProject" confirm-label="Archive project" confirm-test="confirm-archive-project">
            <p>Kept with the project</p>
        </x-ui.confirm-modal>
    BLADE));

    expect($xpath->query('//*[contains(@class, "confirm-mark") and contains(@class, "bg-secondary-soft")]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-test="confirm-archive-project"]')->item(0)->getAttribute('class'))->toContain('bg-secondary-dark!')
        ->and(trim($xpath->query('//*[contains(@class, "modal-shell-body")]')->item(0)->textContent))->toBe('Kept with the project');
});

test('the confirm modal rejects an unknown tone', function () {
    try {
        Blade::render('<x-ui.confirm-modal tone="loud" title="Sure?" confirm="go" confirm-label="Go" />');

        $this->fail('Expected x-ui.confirm-modal to reject the tone [loud].');
    } catch (ViewException $exception) {
        expect($exception->getMessage())->toContain('Unknown x-ui.confirm-modal tone [loud].');
    }
});

test('the segmented field is a radiogroup with the current choice checked', function () {
    View::share('errors', new ViewErrorBag);

    $html = Blade::render('<x-ui.form-segmented wire:model="priority" label="Priority" value="medium" :options="$options" />', [
        'options' => [
            ['value' => 'low', 'label' => 'Low', 'icon' => 'flag', 'iconClass' => 'text-secondary-light'],
            ['value' => 'medium', 'label' => 'Medium', 'icon' => 'flag', 'iconClass' => 'text-status-warn'],
            ['value' => 'high', 'label' => 'High', 'dot' => 'bg-primary'],
        ],
    ]);
    $xpath = formXPath($html);
    $group = $xpath->query('//*[@role="radiogroup"]')->item(0);

    expect($group->getAttribute('aria-labelledby'))->toBe($group->getAttribute('id').'-label')
        ->and($xpath->query('//label[@id="'.$group->getAttribute('id').'-label"]')->length)->toBe(1)
        ->and($group->getAttribute('x-modelable'))->toBe('value')
        ->and($group->getAttribute('wire:model'))->toBe('priority')
        ->and($xpath->query('//*[@role="radio"]')->length)->toBe(3)
        ->and(trim($xpath->query('//*[@role="radio" and @aria-checked="true"]')->item(0)->textContent))->toBe('Medium')
        ->and($xpath->query('//*[@role="radio" and @tabindex="0"]/@data-option-value')->item(0)->value)->toBe('medium')
        ->and($xpath->query('//*[@data-option-value="high"]//span[contains(@class, "bg-primary")]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-option-value="low"]//svg')->length)->toBe(1);
});

test('a segmented field with nothing chosen keeps the first option reachable by tab', function () {
    View::share('errors', (new ViewErrorBag)->put('default', new MessageBag(['state' => ['Choose a state.']])));

    $xpath = formXPath(Blade::render('<x-ui.form-segmented wire:model="state" label="State" value="" :options="$options" />', [
        'options' => [['value' => 'potential', 'label' => 'Potential'], ['value' => 'materialised', 'label' => 'Materialised']],
    ]));
    $group = $xpath->query('//*[@role="radiogroup"]')->item(0);

    expect($xpath->query('//*[@role="radio" and @aria-checked="true"]')->length)->toBe(0)
        ->and($xpath->query('//*[@role="radio" and @tabindex="0"]/@data-option-value')->item(0)->value)->toBe('potential')
        ->and($group->getAttribute('aria-invalid'))->toBe('true')
        ->and(trim($xpath->query('//*[@id="'.$group->getAttribute('id').'-error"]')->item(0)->textContent))->toBe('Choose a state.');
});

test('the form multiselect names two picks and counts three', function (array $picked, string $label) {
    View::share('errors', new ViewErrorBag);

    $xpath = formXPath(Blade::render(<<<'BLADE'
        <x-ui.form-multiselect wire:model="assignedTo" label="Assignees" placeholder="Anyone" :value="$picked">
            <flux:select.option value="1">Lina Haddad</flux:select.option>
            <flux:select.option value="2">Omar Khatib</flux:select.option>
            <flux:select.option value="3">Sara Nasser</flux:select.option>
        </x-ui.form-multiselect>
    BLADE, ['picked' => $picked]));

    expect(trim($xpath->query('//*[@data-trigger-label]')->item(0)->textContent))->toBe($label)
        ->and($xpath->query('//*[@data-flux-menu and @keep-open]')->length)->toBe(1)
        ->and($xpath->query('//*[@data-option-value]//svg[contains(@class, "text-primary") and not(contains(@style, "display: none"))]')->length)->toBe(count($picked));
})->with([
    'nobody' => [[], 'Anyone'],
    'two people' => [['1', '2'], 'Lina Haddad, Omar Khatib'],
    'three people' => [['1', '2', '3'], '3 selected'],
]);

test('the form multiselect shows an error raised on any of its values', function () {
    View::share('errors', (new ViewErrorBag)->put('default', new MessageBag(['assignedTo.1' => ['That person is no longer available.']])));

    $xpath = formXPath(Blade::render(<<<'BLADE'
        <x-ui.form-multiselect wire:model="assignedTo" label="Assignees" :value="['9']">
            <flux:select.option value="9">Lina Haddad</flux:select.option>
        </x-ui.form-multiselect>
    BLADE));

    expect($xpath->query('//button[@data-flux-button]')->item(0)->getAttribute('aria-invalid'))->toBe('true')
        ->and($xpath->query('//*[contains(@class, "form-error")]')->item(0)->textContent)->toContain('That person is no longer available.');
});

test('the file field offers a drop zone around a native file input', function () {
    View::share('errors', new ViewErrorBag);

    $xpath = formXPath(Blade::render('<x-ui.form-file wire:model="upload" label="File" required accept=".pdf,.docx" types="PDF, DOCX" max-size="5 MB" />'));
    $input = $xpath->query('//input[@type="file"]')->item(0);

    expect($input->getAttribute('wire:model'))->toBe('upload')
        ->and($input->getAttribute('accept'))->toBe('.pdf,.docx')
        ->and($xpath->query('//label[contains(@class, "form-file-zone")]//input[@type="file"]')->length)->toBe(1)
        ->and($xpath->query('//label[contains(@class, "form-file-zone")]')->item(0)->textContent)->toContain('Drop a file here')
        ->and($xpath->query('//label[contains(@class, "form-file-zone")]')->item(0)->textContent)->toContain('PDF, DOCX · up to 5 MB')
        ->and($xpath->query('//*[contains(@class, "form-file-chosen") and contains(@style, "display: none")]')->length)->toBe(1);
});

test('an invalid file field marks the zone and names the problem', function () {
    View::share('errors', (new ViewErrorBag)->put('default', new MessageBag(['upload' => ['Choose a file.']])));

    $xpath = formXPath(Blade::render('<x-ui.form-file wire:model="upload" label="File" />'));
    $input = $xpath->query('//input[@type="file"]')->item(0);

    expect($input->getAttribute('aria-invalid'))->toBe('true')
        ->and($xpath->query('//*[contains(@class, "form-file") and @data-invalid]')->length)->toBe(1)
        ->and(trim($xpath->query('//*[@id="'.$input->getAttribute('id').'-error"]')->item(0)->textContent))->toBe('Choose a file.');
});

test('a segmented field bound to a boolean checks the matching option and keeps per-option test hooks', function (bool $happened, string $checked) {
    View::share('errors', new ViewErrorBag);

    $xpath = formXPath(Blade::render('<x-ui.form-segmented wire:model="happened" label="State" :value="$happened" :options="$options" />', [
        'happened' => $happened,
        'options' => [
            ['value' => '0', 'label' => 'Potential risk'],
            ['value' => '1', 'label' => 'Materialised', 'test' => 'form-materialised-option'],
        ],
    ]));

    expect(trim($xpath->query('//*[@role="radio" and @aria-checked="true"]')->item(0)->textContent))->toBe($checked)
        ->and($xpath->query('//*[@role="radio" and @data-test="form-materialised-option" and @data-option-value="1"]')->length)->toBe(1);
})->with([
    'not happened' => [false, 'Potential risk'],
    'happened' => [true, 'Materialised'],
]);

test('a viewer built on the modal shell names its dismiss button close', function () {
    View::share('errors', new ViewErrorBag);

    $xpath = formXPath(Blade::render('<x-ui.modal wire:model="showInspectModal" size="xl" title="Water points" close="closeInspectModal" cancel-label="Close"><p>Readings</p></x-ui.modal>'));

    expect($xpath->query('//footer//button[normalize-space()="Close"]')->length)->toBe(1)
        ->and($xpath->query('//footer//button[normalize-space()="Cancel"]')->length)->toBe(0);
});

test('a file viewer on the modal shell carries its own header actions over an edge-to-edge body', function () {
    View::share('errors', new ViewErrorBag);

    $xpath = formXPath(Blade::render(<<<'BLADE'
        <x-ui.modal wire:model="showPreviewModal" size="xl" title="Budget.pdf" close="closePreview" cancel-label="Close" flush>
            <x-slot:headerActions>
                <a href="/download" data-test="download-preview">Download</a>
            </x-slot:headerActions>

            <iframe title="Budget.pdf"></iframe>
        </x-ui.modal>
    BLADE));

    expect($xpath->query('//header//*[@data-test="download-preview"]')->length)->toBe(1)
        ->and($xpath->query('//*[contains(@class, "modal-shell-body") and contains(@class, "modal-shell-body-flush")]//iframe')->length)->toBe(1);
});

test('a locked segmented field disables every choice and says why', function () {
    View::share('errors', new ViewErrorBag);

    $xpath = formXPath(Blade::render('<x-ui.form-segmented wire:model="taskStatus" label="Status" value="done" disabled help="Completed tasks cannot change status." :options="$options" />', [
        'options' => [['value' => 'todo', 'label' => 'To do'], ['value' => 'done', 'label' => 'Done']],
    ]));
    $group = $xpath->query('//*[@role="radiogroup"]')->item(0);

    expect($xpath->query('//*[@role="radio" and @disabled]')->length)->toBe(2)
        ->and(trim($xpath->query('//*[@id="'.$group->getAttribute('id').'-help"]')->item(0)->textContent))->toBe('Completed tasks cannot change status.');
});
