# HC Tracking Redesign Plan

Everything a team needs to rebuild every page of HC Tracking in the **Operations Board** design, in parallel, without stepping on each other.

Source brief: https://claude.ai/artifact/PQVoT5pU91RebubDgAfjS4 · Product context: [`PRODUCT.md`](../../PRODUCT.md)

---

## 1. How this folder is organised

```
docs/redesign-plan/
├── README.md                  ← you are here: rules of the game, decisions, tracker
├── 00-conventions.md          ← LAW: rules every change must follow (strict mode, money, i18n, RTL, tests, git)
├── 01-design-system.md        ← LOOK: tokens, type, spacing, motion, §6 The Operations Board visual world
├── 02-shared-components.md    ← BUILDING BLOCKS: exact props of every x-ui.* component pages may use
├── 03-charts.md               ← CHARTS: ApexCharts setup + x-ui.chart contract
├── 04-app-shell.md            ← SHELL: sidebar, layout, mobile header (one owner)
├── 05-command-palette.md      ← SEARCH: Ctrl/⌘ K palette (one owner)
├── 06-qa-and-polish.md        ← FINISH: cross-page QA pass after all pages are in review
└── pages/
    └── <page>/                ← one folder per page, one programmer per folder
        ├── README.md          ← the build spec: current state, target design, Livewire code, states, responsive, a11y, build order
        ├── copy.md            ← every new string, English + Arabic, exact keys
        ├── tests.md           ← existing tests that must stay green + full Pest code for new tests
        └── checklist.md       ← sign-off list + review note template
```

**Two layers, one rule:** the numbered files (`00`–`06`) are **shared and binding for everyone**. A page folder never repeats them. It points to the exact sections it depends on (its README §1 "Read first") and adds only what is specific to that page. If a page spec and a shared doc disagree, the shared doc wins; report the conflict to the Foundation owner.

## 2. How a programmer uses this plan (step by step)

### Before starting (once per person)
1. Read [`00-conventions.md`](00-conventions.md) fully (20 min). It lists the test hooks, money rules and translation rules that break builds when ignored.
2. Read [`01-design-system.md`](01-design-system.md) §6 (The Operations Board) and skim [`02-shared-components.md`](02-shared-components.md). These are the vocabulary every page spec uses ("timetable row", "stage rail", "status block", "flap figure").
3. Set up: `composer.cmd install`, `npm install`, `npm run dev`, `./php.cmd artisan test --compact` (baseline: 816 tests, 810 passed, 6 skipped).

### Claiming work
4. Open the **Tracker** (§5). Pick an unclaimed **domain group** (for example "D · Projects"), not a single page: pages in one group share a lang file and are assigned to one person.
5. Write your name in **Owner** for every row of that group and set **Status** to `in progress`. Commit nothing; the tracker edit is coordinated by the team lead (or tell the lead in chat, and they update it).

### Building one page
6. Open `pages/<page>/README.md` and read **§1 Read first**: it names the exact shared sections that page relies on. Read those sections.
7. Read **§2 Files**. You may edit only "You own" files. Anything in "Do not edit" goes through its owner:
   - components, `ui.php`, `common.php`, `app.css` → **Foundation** owner (`01`–`03`)
   - sidebar, layouts, `navigation.php` → **Shell** owner (`04`–`05`)
   - `routes/web.php` → only the dashboard page owner, only its line
8. Follow **§11 Build order** exactly. It always goes: copy keys → Livewire class (keep existing tests green) → markup → states → tests → browser pass → gates.
9. Take strings from `copy.md` (both languages) and tests from `tests.md`.
10. If the page needs a shared component that isn't built yet, code against the contract in `02-shared-components.md` (same tag and props). Your page will render once Foundation merges it. **Never** create a private copy of a shared component inside a page.
11. If the spec is wrong about the real code (a renamed property, a missing relation), follow the real code, write the deviation in `checklist.md` → *Review note*, and tell the lead. Don't invent a workaround in shared files.

