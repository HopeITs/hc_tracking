---
paths:
  - routes/web.php
---

# Routes

## No marketing/welcome page — "/" redirects straight to login or dashboard
By explicit user request, `resources/views/welcome.blade.php` was deleted and the `home`-named route at `/` no longer renders a view. It's `Route::get('/', fn () => redirect()->route(Auth::check() ? 'dashboard' : 'login'))->name('home')` — guests land on login, authenticated users land on the dashboard. The `home` route name is kept (not removed) because `layouts/auth/*.blade.php` link their logo back to `route('home')`; that still works since it just redirects again. Don't reintroduce a rendered view at `/`.
