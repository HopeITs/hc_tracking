# Modal Redesign — Phase 0 (Kit + Project Pilot) Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Build the shared modal shell and form field kit from the modal redesign spec, convert the Add/Edit project modal to it as the pilot, verify it in the real app, and — after the user approves the pilot — finish the remaining kit components (confirm, segmented, multi-select, file).

**Spec:** `docs/superpowers/specs/2026-09-24-modal-redesign-design.md`. Approved mockups (brainstorming session scratchpad, `C:\Users\youss\AppData\Local\Temp\claude\C--xampp-htdocs-hc-tracking\6a386e98-b62a-451b-859d-0e2c2a761704\scratchpad\`): `layout.html` (option B), `fields.html`, `small-dialogs.html` in that scratchpad's `.superpowers/brainstorm/356-1790244894/content/`.

**Architecture:** `x-ui.modal` wraps `flux:modal` (native `<dialog>`, focus trap, `wire:model` boolean) with its own pinned header, scrolling body and footer; every close path (X, Cancel, Esc, backdrop) closes the native dialog, so one `wire:close` action runs for all of them. Fields are Blade components built on one CSS "box" that matches the header filter picker; dropdowns *are* the header filter picker (`flux:dropdown` + `flux:menu` inside `.filter-picker`), with a small Alpine layer (`formPicker`) for the bound value and the keyboard gaps Flux leaves inside a dialog. Long forms use `x-ui.form-section`, a rail of dots on one thread that folds to stacked headings in narrow dialogs.

**Tech Stack:** Laravel 13, PHP 8.5, Livewire 4.4 single-file pages, Flux 2.20 (flux-lite), Alpine (bundled with Livewire), Tailwind 4 (CSS-first in `resources/css/app.css`), Pest 5, Vite (vite-plus).

## Global Constraints

- PHP is `C:\php85\php.exe` (XAMPP's PHP is off-limits). In commands below `php` means `/c/php85/php.exe`. Commands given to the user use `.\php.cmd`.
- **Never commit.** Every "Stage" step is `git add` only; the user reviews every file.
- Branch `youssef-interfaces-edits`. No new dependencies, no new top-level folders.
- Livewire property names, actions, validation rules and `data-test` hooks are preserved. `flux:modal` is bound with `wire:model` to a boolean, never `:open`.
- Every visible string goes through `lang/en/*.php` **and** `lang/ar/*.php`. Arabic plurals use explicit ranges `{0}|{1}|{2}|[3,10]|[11,*]`. Arabic dates use Syrian month names with Western digits (Carbon `translatedFormat`, as the rest of the app).
- Colours only from approved tokens; no raw hex outside `app.css`. Red **text** uses `text-status-critical` / `var(--color-status-critical)`; red dots, borders and buttons use `primary`.
- Logical properties only (`ms/me/ps/pe/start/end`, `inset-inline-*`, `margin-inline-*`).
- Never the `@php(...)` one-line shorthand; always `@php … @endphp`. Every changed Blade file goes through the compile check (Task 1, Step 1).
- Design: no stat-tile grids or uniform bordered panels as primary structure; the rail uses the app's dot-thread motif.
- `.ai/rules/components-ui.md` says flux-lite has no menu JS. **That is stale for Flux 2.20:** `ui-menu`, `ui-menu-item`, `keep-open`, ↑/↓ navigation and type-ahead are all registered (verified in `vendor/livewire/flux/dist/flux-lite.min.js` and in the browser on 2026-09-24). Build on them.
- After PHP edits: `php vendor/bin/pint --dirty --format agent`. Run only the affected test files; the user runs the full suite at the end.
- Browser checks need the Vite dev server (`public/hot` present) and the app on `http://127.0.0.1:8000`. Claude never types passwords: the user signs in once in the built-in browser.

## Interpretations the spec does not spell out (approve with the plan)

1. **This plan covers Phase 0 only.** Phases 1–5 (mass rollout) get their own plan once the pilot is approved, because each form's field mapping depends on the approved kit, and the spec gates the rollout on that approval.
2. **Order inside Phase 0:** the components the pilot needs → the pilot → real-browser check and **your approval** → the four components the pilot does not use (confirm, segmented, multi-select, file). The spec lists all components under Phase 0; building the unused four after approval avoids redoing them if the pilot review changes the look.
3. **Project form grouping follows mockup B**: three rail sections — *Details* (name, status, start, end, live duration, description), *Placement* (city, program, owner), *Beneficiaries* (headline total + four groups, live "1,150 of 1,500 broken down" hint). Today's four fieldsets (Identity, Placement, Schedule, Beneficiary targets) go. New keys: `projects.section_details`, `section_details_hint`, `section_beneficiaries`, `duration`, `broken_down_of_headline`. The keys the old markup used (`section_identity`, `section_schedule`, `beneficiary_targets`, `breakdown_adds_up`, `the_headline_commitment_and_demographic_breakdown_are_record`) become unused and are left in place; removing them is a separate cleanup.
4. **Binding mechanism:** pickers bind through `x-modelable` + `wire:model` (Livewire's documented custom-control pattern) rather than `$wire.$set(prop, value, false)`. Same behaviour — deferred by default, `wire:model.live` for dependent fields, strings on the wire — plus server-side changes (edit prefill, reset on close) flow back into the picker automatically.
5. **Forms get `novalidate`.** Validation is server-side only, so every problem shows as the designed state (red box, red rail dot, "N fields need attention") instead of the browser's own bubble. `required` stays on native inputs for screen readers.
6. **The shell renders Cancel itself**, so Cancel, X, Esc and backdrop are guaranteed to be the same close path (they all close the native dialog; the `close` prop runs once through `wire:close`). Callers only supply the primary action(s).
7. **A small internal `x-ui.form-field`** renders label, help and error for every field type, so they cannot drift apart.
8. **Keyboard gaps in Flux, found in the browser today and fixed by the kit:** (a) Flux's menu type-ahead steals Latin letters typed into a menu's search box — the header City/Program/Owner filters have this bug now; (b) ↓ from the search box does not enter the list; (c) Esc with a menu open inside a dialog closes the whole dialog. **Task 6 applies fix (a) to `x-ui.header-filter-select` too**, so header and form pickers stay identical — say if you would rather leave the header filters untouched.
9. **Neutral confirmations** (archive, unlink): grey warning mark + a solid dark button, via a new `x-ui.button` tone `neutral`. Danger keeps the red mark and red button.
10. **The duration range** ("Mar – May 2026") uses the app's own month names (Carbon, Syrian names in Arabic) handed to the page, not the browser's `Intl`, so it matches every other date.
11. **`bisect_blade.php`** (named in the spec and `.ai/rules/views.md`) does not exist in the repo. Task 1 creates an equivalent compile-check script in the session scratchpad (not committed).
12. **Option parsing** from `<option>` children is written once, in `x-ui.form-select`; `x-ui.form-multiselect` is a thin wrapper that passes `multiple`. (The two header-filter components keep their own copies.)
13. **Menu width** is at least the trigger width, set each time the menu opens.

## File map

| File | Status | Responsibility |
|---|---|---|
| `resources/views/components/ui/form-field.blade.php` | new | label (+ required mark), control slot, help line, error line |
| `resources/views/components/ui/form-input.blade.php` | new | text/number/email/tel/date in the filter-look box, prefix/suffix |
| `resources/views/components/ui/form-textarea.blade.php` | new | same box, no resize handle, 3 rows by default |
| `resources/views/components/ui/form-select.blade.php` | new | the header filter picker as a form field (single; `multiple` added in Task 10) |
| `resources/views/components/ui/modal.blade.php` | new | the shell around `flux:modal` |
| `resources/views/components/ui/form-section.blade.php` | new | rail section: dot, title, hint, error count |
| `resources/views/components/ui/confirm-modal.blade.php` | new (Task 8) | confirmation preset of the shell |
| `resources/views/components/ui/form-segmented.blade.php` | new (Task 9) | 2–4 options, one click, radiogroup |
| `resources/views/components/ui/form-multiselect.blade.php` | new (Task 10) | `x-ui.form-select multiple` |
| `resources/views/components/ui/form-file.blade.php` | new (Task 11) | drop-or-browse file field with upload progress |
| `resources/js/form-fields.js` | new | Alpine `formPicker` (Task 2), `formSegmented` (Task 9), `formFile` (Task 11) |
| `resources/js/modal-shell.js` | new | Alpine `modalShell`: dialog label, reveal first error, upload gate |
| `resources/js/app.js` | modify | import the two new files |
| `resources/css/app.css` | modify | one new block "Form field kit" (fields, select states, shell, rail, segmented, file, confirm mark) |
| `resources/views/components/ui/header-filter-select.blade.php` | modify (Task 6) | search box keeps its keystrokes |
| `resources/views/components/ui/button.blade.php` | modify (Task 8) | `neutral` tone |
| `resources/views/pages/projects/⚡index.blade.php` | modify (Task 5) | the pilot: Add/Edit project modal |
| `lang/en/common.php`, `lang/ar/common.php` | modify | `required`, `fields_need_attention`, file keys (Task 11) |
| `lang/en/projects.php`, `lang/ar/projects.php` | modify | pilot keys |
| `tests/Feature/UiComponentsTest.php` | modify | component tests |
| `tests/Feature/ProjectDirectoryTest.php` | modify | pilot tests |

Test commands (Git Bash, repo root):

```bash
/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php
```

Compile check (created in Task 1; `$CHECK` below means this path):

```
C:/Users/youss/AppData/Local/Temp/claude/C--xampp-htdocs-hc-tracking/6f7d864d-a134-4be1-bc37-b08d8df5bcca/scratchpad/blade_compile_check.php
```

---

### Task 1: Field kit — `x-ui.form-field`, `x-ui.form-input`, `x-ui.form-textarea`

**Files:**
- Create: `$CHECK` (scratchpad, not staged)
- Create: `resources/views/components/ui/form-field.blade.php`
- Create: `resources/views/components/ui/form-input.blade.php`
- Create: `resources/views/components/ui/form-textarea.blade.php`
- Modify: `resources/css/app.css` (insert a new block before the line `.admin-directory [data-flux-error] {`)
- Test: `tests/Feature/UiComponentsTest.php`

**Interfaces:**
- Produces: `<x-ui.form-field :label :for :required :help :error>` (slot = control; wrapper carries `data-form-field`, merges caller `class`; label has `id="{for}-label"`, help `id="{for}-help"`, error `id="{for}-error"`).
- Produces: `<x-ui.form-input wire:model label [id] [type=text] [prefix] [suffix] [required] [help] [class] …>` — all non-prop attributes except `class` land on the `<input>`. Default id `field-{md5(model|label)[0..10]}`. Error from `$errors->first(model)` → `aria-invalid="true"`, `data-invalid` on the `.form-box`.
- Produces: `<x-ui.form-textarea wire:model label [id] [required] [help] [rows=3] [class]>`.
- Produces CSS classes: `.form-label`, `.form-required`, `.form-box`, `.form-box-multiline`, `.form-box-input`, `.form-affix`, `.form-help`, `.form-error`, and the end marker comment `/* End of the form field kit. */` that later tasks insert before.
- Produces test helper `formXPath(string $html): DOMXPath` in `UiComponentsTest.php`.

- [ ] **Step 1: Create the Blade compile check**

Write `$CHECK` with exactly:

```php
<?php

/*
 * Usage: C:\php85\php.exe blade_compile_check.php <file-or-directory> [...]
 *
 * Compiles each Blade file with the application's own compiler and lints the
 * compiled PHP with `php -l`. Stands in for the bisect_blade.php named in
 * .ai/rules/views.md, which is not in the repository.
 */

chdir('C:/xampp/htdocs/hc-tracking');

require 'vendor/autoload.php';

$app = require 'bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$compiler = $app->make('blade.compiler');
$files = [];

foreach (array_slice($argv, 1) as $path) {
    if (is_dir($path)) {
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));

        foreach ($iterator as $file) {
            if (str_ends_with($file->getFilename(), '.blade.php')) {
                $files[] = $file->getPathname();
            }
        }

        continue;
    }

    $files[] = $path;
}

$failures = 0;

foreach ($files as $file) {
    $temp = tempnam(sys_get_temp_dir(), 'blade');
    file_put_contents($temp, $compiler->compileString(file_get_contents($file)));

    $output = [];
    exec(escapeshellarg(PHP_BINARY).' -l '.escapeshellarg($temp).' 2>&1', $output, $status);
    unlink($temp);

    if ($status !== 0) {
        $failures++;
        echo "FAIL {$file}".PHP_EOL.'  '.implode(PHP_EOL.'  ', $output).PHP_EOL;
    }
}

echo ($failures === 0 ? 'OK '.count($files).' files' : "{$failures} of ".count($files).' failing').PHP_EOL;