### Finishing a page
12. Tick every box in `checklist.md` (tests, Pint, build, light/dark × en/ar screenshots, responsive widths, a11y, query budget).
13. Fill the *Review note* (changed files, deviations, screenshots) and set tracker **Status** to `review`.
14. **Do not commit or push.** The project owner reviews every file first (00-conventions §9).

### Order of work across the team
```
Wave 0   Foundation owner: 01-design-system → 02-shared-components → 03-charts  (blocks pages' final look)
Wave 0b  Shell owner:      04-app-shell + 05-command-palette                     (parallel, after 01)
Wave 1   Page owners:      all pages/<page>/ folders in parallel                 (can start on the Livewire/data/test parts
                                                                                   immediately; markup finishes once 02 lands)
Wave 2   QA owner:         06-qa-and-polish                                      (after every page is in review)
```

### Communication rules
- A change to a shared contract (component prop, token name, tone map) is announced to all page owners before it merges.
- Two people never edit the same file. If you must, the owner of the file makes the change.
- Questions about product behaviour go to the project owner, not decided in code.

## 3. Decisions already made (do not reopen)

| Topic | Decision |
| --- | --- |
| Visual world | **The Operations Board** ([01 §6](01-design-system.md)): every record reads as *scheduled · actual · status*; figure housing with flap figures (dashboard, one key figure in detail headers only), timetable rows, lifecycle stage rail, flat status blocks, one flap motion grammar. |
| Brand anchor | Somar Sans, primary `#be1f24`, grays `#58595b` `#6d6e71` `#939598` `#dcddde`, persistent sidebar. Kept. |
| Palette extension | Approved: status green, status amber, funding teal, plus board/housing surfaces. Only for status, surfaces and charts, never as brand colour. |
| Charts | **ApexCharts 4.7** (already in `package.json`), wrapped by `x-ui.chart`. Small inline visuals (bars, rings, sparklines, timelines, heat cells) are server-rendered Blade components. |
| Task & report attachments | **Relabel** as "Referenced documents" (`x-ui.reference-list`). No upload/download backend. |
| Risk severity matrix | **Visual-only.** No migration, no new risk fields. |
| Command palette | **Build it** (Ctrl/⌘ + K). |
| Table/card view toggle | Projects index and Programs index only. |
| Kanban boards | Tasks (by status) and Proposals (by phase) are **read-only**; no drag-and-drop without owner approval. |
| Schema | No migrations, model, observer, factory or seeder changes. |
| Git | Nothing is committed or pushed by programmers; the owner reviews first. |

## 4. Build waves & owners

| Group | Scope | Wave |
| --- | --- | --- |
| Foundation | `01`, `02`, `03` | 0 |
| Shell | `04`, `05` | 0b |
| A · Command center | dashboard | 1 |
| B · Strategy | strategies-index, strategies-show, programs-index, programs-show | 1 |
| C · Plans & logframe | yearly-plans-index, yearly-plans-show, results-index | 1 |
| D · Projects | projects-index, projects-show, milestones-index, risks-index | 1 |
| E · Tasks | tasks-index, tasks-show | 1 |
| F · MEAL | indicators-index, targets-index | 1 |
| G · Reports | reports-index, reports-show | 1 |
| H · Donors & pipeline | donors-index, donors-show, proposals-index | 1 |
| I · Proposal & agreements | proposals-show, funding-agreements-index, funding-agreements-show | 1 |
| J · Money & admin | transfers-index, admin-users, admin-cities, admin-accounts | 1 |
| QA | `06` | 2 |

## 5. Tracker

