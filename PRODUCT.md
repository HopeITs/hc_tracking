# Product

<!-- impeccable:product-schema 1 -->

## Platform

web

## Users

Primary users are a **projects and fundraising manager** and their **program/project officers** at an NGO-style organization (working name "HC Tracking"). They are internal staff, not external/public users, and use the tool as a daily operating system for their program and funding portfolio — not a marketing or public-facing surface.

## Product Purpose

HC Tracking is an internal management platform for planning, funding, and monitoring an organization's strategic programs. It exists to give the fundraising/programs team one place to track the full lifecycle: strategy → yearly plans → programs → projects → milestones/tasks → results/indicators/targets → risks, alongside the funding side: donors → proposals → funding agreements → transfers, plus cross-cutting reporting (an executive summary and detailed reports) and admin (users, cities, accounts). Success means the manager and officers can see status, risk, and progress at a glance and drill into detail without friction.

## Positioning

Not a generic CRUD admin panel. The product's edge is unifying **program delivery** (strategy/projects/milestones/results) and **fundraising** (donors/proposals/agreements/transfers) in one coherent, always-current picture — so leadership decisions and officer execution draw from the same source of truth instead of siloed spreadsheets.

## Operating Context

- Built on Laravel 13 + Livewire/Volt ("pages::" namespaced Livewire full-page components) + Flux UI + Tailwind CSS v4.
- Domains/routes in the app: executive-summary, strategies, programs, yearly-plans, projects, risks, milestones, results, indicators, targets, tasks, proposals, donors, funding-agreements, transfers, reports, admin (users, cities, accounts), plus a dashboard and settings area.
- Multi-language: the app ships translations (`lang/`) for all pages — i18n is a live, non-optional constraint on any redesign.
- Auth via Laravel Fortify (login, 2FA, passkeys) behind `auth`/`verified` middleware; most of the app is gated.
- Runs on an isolated PHP 8.5 toolchain in this environment (project convention, not a product fact for design).

## Capabilities and Constraints

- Data-heavy domain: strategies, programs, projects, milestones, results, indicators/targets, risks, tasks, proposals, donors, funding agreements, transfers — many of these are linked/nested (e.g. programs → projects → milestones; donors → proposals → funding-agreements).
- Current UI leans on tables for most index pages (per user feedback) — explicitly **not required** to stay that way; user wants graphs, statistics, cards, and tables combined wherever they communicate the content best.
- Existing design system: a fixed sidebar layout and a 5-color palette (1 primary red `#be1f24` + 4 secondary grays), Somar Sans as the brand typeface. The user wants these preserved as brand identity while the overall UI/UX, layout, and page concepts are reimagined — this is a full redesign, not a light refinement.
- Explicit design mandate from the user: avoid generic/templated "admin panel" design; deliver something innovative, creative, and impressive, while staying professional and highly usable for the manager/officer audience.

## Brand Commitments

- Product name in code/docs: "HC Tracking."
- Typeface: Somar Sans (self-hosted, weights 300–800) — keep.
- Palette: primary red `#be1f24`, secondary grays `#58595b` / `#6d6e71` / `#939598` / `#dcddde` — keep as the brand palette; may be extended (e.g. semantic status colors, data-viz accents) but the identity anchor stays.
- Sidebar-based navigation shell — keep as the structural anchor; interior layout/pages are open to reinvention.

## Evidence on Hand

- Full page inventory in `resources/views/pages/**` (18+ domains) and `routes/web.php` — used directly as ground truth for the page-by-page design audit.
- No existing DESIGN.md, brand guide, testimonials, or marketing copy — none to be fabricated.

## Product Principles

1. **One source of truth across delivery and funding** — every page should make the connection between program work and the money behind it legible, not hide it in separate silos.
2. **Glanceable status, drill-down detail** — managers need portfolio-level signal (progress, risk, funding health) in seconds; officers need to get to the specific record fast. Design for both altitudes on every index page.
3. **Preserve the brand anchor, reinvent the experience** — the red/gray palette, Somar Sans, and sidebar shell stay recognizable; everything about how information is composed, visualized, and navigated is open to a bold rethink.
4. **i18n and accessibility are non-negotiable constraints**, not afterthoughts — every new pattern must work translated and must meet standard accessibility expectations for an internal tool used daily.
5. **Data-viz and cards over walls of tables** — favor statistics, charts, and card-based summaries as first-class citizens alongside tables, chosen per page based on what best communicates that page's content.

## Accessibility & Inclusion

No formal accessibility standard has been specified by the user; treat as a standard internal business-tool bar (keyboard navigable, sufficient contrast, screen-reader-sane) absent further instruction.
