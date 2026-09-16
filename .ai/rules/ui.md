---
paths:
  - resources/views/components/ui/detail-header.blade.php
---

# Ui

## detail-header has an optional titleClass override, default unchanged
Added an optional `titleClass` prop (default null) forwarded as `class="{{ $titleClass }}"` on the inner `flux:heading`. Flux merges caller classes after its own (`$attributes->class(...)`), so pass `!`-suffixed utilities to override — e.g. strategies/⚡show.blade.php uses `title-class="text-3xl! font-bold! leading-none!"` to match the bold 30px title style used on index/directory page headers, while every other page using x-ui.detail-header (donors, funding-agreements, projects, proposals, reports, tasks, yearly-plans) omits the prop and keeps the original `size="lg"` look untouched.