| # | Spec | Route | Group | Owner | Status |
| --- | --- | --- | --- | --- | --- |
| F1 | [00-conventions.md](00-conventions.md) | — | read-only | — | final |
| F2 | [01-design-system.md](01-design-system.md) | — | Foundation | | todo |
| F3 | [02-shared-components.md](02-shared-components.md) | — | Foundation | | todo |
| F4 | [03-charts.md](03-charts.md) | — | Foundation | | todo |
| F5 | [04-app-shell.md](04-app-shell.md) | all | Shell | | todo |
| F6 | [05-command-palette.md](05-command-palette.md) | all | Shell | | todo |
| F7 | [06-qa-and-polish.md](06-qa-and-polish.md) | all | QA | | todo |
| P1 | [pages/dashboard/](pages/dashboard/README.md) | `dashboard` | A | | todo |
| P3 | [pages/strategies-index/](pages/strategies-index/README.md) | `strategies.index` | B | | todo |
| P4 | [pages/strategies-show/](pages/strategies-show/README.md) | `strategies.show` | B | | todo |
| P5 | [pages/programs-index/](pages/programs-index/README.md) | `programs.index` | B | | todo |
| P6 | [pages/programs-show/](pages/programs-show/README.md) | `programs.show` | B | | todo |
| P7 | [pages/yearly-plans-index/](pages/yearly-plans-index/README.md) | `yearly-plans.index` | C | | todo |
| P8 | [pages/yearly-plans-show/](pages/yearly-plans-show/README.md) | `yearly-plans.show` | C | | todo |
| P9 | [pages/results-index/](pages/results-index/README.md) | `results.index` | C | | todo |
| P10 | [pages/projects-index/](pages/projects-index/README.md) | `projects.index` | D | | todo |
| P11 | [pages/projects-show/](pages/projects-show/README.md) | `projects.show` | D | | todo |
| P12 | [pages/milestones-index/](pages/milestones-index/README.md) | `milestones.index` | D | | todo |
| P13 | [pages/risks-index/](pages/risks-index/README.md) | `risks.index` | D | | todo |
| P14 | [pages/tasks-index/](pages/tasks-index/README.md) | `tasks.index` | E | | todo |
| P15 | [pages/tasks-show/](pages/tasks-show/README.md) | `tasks.show` | E | | todo |
| P16 | [pages/indicators-index/](pages/indicators-index/README.md) | `indicators.index` | F | | todo |
| P17 | [pages/targets-index/](pages/targets-index/README.md) | `targets.index` | F | | todo |
| P18 | [pages/reports-index/](pages/reports-index/README.md) | `reports.index` | G | | todo |
| P19 | [pages/reports-show/](pages/reports-show/README.md) | `reports.show` | G | | todo |
| P20 | [pages/donors-index/](pages/donors-index/README.md) | `donors.index` | H | | todo |
| P21 | [pages/donors-show/](pages/donors-show/README.md) | `donors.show` | H | | todo |
| P22 | [pages/proposals-index/](pages/proposals-index/README.md) | `proposals.index` | H | | todo |
| P23 | [pages/proposals-show/](pages/proposals-show/README.md) | `proposals.show` | I | | todo |
| P24 | [pages/funding-agreements-index/](pages/funding-agreements-index/README.md) | `funding-agreements.index` | I | | todo |
| P25 | [pages/funding-agreements-show/](pages/funding-agreements-show/README.md) | `funding-agreements.show` | I | | todo |
| P26 | [pages/transfers-index/](pages/transfers-index/README.md) | `transfers.index` | J | | todo |
| P27 | [pages/admin-users/](pages/admin-users/README.md) | `admin.users.index` | J | | todo |
| P28 | [pages/admin-cities/](pages/admin-cities/README.md) | `admin.cities.index` | J | | todo |
| P29 | [pages/admin-accounts/](pages/admin-accounts/README.md) | `admin.accounts.index` | J | | todo |

Status values: `todo` → `in progress` → `review` → `done`.

Out of scope: auth pages (`pages/auth/**`) and settings pages (`pages/settings/**`). They inherit the shell and tokens automatically.
