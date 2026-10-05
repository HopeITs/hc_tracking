---
paths:
  - resources/views/pages/auth/login.blade.php
---

# Auth

## Login field focus mirrors .admin-directory's outline, not a border-color change
The "strategy controls" focus style the user asked to match is `.admin-directory [data-flux-control]:focus` in resources/css/flux-overrides.css: a neutral 2px solid outline in `--color-secondary-medium`, `outline-offset: 2px`, `box-shadow: none` — border-color stays whatever it already was, it does NOT turn red/primary on focus. Don't add the `.admin-directory` class to the login page itself to get this for free — that class also forces `background-color: white` and `border-color: secondary-light` on `[data-flux-control]` at the base (unfocused) state, which would silently strip the light-blue tinted fields the reference screenshot needs. Instead the login inputs carry the focus half of that treatment directly as Tailwind utilities: `focus:outline! focus:outline-2! focus:outline-secondary-medium! focus:outline-offset-2! focus:shadow-none!`.

Also: `.matches(':focus')` and `getComputedStyle` after a synthetic `element.focus()` call are unreliable in this project's sandboxed browser tool — `document.activeElement` updates but `:focus` doesn't always apply, so a real `computer` left_click is needed to verify focus-only CSS visually (or rely on inspecting the compiled CSS specificity/source order, as was done here).
