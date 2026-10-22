---
paths:
  - 'resources/views/components/desk/**'
---

# Desk

## Desk panels: decisions where the artifact has no real data
User decisions 2026-10-20: Risks panel = list (open first, then happened). Superseded 2026-10-22: the panel has the 3×3 heat map on risks.likelihood × risks.severity (1–3, nullable; unrated risks stay in the list, off the map). Projects panel derives stage (proposal stage + project status) and health (late item = off track, due within 7 days = watch, else on track). Waiting on others = tasks I created assigned to others and late + my monthly reports awaiting MEAL + my proposals with unread reviewers + my projects' undelivered deliverable requests. Nudge = in-app notification, once per item per 24h, logged in activity. Annual plans: no status pill. To validate: no "flags". Tasks panel tick = Done/back, only when policy updateStatus allows. Install app / Report a bug = Settings sheet rows. The artifact's "?" help buttons are left out.

## The sidebar and old top header are gone (supersedes the sidebar notes)
Tracking Desk redesign (2026-10-20): components/navigation/sidebar, desktop-user-menu, report-bug-button, guideline-button, app-logo, notifications/install-button and module-badge, pages/dashboard/* and resources/css/navigation.css were deleted. Every rule in .ai/rules/app.md, components-navigation.md and navigation.md about the sidebar, its collapse toggle, its header row or the desktop top header is obsolete. The shell is now x-desk.top-bar (logo, Ask bar, guide, tour, ع/EN, theme, avatar → x-desk.settings-sheet). Pages are reached through App\Support\Desk\Destinations (Ask bar "Go to" + Settings sheet), which must stay route-backed and permission-filtered; Security, Log out, Install app and Report a problem live in the Settings sheet. The user id for live notifications stays on <body data-user-id>.
