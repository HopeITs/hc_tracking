---
paths:
  - 'resources/views/**/*.blade.php'
---

# Views

## Never use the @php(...) single-line shorthand — it silently corrupts the page
In this project's Blade version, the `@php($var = expr)` single-line shorthand directive does NOT compile. It's left in the compiled output as literal, unterminated PHP: `<?php($var = expr)` with no closing `?>` and no semicolon — which then swallows every directive after it on the page (including unrelated @foreach/@if elsewhere), producing a ParseError like "unexpected token endforeach/endif" whose line number points nowhere near the real cause. This has recurred more than once (⚡show.blade.php in strategies).

Always use the full block form instead, even for one assignment:
```
@php
    $var = expr;
@endphp
```
Before trusting a Blade page compiles, run `resources/views/pages/**` (or the specific file) through the project's `bisect_blade.php` (compiles the raw source with `blade.compiler->compileString()` and lints the result with `php -l`) rather than only reasoning about `@if`/`@endif` balance — a stray `@php(...)` produces no visible brace imbalance in the source, only in the compiled output.

## flux:input's bare class= lands on the wrapper, not the &lt;input&gt; — use input:class=
Found via the login page: `<flux:input class="...">` puts those classes on Flux's outer `data-flux-input` wrapper div, which has no visible border/background of its own — the actual visible box is the inner `<input>` element, which keeps 100% of Flux's own default styling untouched. Confirmed by inspecting the live DOM: the wrapper had my custom classes, the `<input>` still had Flux's stock `bg-white border-zinc-200 rounded-lg` etc.

To style the real input control, pass `input:class="..."` instead — Flux's own source (`flux/input/index.blade.php`) explicitly extracts anything prefixed `input:` via `Flux::attributesAfter('input:', $attributes, [])` and merges it onto the `<input>` tag itself (after Flux's own default classes, so `!`-suffixed utilities are still needed to override conflicts like `bg-white`/`rounded-lg`/`h-10`). Blade doesn't support `:input:class="$var"` (colon-prefixed dynamic binding doesn't compose with a colon already inside the attribute name) — write it as a plain attribute with an inline expression instead: `input:class="{{ $fieldClasses }}"`.

This same wrapper-vs-control split likely applies to other `flux:with-field`-based components (`flux:select`, `flux:textarea`, etc.) — check for an `input:`/equivalent-prefixed passthrough before assuming a plain `class` prop reaches the visible control.
