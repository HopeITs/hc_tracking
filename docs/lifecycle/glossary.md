# Glossary: terms and roles

Arabic and English for every term used in the pack. Roles and slots never name people: in code everything binds to roles, slots or settings. The source of truth for permissions is `contracts.md §8`, for slots `§9`.

## Roles and slots
| English | العربية | Meaning |
|---|---|---|
| MEAL | المتابعة والتقييم | The department that owns the indicator library, frameworks, targets and report templates. A role (`MEAL`). |
| MEAL Manager (slot) | مدير المتابعة والتقييم (خانة) | A **slot**, not a person: an HR position (`notification_settings` key `proposal_default_reviewers.meal_manager`) that resolves to its current active holder through `Slots::holderOf('meal_manager')`. Decides report reopen requests (D-09a) and is the coverage fallback. |
| Projects & Fundraising Manager | مدير المشاريع وجمع التمويل | The business title. In code and permissions this is the role **Public Relations Manager**; its slot is `fundraising_manager`. |
| Public Relations Manager | مدير العلاقات العامة | The code role name for the Projects & Fundraising Manager. Holds `proposals.correct-status`, `funding-agreements.view/create/update` (D-31b), and decides reopen requests when the MEAL Manager slot is empty or inactive. |
| Finance (Financial) | المالية | Role `Financial`. Money permissions; reviewer slot `finance_manager`. |
| General Manager (= CEO) | المدير العام (= الرئيس التنفيذي) | Role `General Manager`; reviewer slot `ceo`. Corrects final statuses; does **not** validate monthly reports. |
| Managers | المدراء | Role `Managers`: program, branch and center managers. |
| Media Team | فريق الإعلام | Role `Media Team`. Sees its department tasks with no money (D-16). |
| Public Relations | العلاقات العامة | Role `Public Relations`. |
| Team Member | عضو فريق | Role `Team Member`. |
| Super Admin | مدير النظام (للمطورين) | Developers only. Holds every permission technically, but appears in no business list, no fallback, and is never the only test account (D-20a). |

The nine fixed role names (`contracts.md §8`): Super Admin, General Manager, Managers, MEAL, Financial, Media Team, Public Relations, Public Relations Manager, Team Member.

## Lifecycle terms
| English | العربية | Meaning |
|---|---|---|
| Concept note | مذكرة مفاهيمية | The first, short step for one donor: `concept note` → `concept note review` → `concept note submitted`. Can be skipped with a written reason (D-03). |
| Proposal | مقترح | The planning container: logframe, indicators, targets, milestones, department tasks, budget. `proposal draft` → `proposal review` → `submitted`. |
| Project | مشروع | Created in an HR center + program. Draft with no dates; `approved` after donor approval and owner acknowledgement; `active` after MEAL confirms the report template. |
| Center | مركز | An HR building. It hosts several projects; only one project per (center, program) may be open (draft, approved or active). |
| Logframe | الإطار المنطقي | The results framework: Impact → Outcome → Output → Activity, plus cross-cutting nodes (root only). A program framework has `proposal_id IS NULL`; a proposal has its own. |
| Template (report template) | قالب التقرير | MEAL's per-project definition of the monthly report lines (library indicators only, each with its schedule). Confirming it activates the project. Also: a milestone template such as Final Report. |
| Shared attribution | إسناد مشترك | Program setting `shared`: the same indicator counts fully for each donor; one line; the target is the higher one (D-40/D-46). |
| Split attribution | إسناد مقسَّم | Program setting `split`: the manager enters each donor's share; the center total is the sum; the target is the sum. |
| Tranche | دفعة (تحويل) | A `transfers_tracking` row: one money transfer, expected or actual. |
| Installment | قسط | A row of the planned schedule (`funding_agreement_installments`). One expected tranche is generated per installment. |
| Checkpoint (CP0–CP6) | نقطة تحقق | Planning milestones of the 15-day plan: CP0 decisions frozen (day 2), CP1 contracts signed (day 3), CP2 state machine merged (day 6), CP3 report and template schemas (day 9), CP4 end-to-end test (day 11), CP5 feature freeze (day 12), CP6 Go / No-Go (day 13). |
| Slot | خانة وظيفية | A named HR position (for example `meal_manager`, `finance_manager`, `fundraising_manager`, `ceo`) that resolves to its current holder at use time; a vacant slot gives a warning and is skipped. |
| Handoff | ملف التسليم | `docs/lifecycle/handoffs/TNN.md`: written by each task at the end; the integration contract downstream tasks read. |
| Monthly report | التقرير الشهري | One per project per month; the manager submits, MEAL validates. Only validated numbers count. |
| Validate | اعتماد (تدقيق) المتابعة والتقييم | MEAL's confirmation of a submitted monthly report. |
| Annual plan | الخطة السنوية | Per program and year: targets (MEAL) and, from T24, activities with planned months. |
| Final report milestone | مرحلة التقرير النهائي | The `is_final_report` milestone that replaces the removed `final reports` status (D-02). |