exit($failures === 0 ? 0 : 1);
```

Run: `/c/php85/php.exe "$CHECK" resources/views/components/ui` → Expected: `OK 42 files` (already verified during planning).

- [ ] **Step 2: Write the failing tests**

In `tests/Feature/UiComponentsTest.php`, add `use Illuminate\Support\MessageBag;` to the imports (alphabetical, after `use Illuminate\Support\Facades\View;`), then append at the end of the file:

```php
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
```

- [ ] **Step 3: Run the tests to see them fail**

Run: `/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php --filter="form input|form textarea"`
Expected: FAIL — `Unable to locate a class or view for component [ui.form-input]` (and `ui.form-textarea`).

- [ ] **Step 4: Create `resources/views/components/ui/form-field.blade.php`**

```blade
@props([
    'label',
    'for',
    'required' => false,
    'help' => null,
    'error' => null,
])

{{-- The label, help line and error line every x-ui.form-* field shares, so no two field types can drift apart. --}}
<div {{ $attributes->class('min-w-0') }} data-form-field>
    <label id="{{ $for }}-label" for="{{ $for }}" class="form-label">
        {{ $label }}
        @if ($required)
            <span class="form-required" aria-hidden="true">*</span>
        @endif
    </label>

    {{ $slot }}

    @if ($help !== null && $help !== '')
        <p id="{{ $for }}-help" class="form-help">{{ $help }}</p>
    @endif

    @if ($error !== null && $error !== '')
        <p id="{{ $for }}-error" class="form-error">
            <flux:icon.exclamation-circle variant="micro" class="size-3.5 shrink-0" />
            {{ $error }}
        </p>
    @endif
</div>
```

- [ ] **Step 5: Create `resources/views/components/ui/form-input.blade.php`**

```blade
@props([
    'label',
    'id' => null,
    'type' => 'text',
    'prefix' => null,
    'suffix' => null,
    'required' => false,
    'help' => null,
])

@php
    /*
     * A text, number, email, phone or date field in the header filter picker's
     * box. Every attribute that is not a prop (wire:model, maxlength, min, step,
     * placeholder, autofocus...) lands on the <input>; only `class` stays on the
     * wrapper, so callers can place the field in their grid. The error message
     * is read from the bag under the wire:model name.
     */
    $model = (string) $attributes->wire('model')->value();
    $id ??= 'field-'.substr(md5($model.'|'.$label), 0, 10);
    $error = $model !== '' ? $errors->first($model) : '';
    $describedBy = collect([
        $prefix !== null ? $id.'-prefix' : null,
        $suffix !== null ? $id.'-suffix' : null,
        $help !== null ? $id.'-help' : null,
        $error !== '' ? $id.'-error' : null,
    ])->filter()->implode(' ');
@endphp

<x-ui.form-field :label="$label" :for="$id" :required="$required" :help="$help" :error="$error" :class="$attributes->get('class')">
    <div class="form-box" @if ($error !== '') data-invalid @endif>
        @if ($prefix !== null)
            <span id="{{ $id }}-prefix" class="form-affix">{{ $prefix }}</span>
        @endif

        <input
            id="{{ $id }}"
            type="{{ $type }}"
            {{ $attributes->except('class')->class(['form-box-input', 'tabular-nums' => $type === 'number']) }}
            @if ($required) required aria-required="true" @endif
            @if ($error !== '') aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        />

        @if ($suffix !== null)
            <span id="{{ $id }}-suffix" class="form-affix">{{ $suffix }}</span>
        @endif
    </div>
</x-ui.form-field>
```

- [ ] **Step 6: Create `resources/views/components/ui/form-textarea.blade.php`**

```blade
@props([
    'label',
    'id' => null,
    'required' => false,
    'help' => null,
])

@php
    /* Same box and states as x-ui.form-input; no resize handle, three rows unless the caller says otherwise. */
    $model = (string) $attributes->wire('model')->value();
    $id ??= 'field-'.substr(md5($model.'|'.$label), 0, 10);
    $error = $model !== '' ? $errors->first($model) : '';
    $describedBy = collect([
        $help !== null ? $id.'-help' : null,
        $error !== '' ? $id.'-error' : null,
    ])->filter()->implode(' ');
@endphp

<x-ui.form-field :label="$label" :for="$id" :required="$required" :help="$help" :error="$error" :class="$attributes->get('class')">
    <div class="form-box form-box-multiline" @if ($error !== '') data-invalid @endif>
        <textarea
            id="{{ $id }}"
            {{ $attributes->except('class')->merge(['rows' => 3])->class('form-box-input') }}
            @if ($required) required aria-required="true" @endif
            @if ($error !== '') aria-invalid="true" @endif
            @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        ></textarea>
    </div>
