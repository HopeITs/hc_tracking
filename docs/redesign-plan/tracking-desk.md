# Tracking Desk UX Redesign

The plan, decisions and status for the **Tracking Desk** redesign of HC Tracking. Read this before you change or ask about anything in the new shell, Desk, registers, Story pages, side sheets or Settings.

> This replaces the earlier "Operations Board" plan in this folder (`README.md`, `00`–`06`, `pages/`) wherever they conflict. That older plan describes the sidebar layout, which no longer exists.

- **Branch:** `tracking-desk-ux-update` (cut from `gitlab/main` at `bf06a7f8`)
- **Backup of the old site:** branch `original-website`
- **Design source:** the "HC Tracking Desk" artifact, https://claude.ai/artifact/RQSAP2DWspKcyXX3HgTKbW (private, ask Youssef for access). Its "Where did every page go?" button lists where every old page now lives.
- **Push to:** the `gitlab` remote only. Do not push to `origin`, because it mirrors to GitHub.
- **Merge to main:** only when Youssef says so.
- **Area rules:** `.ai/rules/desk.md` (Desk panel decisions, removal of the sidebar)

---

## 1. What changes

The redesign rebuilds the information architecture. It is more than a reskin.

| Old | New |
| --- | --- |
| Sidebar plus top header | **Top bar** with the logo, the **Ask bar** ("Find, add or ask anything"), guide, ع/EN, theme and avatar |
| Dashboard pages | **Desk** made of panels (tasks, projects, risks, waiting on others, plans, …) |
| List pages | **Registers** behind each panel, with table, board and calendar views |
| Project show page with tabs, and a separate proposal page | **One Story page per project**, organised as chapters |
| Centered modal forms | **Side sheets** using one shared component, `x-desk.sheet` |
| Settings and admin spread across the sidebar | **One Settings sheet**: avatar → Settings, in 5 groups |

All navigation goes through `App\Support\Desk\Destinations`, which feeds the Ask bar's "Go to" and the Settings sheet. Every entry must be backed by a route and filtered by permission.

## 2. Decisions that apply to every stage

1. **Desk layout is per user and saved in the DB.** Users can move, resize and add panels.
2. **The Ask bar answers real questions**, such as "what is late?", in addition to search and "Go to".
3. **Fonts:** keep Somar Sans only. Do not use IBM Plex Mono. Use `tabular-nums` for figures.
4. **Old URLs keep working.** They redirect to, or open, the matching register or Story.
5. **Prototype-only parts are left out:** the prototype strip, "Viewing as", Missions, "Show what each part replaces", and the artifact's "?" help buttons.
6. **Design taste:** avoid stat-tile grids and uniform bordered boxes as the main structure. Prefer typographic figures and the dot-thread motif.
7. **Delivery happens in stages.** Youssef tests each stage before the next one starts. There is one commit per stage, made only after his OK.
8. **Tests:** run only the affected test files. Never run the full suite, which takes about 10 minutes.

## 3. Stages

| # | Stage | Commit | Status |
| --- | --- | --- | --- |
| 0 | Design tokens | `167d8c5e` | Done |
| 1 | Top bar, Ask bar, Settings sheet, Desk and all panels, nudge, locked panels | `d0a6b7a9`, `f809c9d2` | Done |
| 2 | Registers: frame, board and calendar views, desk styling | `a00959cb` | Done |
| 3 | Project Story pages | `26f671e7` | Done, **tests owed** |
| 4 | Side sheets, proposal page folded into the Story | `7dcc0ac0` | Done, **tests owed** |
| 5 | Settings (5 groups) and 13 admin/settings pages on `x-desk.register`, plus open-item fixes | `53f89e6c` | Done, tested |
| 6 | Every remaining page on the Desk: record sheets, last registers, risk heat map, test fixes | (uncommitted) | Done, tested |

### Stage 1: top bar and Desk

The sidebar, the old top header and the dashboard pages were deleted. See `.ai/rules/desk.md` for the full list of deleted files and the rule for each panel:

- **Risks panel:** a 3×3 heat map (likelihood × severity, added in stage 6) above the list (open risks first, then risks that happened).
- **Projects panel:** the stage comes from the proposal stage plus the project status. Health is "off track" when an item is late, "watch" when something is due within 7 days, and "on track" otherwise.
- **Waiting on others** collects four things:
  - late tasks I created that are assigned to others
  - my monthly reports awaiting MEAL
  - my proposals with unread reviewers
  - my projects' undelivered deliverable requests
