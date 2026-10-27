# Tracking Desk shell v2 — design

User request 2026-10-27 (branch `tracking-desk-ux-update`). The first Desk redesign removed the sidebar, the
old header tools and put every form in a side sheet. Users found it incomplete: pages felt unbordered, the Desk
was unclear, and panels trapped the page scroll. This pass restores the navigation people know and makes every
surface a centered popup.

## Decisions (user)

1. **Sidebar is back** on every page: an icon rail by default (collapsed), click to expand to labels; the choice
   is remembered per browser. It lists every page the user's role may open (same permission rules as before,
   one source: `App\Support\Desk\Destinations`), grouped like the old sidebar, with unread-module badges.
   "Your Desk" is the first link.
2. **Top bar** (old header, upgraded): logo · search ("Go to") · **Panels** button · notifications bell ·
   install app · report a problem · guideline · tour · language · theme · avatar menu (name, email, Security,
   My notifications, Log out). The Settings side sheet is removed; its links live in the sidebar.
3. **Panels everywhere**: the Panels button opens a centered popup showing a grid of Desk panels for the page
   you are on. Each page has its own set (saved per user per route on `users.page_panels`), with a sensible
   default per page. Add / remove / resize / reorder inside the popup. On the Desk it opens the Desk's own
   customize popup.
4. **Panels paginate, never scroll.** Panel bodies have no inner scroll; panels grow to fit one page of rows and
   share a row height. This removes the scroll trap (`overscroll-behavior: contain`).
5. **Smart search, no AI (for now).** Typo-tolerant, English and Arabic; understands questions (late, due
   today/this week, my tasks, waiting reviews, money, counts), actions ("new proposal", "add task"), pages and
   records. Always permission-filtered. An "Ask AI" fallback slot exists behind `config('desk.ask_ai')`
   (off) so AI can be added later without rework.
6. **No side drawers.** Every sheet (forms, record sheets, settings, customize) is a centered popup. Long forms
   become step wizards (Back / Next, last step submits; a server error jumps to the step holding the first
   invalid field). All form popups share one size so the site feels consistent.
7. **Inner pages** sit on a clear grid: functional sections (details, files, history, team, lists) are
   bordered cards aligned to that grid; headline figures stay typographic (not stat tiles — see the
   "boxy/bland" feedback).

## Non-goals

- No AI provider, package or API key now.
- No change to permissions, routes or data rules.