</x-ui.form-field>
```

- [ ] **Step 7: Add the field kit CSS**

In `resources/css/app.css`, insert immediately **before** the line `.admin-directory [data-flux-error] {`:

```css
/*
 * Form field kit (x-ui.form-*). Every field is the header filter picker's
 * 44px tinted box, so a form row never mixes looks: dropdown triggers take
 * that look from the .filter-picker rules above, every other control from
 * .form-box here.
 */
.form-label {
    display: block;
    margin-bottom: 0.375rem;
    color: var(--color-secondary-medium);
    font-size: 0.6875rem;
    font-weight: 600;
    line-height: 1rem;
    letter-spacing: 0.1em;
    text-transform: uppercase;
}

/* Tracking pulls Arabic letters apart, and Arabic has no capitals. */
.form-label:lang(ar) {
    letter-spacing: normal;
    text-transform: none;
}

.dark .form-label {
    color: var(--color-secondary-soft);
}

.form-required {
    color: var(--color-status-critical);
}

.form-box {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-height: 2.75rem;
    padding-inline: 0.875rem;
    border: 1px solid var(--color-secondary-soft);
    border-radius: 0.5rem;
    background-color: color-mix(in srgb, var(--color-secondary-soft) 38%, var(--color-white));
    color: var(--color-secondary-dark);
    font-size: 0.875rem;
    transition: border-color 150ms ease, background-color 150ms ease;
}

.form-box:hover {
    border-color: var(--color-secondary-light);
    background-color: var(--color-white);
}

.form-box:focus-within {
    border-color: var(--color-secondary-medium);
    background-color: var(--color-white);
    outline: 2px solid var(--color-secondary-medium);
    outline-offset: 2px;
}

.form-box[data-invalid] {
    border-color: var(--color-primary);
}

.form-box-multiline {
    align-items: stretch;
}

.form-box-input {
    flex: 1 1 auto;
    min-width: 0;
    height: 2.625rem;
    padding: 0;
    border: 0;
    background: transparent;
    color: inherit;
    font: inherit;
    outline: none;
}

textarea.form-box-input {
    height: auto;
    padding-block: 0.5rem;
    line-height: 1.4;
    resize: none;
}

.form-box-input::placeholder {
    color: var(--color-secondary-light);
}

.form-affix {
    flex-shrink: 0;
    color: var(--color-secondary-medium);
    font-size: 0.75rem;
    font-weight: 600;
}

.form-help {
    margin-top: 0.25rem;
    color: var(--color-secondary-medium);
    font-size: 0.75rem;
    line-height: 1rem;
}

.form-error {
    display: flex;
    align-items: center;
    gap: 0.25rem;
    margin-top: 0.25rem;
    color: var(--color-status-critical);
    font-size: 0.75rem;
    font-weight: 500;
    line-height: 1rem;
}

.dark .form-box {
    border-color: var(--color-secondary-medium);
    background-color: color-mix(in srgb, var(--color-secondary-medium) 70%, var(--color-secondary-dark));
    color: var(--color-white);
}

.dark .form-box:hover,
.dark .form-box:focus-within {
    background-color: var(--color-secondary-medium);
}

.dark .form-box[data-invalid] {
    border-color: var(--color-primary);
}

.dark .form-box-input {
    color-scheme: dark;
}

.dark .form-box-input::placeholder,
.dark .form-affix,
.dark .form-help {
    color: var(--color-secondary-soft);
}

/* End of the form field kit. */

```

- [ ] **Step 8: Run the tests to see them pass**

Run: `/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php`
Expected: PASS (all tests in the file, including the 4 new ones).

- [ ] **Step 9: Compile check and format**

Run: `/c/php85/php.exe "$CHECK" resources/views/components/ui/form-field.blade.php resources/views/components/ui/form-input.blade.php resources/views/components/ui/form-textarea.blade.php` → Expected: `OK 3 files`.
Run: `/c/php85/php.exe vendor/bin/pint --dirty --format agent` → Expected: no errors.

- [ ] **Step 10: Stage**

```bash
git add resources/views/components/ui/form-field.blade.php resources/views/components/ui/form-input.blade.php resources/views/components/ui/form-textarea.blade.php resources/css/app.css tests/Feature/UiComponentsTest.php
```

---

### Task 2: `x-ui.form-select` + `formPicker`

**Files:**
- Create: `resources/views/components/ui/form-select.blade.php`
- Create: `resources/js/form-fields.js`
- Modify: `resources/js/app.js`
- Modify: `resources/css/app.css` (insert before `/* End of the form field kit. */`)
- Test: `tests/Feature/UiComponentsTest.php`

**Interfaces:**
- Consumes: `x-ui.form-field`, `formXPath()` (Task 1).
- Produces: `<x-ui.form-select wire:model[.live] label [id] [placeholder] [required] [help] [disabled] [locked-reason] [searchable] [search-label] [value] [class] [autofocus] [data-test]>` with `<flux:select.option value="…">Label</flux:select.option>` (or `<option>`) children. Wrapper carries `data-form-select="{model}"`; trigger label span carries `data-trigger-label`; each menu item carries `data-option-value` / `data-option-label`. Current value = `value` prop, else read from the Livewire component (`data_get($__livewire, model)`).
- Produces: Alpine `formPicker({ value, multiple = false, placeholder = '', countTemplate = '' })` in `resources/js/form-fields.js` with `value` (x-modelable), `search`, `triggerLabel`, `isSelected(v)`, `matches(el)`, `choose(v)`, `prepareMenu()`, `focusSearch()`, `onKeydown(e)`.
- Produces CSS: `.form-select-placeholder`, invalid/disabled trigger states.

- [ ] **Step 1: Write the failing tests** — append to `tests/Feature/UiComponentsTest.php`:

```php
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
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php --filter="form select"`
Expected: FAIL — `Unable to locate a class or view for component [ui.form-select]`.

- [ ] **Step 3: Create `resources/views/components/ui/form-select.blade.php`**

```blade
@props([
    'label',
    'id' => null,
    'placeholder' => null,
    'required' => false,
    'help' => null,
    'disabled' => false,
    'lockedReason' => null,
    'searchable' => null,
    'searchLabel' => null,
    'value' => null,
])

@php
    /*
     * A form dropdown that is the header filter picker by construction: the
     * same flux:dropdown / flux:menu markup inside the same .filter-picker
     * hook, so app.css styles both from one set of rules. Choices come from
     * <option> children, the convention x-ui.header-filter-select already
     * uses, so a flux:select migrates by renaming its tag.
     *
     * The bound value lives in Alpine (formPicker, resources/js/form-fields.js)
     * and reaches Livewire through x-modelable, so wire:model stays deferred
     * unless the caller writes wire:model.live. The server renders the same
     * label and check mark first, reading the current value from the Livewire
     * component unless `value` is passed.
     */
    /* Livewire's wire() macro answers false, not null, when there is no binding. */
    $model = (string) $attributes->wire('model')->value();

    if ($model === '') {
        throw new InvalidArgumentException('The x-ui.form-select component requires a wire:model binding.');
    }

    $document = new DOMDocument('1.0', 'UTF-8');
    $previousLibxmlState = libxml_use_internal_errors(true);
    $document->loadHTML(
        '<?xml encoding="utf-8" ?><select>'.(string) $slot.'</select>',
        LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD,
    );
    libxml_clear_errors();
    libxml_use_internal_errors($previousLibxmlState);

    $options = [];

    foreach ($document->getElementsByTagName('option') as $option) {
        $options[] = [
            'disabled' => $option->hasAttribute('disabled'),
            'label' => trim(preg_replace('/\s+/u', ' ', $option->textContent) ?? $option->textContent),
            'value' => $option->getAttribute('value'),
        ];
    }

    $livewire = $__env->shared('__livewire');
    $rawValue = $value ?? ($livewire !== null ? data_get($livewire, $model) : null);
    $currentValue = $rawValue instanceof BackedEnum ? (string) $rawValue->value : (string) ($rawValue ?? '');
    $selectedLabel = collect($options)->firstWhere('value', $currentValue)['label'] ?? null;

    $id ??= 'field-'.substr(md5($model.'|'.$label), 0, 10);
    $searchable ??= count($options) > 7;
    $searchLabel ??= $label;
    $locked = $disabled && $lockedReason !== null;
    $helpText = $locked ? $lockedReason : $help;
    $error = $errors->first($model);
    $describedBy = collect([
        $helpText !== null ? $id.'-help' : null,
        $error !== '' ? $id.'-error' : null,
    ])->filter()->implode(' ');
@endphp

<x-ui.form-field
    :label="$label"
    :for="$id"
    :required="$required"
    :help="$helpText"
    :error="$error"
    :class="$attributes->get('class')"
    data-form-select="{{ $model }}"
>
    <div
        class="min-w-0"
        x-data="formPicker(@js(['value' => $currentValue, 'placeholder' => (string) $placeholder]))"
        x-modelable="value"
        {{ $attributes->whereStartsWith('wire:model') }}
        x-on:keydown.capture="onKeydown($event)"
    >
        <flux:dropdown position="bottom" align="start" class="filter-picker block! w-full!">
            <flux:button
                :id="$id"
                variant="outline"
                icon:trailing="chevron-up-down"
                class="w-full! justify-between!"
                :disabled="$disabled"
                :autofocus="$attributes->has('autofocus')"
                :aria-invalid="$error !== '' ? 'true' : null"
                :aria-describedby="$describedBy !== '' ? $describedBy : null"
                :data-test="$attributes->get('data-test')"
                x-ref="trigger"
                x-on:click="prepareMenu()"
                x-on:keydown.down="prepareMenu()"
            >
                <span class="flex min-w-0 items-center gap-1.5">
                    @if ($locked)
                        <flux:icon.lock-closed variant="micro" class="size-3.5 shrink-0 text-secondary-light" />
                    @endif

                    <span
                        class="{{ $selectedLabel === null ? 'form-select-placeholder ' : '' }}truncate"
                        data-trigger-label
                        x-bind:class="{ 'form-select-placeholder': triggerLabel === null }"
                        x-text="triggerLabel ?? placeholder"
                    >{{ $selectedLabel ?? $placeholder }}</span>
                </span>
            </flux:button>

            <flux:menu class="filter-menu" x-ref="menu" x-on:focus="focusSearch()">
                @if ($searchable)
                    <div class="border-b border-secondary-soft p-1.5 dark:border-secondary-medium">
                        <x-ui.input
                            type="search"
                            size="sm"
                            icon="magnifying-glass"
                            x-ref="search"
                            x-model="search"
                            :aria-label="$searchLabel"
                            :placeholder="$searchLabel"
                        />
                    </div>
                @endif

                @foreach ($options as $option)
                    <flux:menu.item
                        :data-option-value="$option['value']"
                        :data-option-label="$option['label']"
                        :disabled="$option['disabled']"
                        x-show="matches($el)"
                        x-bind:disabled="{{ $option['disabled'] ? 'true' : '! matches($el)' }}"
                        x-on:click="choose({{ Illuminate\Support\Js::from($option['value']) }})"
                        :wire:key="$id.'-'.md5($option['value'])"
                    >
                        <span class="min-w-0 truncate">{{ $option['label'] }}</span>

                        <flux:icon.check
                            class="ms-auto size-4 shrink-0 text-secondary-medium"
                            x-show="isSelected({{ Illuminate\Support\Js::from($option['value']) }})"
                            :style="$currentValue === $option['value'] ? null : 'display: none'"
                        />
                    </flux:menu.item>
                @endforeach
            </flux:menu>
        </flux:dropdown>
    </div>
</x-ui.form-field>
```

- [ ] **Step 4: Create `resources/js/form-fields.js`**

```js
/**
 * Form dropdowns (x-ui.form-select, x-ui.form-multiselect).
 *
 * The menu is Flux's own ui-dropdown + ui-menu, so it looks and positions
 * exactly like the header filter picker: it opens in the top layer above an
 * open dialog, flips near the viewport edge and moves focus with ↑/↓. This
 * adds what a form field needs on top of it:
 *
 * - `value` is x-modelable, so `wire:model` binds it (deferred by default,
 *   `.live` for dependent fields) and server-side changes flow back in.
 * - Labels are read from the rendered menu items, so a list the server
 *   re-renders (a dependent field) never leaves a stale label behind.
 * - Flux's type-ahead listens on the whole menu and steals Latin letters
 *   typed into the search box; those keystrokes stop at the box instead, and
 *   typing anywhere in an open searchable menu lands in it.
 * - ↓ from the search box enters the list, and Enter there picks the first
 *   match instead of submitting the surrounding form. Filtered-out items are
 *   disabled as well as hidden so Flux's arrow keys skip them.
 * - Inside a modal <dialog> an unhandled Esc closes the dialog too, so Esc
 *   with the menu open closes only the menu and returns focus to the trigger.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('formPicker', ({ value = '', multiple = false, placeholder = '', countTemplate = '' } = {}) => ({
        value,
        multiple,
        placeholder,
        countTemplate,
        search: '',

        normalize(item) {
            return item === null || item === undefined ? '' : String(item);
        },

        selectedValues() {
            if (! this.multiple) {
                return [this.normalize(this.value)];
            }

            return Array.isArray(this.value) ? this.value.map((item) => this.normalize(item)) : [];
        },

        isSelected(optionValue) {
            return this.selectedValues().includes(optionValue);
        },

        labelFor(optionValue) {
            const option = Array.from(this.$root.querySelectorAll('[data-option-value]'))
                .find((element) => element.dataset.optionValue === optionValue);

            return option ? option.dataset.optionLabel : null;
        },

        get triggerLabel() {
            const labels = this.selectedValues()
                .map((optionValue) => this.labelFor(optionValue))
                .filter((label) => label !== null);

            if (labels.length === 0) {
                return null;
            }

            if (! this.multiple || labels.length <= 2) {
                return labels.join(', ');
            }

            return this.countTemplate.replace('__COUNT__', labels.length.toLocaleString());
        },

        matches(element) {
            const query = this.search.trim().toLowerCase();

            return query === '' || element.textContent.toLowerCase().includes(query);
        },

        choose(optionValue) {
            if (this.multiple) {
                const current = this.selectedValues();

                this.value = current.includes(optionValue)
                    ? current.filter((item) => item !== optionValue)
                    : [...current, optionValue];

                return;
            }

            this.value = optionValue;
            this.closeMenu();
        },

        prepareMenu() {
            this.search = '';
            this.$refs.menu.style.minWidth = `${this.$refs.trigger.offsetWidth}px`;
        },

        menuIsOpen() {
            return this.$refs.menu.matches(':popover-open');
        },

        closeMenu() {
            if (this.menuIsOpen()) {
                this.$refs.menu.hidePopover();
            }

            this.$refs.trigger.focus();
        },

        focusSearch() {
            this.$refs.search?.focus();
        },

        firstOption() {
            return Array.from(this.$refs.menu.querySelectorAll('[data-option-value]'))
                .find((option) => ! option.disabled);
        },

        onKeydown(event) {
            if (! this.menuIsOpen()) {
                return;
            }

            if (event.key === 'Escape') {
                event.preventDefault();
                event.stopPropagation();
                this.closeMenu();

                return;
            }

            const search = this.$refs.search;

            if (! search) {
                return;
            }

            if (event.target === search) {
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    event.stopPropagation();
                    this.firstOption()?.focus();
                } else if (event.key === 'Enter') {
                    event.preventDefault();
                    event.stopPropagation();
                    this.firstOption()?.click();
                } else if (event.key.length === 1) {
                    event.stopPropagation();
                }

                return;
            }

            if (/^\S$/u.test(event.key) && ! event.ctrlKey && ! event.metaKey && ! event.altKey) {
                event.stopPropagation();
                search.focus();
            }
        },
    }));
});
```

- [ ] **Step 5: Import it** — in `resources/js/app.js`, add `import './form-fields';` directly after `import './count-up';`.

- [ ] **Step 6: Add the select CSS** — in `resources/css/app.css`, insert immediately **before** `/* End of the form field kit. */`:

```css
/* Form dropdowns are the header filter picker; these add the form-only states. */
.form-select-placeholder {
    color: var(--color-secondary-light);
    font-weight: 400;
}

.dark .form-select-placeholder {
    color: var(--color-secondary-soft);
}

.filter-picker [data-flux-button][aria-invalid='true'] {
    border-color: var(--color-primary) !important;
}

.filter-picker [data-flux-button]:disabled {
    cursor: not-allowed;
    opacity: 0.6;
}

```

- [ ] **Step 7: Run the tests to see them pass**

Run: `/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php`
Expected: PASS.

- [ ] **Step 8: Compile check and format**

Run: `/c/php85/php.exe "$CHECK" resources/views/components/ui/form-select.blade.php` → `OK 1 files`.
Run: `/c/php85/php.exe vendor/bin/pint --dirty --format agent`.

- [ ] **Step 9: Stage**

```bash
git add resources/views/components/ui/form-select.blade.php resources/js/form-fields.js resources/js/app.js resources/css/app.css tests/Feature/UiComponentsTest.php
```

---

### Task 3: `x-ui.modal` shell + `modalShell`

**Files:**
- Create: `resources/views/components/ui/modal.blade.php`
- Create: `resources/js/modal-shell.js`
- Modify: `resources/js/app.js`
- Modify: `resources/css/app.css` (before `/* End of the form field kit. */`)
- Modify: `lang/en/common.php`, `lang/ar/common.php`
- Test: `tests/Feature/UiComponentsTest.php`

**Interfaces:**
- Consumes: `formXPath()` (Task 1), `.form-required` (Task 1).
- Produces: `<x-ui.modal wire:model="showX" [size=sm|md|lg|xl] title [description] [submit="action"] [close="action"] [required-note]>` body `</x-ui.modal>` with optional named slots `mark` (before the title), `actions` (footer, after Cancel), `footerMeta` (footer start). The `<dialog>` gets classes `modal-shell modal-shell-{size}` + `max-w-md|xl|3xl|5xl` and `wire:close="{close}"`; with `submit` the frame is `<form wire:submit="{submit}" novalidate>`. The body (`.modal-shell-body`, container `modal`) is omitted when the default slot is empty. Actions sit in `<fieldset class="modal-shell-submit">`, disabled while uploads are in flight.
- Produces: Alpine `modalShell(titleId, submitAction)` with `uploads`, `close()`, `uploadStarted()`, `uploadEnded()`, `revealFirstError()`.
- Produces: `common.required`.

- [ ] **Step 1: Write the failing tests** — append to `tests/Feature/UiComponentsTest.php`:

```php
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
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php --filter="modal shell"`
Expected: FAIL — `Unable to locate a class or view for component [ui.modal]`.

- [ ] **Step 3: Add `common.required`**

`lang/en/common.php` — insert after `'reports' => 'Reports',`:

```php
    'required' => 'Required',
```

`lang/ar/common.php` — insert after `'reports' => 'التقارير',`:

```php
    'required' => 'مطلوب',
```

- [ ] **Step 4: Create `resources/views/components/ui/modal.blade.php`**

```blade
@props([
    'size' => 'md',
    'title',
    'description' => null,
    'submit' => null,
    'close' => null,
    'requiredNote' => false,
])

@php
    /*
     * The one shell every create/edit dialog and confirmation uses. It keeps
     * flux:modal underneath (native <dialog>, focus trap, Esc, backdrop, the
     * wire:model boolean) and draws its own header, body and footer: header
     * and footer stay pinned and only the body scrolls, and only when the
     * window is genuinely too short. Flux's floating X is switched off in
     * favour of the header's own.
     *
     * The X, Cancel, Esc and a backdrop click all close the native dialog, so
     * the `close` action runs exactly once, through wire:close, whichever way
     * the user leaves.
     */
    $maxWidth = match ($size) {
        'sm' => 'max-w-md',
        'md' => 'max-w-xl',
        'lg' => 'max-w-3xl',
        'xl' => 'max-w-5xl',
        default => throw new InvalidArgumentException("Unknown x-ui.modal size [{$size}]."),
    };

    /* Keyed on the binding, not the title: the title changes between add and edit. */
    $titleId = 'modal-title-'.substr(md5((string) ($attributes->wire('model')->value() ?: $title)), 0, 10);
    $submitAction = $submit === null ? null : Illuminate\Support\Str::before($submit, '(');
    $frameTag = $submit === null ? 'div' : 'form';

    $modalAttributes = $attributes->class([$maxWidth, 'modal-shell', 'modal-shell-'.$size]);

    if ($close !== null) {
        $modalAttributes = $modalAttributes->merge(['wire:close' => $close]);
    }
@endphp

<flux:modal :closable="false" :attributes="$modalAttributes">
    <{{ $frameTag }}
        class="modal-shell-frame"
        @if ($submit !== null) wire:submit="{{ $submit }}" novalidate @endif
        x-data="modalShell(@js($titleId), @js($submitAction))"
        x-on:livewire-upload-start="uploadStarted()"
        x-on:livewire-upload-finish="uploadEnded()"
        x-on:livewire-upload-error="uploadEnded()"
        x-on:livewire-upload-cancel="uploadEnded()"
    >
        <header class="modal-shell-header">
            @isset($mark)
                {{ $mark }}
            @endisset

            <div class="min-w-0 flex-1">
                <h2 id="{{ $titleId }}" class="modal-shell-title">{{ $title }}</h2>

                @if ($description !== null && $description !== '')
                    <p class="modal-shell-description">{{ $description }}</p>
                @endif
            </div>

            <button type="button" class="modal-shell-close" x-on:click="close()" aria-label="{{ __('common.close') }}">
                <flux:icon.x-mark class="size-5" />
            </button>
        </header>

        @if ($slot->hasActualContent())
            <div class="modal-shell-body">
                {{ $slot }}
            </div>
        @endif

        <footer class="modal-shell-footer">
            <div class="modal-shell-meta">
                @if (isset($footerMeta))
                    {{ $footerMeta }}
                @elseif ($requiredNote)
                    <span class="form-required" aria-hidden="true">*</span>
                    {{ __('common.required') }}
                @endif
            </div>

            <div class="modal-shell-actions">
                <x-ui.button type="button" x-on:click="close()">{{ __('common.cancel') }}</x-ui.button>

                <fieldset class="modal-shell-submit" x-bind:disabled="uploads > 0">
                    {{ $actions ?? '' }}
                </fieldset>
            </div>
        </footer>
    </{{ $frameTag }}>
</flux:modal>
```

- [ ] **Step 5: Create `resources/js/modal-shell.js`**

```js
/**
 * The shared modal shell (x-ui.modal).
 *
 * Flux keeps the <dialog>'s own attributes to itself, so the shell labels the
 * dialog with its title here. Every close control closes the native dialog,
 * which Flux and wire:close both observe, so one close action runs however
 * the user leaves. After a save comes back with errors it brings the first
 * invalid field into view and focuses it, and it counts file uploads in
 * flight so the submit actions stay disabled until every upload has landed.
 */
document.addEventListener('alpine:init', () => {
    window.Alpine.data('modalShell', (titleId, submitAction = null) => ({
        uploads: 0,
        stopWatchingSubmit: null,

        init() {
            this.dialog()?.setAttribute('aria-labelledby', titleId);

            if (submitAction !== null) {
                this.stopWatchingSubmit = this.$wire.intercept(submitAction, ({ onFinish }) => {
                    onFinish(() => this.revealFirstError());
                });
            }
        },

        destroy() {
            this.stopWatchingSubmit?.();
        },

        dialog() {
            return this.$root.closest('dialog');
        },

        close() {
            this.dialog()?.close();
        },

        uploadStarted() {
            this.uploads++;
        },

        uploadEnded() {
            this.uploads = Math.max(0, this.uploads - 1);
        },

        revealFirstError() {
            const field = this.$root.querySelector('[aria-invalid="true"]');

            if (! field) {
                return;
            }

            const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

            field.scrollIntoView({ block: 'center', behavior: reduceMotion ? 'auto' : 'smooth' });
            field.focus({ preventScroll: true });
        },
    }));
});
```

- [ ] **Step 6: Import it** — in `resources/js/app.js`, add `import './modal-shell';` directly after `import './gantt';`.

- [ ] **Step 7: Add the shell CSS** — in `resources/css/app.css`, insert immediately **before** `/* End of the form field kit. */`:

```css
/*
 * Modal shell (x-ui.modal): header and footer pinned, only the body scrolls,
 * and only when the window is genuinely too short. Below the sm breakpoint
 * the dialog becomes a full-height sheet with two full-width buttons.
 */
[data-flux-modal] > dialog.modal-shell:not([data-flux-flyout]):not([data-flux-modal-overflow]) {
    padding: 0;
    overflow: hidden;
    background-color: var(--color-white);
    color: var(--color-secondary-dark);
}

[data-flux-modal] > dialog.modal-shell[open]:not([data-flux-flyout]):not([data-flux-modal-overflow]) {
    display: flex;
    flex-direction: column;
}

.dark [data-flux-modal] > dialog.modal-shell:not([data-flux-flyout]):not([data-flux-modal-overflow]) {
    background-color: var(--color-secondary-dark);
    color: var(--color-white);
}

.modal-shell-frame {
    display: flex;
    flex: 1 1 auto;
    flex-direction: column;
    min-height: 0;
}

.modal-shell-header {
    display: flex;
    flex-shrink: 0;
    align-items: flex-start;
    gap: 0.75rem;
    padding: 1rem 1.5rem 0.875rem;
    border-bottom: 1px solid var(--color-secondary-soft);
}

.modal-shell-title {
    font-size: 1.125rem;
    font-weight: 700;
    line-height: 1.75rem;
}

.modal-shell-description {
    color: var(--color-secondary-medium);
    font-size: 0.875rem;
    line-height: 1.25rem;
}

.modal-shell-close {
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 2.25rem;
    height: 2.25rem;
    margin-inline-end: -0.5rem;
    border-radius: 0.375rem;
    color: var(--color-secondary-light);
}

.modal-shell-close:hover {
    background-color: var(--color-secondary-soft);
    color: var(--color-secondary-dark);
}

.modal-shell-close:focus-visible {
    outline: 2px solid var(--color-secondary-medium);
    outline-offset: 2px;
}

.modal-shell-body {
    flex: 1 1 auto;
    min-height: 0;
    padding: 1.25rem 1.5rem;
    overflow-y: auto;
    overscroll-behavior: contain;
    container: modal / inline-size;
}

.modal-shell-footer {
    display: flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 0.75rem 1.5rem;
    border-top: 1px solid var(--color-secondary-soft);
    background-color: var(--color-board-head);
}

.modal-shell-meta {
    color: var(--color-secondary-medium);
    font-size: 0.75rem;
}

.modal-shell-actions {
    display: flex;
    gap: 0.5rem;
    margin-inline-start: auto;
}

.modal-shell-submit {
    display: flex;
    gap: 0.5rem;
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}

/* Rail forms (xl) sit a little wider; their sections bring their own vertical rhythm. */
.modal-shell-xl .modal-shell-header {
    padding: 0.875rem 1.75rem 0.75rem;
}

.modal-shell-lg .modal-shell-title,
.modal-shell-xl .modal-shell-title {
    font-size: 1.25rem;
}

.modal-shell-xl .modal-shell-body {
    padding: 0 1.75rem;
}

.modal-shell-xl .modal-shell-footer {
    padding-inline: 1.75rem;
}

.dark .modal-shell-header,
.dark .modal-shell-footer {
    border-color: var(--color-secondary-medium);
}

.dark .modal-shell-footer {
    background-color: var(--color-secondary-dark);
}

.dark .modal-shell-description,
.dark .modal-shell-meta {
    color: var(--color-secondary-soft);
}

.dark .modal-shell-close:hover {
    background-color: var(--color-secondary-medium);
    color: var(--color-white);
}

@media (width < 40rem) {
    [data-flux-modal] > dialog.modal-shell:not([data-flux-flyout]):not([data-flux-modal-overflow]) {
        width: 100%;
        max-width: 100%;
        height: 100dvh;
        max-height: 100dvh;
        margin: 0;
        border-radius: 0;
    }

    .modal-shell-header,
    .modal-shell-xl .modal-shell-header {
        padding-inline: 1rem;
    }

    .modal-shell-body,
    .modal-shell-xl .modal-shell-body {
        padding-inline: 1rem;
    }

    .modal-shell-footer,
    .modal-shell-xl .modal-shell-footer {
        padding: 0.75rem 1rem;
    }

    .modal-shell-meta {
        display: none;
    }

    .modal-shell-actions {
        display: grid;
        grid-template-columns: repeat(2, minmax(0, 1fr));
        width: 100%;
    }

    .modal-shell-actions [data-flux-button] {
        width: 100%;
        min-height: 2.75rem;
    }

    .modal-shell-submit > * {
        flex: 1 1 0;
    }
}

```

- [ ] **Step 8: Run the tests to see them pass**

Run: `/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

- [ ] **Step 9: Compile check and format**

Run: `/c/php85/php.exe "$CHECK" resources/views/components/ui/modal.blade.php` → `OK 1 files`.
Run: `/c/php85/php.exe vendor/bin/pint --dirty --format agent`.

- [ ] **Step 10: Stage**

```bash
git add resources/views/components/ui/modal.blade.php resources/js/modal-shell.js resources/js/app.js resources/css/app.css lang/en/common.php lang/ar/common.php tests/Feature/UiComponentsTest.php
```

---

### Task 4: `x-ui.form-section` (the rail)

**Files:**
- Create: `resources/views/components/ui/form-section.blade.php`
- Modify: `resources/css/app.css` (before `/* End of the form field kit. */`)
- Modify: `lang/en/common.php`, `lang/ar/common.php`
- Test: `tests/Feature/UiComponentsTest.php`

**Interfaces:**
- Consumes: `formXPath()` (Task 1); the `modal` container on `.modal-shell-body` (Task 3).
- Produces: `<x-ui.form-section title [hint="static"] [:fields="['errorKey', …]"] [x-data …]>` fields `</x-ui.form-section>`; a named slot `hint` overrides the prop for live/rich hints. Renders `<section class="form-section" [data-invalid]>` → `.form-section-rail` (`h3.form-section-heading` with `.form-section-dot`, `p.form-section-hint`) + `.form-section-fields`. With errors on any listed key, the hint becomes `trans_choice('common.fields_need_attention', n)`.
- Produces CSS: `.form-section*`, `.form-section-figure` (bold figure inside a hint).
- Produces: `common.fields_need_attention`.

- [ ] **Step 1: Write the failing tests** — append to `tests/Feature/UiComponentsTest.php`:

```php
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
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php --filter="form section|attention count"`
Expected: FAIL — `Unable to locate a class or view for component [ui.form-section]`, and the Arabic test returns the key itself.

- [ ] **Step 3: Add `common.fields_need_attention`**

`lang/en/common.php` — insert after `'executive_summary' => 'Executive Summary',`:

```php
    'fields_need_attention' => ':count field needs attention|:count fields need attention',
```

`lang/ar/common.php` — insert after `'executive_summary' => 'الملخص التنفيذي',`:

```php
    'fields_need_attention' => '{0} لا توجد حقول تحتاج إلى مراجعة|{1} حقل واحد يحتاج إلى مراجعة|{2} حقلان يحتاجان إلى مراجعة|[3,10] :count حقول تحتاج إلى مراجعة|[11,*] :count حقلًا يحتاج إلى مراجعة',
```

- [ ] **Step 4: Create `resources/views/components/ui/form-section.blade.php`**

```blade
@props([
    'title',
    'hint' => null,
    'fields' => [],
])

@php
    /*
     * One section of a long form. In a wide dialog its name sits in a rail
     * beside the fields, one dot per section on a single thread; narrower
     * dialogs fold it into a heading above them (see .form-section in app.css).
     *
     * `fields` lists the validation keys this section holds: when any of them
     * fails, the dot turns red and the hint becomes the number of fields that
     * need attention. A `hint` slot replaces the plain-text prop for live
     * figures written in Alpine.
     */
    $invalidCount = collect($fields)->filter(fn (string $field): bool => $errors->has($field))->count();
@endphp

<section {{ $attributes->class('form-section') }} @if ($invalidCount > 0) data-invalid @endif>
    <div class="form-section-rail">
        <h3 class="form-section-heading">
            <span class="form-section-dot" aria-hidden="true"></span>
            {{ $title }}
        </h3>

        @if ($invalidCount > 0)
            <p class="form-section-hint">{{ trans_choice('common.fields_need_attention', $invalidCount, ['count' => $invalidCount]) }}</p>
        @elseif ($hint !== null && trim((string) $hint) !== '')
            <p class="form-section-hint">{{ $hint }}</p>
        @endif
    </div>

    <div class="form-section-fields min-w-0">
        {{ $slot }}
    </div>
</section>
```

- [ ] **Step 5: Add the rail CSS** — in `resources/css/app.css`, insert immediately **before** `/* End of the form field kit. */`:

```css
/*
 * Rail sections (x-ui.form-section). In a wide dialog each section's name sits
 * in a start-side rail, one dot per section on a single thread (the app's
 * dot-thread motif), so headings cost width instead of height. Narrower
 * dialogs fold the rail into a dot + hairline heading above the fields.
 */
.form-section {
    display: grid;
    gap: 0.75rem;
    padding-block: 0.75rem;
}

.form-section-rail {
    position: relative;
    min-width: 0;
}

.form-section-heading {
    display: flex;
    align-items: center;
    gap: 0.625rem;
    font-size: 0.875rem;
    font-weight: 600;
    line-height: 1.25rem;
}

.form-section-heading::after {
    content: '';
    flex: 1 1 auto;
    height: 1px;
    background-color: var(--color-secondary-soft);
}

.form-section-dot {
    position: relative;
    z-index: 1;
    flex-shrink: 0;
    width: 7px;
    height: 7px;
    border-radius: 9999px;
    background-color: var(--color-secondary-dark);
    box-shadow: 0 0 0 4px var(--color-white);
}

.form-section[data-invalid] .form-section-dot {
    background-color: var(--color-primary);
}

.form-section-hint {
    margin-top: 0.125rem;
    margin-inline-start: 17px;
    color: var(--color-secondary-medium);
    font-size: 0.75rem;
    line-height: 1rem;
    font-variant-numeric: tabular-nums;
}

.form-section[data-invalid] .form-section-hint {
    color: var(--color-status-critical);
}

.form-section-figure {
    color: var(--color-secondary-dark);
    font-weight: 600;
}

.dark .form-section-heading::after {
    background-color: var(--color-secondary-medium);
}

.dark .form-section-dot {
    background-color: var(--color-white);
    box-shadow: 0 0 0 4px var(--color-secondary-dark);
}

.dark .form-section-hint {
    color: var(--color-secondary-soft);
}

.dark .form-section-figure {
    color: var(--color-white);
}

@container modal (width >= 56rem) {
    .form-section {
        grid-template-columns: 10rem minmax(0, 1fr);
        column-gap: 2rem;
    }

    .form-section + .form-section {
        border-top: 1px solid color-mix(in srgb, var(--color-secondary-soft) 70%, transparent);
    }

    .dark .form-section + .form-section {
        border-top-color: var(--color-secondary-medium);
    }

    .form-section-heading::after {
        content: none;
    }

    /* The thread: out of the first dot, through every section, into the last dot. */
    .form-section-rail::before {
        content: '';
        position: absolute;
        inset-inline-start: 3px;
        top: -0.75rem;
        bottom: -0.75rem;
        width: 1px;
        background-color: var(--color-secondary-soft);
    }

    .form-section:first-child .form-section-rail::before {
        top: 0.625rem;
    }

    .form-section:last-child .form-section-rail::before {
        bottom: auto;
        height: 1.375rem;
    }

    .form-section:only-child .form-section-rail::before {
        content: none;
    }

    .dark .form-section-rail::before {
        background-color: var(--color-secondary-medium);
    }
}

```

- [ ] **Step 6: Run the tests to see them pass**

Run: `/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php tests/Feature/LocalizationTest.php`
Expected: PASS.

- [ ] **Step 7: Compile check and format**

Run: `/c/php85/php.exe "$CHECK" resources/views/components/ui/form-section.blade.php` → `OK 1 files`.
Run: `/c/php85/php.exe vendor/bin/pint --dirty --format agent`.

- [ ] **Step 8: Stage**

```bash
git add resources/views/components/ui/form-section.blade.php resources/css/app.css lang/en/common.php lang/ar/common.php tests/Feature/UiComponentsTest.php
```

---

### Task 5: Pilot — the Add/Edit project modal

**Files:**
- Modify: `resources/views/pages/projects/⚡index.blade.php` (the `@php` block after the class, around `$durationForms`; the `<flux:modal wire:model="showProjectModal" …>` block, currently lines 1686–1813)
- Modify: `lang/en/projects.php`, `lang/ar/projects.php`
- Test: `tests/Feature/ProjectDirectoryTest.php`

**Interfaces:**
- Consumes: `x-ui.modal`, `x-ui.form-section`, `x-ui.form-input`, `x-ui.form-textarea`, `x-ui.form-select` (Tasks 1–4).
- Unchanged Livewire contract: properties `showProjectModal`, `editingProjectId`, `name`, `description`, `projectStatus`, `startDate`, `endDate`, `targetBeneficiaries`, `targetMen`, `targetWomen`, `targetBoys`, `targetGirls`, `cityId`, `programId`, `userId`; actions `openCreateModal`, `openEditModal`, `saveProject`, `closeProjectModal`; hook `data-test="save-project-button"`. **No PHP class change.** The archive modal (`showArchiveModal`) is untouched until Phase 3.

- [ ] **Step 1: Write the failing tests**

Append to `tests/Feature/ProjectDirectoryTest.php` (expected labels are written out as known values, per the testing rules):

```php
test('the project form lays its fields along a three-section rail with no native selects', function () {
    $user = User::factory()->create();

    $html = Livewire::actingAs($user)->test('pages::projects.index')->call('openCreateModal')->html();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath = new DOMXPath($document);
    $dialog = '//dialog[contains(@class, "modal-shell")][.//*[@data-test="save-project-button"]]';
    $headings = array_map(
        fn (DOMNode $heading): string => trim($heading->textContent),
        iterator_to_array($xpath->query($dialog.'//*[contains(@class, "form-section-heading")]')),
    );

    expect($xpath->query($dialog)->length)->toBe(1)
        ->and($headings)->toBe(['Details', 'Placement', 'Beneficiaries'])
        ->and($xpath->query($dialog.'//select')->length)->toBe(0)
        ->and($xpath->query($dialog.'//*[@data-form-select]')->length)->toBe(4)
        ->and($xpath->query($dialog.'[@*[name()="wire:close"]="closeProjectModal"]')->length)->toBe(1)
        ->and($xpath->query($dialog.'//form[@*[name()="wire:submit"]="saveProject"]')->length)->toBe(1);
});

test('a failed project save points at the rail sections that hold the problems', function () {
    $user = User::factory()->create();

    Livewire::actingAs($user)
        ->test('pages::projects.index')
        ->call('openCreateModal')
        ->call('saveProject')
        ->assertHasErrors(['name', 'cityId', 'programId', 'userId'])
        ->assertSeeText('1 field needs attention')
        ->assertSeeText('3 fields need attention')
        ->assertSeeHtml('aria-invalid="true"');
});

test('editing a project shows its saved choices in the form pickers', function () {
    $user = User::factory()->create();
    $city = insertHrCity('Idlib', 'إدلب');
    $owner = insertHrEmployee(firstNameEn: 'Lina', lastNameEn: 'Haddad', firstNameAr: 'لينا', lastNameAr: 'حداد');
    $program = Program::factory()->create();
    $project = Project::factory()->create([
        'status' => ProjectStatus::Active,
        'city_id' => $city,
        'program_id' => $program->id,
        'user_id' => $owner,
    ]);

    $html = Livewire::actingAs($user)->test('pages::projects.index')->call('openEditModal', $project->id)->html();
    $document = new DOMDocument;
    @$document->loadHTML('<?xml encoding="utf-8" ?>'.$html);
    $xpath = new DOMXPath($document);
    $triggerLabel = fn (string $model): string => trim($xpath->query('//*[@data-form-select="'.$model.'"]//*[@data-trigger-label]')->item(0)->textContent);

    expect($triggerLabel('projectStatus'))->toBe('Active')
        ->and($triggerLabel('cityId'))->toBe('Idlib · إدلب')
        ->and($triggerLabel('programId'))->toBe($program->name)
        ->and($triggerLabel('userId'))->toBe('Lina Haddad · لينا حداد');
});
```

- [ ] **Step 2: Run the tests to see them fail**

Run: `/c/php85/php.exe artisan test --compact tests/Feature/ProjectDirectoryTest.php --filter="rail|pickers"`
Expected: FAIL — no `dialog.modal-shell` in the page yet.

- [ ] **Step 3: Add the pilot keys**

`lang/en/projects.php`:
- after `'breakdown_adds_up' => …,` insert `    'broken_down_of_headline' => ':breakdown of :headline broken down',`
- before `'duration_days' => …,` insert `    'duration' => 'Duration',`
- before `'section_identity' => 'Identity',` insert:

```php
    'section_beneficiaries' => 'Beneficiaries',
    'section_details' => 'Details',
    'section_details_hint' => 'Name, lifecycle & dates',
```

`lang/ar/projects.php` (same positions):
- `    'broken_down_of_headline' => 'تم توزيع :breakdown من أصل :headline',`
- `    'duration' => 'المدة',`
- and:

```php
    'section_beneficiaries' => 'المستفيدون',
    'section_details' => 'التفاصيل',
    'section_details_hint' => 'الاسم ودورة الحياة والتواريخ',
```

- [ ] **Step 4: Hand the page its month names** — in the view's `@php` block (after the class), directly after the closing `];` of `$durationForms`, add:

```php

    /* Month names in the reader's language (Syrian names in Arabic), so the
       project modal's live duration range matches every other date here. */
    $monthNames = collect(range(1, 12))
        ->map(fn (int $month): string => CarbonImmutable::create(2000, $month, 1)->translatedFormat(__('formats.month_short')))
        ->all();
```

- [ ] **Step 5: Replace the project modal** — replace the whole block from `<flux:modal wire:model="showProjectModal"` through its closing `</flux:modal>` (the one before the `{{-- Archiving is reversible … --}}` comment) with:

```blade
    <x-ui.modal
        wire:model="showProjectModal"
        size="xl"
        :title="$editingProjectId === null ? __('projects.add_project') : __('projects.edit_project')"
        :description="__('projects.define_delivery_ownership_lifecycle_schedule_and_beneficiary')"
        submit="saveProject"
        close="closeProjectModal"
        required-note
    >
        {{-- One Alpine scope for the live figures: the duration under Details and the breakdown beside Beneficiaries. --}}
        <div
            class="contents"
            x-data="{
                forms: @js($durationForms),
                months: @js($monthNames),
                breakdownTemplate: @js(e(__('projects.broken_down_of_headline', ['breakdown' => '__BREAKDOWN__', 'headline' => '__HEADLINE__']))),
                get durationDays() {
                    const start = Date.parse($wire.startDate);
                    const end = Date.parse($wire.endDate);

                    if (Number.isNaN(start) || Number.isNaN(end)) {
                        return 0;
                    }

                    return Math.max(0, Math.floor((end - start) / 86400000) + 1);
                },
                get durationLabel() {
                    const days = this.durationDays;

                    if (days < 1) {
                        return '';
                    }

                    const form = days <= 3 ? this.forms[days] : (days <= 10 ? this.forms[3] : this.forms[11]);

                    return form.replace('__N__', days.toLocaleString());
                },
                get durationRange() {
                    if (this.durationDays < 1) {
                        return '';
                    }

                    const [startYear, startMonth] = $wire.startDate.split('-').map(Number);
                    const [endYear, endMonth] = $wire.endDate.split('-').map(Number);
                    const start = this.months[startMonth - 1];
                    const end = this.months[endMonth - 1];

                    if (startYear !== endYear) {
                        return `${start} ${startYear} – ${end} ${endYear}`;
                    }

                    return startMonth === endMonth ? `${start} ${startYear}` : `${start} – ${end} ${startYear}`;
                },
                get breakdownHint() {
                    const breakdown = [$wire.targetMen, $wire.targetWomen, $wire.targetBoys, $wire.targetGirls]
                        .reduce((total, value) => total + (Number(value) || 0), 0);
                    const headline = Number($wire.targetBeneficiaries) || 0;

                    if (breakdown === 0 && headline === 0) {
                        return '';
                    }

                    return this.breakdownTemplate
                        .replace('__BREAKDOWN__', `<b class='form-section-figure'>${breakdown.toLocaleString()}</b>`)
                        .replace('__HEADLINE__', headline.toLocaleString());
                },
            }"
        >
            <x-ui.form-section
                :title="__('projects.section_details')"
                :hint="__('projects.section_details_hint')"
                :fields="['name', 'projectStatus', 'startDate', 'endDate', 'description']"
            >
                <div class="grid grid-cols-2 gap-x-4 gap-y-3 @4xl/modal:grid-cols-3">
                    <x-ui.form-input wire:model="name" :label="__('projects.project_name')" maxlength="255" required autofocus class="col-span-2" />

                    <x-ui.form-select wire:model="projectStatus" :label="__('common.status')" required class="col-span-2 @4xl/modal:col-span-1">
                        @foreach (ProjectStatus::cases() as $statusOption)
                            <flux:select.option :value="$statusOption->value">{{ $statusOption->label() }}</flux:select.option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-input wire:model="startDate" :label="__('common.start_date')" type="date" />
                    <x-ui.form-input wire:model="endDate" :label="__('projects.end_date')" type="date" />

                    <div class="col-span-2 min-w-0 @4xl/modal:col-span-1">
                        <span class="form-label">{{ __('projects.duration') }}</span>
                        <p class="flex min-h-11 flex-wrap items-center gap-x-2 tabular-nums">
                            <span class="text-lg font-bold text-secondary-dark dark:text-white" x-text="durationLabel || '—'">—</span>
                            <span class="text-xs font-medium text-secondary-medium dark:text-secondary-soft" x-text="durationRange"></span>
                        </p>
                    </div>

                    <x-ui.form-textarea wire:model="description" :label="__('common.description')" rows="2" class="col-span-2 @4xl/modal:col-span-3" />
                </div>
            </x-ui.form-section>

            <x-ui.form-section :title="__('projects.section_placement')" :fields="['cityId', 'programId', 'userId']">
                <div class="grid gap-x-4 gap-y-3 @2xl/modal:grid-cols-3">
                    <x-ui.form-select wire:model="cityId" :label="__('common.city')" :placeholder="__('projects.select_a_city')" required>
                        @foreach ($this->cityOptions as $cityOption)
                            <flux:select.option :value="$cityOption->id">{{ $cityOption->name }} · {{ $cityOption->secondary_name }}</flux:select.option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-select wire:model="programId" :label="__('common.program')" :placeholder="__('projects.select_a_program')" required>
                        @foreach ($this->programOptions as $programOption)
                            <flux:select.option :value="$programOption->id">{{ $programOption->name }}</flux:select.option>
                        @endforeach
                    </x-ui.form-select>

                    <x-ui.form-select wire:model="userId" :label="__('projects.owner')" :placeholder="__('projects.select_an_owner')" required>
                        @foreach ($this->userOptions as $userOption)
                            <flux:select.option :value="$userOption->id">{{ $userOption->name }} · {{ $userOption->secondary_name }}</flux:select.option>
                        @endforeach
                    </x-ui.form-select>
                </div>
            </x-ui.form-section>

            <x-ui.form-section
                :title="__('projects.section_beneficiaries')"
                :fields="['targetBeneficiaries', 'targetMen', 'targetWomen', 'targetBoys', 'targetGirls']"
            >
                <x-slot:hint>
                    <span x-show="breakdownHint !== ''" x-html="breakdownHint" style="display: none"></span>
                </x-slot:hint>

                <div class="grid grid-cols-2 gap-3 @2xl/modal:grid-cols-5">
                    <x-ui.form-input wire:model="targetBeneficiaries" :label="__('projects.headline_total')" type="number" min="0" step="1" class="col-span-2 @2xl/modal:col-span-1" />
                    <x-ui.form-input wire:model="targetMen" :label="__('projects.men')" type="number" min="0" step="1" />
                    <x-ui.form-input wire:model="targetWomen" :label="__('projects.women')" type="number" min="0" step="1" />
                    <x-ui.form-input wire:model="targetBoys" :label="__('projects.boys')" type="number" min="0" step="1" />
                    <x-ui.form-input wire:model="targetGirls" :label="__('projects.girls')" type="number" min="0" step="1" />
                </div>
            </x-ui.form-section>
        </div>

        <x-slot:actions>
            <x-ui.button type="submit" tone="primary" data-test="save-project-button">
                {{ $editingProjectId === null ? __('projects.create_project') : __('common.save_changes') }}
            </x-ui.button>
        </x-slot:actions>
    </x-ui.modal>
```

(`x-html` is safe here: the template is HTML-escaped on the server with `e()` and the only inserted values are numbers.)

- [ ] **Step 6: Run the page's tests**

Run: `/c/php85/php.exe artisan test --compact tests/Feature/ProjectDirectoryTest.php tests/Feature/UiComponentsTest.php tests/Feature/LocalizationTest.php`
Expected: PASS — the 3 new tests and every existing test (the save/validation tests use `->set()`, which the new markup does not affect).

- [ ] **Step 7: Compile check and format**

Run: `/c/php85/php.exe "$CHECK" "resources/views/pages/projects/⚡index.blade.php"` → `OK 1 files`.
Run: `/c/php85/php.exe vendor/bin/pint --dirty --format agent`.

- [ ] **Step 8: Stage**

```bash
git add "resources/views/pages/projects/⚡index.blade.php" lang/en/projects.php lang/ar/projects.php tests/Feature/ProjectDirectoryTest.php
```

---

### Task 6: Header filters keep their search keystrokes (flagged — interpretation 8)

**Files:**
- Modify: `resources/views/components/ui/header-filter-select.blade.php` (the `<x-ui.input type="search" …>` inside `flux:menu`)
- Test: `tests/Feature/UiComponentsTest.php`

**Interfaces:**
- Consumes: `formXPath()` (Task 1).
- Produces: no API change; the header filter's search input gains `x-on:keydown="$event.key.length === 1 && $event.stopPropagation()"`.

- [ ] **Step 1: Write the failing test** — append:

```php
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
```

- [ ] **Step 2: Run it to see it fail**

Run: `/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php --filter="keystrokes"`
Expected: FAIL — attribute is empty.

- [ ] **Step 3: Implement** — in `header-filter-select.blade.php`, add one attribute to the search input so it reads:

```blade
                    <x-ui.input
                        type="search"
                        size="sm"
                        icon="magnifying-glass"
                        x-model="optionSearch"
                        x-on:keydown="$event.key.length === 1 && $event.stopPropagation()"
                        :aria-label="$searchLabel"
                        :placeholder="$searchLabel"
                    />
```

and, in the existing `@php` block, add this comment directly above `$model = $attributes->wire('model')->value();`:

```php
    /* Flux's menu type-ahead listens on the whole menu; single-character keys stop at the search box so typing filters instead of jumping. */
```

- [ ] **Step 4: Run the tests** — `/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php` → PASS.

- [ ] **Step 5: Compile check** — `/c/php85/php.exe "$CHECK" resources/views/components/ui/header-filter-select.blade.php` → `OK 1 files`; then `/c/php85/php.exe vendor/bin/pint --dirty --format agent`.

- [ ] **Step 6: Stage**

```bash
git add resources/views/components/ui/header-filter-select.blade.php tests/Feature/UiComponentsTest.php
```

---

### Task 7: Real-browser verification of the pilot — then STOP for approval

**Files:** none changed unless a defect is found (fix it in the component or CSS it belongs to, add a regression test where a test can see it, re-run Tasks 1–5's test files, stage).

- [ ] **Step 1: Preconditions.** Confirm `public/hot` exists (Vite dev server running) and `http://127.0.0.1:8000` answers. If Vite is not running, ask the user to run `npm run dev` (or `composer run dev`). Open the built-in browser at `http://127.0.0.1:8000/projects`; if it lands on the sign-in page, **ask the user to sign in** and wait.

- [ ] **Step 2: Desktop, light, English — 1366×768 laptop.** `resize_window` to width 1366, height 648 (the visible browser area of a 1366×768 laptop). Click **Add project**. Check with `javascript_tool`:

```js
(() => {
    const dialog = document.querySelector('dialog.modal-shell[open]');
    const body = dialog.querySelector('.modal-shell-body');
    return {
        dialogHeight: dialog.getBoundingClientRect().height,
        bodyScrolls: body.scrollHeight > body.clientHeight,
        focused: document.activeElement?.getAttribute('wire:model'),
        labelledBy: dialog.getAttribute('aria-labelledby'),
    };
})()
```

Expected: `bodyScrolls: false`, `focused: "name"`, `labelledBy` starts with `modal-title-`. Screenshot.

- [ ] **Step 3: Validation state.** Click **Create project** with the form empty. Expected: the Details and Placement dots turn red with "1 field needs attention" / "3 fields need attention"; Name, City, Program, Owner get red borders and messages; focus lands on Name; the body still does not scroll at 1366×648. Screenshot.

- [ ] **Step 4: Pickers, keyboard first.** Tab to **City**, press ↓ (menu opens, first city focused), type `al` (letters land in the search box — **not** a jump to a city), press ↓ then Enter → the trigger shows the city, the menu closes, focus is back on the trigger. Open **Owner**, press Esc → only the menu closes; the dialog stays open. Press Esc again → the dialog closes. Reopen **Add project** → the form is empty (the close action ran). Pick **Status** with the mouse → check mark moves; menu is at least as wide as the trigger. Open **Owner** (last row of pickers) → the menu is drawn over the dialog's edge, never cut off by it.

- [ ] **Step 5: Live rail figures.** Enter start 2026-03-01, end 2026-05-31 → Duration shows "92 days" and "Mar – May 2026". Enter total 1500 and men 320, women 410, boys 200, girls 220 → Beneficiaries hint reads "**1,150** of 1,500 broken down".

- [ ] **Step 6: Save and edit.** Fill the required pickers, **Create project** → while the request runs the button shows Flux's spinner and cannot be pressed again; then a toast, and the dialog closes. Open **Edit** on that project → every picker shows its saved label; Cancel → closes; the X → closes; a backdrop click → closes.

- [ ] **Step 7: Dark mode.** `javascript_tool`: `document.documentElement.classList.add('dark')`. Reopen the modal, trigger the validation state, open a menu. Check contrast of labels, placeholders, hints, red text (`text-status-critical`), dots, footer. Screenshot. Remove the class afterwards.

- [ ] **Step 8: Arabic.** Switch language from the sidebar (this saves the user's own locale; switch back at the end). Reopen the modal: rail on the right, thread and dots mirrored, close button on the left, chevrons fine, labels without letter-spacing, month names Syrian (آذار – أيار), "حقلان يحتاجان إلى مراجعة"-style counts. Screenshot light and dark. Switch back to English.

- [ ] **Step 9: Phone.** `resize_window` preset `mobile` and reload. Open **Add project**: full-height sheet, rail folded into dot + hairline headings, fields one per row (dates two-up, men/women and boys/girls two-up), footer two full-width 44px buttons, only the middle scrolls. Open **Owner** near the bottom → the menu flips upward instead of running off-screen. Screenshot. Then `resize_window` preset `desktop`.

- [ ] **Step 10: Report and STOP.** Summarise what was checked with the screenshots, list any deviations and fixes, and **ask the user to approve the pilot. Do not start Task 8 until they do.** Their review notes may change the kit; apply them first (with tests) and re-run this task's checks.

---

### Task 8: `x-ui.confirm-modal` + `x-ui.button` neutral tone *(after pilot approval)*

**Files:**
- Create: `resources/views/components/ui/confirm-modal.blade.php`
- Modify: `resources/views/components/ui/button.blade.php`
- Modify: `resources/css/app.css` (before `/* End of the form field kit. */`)
- Test: `tests/Feature/UiComponentsTest.php`

**Interfaces:**
- Consumes: `x-ui.modal` (mark slot, actions slot), `formXPath()`.
- Produces: `<x-ui.confirm-modal wire:model="showX" title [message] confirm="method(args)" confirm-label [confirm-test] [tone=danger|neutral] [close]>` optional body `</x-ui.confirm-modal>`. Danger: red mark + `primary` button. Neutral: grey mark + `neutral` button. Wording is passed in by callers unchanged.
- Produces: `x-ui.button tone="neutral"`.

- [ ] **Step 1: Write the failing tests** — append, and extend the existing button dataset:

In the existing test `the ui button renders one tone per variant`, add a dataset row after `'ghost' => ['ghost', 'bg-transparent!'],`:

```php
    'neutral' => ['neutral', 'bg-secondary-dark!'],
```

Append:

```php
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
```

- [ ] **Step 2: Run to see them fail** — `/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php --filter="confirmation|confirm modal|one tone per variant"` → FAIL (missing component; unknown tone `neutral`).

- [ ] **Step 3: Add the neutral tone** — in `button.blade.php`, add after the `'ghost' => …,` line:

```php
        'neutral' => 'border-secondary-dark! bg-secondary-dark! text-white! hover:border-secondary-medium! hover:bg-secondary-medium! dark:border-white! dark:bg-white! dark:text-secondary-dark! dark:hover:bg-secondary-soft!',
```

- [ ] **Step 4: Create `resources/views/components/ui/confirm-modal.blade.php`**

```blade
@props([
    'title',
    'message' => null,
    'confirm',
    'confirmLabel',
    'confirmTest' => null,
    'tone' => 'danger',
    'close' => null,
])

@php
    /*
     * A confirmation in the same shell as every form: a small warning mark
     * beside the title, the consequence as its description, then Cancel and
     * the action. `danger` (delete) is red; `neutral` (archive, unlink) is a
     * grey mark and a dark button, because nothing is lost.
     */
    [$markClasses, $buttonTone] = match ($tone) {
        'danger' => ['bg-primary/10 text-status-critical', 'primary'],
        'neutral' => ['bg-secondary-soft text-secondary-dark dark:bg-secondary-medium dark:text-white', 'neutral'],
        default => throw new InvalidArgumentException("Unknown x-ui.confirm-modal tone [{$tone}]."),
    };
@endphp

<x-ui.modal size="sm" :title="$title" :description="$message" :close="$close" :attributes="$attributes">
    <x-slot:mark>
        <span class="confirm-mark {{ $markClasses }}" aria-hidden="true">
            <flux:icon.exclamation-triangle variant="mini" class="size-4" />
        </span>
    </x-slot:mark>

    {{ $slot }}

    <x-slot:actions>
        <x-ui.button type="button" :tone="$buttonTone" wire:click="{{ $confirm }}" :data-test="$confirmTest">{{ $confirmLabel }}</x-ui.button>
    </x-slot:actions>
</x-ui.modal>
```

- [ ] **Step 5: Add the mark CSS** — before `/* End of the form field kit. */`:

```css
/* Confirmations (x-ui.confirm-modal): the small warning mark beside the title. */
.confirm-mark {
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    margin-top: 0.125rem;
    border-radius: 9999px;
}

```

- [ ] **Step 6: Run the tests** — `/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php` → PASS.

- [ ] **Step 7: Compile check and format** — `/c/php85/php.exe "$CHECK" resources/views/components/ui/confirm-modal.blade.php resources/views/components/ui/button.blade.php` → `OK 2 files`; pint.

- [ ] **Step 8: Stage**

```bash
git add resources/views/components/ui/confirm-modal.blade.php resources/views/components/ui/button.blade.php resources/css/app.css tests/Feature/UiComponentsTest.php
```

---

### Task 9: `x-ui.form-segmented` *(after pilot approval)*

**Files:**
- Create: `resources/views/components/ui/form-segmented.blade.php`
- Modify: `resources/js/form-fields.js` (add `formSegmented` inside the existing `alpine:init` listener)
- Modify: `resources/css/app.css` (before `/* End of the form field kit. */`)
- Test: `tests/Feature/UiComponentsTest.php`

**Interfaces:**
- Consumes: `x-ui.form-field`, `formXPath()`.
- Produces: `<x-ui.form-segmented wire:model label :options="[['value' => …, 'label' => …, 'dot' => 'bg-…'|null, 'icon' => 'flag'|null, 'iconClass' => 'text-…'|null], …]" [id] [required] [help] [value] [class]>` — `role="radiogroup"` labelled by the field label; each option `role="radio"` with `aria-checked`; roving `tabindex`; ←/→ (mirrored in RTL) and ↑/↓ move and select.
- Produces: Alpine `formSegmented(value, values)`.

- [ ] **Step 1: Write the failing tests** — append:

```php
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
```

- [ ] **Step 2: Run to see them fail** — `--filter="segmented"` → FAIL (missing component).

- [ ] **Step 3: Create `resources/views/components/ui/form-segmented.blade.php`**

```blade
@props([
    'label',
    'options',
    'id' => null,
    'required' => false,
    'help' => null,
    'value' => null,
])

@php
    /*
     * Two to four fixed choices as one-click segments in the same 44px box as
     * every other field. A radiogroup with a roving tab stop: Tab enters on
     * the chosen segment (or the first), arrows move and choose. The value
     * binds through x-modelable like x-ui.form-select.
     */
    /* Livewire's wire() macro answers false, not null, when there is no binding. */
    $model = (string) $attributes->wire('model')->value();

    if ($model === '') {
        throw new InvalidArgumentException('The x-ui.form-segmented component requires a wire:model binding.');
    }

    $livewire = $__env->shared('__livewire');
    $rawValue = $value ?? ($livewire !== null ? data_get($livewire, $model) : null);
    $currentValue = $rawValue instanceof BackedEnum ? (string) $rawValue->value : (string) ($rawValue ?? '');
    $values = array_map(fn (array $option): string => (string) $option['value'], $options);
    $tabStop = in_array($currentValue, $values, true) ? $currentValue : ($values[0] ?? '');

    $id ??= 'field-'.substr(md5($model.'|'.$label), 0, 10);
    $error = $errors->first($model);
    $describedBy = collect([
        $help !== null ? $id.'-help' : null,
        $error !== '' ? $id.'-error' : null,
    ])->filter()->implode(' ');
@endphp

<x-ui.form-field :label="$label" :for="$id" :required="$required" :help="$help" :error="$error" :class="$attributes->get('class')">
    <div
        id="{{ $id }}"
        role="radiogroup"
        aria-labelledby="{{ $id }}-label"
        @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
        @if ($error !== '') aria-invalid="true" data-invalid @endif
        class="form-segmented"
        x-data="formSegmented(@js($currentValue), @js($values))"
        x-modelable="value"
        {{ $attributes->whereStartsWith('wire:model') }}
        x-on:keydown.left.prevent="step($event, -1)"
        x-on:keydown.right.prevent="step($event, 1)"
        x-on:keydown.up.prevent="step($event, -1)"
        x-on:keydown.down.prevent="step($event, 1)"
    >
        @foreach ($options as $option)
            @php
                $optionValue = (string) $option['value'];
            @endphp

            <button
                type="button"
                role="radio"
                class="form-segment"
                data-option-value="{{ $optionValue }}"
                aria-checked="{{ $currentValue === $optionValue ? 'true' : 'false' }}"
                tabindex="{{ $tabStop === $optionValue ? '0' : '-1' }}"
                x-bind:aria-checked="isChecked(@js($optionValue)) ? 'true' : 'false'"
                x-bind:tabindex="tabStop() === @js($optionValue) ? 0 : -1"
                x-on:click="value = @js($optionValue)"
                wire:key="{{ $id }}-{{ md5($optionValue) }}"
            >
                @if (($option['dot'] ?? null) !== null)
                    <span class="size-2 shrink-0 rounded-full {{ $option['dot'] }}" aria-hidden="true"></span>
                @elseif (($option['icon'] ?? null) !== null)
                    <flux:icon :icon="$option['icon']" variant="micro" class="size-3.5 shrink-0 {{ $option['iconClass'] ?? '' }}" />
                @endif

                <span class="truncate">{{ $option['label'] }}</span>
            </button>
        @endforeach
    </div>
</x-ui.form-field>
```

- [ ] **Step 4: Add `formSegmented`** — in `resources/js/form-fields.js`, inside the `alpine:init` callback after the `formPicker` registration, add:

```js
    /**
     * One-click segments (x-ui.form-segmented): a radiogroup whose value binds
     * through x-modelable. Arrow keys move and choose; left and right swap in
     * right-to-left pages so "next" always points forward in reading order.
     */
    window.Alpine.data('formSegmented', (value = '', values = []) => ({
        value,
        values,

        current() {
            return this.value === null || this.value === undefined ? '' : String(this.value);
        },

        isChecked(optionValue) {
            return this.current() === optionValue;
        },

        tabStop() {
            return this.values.includes(this.current()) ? this.current() : (this.values[0] ?? '');
        },

        step(event, delta) {
            const mirrored = getComputedStyle(this.$root).direction === 'rtl' && ['ArrowLeft', 'ArrowRight'].includes(event.key);
            const direction = mirrored ? -delta : delta;
            const index = Math.max(0, this.values.indexOf(this.tabStop()));
            const next = this.values[(index + direction + this.values.length) % this.values.length];

            this.value = next;
            this.$nextTick(() => this.$root.querySelector(`[data-option-value="${CSS.escape(next)}"]`)?.focus());
        },
    }));
```

- [ ] **Step 5: Add the segmented CSS** — before `/* End of the form field kit. */`:

```css
/* One-click segments (x-ui.form-segmented): the same 44px box, split into choices. */
.form-segmented {
    display: flex;
    gap: 3px;
    min-height: 2.75rem;
    padding: 3px;
    border: 1px solid var(--color-secondary-soft);
    border-radius: 0.5rem;
    background-color: color-mix(in srgb, var(--color-secondary-soft) 38%, var(--color-white));
}

.form-segmented[data-invalid] {
    border-color: var(--color-primary);
}

.form-segment {
    display: inline-flex;
    flex: 1 1 0;
    align-items: center;
    justify-content: center;
    gap: 0.375rem;
    min-width: 0;
    padding-inline: 0.5rem;
    border-radius: 0.375rem;
    color: var(--color-secondary-medium);
    font-size: 0.8125rem;
    font-weight: 600;
    white-space: nowrap;
}

.form-segment[aria-checked='true'] {
    background-color: var(--color-white);
    color: var(--color-secondary-dark);
    box-shadow:
        0 1px 2px color-mix(in srgb, var(--color-secondary-dark) 18%, transparent),
        0 0 0 1px color-mix(in srgb, var(--color-secondary-dark) 8%, transparent);
}

.form-segment:focus-visible {
    outline: 2px solid var(--color-secondary-medium);
    outline-offset: 1px;
}

.dark .form-segmented {
    border-color: var(--color-secondary-medium);
    background-color: color-mix(in srgb, var(--color-secondary-medium) 70%, var(--color-secondary-dark));
}

.dark .form-segmented[data-invalid] {
    border-color: var(--color-primary);
}

.dark .form-segment {
    color: var(--color-secondary-soft);
}

.dark .form-segment[aria-checked='true'] {
    background-color: var(--color-secondary-medium);
    color: var(--color-white);
}

```

- [ ] **Step 6: Run the tests** — `/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php` → PASS.

- [ ] **Step 7: Compile check and format** — `/c/php85/php.exe "$CHECK" resources/views/components/ui/form-segmented.blade.php` → `OK 1 files`; pint.

- [ ] **Step 8: Stage**

```bash
git add resources/views/components/ui/form-segmented.blade.php resources/js/form-fields.js resources/css/app.css tests/Feature/UiComponentsTest.php
```

---

### Task 10: `x-ui.form-multiselect` *(after pilot approval)*

**Files:**
- Modify: `resources/views/components/ui/form-select.blade.php` (add `multiple`)
- Create: `resources/views/components/ui/form-multiselect.blade.php`
- Test: `tests/Feature/UiComponentsTest.php`

**Interfaces:**
- Consumes: `x-ui.form-select`, `formPicker({ multiple: true, countTemplate })` (already supports it, Task 2).
- Produces: `<x-ui.form-multiselect wire:model[.live] label [placeholder] [required] [help] [disabled] [locked-reason] [searchable] [search-label] [:value="[…]"] [class]>` with `<option>` children. Menu stays open (`keep-open`); selected rows get a primary (red) check; trigger reads the names for 1–2 picks and `common.count_selected` for 3+. Errors are read from `model` or `model.*`.

- [ ] **Step 1: Write the failing tests** — append:

```php
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
```

- [ ] **Step 2: Run to see them fail** — `--filter="multiselect"` → FAIL (missing component).

- [ ] **Step 3: Teach `x-ui.form-select` to pick several** — in `form-select.blade.php`:

1. Add `'multiple' => false,` to `@props` (after `'value' => null,`).
2. Replace the two lines `$currentValue = …` and `$selectedLabel = …` (keep `$rawValue = …` above them) with:

```php
    $toString = fn (mixed $item): string => $item instanceof BackedEnum ? (string) $item->value : (string) ($item ?? '');
    $currentValues = $multiple
        ? collect(is_iterable($rawValue) ? $rawValue : [])->map($toString)->values()->all()
        : [$toString($rawValue)];
    $currentValue = $currentValues[0] ?? '';
    $selectedLabels = collect($options)->whereIn('value', $currentValues)->pluck('label')->all();
    $selectedLabel = match (true) {
        $selectedLabels === [] => null,
        ! $multiple, count($selectedLabels) <= 2 => implode(', ', $selectedLabels),
        default => __('common.count_selected', ['count' => count($selectedLabels)]),
    };
```

3. Replace `$error = $errors->first($model);` with:

```php
    $error = $errors->first($model) ?: ($multiple ? $errors->first($model.'.*') : '');
```

4. Replace the `x-data` line with:

```blade
        x-data="formPicker(@js([
            'value' => $multiple ? $currentValues : $currentValue,
            'multiple' => $multiple,
            'placeholder' => (string) $placeholder,
            'countTemplate' => __('common.count_selected', ['count' => '__COUNT__']),
        ]))"
```

5. Replace `<flux:menu class="filter-menu" x-ref="menu" x-on:focus="focusSearch()">` with:

```blade
            <flux:menu class="filter-menu" :keep-open="$multiple" x-ref="menu" x-on:focus="focusSearch()">
```

6. Replace the check icon's three attribute lines (`class`, `x-show`, `:style`) with:

```blade
                            class="ms-auto size-4 shrink-0 {{ $multiple ? 'text-primary' : 'text-secondary-medium' }}"
                            x-show="isSelected({{ Illuminate\Support\Js::from($option['value']) }})"
                            :style="in_array($option['value'], $currentValues, true) ? null : 'display: none'"
```

- [ ] **Step 4: Create `resources/views/components/ui/form-multiselect.blade.php`**

```blade
@props([
    'label',
    'id' => null,
    'placeholder' => null,
    'required' => false,
    'help' => null,
    'disabled' => false,
    'lockedReason' => null,
    'searchable' => null,
    'searchLabel' => null,
    'value' => null,
])

{{-- x-ui.form-select for several values: the menu stays open while picking, and the trigger names the picks. --}}
<x-ui.form-select
    multiple
    :label="$label"
    :id="$id"
    :placeholder="$placeholder"
    :required="$required"
    :help="$help"
    :disabled="$disabled"
    :locked-reason="$lockedReason"
    :searchable="$searchable"
    :search-label="$searchLabel"
    :value="$value"
    :attributes="$attributes"
>{{ $slot }}</x-ui.form-select>
```

- [ ] **Step 5: Run the tests** — `/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php tests/Feature/ProjectDirectoryTest.php` → PASS (the single-select and pilot tests must stay green).

- [ ] **Step 6: Compile check and format** — `/c/php85/php.exe "$CHECK" resources/views/components/ui/form-select.blade.php resources/views/components/ui/form-multiselect.blade.php` → `OK 2 files`; pint.

- [ ] **Step 7: Stage**

```bash
git add resources/views/components/ui/form-select.blade.php resources/views/components/ui/form-multiselect.blade.php tests/Feature/UiComponentsTest.php
```

---

### Task 11: `x-ui.form-file` *(after pilot approval)*

**Files:**
- Create: `resources/views/components/ui/form-file.blade.php`
- Modify: `resources/js/form-fields.js` (add `formFile`)
- Modify: `resources/css/app.css` (before `/* End of the form field kit. */`)
- Modify: `lang/en/common.php`, `lang/ar/common.php`
- Test: `tests/Feature/UiComponentsTest.php`

**Interfaces:**
- Consumes: `x-ui.form-field`, `formXPath()`; `x-ui.modal`'s upload gate (Task 3) picks up the bubbling `livewire-upload-*` events.
- Produces: `<x-ui.form-file wire:model label [id] [required] [help] [accept=".pdf,.docx"] [types="PDF, DOCX"] [max-size="5 MB"] [class]>`. Wrapper carries `data-form-file="{model}"`. Shows the drop zone until a file is chosen, then name + live progress + remove.
- Produces: Alpine `formFile(name, model, sizeTemplate)`; keys `common.browse`, `common.drop_a_file_here`, `common.drop_or_browse`, `common.remove_file`, `common.up_to_size`, `common.uploaded_of_total`.

- [ ] **Step 1: Write the failing tests** — append:

```php
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
```

- [ ] **Step 2: Run to see them fail** — `--filter="file field"` → FAIL (missing component).

- [ ] **Step 3: Add the keys**

`lang/en/common.php` (alphabetical positions):
- after `'appearance' => 'Appearance',`: `    'browse' => 'browse',`
- after `'download_name' => 'Download :name',`:

```php
    'drop_a_file_here' => 'Drop a file here',
    'drop_or_browse' => ':drop or :browse',
```

- after `'reports' => 'Reports',` (before `'required'`): `    'remove_file' => 'Remove file',`
- after `'unscheduled' => 'Unscheduled',`: `    'up_to_size' => 'up to :size',`
- after `'updating' => 'Updating',`: `    'uploaded_of_total' => ':loaded of :total MB',`

`lang/ar/common.php` (same positions):
- `    'browse' => 'استعرض',`
- `    'drop_a_file_here' => 'أفلت ملفًا هنا',` and `    'drop_or_browse' => ':drop أو :browse',`
- `    'remove_file' => 'إزالة الملف',`
- `    'up_to_size' => 'حتى :size',`
- `    'uploaded_of_total' => ':loaded من :total ميغابايت',`

- [ ] **Step 4: Create `resources/views/components/ui/form-file.blade.php`**

```blade
@props([
    'label',
    'id' => null,
    'required' => false,
    'help' => null,
    'accept' => null,
    'types' => null,
    'maxSize' => null,
])

@php
    /*
     * A drop-or-browse file field. The real <input type="file" wire:model>
     * sits inside the drop zone's label, so clicking, the keyboard and screen
     * readers all use the native picker; a dropped file is handed to that same
     * input. Livewire's upload events drive the progress row, and they bubble
     * up to x-ui.modal, which keeps the submit actions disabled until the
     * upload has landed.
     */
    /* Livewire's wire() macro answers false, not null, when there is no binding. */
    $model = (string) $attributes->wire('model')->value();

    if ($model === '') {
        throw new InvalidArgumentException('The x-ui.form-file component requires a wire:model binding.');
    }

    $livewire = $__env->shared('__livewire');
    $file = $livewire !== null ? data_get($livewire, $model) : null;
    $fileName = $file instanceof Livewire\Features\SupportFileUploads\TemporaryUploadedFile ? $file->getClientOriginalName() : null;

    $id ??= 'field-'.substr(md5($model.'|'.$label), 0, 10);
    $error = $errors->first($model);
    $allowed = collect([$types, $maxSize !== null ? __('common.up_to_size', ['size' => $maxSize]) : null])->filter()->implode(' · ');
    $prompt = str_replace(
        ['__DROP__', '__BROWSE__'],
        [
            '<span class="font-semibold">'.e(__('common.drop_a_file_here')).'</span>',
            '<span class="form-file-browse">'.e(__('common.browse')).'</span>',
        ],
        e(__('common.drop_or_browse', ['drop' => '__DROP__', 'browse' => '__BROWSE__'])),
    );
    $describedBy = collect([
        $help !== null ? $id.'-help' : null,
        $error !== '' ? $id.'-error' : null,
    ])->filter()->implode(' ');
@endphp

<x-ui.form-field :label="$label" :for="$id" :required="$required" :help="$help" :error="$error" :class="$attributes->get('class')" data-form-file="{{ $model }}">
    <div
        class="form-file"
        @if ($error !== '') data-invalid @endif
        x-data="formFile(@js($fileName), @js($model), @js(__('common.uploaded_of_total', ['loaded' => '__LOADED__', 'total' => '__TOTAL__'])))"
        x-on:livewire-upload-start="started()"
        x-on:livewire-upload-progress="progressed($event.detail)"
        x-on:livewire-upload-finish="uploading = false"
        x-on:livewire-upload-error="cleared()"
        x-on:livewire-upload-cancel="cleared()"
        x-on:dragover.prevent="dragging = true"
        x-on:dragleave.prevent="dragging = false"
        x-on:drop.prevent="dropped($event)"
        x-bind:data-dragging="dragging ? 'true' : null"
    >
        <label class="form-file-zone" x-show="name === null" @if ($fileName !== null) style="display: none" @endif>
            <input
                id="{{ $id }}"
                type="file"
                class="sr-only"
                x-ref="input"
                x-on:change="picked($event)"
                {{ $attributes->whereStartsWith('wire:model') }}
                @if ($accept !== null) accept="{{ $accept }}" @endif
                @if ($required) aria-required="true" @endif
                @if ($error !== '') aria-invalid="true" @endif
                @if ($describedBy !== '') aria-describedby="{{ $describedBy }}" @endif
            />

            <span class="form-file-icon" aria-hidden="true">
                <flux:icon.cloud-arrow-up class="size-5" />
            </span>

            <span class="min-w-0 text-sm">
                <span class="block">{!! $prompt !!}</span>

                @if ($allowed !== '')
                    <span class="form-help block">{{ $allowed }}</span>
                @endif
            </span>
        </label>

        <div class="form-file-chosen" x-show="name !== null" @if ($fileName === null) style="display: none" @endif>
            <span class="form-file-doc" aria-hidden="true">
                <flux:icon.document class="size-5" />
            </span>

            <div class="min-w-0 flex-1">
                <p class="truncate text-sm font-semibold" x-text="name">{{ $fileName }}</p>

                <div class="mt-1.5 flex items-center gap-2" x-show="uploading" style="display: none">
                    <span class="form-file-track"><span class="form-file-bar" x-bind:style="`width: ${progress}%`"></span></span>
                    <span class="text-xs tabular-nums text-secondary-medium dark:text-secondary-soft" x-text="sizeLabel"></span>
                </div>
            </div>

            <button type="button" class="form-file-remove" x-on:click="remove()" aria-label="{{ __('common.remove_file') }}">
                <flux:icon.x-mark class="size-4" />
            </button>
        </div>
    </div>
</x-ui.form-field>
```

(`{!! $prompt !!}` is safe: the sentence is escaped with `e()` before the two escaped spans are put in.)

- [ ] **Step 5: Add `formFile`** — in `resources/js/form-fields.js`, inside the `alpine:init` callback after `formSegmented`, add:

```js
    /**
     * The file field (x-ui.form-file): the chosen name, live progress from
     * Livewire's upload events, drop support and remove.
     */
    window.Alpine.data('formFile', (name = null, model = '', sizeTemplate = '') => ({
        name,
        uploading: false,
        progress: 0,
        loaded: 0,
        total: 0,
        dragging: false,

        picked(event) {
            this.name = event.target.files?.[0]?.name ?? null;
        },

        dropped(event) {
            this.dragging = false;

            const files = event.dataTransfer?.files;

            if (! files || files.length === 0) {
                return;
            }

            this.$refs.input.files = files;
            this.$refs.input.dispatchEvent(new Event('change', { bubbles: true }));
        },

        started() {
            this.uploading = true;
            this.progress = 0;
        },

        progressed(detail) {
            this.progress = detail.progress ?? 0;
            this.loaded = detail.loaded ?? 0;
            this.total = detail.total ?? 0;
        },

        get sizeLabel() {
            if (! this.total) {
                return `${this.progress}%`;
            }

            const megabytes = (bytes) => (bytes / 1048576).toFixed(1);

            return sizeTemplate.replace('__LOADED__', megabytes(this.loaded)).replace('__TOTAL__', megabytes(this.total));
        },

        cleared() {
            this.uploading = false;
            this.name = null;
            this.$refs.input.value = '';
        },

        remove() {
            if (this.uploading) {
                this.$wire.$cancelUpload(model);
            } else {
                this.$wire.$set(model, null);
            }

            this.cleared();
        },
    }));
```

- [ ] **Step 6: Add the file CSS** — before `/* End of the form field kit. */`:

```css
/* File field (x-ui.form-file): a dashed drop zone, then the chosen file with its progress. */
.form-file-zone,
.form-file-chosen {
    display: flex;
    align-items: center;
    gap: 1rem;
    min-height: 5.5rem;
    padding-inline: 1.25rem;
    border-radius: 0.5rem;
}

.form-file-zone {
    border: 1px dashed var(--color-secondary-light);
    background-color: color-mix(in srgb, var(--color-secondary-soft) 38%, var(--color-white));
    cursor: pointer;
    transition: border-color 150ms ease, background-color 150ms ease;
}

.form-file-zone:hover,
.form-file[data-dragging] .form-file-zone {
    border-color: var(--color-secondary-medium);
    background-color: var(--color-white);
}

.form-file-zone:focus-within {
    outline: 2px solid var(--color-secondary-medium);
    outline-offset: 2px;
}

.form-file[data-invalid] .form-file-zone {
    border-color: var(--color-primary);
}

.form-file-chosen {
    border: 1px solid var(--color-secondary-soft);
    background-color: var(--color-white);
}

.form-file-icon,
.form-file-doc {
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 2.5rem;
    height: 2.5rem;
}

.form-file-icon {
    border-radius: 9999px;
    background-color: var(--color-white);
    color: var(--color-secondary-medium);
}

.form-file-doc {
    border-radius: 0.5rem;
    background-color: color-mix(in srgb, var(--color-primary) 10%, transparent);
    color: var(--color-status-critical);
}

.form-file-browse {
    color: var(--color-status-critical);
    font-weight: 600;
    text-decoration: underline;
    text-underline-offset: 2px;
}

.form-file-track {
    flex: 1 1 auto;
    height: 0.25rem;
    overflow: hidden;
    border-radius: 9999px;
    background-color: var(--color-secondary-soft);
}

.form-file-bar {
    display: block;
    height: 100%;
    border-radius: 9999px;
    background-color: var(--color-secondary-dark);
    transition: width 150ms ease;
}

.form-file-remove {
    display: inline-flex;
    flex-shrink: 0;
    align-items: center;
    justify-content: center;
    width: 2rem;
    height: 2rem;
    border-radius: 0.375rem;
    color: var(--color-secondary-light);
}

.form-file-remove:hover {
    background-color: var(--color-secondary-soft);
    color: var(--color-secondary-dark);
}

.dark .form-file-zone {
    border-color: var(--color-secondary-light);
    background-color: color-mix(in srgb, var(--color-secondary-medium) 70%, var(--color-secondary-dark));
}

.dark .form-file-zone:hover,
.dark .form-file[data-dragging] .form-file-zone {
    background-color: var(--color-secondary-medium);
}

.dark .form-file[data-invalid] .form-file-zone {
    border-color: var(--color-primary);
}

.dark .form-file-chosen {
    border-color: var(--color-secondary-medium);
    background-color: var(--color-secondary-dark);
}

.dark .form-file-icon {
    background-color: var(--color-secondary-medium);
    color: var(--color-white);
}

.dark .form-file-track {
    background-color: var(--color-secondary-medium);
}

.dark .form-file-bar {
    background-color: var(--color-white);
}

.dark .form-file-remove:hover {
    background-color: var(--color-secondary-medium);
    color: var(--color-white);
}

```

- [ ] **Step 7: Run the tests** — `/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php tests/Feature/LocalizationTest.php` → PASS.

- [ ] **Step 8: Compile check and format** — `/c/php85/php.exe "$CHECK" resources/views/components/ui/form-file.blade.php` → `OK 1 files`; pint.

- [ ] **Step 9: Stage**

```bash
git add resources/views/components/ui/form-file.blade.php resources/js/form-fields.js resources/css/app.css lang/en/common.php lang/ar/common.php tests/Feature/UiComponentsTest.php
```

---

### Task 12: Phase 0 wrap-up

- [ ] **Step 1: Run every file this phase touched**

```bash
/c/php85/php.exe artisan test --compact tests/Feature/UiComponentsTest.php tests/Feature/ProjectDirectoryTest.php tests/Feature/LocalizationTest.php
```

Expected: PASS.

- [ ] **Step 2: Compile check everything changed** — `/c/php85/php.exe "$CHECK" resources/views/components/ui "resources/views/pages/projects/⚡index.blade.php"` → `OK … files`.

- [ ] **Step 3: Production build** — ask the user to run `npm run build` (the new JS and CSS only reach production assets through a build).

- [ ] **Step 4: Report** — list every staged file, the approved-pilot notes applied, and anything deferred. Ask the user to run the full suite:

```bash
.\php.cmd artisan test --compact
```

- [ ] **Step 5: Next plan** — offer to write the Phase 1–5 rollout plan (long rail forms → short forms → confirmations → tasks drawer and viewers → full sweep), which also records the modal/field conventions in `.ai/rules` via `record-rule` at the end, including the correction to the stale flux-lite note in `.ai/rules/components-ui.md`.
