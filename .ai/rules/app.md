---
paths:
  - 'resources/views/{components/navigation/**,layouts/app/sidebar.blade.php}'
---

# App

## Keep sidebar navigation route-backed
The application sidebar is persistent from lg upward, uses a fluid 16rem-to-18rem width, and collapses only as a mobile drawer. Add navigation items only when their named routes exist; do not add placeholder links. Keep desktop rows dense while preserving Flux mobile touch targets.
