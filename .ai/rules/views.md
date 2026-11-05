---
paths:
  - 'resources/views/**/*.blade.php'
---

# Views

## Never use the @php(...) single-line shorthand — it is a landmine that arms later
Always use the full block form, even for one assignment:
```
@php
    $var = expr;
@endphp
```

**Do not conclude this rule is stale when a shorthand you test appears to work.** It usually does work, right up to the moment someone else edits the file. Mechanism, verified against laravel/framework v13.34.0 on 2026-11-04:

`BladeCompiler::storePhpBlocks` runs *before* any directive is compiled and extracts raw blocks with `/(?<!@)@php(.*?)@endphp/s`.

- A shorthand with **no later `@php...@endphp` block in the same file** is never matched by that regex. It falls through to `compilePhp`, which emits `<?php ($var = expr); ?>` — valid, and the page is fine.
- A shorthand with a **later `@php...@endphp` block in the same file** is matched: the regex spans from the shorthand to that block's `@endphp` and stores *everything between* as one raw PHP block. Nothing in that span is compiled any more.

What that then looks like has two shapes, and only one of them is loud:

- **Always:** the whole span is emitted to the browser as literal text — you see raw `@if (...)`, `@foreach (...)` and the shorthand itself on the page — and no directive inside it runs. Variables the swallowed block assigned are never set, so later `{{ $var }}` hits an undefined variable.
- **Only when the span cuts through a directive pair** (openers inside it, their `@endif`/`@endforeach` outside) are those closers left orphaned, and *that* is what produces the fatal "unexpected token endif/endforeach" at a line nowhere near the cause. This is the failure that has recurred more than once (⚡show.blade.php in strategies).

The literal-text leak is why there is often no error: the stored block begins `<?php(` with no space, which is **not** a valid PHP open tag, so the span is never parsed as PHP at all and `php -l` passes. (Written with a space — `@php (...)` — it *is* a valid open tag and fails right there instead.) So do not count on a parse error to catch this: when the span happens to enclose balanced pairs, the page simply renders its own source and loses its variables, which is the harder version to diagnose.

So the hazard is not the shorthand alone — it is the shorthand *plus* any block form added below it later on. Adding one innocuous `@php ... @endphp` anywhere beneath an existing shorthand breaks everything between the two. Two consequences:

- When a file holds several shorthands, convert **all** of them in one go. Converting only the later one arms the earlier one, because the new `@endphp` becomes the regex's closer.
- The order matters, not the count: only shorthands *above* an `@endphp` are at risk.

To check a page really compiles, compile the raw source with `blade.compiler->compileString()` and lint the result with `php -l`, rather than reasoning about `@if`/`@endif` balance — a stray `@php(...)` produces no visible brace imbalance in the source, only in the compiled output. (Earlier revisions of this rule pointed at a `bisect_blade.php` helper; no such script is in the tree, so write the equivalent as a throwaway. Driving `BladeCompiler` standalone needs `withoutComponentTags()`, since component-tag compilation wants a container and runs after the raw-block extraction anyway.)

## flux:input's bare class= lands on the wrapper, not the &lt;input&gt; — use input:class=
Found via the login page: `<flux:input class="...">` puts those classes on Flux's outer `data-flux-input` wrapper div, which has no visible border/background of its own — the actual visible box is the inner `<input>` element, which keeps 100% of Flux's own default styling untouched. Confirmed by inspecting the live DOM: the wrapper had my custom classes, the `<input>` still had Flux's stock `bg-white border-zinc-200 rounded-lg` etc.

To style the real input control, pass `input:class="..."` instead — Flux's own source (`flux/input/index.blade.php`) explicitly extracts anything prefixed `input:` via `Flux::attributesAfter('input:', $attributes, [])` and merges it onto the `<input>` tag itself (after Flux's own default classes, so `!`-suffixed utilities are still needed to override conflicts like `bg-white`/`rounded-lg`/`h-10`). Blade doesn't support `:input:class="$var"` (colon-prefixed dynamic binding doesn't compose with a colon already inside the attribute name) — write it as a plain attribute with an inline expression instead: `input:class="{{ $fieldClasses }}"`.

This same wrapper-vs-control split likely applies to other `flux:with-field`-based components (`flux:select`, `flux:textarea`, etc.) — check for an `input:`/equivalent-prefixed passthrough before assuming a plain `class` prop reaches the visible control.

## Red text uses text-status-critical, not text-primary
--color-primary (#be1f24) is unreadable as text on the dark ground. --color-status-critical equals primary in light mode and switches to #ff8a86 in dark (Graphite), so use text-status-critical for alarm/overdue TEXT. Non-alarm red text (links, emphasis) that uses text-primary needs `dark:text-brand-text`. Keep bg-primary/border-primary for dots, buttons and rules. Applied across proposals/⚡show, _task-row and _file-row on 2026-09-21.

## Dark mode classes use the Graphite tokens
Behind `dark:` use only the Graphite tokens (`surface-0..3`, `ink*`, `line*`, `field-line`, `brand*`, `shadow-raised`), never raw `secondary-*`/`zinc-*` greys — see .ai/rules/css.md. Never change a light (non-`dark:`) class when restyling dark mode: light mode stays pixel-identical.

## Guard modal bodies behind their flag
Every `x-ui.modal` body sits behind `@if ($flag)`; shared modal components take `:open`.