- **Nudge** sends an in-app notification, at most once per item per 24 hours, and is logged in activity.
- **Tasks panel:** the tick toggles Done/back, and only appears when the `updateStatus` policy allows it.
- **Annual plans panel:** has no status pill. **To validate panel:** has no flags.
- **Install app** and **Report a problem** are rows in the Settings sheet.
- The user id for live notifications stays on `<body data-user-id>`.

### Stage 2: registers

`x-desk.register` is the frame for every list. It has search (`x-desk.register-search`), a pager, and board (`x-desk.board*`) and calendar (`x-desk.month-calendar`) views.

### Stage 3: project Story pages

- The proposal page folds into the project Story. Its old URL redirects to the Story at the right chapter.
- Project tabs move into the Story:
  - The **delivery chapter** holds milestones, tasks, plan, risks and targets.
  - The **side column** holds proposals, files and activity.
  - Each one reuses the existing tab partial.
- The Nudge button reuses the stage 1 nudge and is hidden when there is no href.
- `ReadsProjectStory::chapters` null-guards the program name.

### Stage 4: side sheets

- There is one shared `x-desk.sheet`. **Every** existing modal form across the app was reskinned into it, with its logic unchanged.
- Yes/no confirmations stay as centered dialogs.
- The proposal page is removed. All `/proposals/{id}` URLs, including `?tab=…`, open the Story with the matching sheet or chapter.
- The proposal's read-only tabs (chronology, deadlines, documents, …) moved into the Story.

### Stage 5: Settings and admin

- The Settings sheet has 5 groups.
- 13 admin and settings pages now use `x-desk.register`.
- Open-item fixes in this stage:
  - Story edit, archive and restore
  - details for done chapters
  - a side column without boxes
  - a compact activity timeline
  - logframe links that open in place

## 4. Still owed

1. Run the affected Pest tests for stages 3–5 and fix anything that fails.
2. Youssef tests stage 5 in the browser.
3. Merge to `main`, only on Youssef's go.

## 5. Where the code lives

| Area | Path |
| --- | --- |
| Shell, Ask bar, Settings sheet | `resources/views/components/desk/top-bar.blade.php`, `⚡ask.blade.php`, `settings-sheet.blade.php` |
| Panels | `resources/views/components/desk/panels/`, `app/Support/Desk/Panels.php` |
| Navigation map | `app/Support/Desk/Destinations.php` |
| Registers | `resources/views/components/desk/register*.blade.php`, `board*.blade.php`, `month-calendar.blade.php`, `pager.blade.php` |
| Story | `resources/views/pages/projects/_story-*.blade.php`, `⚡show.blade.php`, `app/Livewire/Concerns/Projects/ReadsProjectStory.php` |
| Helpers | `app/Support/Desk/` (`Money`, `ProjectStage`, `RelativeDay`) |
| Styles | `resources/css/desk.css`, `resources/css/desk-story.css` |
| Strings | `lang/{en,ar}/desk.php`, `lang/{en,ar}/story.php` |

## 6. Stage 6: everything on the Desk (2026-10-22)

- **Record sheets** (`x-desk.record-sheet`): task, milestone, annual plan, funding agreement, donor report (and compose), monthly report, report, donor, strategy and program pages open as a side sheet over their register. Old URLs keep working; the show component keeps all its logic; the register behind is deferred and inert. Closing (X, Esc, scrim) goes back to the register.
- **Program** opens over the Projects register filtered to it.
- **Timeline, Results, Executive summary, Monthly matrix, Indicator library** moved onto `x-desk.register`. Timeline is the full register behind the Desk's Dates lens (the panel's "open" link follows the lens). Results stays a register because program-level frameworks have no Story.
- **Risk heat map**: new nullable `risks.likelihood` / `risks.severity` (1–3), set in the risk sheet.
- **Footer** stays, on every Desk page.
- **Fixes found by the test run**: activity timeline missing root (500 on every record page), Results link hidden from MEAL, Monthly Matrix SQL failing under MySQL ONLY_FULL_GROUP_BY.
