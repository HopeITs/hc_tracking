---
paths:
  - 'resources/{css/app.css,fonts/alex-brush/**,views/components/hope-pt-logo.blade.php}'
---

# Components

## Logo script text uses the self-hosted Alex Brush font, not a system font stack
The first attempt at the "HOPE PT" logo used a system cursive font-family stack ('Segoe Script', 'Brush Script MT', cursive) for the "PT" glyphs. The user asked twice for closer fidelity to a reference logo image (bold red "HOPE CENTER" + a flowing gray script for the "HR"/"PT" part), and system-font rendering is both unverifiable in this dev environment (the sandboxed browser has none of those fonts installed, so every named stack silently fell back to the same generic cursive) and inconsistent across end-user machines.

Fixed by self-hosting Google's "Alex Brush" font (OFL-licensed, downloaded via `fonts.googleapis.com`/`fonts.gstatic.com`, then vendored into the repo) the same way `resources/fonts/somar-sans/**` is already self-hosted — `resources/fonts/alex-brush/AlexBrush-Regular.ttf` + one `@font-face` block in `resources/css/app.css` (font-weight 400, right after the Somar Sans block). `x-hope-pt-logo` (`resources/views/components/hope-pt-logo.blade.php`) is an inline SVG: "HOPE"/"CENTER" as bold/condensed Arial-stack text in `var(--color-primary)`, "PT" as `font-family: 'Alex Brush', cursive` in a `.hope-pt-logo-script` class whose fill is `var(--color-secondary-medium)` in light mode and `var(--color-secondary-soft)` in dark (scoped `<style>` inside the SVG, keyed off the ambient `.dark` class — no JS needed since it's inlined, not an `<img src>`).

If the logo needs further tuning, iterate the `x`/`y`/`font-size` numbers directly in that component — the current values (PT at x=212 y=76 size=64, viewBox 0 0 380 130) were reached by visually comparing renders against the reference image, not computed analytically.
