# Logo Preloader Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.
>
> **Project override:** never run `git commit` or `git push`. Every "Review" step means: leave the changes in the working tree and report the changed files. The user reviews every file before anything enters history.

**Goal:** A full-screen HOPE CENTER Tracking logo preloader (concept D, approved 2026-09-24). A write-on intro plays on the first load in a browser within 8 hours. Every slow page load or `wire:navigate` page switch shows the logo with its **O spinning** as the loading ring.

**Architecture:** One Blade component (`x-preloader`) renders the loader element with an inline copy of the logo SVG, wrapped in `@persist('preloader')` in the app and auth layouts, so Livewire reuses one element across page switches. A tiny inline script picks *intro* or *quick* mode before first paint. `app.css` holds every style and animation (driven by `data-state`), and `resources/js/preloader.js` moves the element between states on first load and on Livewire navigation events. Livewire's own NProgress bar is turned off so there is only one loading indicator.

**Tech Stack:** Laravel 13, Livewire 4.4.6 (`wire:navigate`, `@persist`), Flux 2.20, Tailwind CSS v4 (CSS-first `app.css`), Vite, Pest 5, PHP 8.5 via `.\php.cmd`.

## Global Constraints

- PHP commands always use `.\php.cmd` (isolated PHP 8.5). Never run bare `php`.
- No git commits or pushes. Leave everything uncommitted for the user's review.
- No raw hex colors outside `resources/css/app.css`. Inside it, use the tokens `--color-primary` (#be1f24), `--color-secondary-medium` (#6d6e71), `--color-secondary-dark` (#58595b), and `--color-white`.
- Every visible or announced string goes through `lang/en/*.php` **and** `lang/ar/*.php` (`LocalizationTest` enforces this).
- Don't inline the designer's SVG files (`public/logo-tracking*.svg`). They use generic `.cls-1`/`.cls-2` classes that collide. The preloader carries its **own** copy of the shapes, styled with `hc-preloader__*` classes, with no `<style>` block inside the SVG.
- No new dependencies.
- Timings (single source of truth):

| Constant | Value | Meaning |
|---|---|---|
| `SHOW_DELAY_MS` | 200 | Nothing shows if loading finishes within this time |
| `MIN_VISIBLE_MS` | 500 | Once shown, it stays at least this long (no flicker) |
| `LEAVE_MS` | 400 | Fade-out duration |
| `FAILSAFE_MS` | 10000 | The loader always disappears after this, even if JS fails |
| Intro replay window | 8 hours | localStorage key `hc-preloader:intro-at` |
| Intro length | ~1.5 s | HOPE rises (80 ms stagger), CENTER follows (starts 380 ms, 45 ms stagger), "Tracking" writes (starts 600 ms, lasts 900 ms) |

---

## Behavior spec (A to Z)

| Moment | What happens |
|---|---|
| First full load in this browser in 8 h (and reduced motion is off) | The write-on intro plays (~1.5 s). If the page is still not ready afterwards, the O turns into the spinner until it is. Then the O fills solid and the loader fades out (400 ms, logo scales to 1.04). |
| Any other full load (refresh, new tab, right after login) | Invisible for 200 ms. If the page is still loading, it fades in with the spinning O, stays ≥500 ms, then fades out. |
| `wire:navigate` page switch (sidebar, links, back/forward) | Same as above: 200 ms of nothing, then the spinning O, ≥500 ms on screen, the O fills, fade out. |
| Navigation cancelled (e.g. notification rules "unsaved changes" → Cancel) | Never appears. |
| Navigation never finishes | Removed after 10 s by JS. |
| JS never loads | A CSS-only failsafe animation hides the boot loader after 10 s. `<noscript>` hides it immediately. |
| Back-forward cache restore (`pageshow.persisted`) | Hidden immediately. |
| Reduced motion | No intro, no rise/write, no spinner. The O pulses gently (fill-opacity 1 ↔ 0.35). Fades are kept. |
| Dark mode (`.dark` on `<html>`, set by `@fluxAppearance`) | Background `--color-secondary-dark`, white logo, spinner stays brand red. |
| Arabic / RTL | The logo is not mirrored; "Tracking" writes left to right. |
| Screen readers | The `role="status"` label announces "Loading…" / "جارٍ التحميل…" while visible. It's emptied (and the loader is `inert`) while hidden. |
| Print | Hidden. |
| Where | The app layout (`layouts/app/sidebar`) and the login layout (`layouts/auth/simple`). **Not** on error pages, which stay free of extra machinery. Unused layouts (`auth/card`, `auth/split`, `app/header`) are untouched. |
| "Ready" means | DOMContentLoaded and `document.fonts.ready` (Somar Sans rendered). It does not wait for every image. |

### State machine (`data-state` on `[data-preloader]`)

```
boot ──(page ready)──► shown ─► leaving ─► hidden
 │  (intro finished, page not ready) ─► waiting ─(ready)─► shown ─► leaving ─► hidden
hidden ──(livewire:navigate + 200 ms, not cancelled)──► waiting ──(livewire:navigated, ≥500 ms)──► shown ─► leaving ─► hidden
```

- `boot`: server-rendered. Quick mode fades in at 200 ms through CSS; intro mode is visible at once and plays the write-on. Both carry the 10 s CSS failsafe.
- `waiting`: visible, O tinted to 20 %, red arc spinning inside the O (masked to the O's shape).
- `shown`: visible, O solid, arc fading. It pins opacity at 1 so the fade-out always starts from fully visible.
- `leaving`: opacity → 0 over 400 ms, logo scale 1.04, then `hidden`.
- `hidden`: invisible, not clickable, `inert`, label emptied.

## File map

| File | Action | Responsibility |
|---|---|---|
| `resources/views/components/preloader.blade.php` | Create | Mode script, loader element, and inline logo SVG |
| `resources/views/layouts/app/sidebar.blade.php` | Modify | Render `<x-preloader />` in `@persist('preloader')` as the first body child |
| `resources/views/layouts/auth/simple.blade.php` | Modify | Same |
| `lang/en/common.php`, `lang/ar/common.php` | Modify | `loading` key |
| `resources/css/app.css` | Modify | All loader styles; delete the NProgress block |
| `resources/js/preloader.js` | Create | First-load dismissal plus navigation behavior |
| `resources/js/app.js` | Modify | `import './preloader';` |
| `config/livewire.php` | Create (via artisan) | `show_progress_bar => false` |
| `tests/Feature/PreloaderTest.php` | Create | Server-side coverage |
| `.ai/rules/preloader.md` | Create (via `record-rule`) | The inline-logo exception and the loader contract |

---

### Task 1: Preloader component in both layouts

**Files:**
- Create: `resources/views/components/preloader.blade.php`
- Modify: `resources/views/layouts/app/sidebar.blade.php:6` (first child of `<body>`)
- Modify: `resources/views/layouts/auth/simple.blade.php:6` (first child of `<body>`)
- Modify: `lang/en/common.php:63`, `lang/ar/common.php:63` (alphabetical, before `log_out`)
- Test: `tests/Feature/PreloaderTest.php`

**Interfaces:**
- Produces:
  - `[data-preloader]`: the root element, with `data-state="boot"` as rendered.
  - `[data-preloader-label]`: the `role="status"` element, whose `data-text` holds the translated label.
  - `html[data-preloader="intro"]`: set by the inline script when the intro should play.
  - Classes `hc-preloader`, `hc-preloader__logo`, `__letter` (4, `--i` 0–3), `__o`, `__small` (6, `--i` 0–5), `__script`, `__wipe`, `__ring`.
  - SVG ids `hc-preloader-write` (clipPath) and `hc-preloader-o` (mask).

- [ ] **Step 1: Write the failing tests**

Create with `.\php.cmd artisan make:test --pest PreloaderTest --no-interaction`, then replace its contents:

```php
<?php

use App\Models\User;

test('the login screen renders the logo preloader', function () {
    $this->get(route('login'))
        ->assertOk()
        ->assertSee('x-persist="preloader"', false)
        ->assertSee('data-preloader', false)
        ->assertSee(__('common.loading'));
});

test('application pages render one persisted preloader that starts in the boot state', function () {
    $this->actingAs(User::factory()->create());

    $content = $this->get(route('dashboard'))->assertOk()->getContent();

    expect(substr_count($content, 'x-persist="preloader"'))->toBe(1)
        ->and($content)->toContain('data-state="boot"')
        ->and($content)->toContain(__('common.loading'));
});

test('error pages stay free of the preloader', function () {
    config(['app.debug' => false]);

    $this->get('/this-route-does-not-exist')
        ->assertNotFound()
        ->assertDontSee('data-preloader', false);
});
```

- [ ] **Step 2: Run the tests and confirm they fail**

Run: `.\php.cmd artisan test --compact tests/Feature/PreloaderTest.php`
Expected: the first two fail (no `x-persist="preloader"`), and the error-page test passes.

- [ ] **Step 3: Add the translation key**

`lang/en/common.php`, directly above `'log_out' => 'Log out',`:
```php
    'loading' => 'Loading…',
```
`lang/ar/common.php`, directly above `'log_out' => 'تسجيل الخروج',` (same wording as `notifications.loading`):
```php
    'loading' => 'جارٍ التحميل…',
```

- [ ] **Step 4: Create the component**

`resources/views/components/preloader.blade.php`. The shapes below were generated from `public/logo-tracking.svg` and are copied exactly. Do not retype them:

```blade
{{--
    Full-screen logo preloader. States, timings and the navigation behaviour
    live in resources/js/preloader.js; every style and animation lives in
    resources/css/app.css under "Logo preloader".

    The logo is a private copy of public/logo-tracking.svg: the designer file
    styles its shapes with generic .cls-1/.cls-2 rules that collide when
    inlined, so these shapes carry hc-preloader__* classes instead. Colour
    comes from app.css (red/grey in light, white in dark).

    The script runs before the element is parsed so the intro/quick choice is
    made before the first paint. data-navigate-once keeps Livewire from
    re-running it on page switches.
--}}
<script data-navigate-once>
    (() => {
        const replayAfter = 8 * 60 * 60 * 1000;

        try {
            if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
                return;
            }

            const playedAt = Number(localStorage.getItem('hc-preloader:intro-at') || 0);

            if (Date.now() - playedAt > replayAfter) {
                localStorage.setItem('hc-preloader:intro-at', String(Date.now()));
                document.documentElement.dataset.preloader = 'intro';
            }
        } catch (error) {
            // Storage is blocked (private mode): keep the quick loader.
        }
    })();
</script>

<noscript><style>.hc-preloader { display: none; }</style></noscript>

<div class="hc-preloader" data-preloader data-state="boot">
    <p class="sr-only" role="status" data-preloader-label data-text="{{ __('common.loading') }}">{{ __('common.loading') }}</p>

    <svg class="hc-preloader__logo" viewBox="0 0 328.14 75.02" aria-hidden="true" focusable="false">
        <defs>
            <clipPath id="hc-preloader-write">
                <rect class="hc-preloader__wipe" x="178" y="0" width="151" height="76" />
            </clipPath>
            <mask id="hc-preloader-o">
                <path fill="white" d="M181.15,62.71c0-3.24-2.76-4.38-5.63-4.38h-5.31v15.28h2.72v-6.47h1.99l3.54,6.47h3.28l-4.08-6.78c2.14-.48,3.5-1.92,3.5-4.12ZM172.93,64.91v-4.29h2.46c1.4,0,3.04.41,3.04,2.07,0,1.86-1.75,2.22-3.28,2.22h-2.22Z" />
            </mask>
        </defs>

            <polygon class="hc-preloader__letter" style="--i: 0" points="44.89 52.81 44.89 1.38 32.47 1.38 32.47 20.85 12.42 20.85 12.42 1.38 0 1.38 0 52.81 12.42 52.81 12.42 31.31 32.47 31.31 32.47 52.81 44.89 52.81" />
            <path class="hc-preloader__letter hc-preloader__o" style="--i: 1" d="M181.15,62.71c0-3.24-2.76-4.38-5.63-4.38h-5.31v15.28h2.72v-6.47h1.99l3.54,6.47h3.28l-4.08-6.78c2.14-.48,3.5-1.92,3.5-4.12ZM172.93,64.91v-4.29h2.46c1.4,0,3.04.41,3.04,2.07,0,1.86-1.75,2.22-3.28,2.22h-2.22Z" />
            <path class="hc-preloader__letter" style="--i: 2" d="M136.19,1.38h-18.89v51.43h12.42v-19.39h6.17c10.75,0,20.41-4.29,20.41-16.2s-8.86-15.83-20.12-15.83ZM134.95,23.53h-5.23v-12.13h5.45c4.43,0,8.72.94,8.72,5.88s-4.28,6.25-8.93,6.25Z" />
            <path class="hc-preloader__letter" style="--i: 3" d="M197.25,50.06c0-2.35-.3-5.16-1.34-7.86h-20.14v-10.68h4.76c-.04-.28-.08-.56-.08-.86,0-1.53.67-2.98,1.9-4.08l.04-.04.04-.04c1.2-1.02,2.56-1.72,4.04-2.08h.05s.05-.02.05-.02c.55-.12,1.4-.27,2.37-.27,1.53,0,3.11.33,4.7.99.07.03.14.07.21.1v-3.66h-18.09v-9.66h24.05V1.38h-36.03v51.43h33.32c.09-.95.15-1.88.15-2.75Z" />
            <path class="hc-preloader__small" style="--i: 0" d="M10.08,71.56c-3.15,0-5.18-2.31-5.18-5.63,0-3.15,2.14-5.59,5.29-5.59,1.23,0,2.68.48,3.47,1.57l2.07-1.64c-1.21-1.6-3.56-2.33-5.55-2.33-4.7,0-8.29,3.09-8.29,7.99s3.39,8.07,8.16,8.07c2.59,0,4.57-.99,5.91-2.68l-2.18-1.53c-.78,1.06-2.07,1.77-3.71,1.77Z" />
            <polygon class="hc-preloader__small" style="--i: 1" points="39.8 66.87 46.82 66.87 46.82 64.59 39.8 64.59 39.8 60.7 47.2 60.7 47.2 58.33 37.08 58.33 37.08 73.61 47.59 73.61 47.59 71.19 39.8 71.19 39.8 66.87" />
            <polygon class="hc-preloader__small" style="--i: 2" points="80.42 69.77 80.38 69.77 73.28 58.33 69.67 58.33 69.67 73.61 72.39 73.61 72.39 61.8 72.43 61.8 79.69 73.61 83.14 73.61 83.14 58.33 80.42 58.33 80.42 69.77" />
            <polygon class="hc-preloader__small" style="--i: 3" points="104.34 60.7 109.02 60.7 109.02 73.61 111.74 73.61 111.74 60.7 116.42 60.7 116.42 58.33 104.34 58.33 104.34 60.7" />
            <polygon class="hc-preloader__small" style="--i: 4" points="140.33 66.87 147.35 66.87 147.35 64.59 140.33 64.59 140.33 60.7 147.74 60.7 147.74 58.33 137.62 58.33 137.62 73.61 148.13 73.61 148.13 71.19 140.33 71.19 140.33 66.87" />
            <path class="hc-preloader__small" style="--i: 5" d="M81.06,0c-16.05,0-27.96,10.39-27.96,26.87s11.91,27.31,27.96,27.31,28.04-11.04,28.04-27.31S97.18,0,81.06,0ZM81.06,42.85c-8.64,0-14.67-6.68-14.67-15.98s5.96-15.69,14.67-15.69,14.75,6.68,14.75,15.69-6.1,15.98-14.75,15.98Z" />
            <g class="hc-preloader__script" clip-path="url(#hc-preloader-write)">
                <path d="M211.12,47.93c.31,0,.61,0,.87.03l-.87-.03Z" />
                <path d="M279.33,44.2c0-.29.05-.55.15-.79.29-.75.95-1.28,1.65-1.28.09,0,.24,0,.42.06.62.24,1,.84,1,1.55,0,.29-.05.55-.15.79-.24.73-.92,1.28-1.63,1.28-.1,0-.26,0-.45-.06-.62-.24-1-.84-1-1.55ZM328.14,49.22c0,.46-.32.84-.6,1.11l-.2.2h-.29c-5.24.2-9.28,3.13-10.58,5.94-.96,3.34-2.68,8.32-3.82,11.32-.95,2.68-2.81,7.22-4.57,7.22-.09,0-.25,0-.43-.07-.02,0-.05-.02-.07-.03l-.04.03v-.05c-.5-.22-1.19-.79-1.19-2.28,0-.85.21-1.71.34-2.16.39-1.51,1.07-3.4,1.96-5.45,1.09-2.45,3.51-7.3,5.46-10.02.14-.46.28-.83.43-1.19.15-.39.3-.76.41-1.19.12-.44.23-.89.33-1.32l-1.6-.6-.28.29c-2.15,2.37-3.67,3.95-4.91,4.34-.52.17-1.11.19-1.62-.06-.17-.08-.32-.19-.44-.31l-.55.28.39-.46c-.11-.15-.18-.31-.22-.49-1.06.49-2.13.79-3.19.79-2.08,0-3.7-1.17-4.8-3.46-1.02.66-3.47,3.9-3.81,4.7l-.06.17c-.29.62-1.03.9-1.64.9-.78,0-1.35-.5-1.35-1.19l.02-.27,1.02-4.09c-3.96,1.6-9.89,3.78-12.7,3.78-.53,0-1.28-.06-1.74-.53-.48-.34-1.02-.99-1.13-2.21-2.48.62-5.62.93-9.56.93-1.62,0-3.22-.06-4.73-.12-.52,1.52-1.13,3.79-1.72,5.99-.69,2.58-1.41,5.25-2.01,6.85-.26.6-1.02.86-1.61.86-.93,0-1.35-.61-1.35-1.21,0-.14.02-.41.17-.71l.34-1.01c.61-2.93,1.84-6.8,3.56-11.17-2.07,1.3-3.89,2.16-5.43,2.56-.66.19-1.32.29-1.95.29-.54,0-1-.07-1.39-.21-.93-.31-1.62-.95-1.98-1.84-.21-.55-.32-1.09-.32-1.66,0-.1.01-.21.02-.31-2.4,1.28-6.04,2.83-8.51,2.83-2.12,0-3.33-1.15-3.33-3.16,0-.26.02-.52.05-.8-.17.02-.35.02-.52-.03-.03.02-.05.05-.08.07l-.34.32c-2.46,2.34-4.3,3.99-5.55,3.99l-.25-.04c-.4-.07-.74-.28-1.04-.62-.3-.38-.44-.81-.44-1.34,0-.17,0-.34.04-.5.05-.44.23-.99.49-1.52.02-.04.04-.08.06-.12-.92.06-1.7.06-1.72.06-2.59,0-5.98-.41-7.42-2.35-.43.25-1.79,1.79-4.02,6.86,0,.01-.01.03-.02.04-.08.54-.53,1-1.41,1-.81,0-1.64-.39-1.64-1.14l.05-.28,2.02-8.47c.28-.77.88-1.18,1.64-1.18.72,0,1.31.38,1.54.92.54-.35,1.09-.54,1.63-.54s1.27.18,1.92,1.03l.09.14c.55,1.21,2.81,1.88,6.38,1.88.92,0,1.73-.05,2.27-.08h.1c.79-.88,1.79-1.66,2.97-2.01.5-.15.99-.22,1.5-.22.71,0,1.24.18,1.63.4,1.13-.88,1.67-1,2.02-1,.17,0,.31.04.42.08.33.09.98.41.98,1.58,0,.69-.22,1.59-.65,2.68-.21.58-.33,1.11-.33,1.52,0,.51.09.96,1.22.96,2.78,0,8.06-3.02,8.87-3.92.2-.35.43-.52.65-.61,1.19-1.83,3.05-3.29,4.99-3.29h.24c.27,0,.51.07.7.12l.13.05c.68.34,1.05.99,1.05,1.82,0,1.08-.62,2.32-.81,2.52-.9.74-2.58.39-3.02-.58-1.1,1.15-2.19,2.71-2.19,3.83,0,.17.02.28.06.37.11.26.27.41.51.48.08.03.18.07.44.07.45,0,1.08-.11,1.84-.31,1.48-.39,3.27-1.37,5.93-3.26.35-.45.83-.94,1.45-1.49.12-.12.28-.26.45-.41,3.05-7.06,6.72-14.14,9.59-18.5.67-1.1,1.3-1.64,1.92-1.64.28,0,.53.11.73.31.21.21.33.52.33.88l-.02.15c-.25,1.2-.55,1.81-1.44,1.85l-.03-.03c-1.97,3.04-4.35,7.65-7.25,14.02.4-.28.83-.58,1.29-.88,2.06-1.37,3.19-2.02,4.63-2.05.81.05,1.32.58,1.32,1.33,0,.4-.13.81-.39,1.24l-.26.42-.49-.08c-.1-.02-.21-.02-.31-.01-.89,0-2.07.44-3.16,1.18-1.18.76-3.07,2.17-4.45,3.25,0,.01,0,.02,0,.03,0,.23-.08.53-.37.81-.13.34-.25.67-.37,1.01,1.43.11,2.57.16,3.61.16,3.56,0,6.6-.53,10.36-1.8.3-1.11.73-2.08.99-2.53.27-.57.83-.94,1.52-.94.96,0,1.73.71,1.73,1.59,0,.1,0,.26-.06.45l-.05.12c-.4.72-1.43,2.87-1.43,4.08,0,.25.05.44.13.5.03,0,.07,0,.16,0,2.14,0,10.03-3.19,12.98-4.46,0,0,.01,0,.02,0l.56-2.24c.19-.76.8-1.25,1.54-1.25.89,0,1.59.7,1.59,1.59l-.03.26-.67,2.23c.79-.7,1.45-1.01,2.09-1.01.55,0,1.33.24,1.86,1.38,1.14,2.61,2.31,2.91,3.1,2.91,1.35,0,3.08-1.07,4.86-2.32,1.29-1.44,3.04-2.89,4.4-3.12.51-.08.83-.07,1.17-.05,1.28-1.27,2.28-2.05,3.22-2.05.17,0,.31.03.4.06l.1.03c.57.2,1.25.76,1.25,2.31,0,.41-.05.9-.15,1.47-.14.88-.28,1.6-.5,2.51,2.84-2.04,7.13-3.52,8.95-3.52.14,0,.31,0,.49.05.82.27.94.79.94,1.07ZM315.9,48.38c-.11.1-.22.21-.35.34.08.19.13.4.17.63.06-.27.11-.54.16-.8,0-.05.02-.11.03-.17ZM203.63,55.8c-1.4,9.27-6.79,16.33-7.5,17.21h0c-.25.35-.7.57-1.17.57-.66,0-1.14-.45-1.14-1.07,0-.27.1-.5.21-.7l6.76-16.79c.24-1.75.36-3.43.36-4.98,0-5.38-1.36-12.77-7.82-17.57-1.2.07-2.15.1-2.97.1-4.79,0-5.43-.75-5.64-1-.23-.22-.36-.55-.36-.93,0-.29.1-.72.6-1.16.74-.63,1.56-1.05,2.44-1.26.43-.09.97-.19,1.56-.19,1.02,0,2.1.24,3.21.7.55.22,1.11.52,1.7.9,2.55-.19,5.1-.4,7.65-.62,3.29-.28,6.69-.56,10.06-.79l.66-1.69c.14-.56.68-.99,1.3-.99.77,0,1.38.61,1.38,1.4v.14l-.05.22-.28.73c10.17-.56,16.06-.8,20.18-.8,3.46,0,5.75.18,7.42.59,2.05.55,3.08,1.49,3.08,2.81,0,.15,0,.34-.05.56-.05.58-.5.98-1.11.98s-1.18-.31-1.49-.78l-.2-.3.11-.34c-.01-.14-.3-.61-1.14-.85-2.54-.67-7.58-.42-19.31.26-2.47.14-5.23.3-8.34.48l-10.11,25.12ZM203.45,48.86l2.69-5.83,4.33-12c-2.36.18-4.74.4-7.05.62-2.2.2-4.46.41-6.73.6,4.15,4.14,6.42,9.72,6.75,16.61Z" />
            </g>
            <g class="hc-preloader__ring" mask="url(#hc-preloader-o)">
                <circle cx="81.08" cy="27.09" r="21.4" />
            </g>
    </svg>
</div>
```

- [ ] **Step 5: Render it in both layouts**

In `resources/views/layouts/app/sidebar.blade.php`, insert directly after the `<body ...>` line (before `<x-navigation.sidebar />`):
```blade
        @persist('preloader')
            <x-preloader />
        @endpersist

```
In `resources/views/layouts/auth/simple.blade.php`, insert the same block directly after `<body class="min-h-screen bg-[#e5e7eb] antialiased dark:bg-secondary-dark">`.

It sits in the layouts (not inside the component) to match the existing `@persist('toast')` / `@persist('notification-toasts')` pattern. Both layouts use the same key, so a `wire:navigate` hop between them reuses the element.

- [ ] **Step 6: Run the tests and confirm they pass**

Run: `.\php.cmd artisan test --compact tests/Feature/PreloaderTest.php tests/Feature/LocalizationTest.php tests/Feature/DashboardTest.php tests/Feature/Auth/AuthenticationTest.php tests/Feature/ErrorPagesTest.php`
Expected: all PASS.

(Without Task 2's CSS, the loader renders as a plain inline SVG at the top of the page. That's expected until Task 2 is done.)

- [ ] **Step 7: Review.** Leave the changes uncommitted and list the changed files.

---

### Task 2: Styles, behavior, and one loading indicator

**Files:**
- Modify: `resources/css/app.css:354-371` (replace the NProgress block)
- Create: `resources/js/preloader.js`
- Modify: `resources/js/app.js:1` (the import list)
- Create: `config/livewire.php` (artisan)
- Test: `tests/Feature/PreloaderTest.php`

**Interfaces:**
- Consumes: everything that Task 1 produces.
- Produces:
  - Keyframe names `hc-preloader-appear`, `hc-preloader-failsafe`, `hc-preloader-rise`, `hc-preloader-write`, `hc-preloader-spin`, `hc-preloader-breathe`. The JS reads `hc-preloader-appear`, `hc-preloader-rise` and `hc-preloader-write` by name through `element.getAnimations()`.

- [ ] **Step 1: Write the failing test** (append to `tests/Feature/PreloaderTest.php`)

```php
test('livewire progress bar is off so the preloader is the only navigation indicator', function () {
    expect(config('livewire.navigate.show_progress_bar'))->toBeFalse();
});
```

- [ ] **Step 2: Run it and confirm it fails**

Run: `.\php.cmd artisan test --compact --filter="progress bar" tests/Feature/PreloaderTest.php`
Expected: FAIL (the vendor default is `true`).

- [ ] **Step 3: Publish the Livewire config and turn the bar off**

Run: `.\php.cmd artisan livewire:config --no-interaction`
Then, in `config/livewire.php`, replace:
```php
    'navigate' => [
        'show_progress_bar' => true,
        'progress_bar_color' => '#2299dd',
    ],
```
with:
```php
    'navigate' => [
        'show_progress_bar' => false,
        'progress_bar_color' => '#be1f24',
    ],
```
(The color is kept brand red in case someone turns the bar back on.)

- [ ] **Step 4: Run it and confirm it passes**

Run: `.\php.cmd artisan test --compact tests/Feature/PreloaderTest.php`
Expected: 4 PASS.

- [ ] **Step 5: Replace the NProgress CSS with the preloader styles**

In `resources/css/app.css`, delete the whole block from the comment `/* * Page-navigation progress bar (Livewire's wire:navigate, NProgress markup):` through the closing `}` of `html #nprogress .spinner-icon { ... }`. Put this in its place:

```css
/*
 * Logo preloader (x-preloader, resources/js/preloader.js). data-state drives it:
 *   boot     server-rendered. Quick mode fades in after 200ms; intro mode
 *            (html[data-preloader='intro']) is visible at once and plays the
 *            write-on. Both hide themselves after 10s even if no script runs.
 *   waiting  visible; the O of HOPE becomes the loading ring.
 *   shown    visible and solid; pins opacity so the fade starts from 1.
 *   leaving  fades out while the logo grows slightly.
 *   hidden   gone, not clickable.
 */
.hc-preloader {
    position: fixed;
    inset: 0;
    z-index: 100;
    display: grid;
    place-items: center;
    background-color: var(--color-white);
    color: var(--color-primary);
    opacity: 0;
    visibility: hidden;
    pointer-events: none;
}

.dark .hc-preloader {
    background-color: var(--color-secondary-dark);
    color: var(--color-white);
}

.hc-preloader:is([data-state='boot'], [data-state='waiting'], [data-state='shown']) {
    visibility: visible;
    pointer-events: auto;
}

.hc-preloader:is([data-state='waiting'], [data-state='shown']) {
    opacity: 1;
}

.hc-preloader[data-state='waiting'] {
    transition: opacity 150ms ease-out;
}

.hc-preloader[data-state='boot'] {
    animation:
        hc-preloader-appear 150ms ease-out 200ms forwards,
        hc-preloader-failsafe 1ms linear 10s forwards;
}

html[data-preloader='intro'] .hc-preloader[data-state='boot'] {
    opacity: 1;
    animation: hc-preloader-failsafe 1ms linear 10s forwards;
}

.hc-preloader[data-state='leaving'] {
    visibility: hidden;
    opacity: 0;
    transition:
        opacity 400ms cubic-bezier(0.4, 0, 0.2, 1),
        visibility 0s linear 400ms;
}

.hc-preloader__logo {
    width: min(15rem, 64vw);
    overflow: visible;
    fill: currentColor;
    transition: transform 400ms cubic-bezier(0.4, 0, 0.2, 1);
}

.hc-preloader[data-state='leaving'] .hc-preloader__logo {
    transform: scale(1.04);
}

.hc-preloader__script {
    fill: var(--color-secondary-medium);
}

.dark .hc-preloader__script {
    fill: var(--color-white);
}

.hc-preloader__letter,
.hc-preloader__small {
    transform-box: fill-box;
    transform-origin: 50% 100%;
}

.hc-preloader__wipe {
    transform-box: fill-box;
    transform-origin: 0 50%;
}

.hc-preloader__o {
    transition: fill-opacity 200ms ease-out;
}

.hc-preloader__ring {
    opacity: 0;
    transition: opacity 200ms ease-out;
}

.hc-preloader__ring circle {
    fill: none;
    stroke: var(--color-primary);
    stroke-width: 15;
    stroke-dasharray: 40 96;
    transform-box: view-box;
    transform-origin: 81.08px 27.09px;
}

/* The spinning O: quick boot and waiting. */
.hc-preloader:is([data-state='boot'], [data-state='waiting']) .hc-preloader__o {
    fill-opacity: 0.2;
}

.hc-preloader:is([data-state='boot'], [data-state='waiting']) .hc-preloader__ring {
    opacity: 1;
}

.hc-preloader:not([data-state='hidden']) .hc-preloader__ring circle {
    animation: hc-preloader-spin 850ms linear infinite;
}

/* The write-on intro: the O stays solid while the letters arrive. */
html[data-preloader='intro'] .hc-preloader[data-state='boot'] .hc-preloader__o {
    fill-opacity: 1;
}

html[data-preloader='intro'] .hc-preloader[data-state='boot'] .hc-preloader__ring {
    opacity: 0;
}

html[data-preloader='intro'] .hc-preloader[data-state='boot'] .hc-preloader__letter {
    animation: hc-preloader-rise 750ms cubic-bezier(0.16, 1, 0.3, 1) calc(var(--i) * 80ms) both;
}

html[data-preloader='intro'] .hc-preloader[data-state='boot'] .hc-preloader__small {
    animation: hc-preloader-rise 500ms cubic-bezier(0.16, 1, 0.3, 1) calc(380ms + var(--i) * 45ms) both;
}

html[data-preloader='intro'] .hc-preloader[data-state='boot'] .hc-preloader__wipe {
    animation: hc-preloader-write 900ms cubic-bezier(0.65, 0, 0.35, 1) 600ms both;
}

@keyframes hc-preloader-appear {
    to {
        opacity: 1;
    }
}

@keyframes hc-preloader-failsafe {
    to {
        opacity: 0;
        visibility: hidden;
        pointer-events: none;
    }
}

@keyframes hc-preloader-rise {
    from {
        opacity: 0;
        transform: translateY(14px);
    }
}

@keyframes hc-preloader-write {
    from {
        transform: scaleX(0);
    }
}

@keyframes hc-preloader-spin {
    to {
        transform: rotate(1turn);
    }
}

@keyframes hc-preloader-breathe {
    to {
        fill-opacity: 0.35;
    }
}

@media (prefers-reduced-motion: reduce) {
    .hc-preloader__ring {
        display: none;
    }

    .hc-preloader:is([data-state='boot'], [data-state='waiting']) .hc-preloader__o {
        fill-opacity: 1;
        animation: hc-preloader-breathe 1.2s ease-in-out infinite alternate;
    }

    .hc-preloader__logo,
    .hc-preloader[data-state='leaving'] .hc-preloader__logo {
        transform: none;
        transition: none;
    }
}

@media print {
    .hc-preloader {
        display: none;
    }
}
```

- [ ] **Step 6: Create `resources/js/preloader.js`**

```js
/**
 * Drives the logo preloader (<x-preloader>) through its data-state values:
 * boot → (waiting) → shown → leaving → hidden. See the "Logo preloader"
 * block in app.css for what each state looks like.
 *
 * First load: the element arrives in `boot`. CSS alone fades it in after
 * SHOW_DELAY_MS (quick mode) or plays the write-on intro (intro mode, chosen
 * by the inline script in the component). This waits for the page and fonts,
 * and for the intro to finish, then fades it out.
 *
 * Page switches: the element is @persist-ed, so the same node survives every
 * wire:navigate swap. It only appears when a switch takes longer than
 * SHOW_DELAY_MS, and once visible it stays at least MIN_VISIBLE_MS, so fast
 * switches never flicker.
 */
const SHOW_DELAY_MS = 200;
const MIN_VISIBLE_MS = 500;
const LEAVE_MS = 400;
const FAILSAFE_MS = 10000;
const INTRO_ANIMATIONS = ['hc-preloader-rise', 'hc-preloader-write'];

let showTimer = null;
let failsafeTimer = null;
let visibleSince = null;
let navigating = false;

const findPreloader = () => document.querySelector('[data-preloader]');

const wait = (milliseconds) => new Promise((resolve) => setTimeout(resolve, milliseconds));

const setState = (element, state) => {
    element.dataset.state = state;
    element.toggleAttribute('inert', state === 'hidden' || state === 'leaving');

    const label = element.querySelector('[data-preloader-label]');

    if (label) {
        label.textContent = state === 'hidden' ? '' : label.dataset.text;
    }
};

const clearTimers = () => {
    clearTimeout(showTimer);
    clearTimeout(failsafeTimer);
    showTimer = null;
    failsafeTimer = null;
};

const hideNow = (element) => {
    clearTimers();
    visibleSince = null;
    setState(element, 'hidden');
};

/**
 * Fade out, but never before the loader has been on screen MIN_VISIBLE_MS.
 * A loader that never became visible is hidden without any animation.
 */
const leave = (element) => {
    clearTimers();

    if (visibleSince === null) {
        hideNow(element);

        return;
    }

    const remaining = Math.max(0, visibleSince + MIN_VISIBLE_MS - performance.now());

    setTimeout(() => {
        if (navigating) {
            return;
        }

        setState(element, 'shown');
        void element.offsetWidth;
        setState(element, 'leaving');

        setTimeout(() => {
            if (element.dataset.state === 'leaving') {
                hideNow(element);
            }
        }, LEAVE_MS);
    }, remaining);
};

/**
 * When the boot loader became visible, on the performance.now() clock, or
 * null when it has not appeared yet. Intro mode is visible from the start.
 * Quick mode appears when its CSS fade-in starts, SHOW_DELAY_MS after the
 * animation began.
 */
const bootVisibleSince = (element) => {
    if (document.documentElement.dataset.preloader === 'intro') {
        return 0;
    }

    const appear = element
        .getAnimations()
        .find((animation) => animation.animationName === 'hc-preloader-appear');

    if (!appear || appear.startTime === null) {
        return null;
    }

    const since = appear.startTime + SHOW_DELAY_MS;

    return performance.now() >= since ? since : null;
};

const finishBoot = async (element) => {
    const domReady = document.readyState === 'loading'
        ? new Promise((resolve) => document.addEventListener('DOMContentLoaded', resolve, { once: true }))
        : Promise.resolve();

    let isReady = false;
    const ready = Promise.all([domReady, document.fonts?.ready ?? Promise.resolve()])
        .then(() => {
            isReady = true;
        });

    const intro = element
        .getAnimations({ subtree: true })
        .filter((animation) => INTRO_ANIMATIONS.includes(animation.animationName))
        .map((animation) => animation.finished);

    await Promise.allSettled(intro);

    if (element.dataset.state !== 'boot') {
        return;
    }

    if (intro.length > 0 && !isReady) {
        setState(element, 'waiting');
    }

    await Promise.race([ready, wait(FAILSAFE_MS)]);

    if (navigating) {
        return;
    }

    visibleSince = bootVisibleSince(element);
    leave(element);
};

document.addEventListener('livewire:navigate', (event) => {
    const element = findPreloader();

    if (!element) {
        return;
    }

    clearTimers();
    navigating = true;

    // Checked after the delay: another listener (e.g. the notification rules'
    // unsaved-changes confirm) may cancel the navigation after this one ran.
    showTimer = setTimeout(() => {
        if (event.defaultPrevented) {
            navigating = false;

            return;
        }

        if (element.dataset.state !== 'waiting') {
            visibleSince = performance.now();
            setState(element, 'waiting');
        }
    }, SHOW_DELAY_MS);

    failsafeTimer = setTimeout(() => {
        navigating = false;
        hideNow(element);
    }, FAILSAFE_MS);
});

document.addEventListener('livewire:navigated', () => {
    // Livewire also fires this on the initial page load; finishBoot owns that.
    if (!navigating) {
        return;
    }

    navigating = false;

    const element = findPreloader();

    if (element) {
        leave(element);
    }
});

window.addEventListener('pageshow', (event) => {
    const element = findPreloader();

    if (event.persisted && element) {
        navigating = false;
        hideNow(element);
    }
});

const bootElement = findPreloader();

if (bootElement?.dataset.state === 'boot') {
    finishBoot(bootElement);
}
```

- [ ] **Step 7: Import it first in `resources/js/app.js`**

Add as the very first line (above `import './charts';`), so boot handling starts before the other modules:
```js
import './preloader';
```

- [ ] **Step 8: Build and run the tests**

Run: `npm run build`
Expected: the build succeeds with no CSS or JS errors.
Run: `.\php.cmd artisan test --compact tests/Feature/PreloaderTest.php`
Expected: 4 PASS.

- [ ] **Step 9: Review.** Leave the changes uncommitted and list the changed files.

---

### Task 3: Browser verification (no code, proof only)

Use the in-app browser. Start the dev server with `preview_start {name: "hc-tracking"}`, or use the XAMPP URL from Boost `get-absolute-url`. Log in as a seeded user.

- [ ] **Step 1: Intro.** Run `localStorage.removeItem('hc-preloader:intro-at'); location.reload();`
  - The intro plays: HOPE rises letter by letter, CENTER follows, and "Tracking" writes left to right. Then the loader fades out.
  - Take a screenshot mid-intro.
  - Confirm that `document.querySelector('[data-preloader]').dataset.state === 'hidden'` afterwards.
- [ ] **Step 2: Quick refresh.** Reload again. The intro does **not** replay, and on a fast local load nothing is visible.
- [ ] **Step 3: Slow page switch (simulated).** In the console:
  ```js
  const e = new CustomEvent('livewire:navigate', { cancelable: true, detail: {} });
  document.dispatchEvent(e);
  setTimeout(() => document.dispatchEvent(new CustomEvent('livewire:navigated')), 1500);
  ```
  At ~300 ms the state is `waiting` (spinning O, screenshot). At ~1.5 s the O fills and the loader fades, ending in `hidden`.
- [ ] **Step 4: Fast switch.** Same as step 3 but with a 100 ms delay. The state never leaves `hidden`.
- [ ] **Step 5: Cancelled switch.** Dispatch the navigate event, call `e.preventDefault()` immediately, and don't dispatch navigated. Nothing appears.
- [ ] **Step 6: Real navigation.** Click through five sidebar links. There's no NProgress bar and no flicker. Check `read_console_messages` for errors: expected none.
- [ ] **Step 7: Dark mode.** `resize_window {colorScheme: "dark"}` (or the theme toggle), then repeat step 3. The background is `#58595b`, the logo white, the arc red. Take a screenshot.
- [ ] **Step 8: Reduced motion.** Emulate `prefers-reduced-motion: reduce` if the browser allows it; otherwise check the media block's rules through `javascript_tool` computed styles. Repeat step 3: no spinner, the O pulses, and clearing `hc-preloader:intro-at` doesn't bring back the intro.
- [ ] **Step 9: Arabic.** Switch the locale to Arabic and repeat step 3. The logo isn't mirrored and the label reads "جارٍ التحميل…".
- [ ] **Step 10: Phone width.** `resize_window {preset: "mobile"}` and repeat step 3. The logo is ≤64vw with no overflow. Reset with `preset: "desktop"`.
- [ ] **Step 11: Login page.** Log out and clear the intro key. The intro plays on the login screen.

If any check fails: diagnose in the source, fix it, and re-run the check (superpowers:systematic-debugging).

---

### Task 4: Rule, formatting, final checks

- [ ] **Step 1: Record the project rule** with Boost `record-rule`:
  - glob: `{resources/views/components/preloader.blade.php,resources/js/preloader.js,resources/views/layouts/app/sidebar.blade.php,resources/views/layouts/auth/simple.blade.php,config/livewire.php}`
  - title: `Logo preloader: private logo copy, persisted, sole navigation indicator`
  - note: "x-preloader carries its own copy of the logo shapes (hc-preloader__* classes, no <style> in the SVG). It is the one allowed exception to 'don't inline the designer SVGs' because the write-on and the spinning O need per-shape control. If the logo changes, regenerate the shapes from public/logo-tracking.svg. It renders in @persist('preloader') in app/sidebar and auth/simple, never on error pages. The state machine lives in preloader.js and all styles in app.css; timings are 200ms show delay, 500ms minimum, 10s failsafe, and an 8h intro replay (localStorage hc-preloader:intro-at). Livewire's progress bar is off (config/livewire.php); don't turn it back on, or two indicators show."
- [ ] **Step 2: Format.** `.\php.cmd vendor/bin/pint --dirty --format agent`
- [ ] **Step 3: Targeted tests.** `.\php.cmd artisan test --compact tests/Feature/PreloaderTest.php tests/Feature/LocalizationTest.php tests/Feature/DashboardTest.php tests/Feature/ErrorPagesTest.php tests/Feature/Auth/AuthenticationTest.php` → all PASS.
- [ ] **Step 4: Hand-off.** Report the changed files. Ask the user to run the full suite with `.\php.cmd artisan test --compact` and to review the diff. Offer to commit, but don't commit.

---

## Self-review (done while writing)

- **Coverage:**
  - Behavior table → Tasks 1–2 and verification Task 3.
  - Error-page exclusion → Task 1 test 3.
  - Single indicator → Task 2 test and config.
  - i18n → Task 1 Step 3 plus `LocalizationTest`.
  - Reduced motion, dark mode, RTL, print → the CSS in Task 2 and Task 3 steps 7–10.
  - Failsafes → CSS `hc-preloader-failsafe`, JS `FAILSAFE_MS`, `<noscript>`, `pageshow`.
- **Names are consistent:**
  - `[data-preloader]`, `[data-preloader-label]`, and `data-state` values `boot|waiting|shown|leaving|hidden` are used the same in the Blade, CSS and JS.
  - The keyframe names in the JS match the CSS.
  - `hc-preloader:intro-at` is the same in the Blade and the rule.
- **Known limits:**
  - The intro timestamp is written when the intro is chosen, so two tabs opened in the same instant can both play it. That's acceptable.
  - `startTime`-based visibility is approximate by one frame at most.
