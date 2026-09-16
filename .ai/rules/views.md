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
