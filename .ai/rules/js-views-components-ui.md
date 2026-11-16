---
paths:
  - '{resources/views/components/ui/modal.blade.php,resources/js/modal-shell.js,resources/views/components/ui/form-section.blade.php}'
---

# Js Views Components Ui

## x-ui.modal never steps
No step wizard in any modal (user 2026-11-16, reverses desk-shell v2 decision 6; spec docs/superpowers/specs/2026-11-16-sectioned-modal-design.md). Every section shows at once and Save stays in the footer. 5+ top-level form sections → x-ui.modal renders the section index inside .modal-shell-main (chips below 48rem) and CSS widens the dialog to 960px via :has(.modal-shell-main) — never a class on the <dialog>, Flux keeps it wire:ignore.self; fewer → stacked sections at 760px. A section with no required control takes :optional="true". Enter does not submit indexed forms from plain inputs; Ctrl/Cmd+Enter submits any modal form. Tests read section names from .form-section-title (the heading also holds the Optional tag).
