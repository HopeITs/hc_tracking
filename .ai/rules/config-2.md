---
paths:
  - config/session.php
---

# Config 2

## expire_on_close defaults true — a non-remembered login must not survive browser close
`'expire_on_close'` now defaults to `true` (was Laravel's stock `false`), with `SESSION_EXPIRE_ON_CLOSE=true` set explicitly in `.env` and `.env.example`. Root cause fixed: Laravel's stock default sets the session cookie's Max-Age to `SESSION_LIFETIME` minutes (120) regardless of whether "remember me" was checked, so a user who did NOT check it would still stay logged in after fully closing and reopening the browser, for up to 2 hours — this was reported as a bug ("remember me" being effectively always-on).

This setting is independent of the "remember me" feature: when `expire_on_close` is true, Laravel sets the session cookie's expiry to `0` (a true browser-session cookie, confirmed via `StartSession::getCookieExpirationDate()`), which is unrelated to the separate `remember_web_...` recaller cookie Fortify/`Auth::login($user, true)` sets — that cookie keeps its own long (~5 year) expiry and is what makes "remember me" still work correctly across a closed browser. Don't revert this thinking it will break remember-me; they're two different cookies. Covered by the test `'the session cookie itself expires when the browser closes, remember me or not'` in `tests/Feature/Auth/AuthenticationTest.php`, which inspects `getExpiresTime()` on both cookies directly rather than relying on cookie presence alone.
