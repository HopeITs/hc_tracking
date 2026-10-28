# خطة تنفيذ مخرجات اجتماع 23/10/2026

> الحضور: Rami · Rita · Mai · Alice · Anthony. المصدر: محضر Mai + ملاحظات Rita.
> الحالة: **مسودة للمراجعة** (27/10/2026). تُقسَّم بعد الاعتماد إلى prompts بنمط `docs/lifecycle/prompts/TNN.md` (الأرقام من T35).
> المرجع لكل مهمة: `contracts.md` و`decisions.md`. أي بند يغيّر state machine أو جدول Phase 1 يبقى عند Team A.

## 0. ملخص المسح: ما هو موجود وما هو ناقص

| البند | الوضع الحالي في الكود | المطلوب |
|---|---|---|
| Dashboard panels | تخطيط ثابت للجميع، الإخفاء فقط بـ `canView()`. لا يوجد زر Add panel ولا إعدادات لكل مستخدم | تخصيص Panels بحسب الـ role |
| To-do / Notes card | غير موجود | إضافة |
| Risks على الديسك | tab داخل «Needs attention» + tile في pulse | إزالة |
| Project card بالتواريخ | غير موجود (فقط chart projects by status) | إضافة |
| Sidebar | المجموعات تختفي كاملةً إذا لا صلاحيات. Reports داخل مجموعة M&E | Reports ظاهرة، main tabs تظهر |
| Back button | غير موجود، فقط breadcrumbs في `x-ui.detail-header` | إضافة |
| Go to / Command palette | **غير منفَّذ أصلاً** (`docs/redesign-plan/05` spec فقط، F6 todo) | بناءه مع تصحيح وجهة Programs |
| City / Center في Proposal form | لا حقلي city ولا center؛ project picker بالاسم فقط | City إجبارية multi، Center اختياري multi |
| Donor pending | لا `donors.status`؛ لا إضافة inline | إضافة Pending/Approved + inline create |
| Concept note narrative | لا `MediaCollection::ConceptNote` | ملف أو نص + Title/Donor/Date |
| Submitted + On Hold | حالتان منفصلتان (`ProposalStatus`) | دمج إلى «Submitted (On Hold)» |
| Extension date | غير موجود؛ `ApproveProjectFromProposal` يمدّ `end_date` بصمت | حقل `extension_date` مميَّز |
| Internal project number | `proposals.internal_project_number` يدوي، unique | توليد تلقائي |
| Center فيزيائي / غير فيزيائي | لا يوجد؛ المركز من HR فقط | نمذجة المركز الافتراضي المُسنَد لمركز فعلي |
| Risks & Assumptions في Logframe | لا عمود ولا UI؛ Risk مربوط بـ project فقط | نقل الـ Risks إلى عقد الـ logframe |
| Tasks scoping | My tasks = assigned OR created؛ department scope بالـ role لا بالمدير | 3 نطاقات واضحة |
| Reporting as task | التقارير الشهرية alerts فقط، ليست tasks | Report task يظهر بالديسك |
| Indicator من داخل المشروع | غير ممكن (فقط صفحة indicators) | إضافة |
| Final reports لإغلاق Completed | **موجود** (D-02c، `hasFinalReport()`) | لا شيء |
| Targets behind clickable | اسم المشروع نص عادي في `_index-card` | رابط |
| ألوان الحالات | `enum-badge` موحّد لكن 74 ملف بأصناف يدوية | توحيد |
| Scroll فوق الكارد | overflow على الكارد يبلع scroll الصفحة | CSS |

## 1. قرارات مطلوبة من الإدارة قبل البدء (تُضاف إلى `decisions.md`)

| # | السؤال | الاقتراح الافتراضي |
|---|---|---|
| D-55 | **Center غير فيزيائي**: مثل «Micro Idlib» يُتابَع من «Micro Aleppo». هل نعرّفه في HR كمركز بـ `is_virtual` + `managed_by_center_id`، أم كـ city فقط على المشروع بدون center؟ | مركز في HR (الفكرة موجودة بنظام آخر عند Rami) مع `managed_by_center_id`؛ المشروع يُسنَد للمركز المُدير |
| D-56 | **إضافة المشروع من جهة واحدة**: Tracking أم HR؟ | HR هو المصدر للمراكز؛ **المشاريع تُنشأ من Tracking فقط**، و«Add center» يفتح HR |
| D-57 | **Internal project number**: صيغة الترقيم؟ (مثلاً `HC-2026-012`) ومن أي رقم يبدأ بعد المشاريع الحالية؟ | `HC-YYYY-SEQ` سنوي، يُولَّد عند إنشاء الـ proposal، غير قابل للتعديل |
| D-58 | **Donor pending**: من يعتمد الممول الجديد؟ وهل يمنع الـ pending الانتقال من `concept note` إلى `proposal draft`؟ | يعتمده Public Relations Manager أو GM؛ الـ pending يمنع `concept note review` فقط |
| D-59 | **دمج Submitted/On Hold**: هل تُحذف حالة `on hold` فعلاً (migration) أم تبقى كـ flag «on hold» على `submitted`؟ | تبقى `submitted` واحدة مع `hold_reason` + `status_remind_on`؛ `OnHold` تصبح `@deprecated` مثل `FinalReports` |
| D-60 | **Extension**: هل التمديد يحتاج موافقة (ملف donor_approval) كما الاعتماد الأول؟ | نعم، نفس evidence |
| D-61 | **Tasks المدير**: «مدير القسم» يُقرأ من HR (`positions.direct_manager_id`) أم من slot؟ | HR، عبر `SupervisionMap` (T23) |
| D-62 | **Reporting task**: التقرير الشهري يولّد task عند الإنشاء أم فقط إشعار deadline كما الآن؟ | task من نوع `report` لمُعدّ التقرير (D-12a/D-30) مع رابط للتقرير |
| D-63 | **Risks القائمة**: ماذا يحدث بـ 100+ risk المرتبطة بمشاريع؟ | تُهاجَر إلى عقدة root في logframe المقترح النشط، أو تبقى مع تاريخ فقط |
| D-64 | **الموظف البديل**: يحتاج بيانات إجازات من HR. هل متوفرة؟ | مؤجَّل (Phase 4) حتى تأكيد HR |

## 2. المهام مرتبة بالأولوية

### المرحلة A: إصلاحات سريعة (يوم 1–3) — لا قرارات مطلوبة

> الـ prompts جاهزة: `prompts/T35.md` … `prompts/T41.md`. التشغيل: `/lifecycle T35` (T40 على slice-ين: `/lifecycle T40 1` ثم `/lifecycle T40 2`). الترتيب المقترح: T35 → T39 → T37 → T38 → T36 → T41 → T40 (الأخير لأنه sweep يمسّ الصفحات التي تغيّرها البقية).

| المهمة | الوصف | الملفات | حجم |
|---|---|---|---|
| **T35** Scroll | إزالة `overflow-y-auto` من الكاردات التي لا تحتاجه، أو `overscroll-behavior: contain` فقط للقوائم الطويلة | `pages/dashboard/_index-row-*.blade.php`, `components/ui/panel.blade.php` | 0.5 يوم |
| **T36** Dashboard cleanup | إزالة tab Risks من «Needs attention» وtile «materialised risks»؛ إضافة **Project card** يعرض المشاريع النشطة مع start/end/extension وtab للتواريخ | `app/Livewire/Concerns/Dashboard/{ChartsDashboard,ReadsDashboardAttention}.php`, `_index-row-*` | 1 يوم |
| **T37** Targets behind | جعل اسم المشروع رابطاً إلى `projects.show` في `indicators/_index-card.blade.php:37` وفي نتائج Off track | `pages/indicators/_index-card.blade.php`, `pages/results/**` | 0.5 يوم |
| **T38** Back button | مكوّن `x-ui.back-link` داخل `detail-header` يعود للمستوى الأعلى من breadcrumbs (لا `history.back()`) | `components/ui/detail-header.blade.php` + كل صفحات show | 0.5 يوم |
| **T39** Sidebar | إظهار Reports كمجموعة مستقلة **Reports** (Monthly reports · Monthly Matrix · Report templates · Donor reports · Reporting duties)؛ التحقق من سبب اختفاء المجموعات: مستخدم بلا صلاحيات المجموعة لا يراها، وهذا متعمَّد. المطلوب تثبيت المجموعات الرئيسية وإظهار «لا صلاحية» بدلاً من الإخفاء إذا أرادت الإدارة | `components/navigation/sidebar.blade.php`, `.ai/rules/components-navigation.md` | 0.5 يوم |
| **T40** Status colours | إضافة `tone()` موحّد على الـ enums (`HasTranslatedLabel` → `HasTone`) واستبدال الـ 74 ملف تدريجياً بـ `x-ui.enum-badge`؛ ألوان alerts من `config/notifications.php` فقط | `components/ui/enum-badge.blade.php`, `app/Enums/**`, `resources/css/app.css` | 2 يوم |
| **T41** Project page simplification | تحويل الـ panels فوق الـ tabs إلى accordion مغلق افتراضياً عدا الهيدر؛ حذف التكرار: التواريخ مرة واحدة، beneficiaries مرة واحدة، Funding sources تُدمج في tab Proposals | `pages/projects/⚡show.blade.php`, `_show-*-tab.blade.php` | 1.5 يوم |

### المرحلة B: الـ Proposal lifecycle (يوم 4–9) — Team A، تحتاج D-55…D-60

| المهمة | الوصف | حجم |
|---|---|---|
| **T42** City/Center في Proposal form | حقل **City** multi-select إجباري (من `hchr_cities`)، **Center** multi-select اختياري مفلتر بالمدن؛ الـ project picker يُفلتر بالمراكز المختارة (يحل مشكلة «لا مراكز بالـ dropdown»)؛ **Notes تنتقل لآخر الفورم**؛ جدول `proposal_city` + `proposal_center` | 2 يوم |
| **T43** Donor pending | `donors.status` (`pending`/`approved`)، إضافة inline من الفورم (modal) ← pending، إشعار للمعتمد، badge على الـ proposal، gate على `concept note → concept note review` | 1.5 يوم |
| **T44** Concept note narrative | `MediaCollection::ConceptNote` + حقل نص `concept_note_text` بديل عن الملف؛ عرض Title · Donor · created_at في صفحة المقترح؛ الانتقال `concept note submitted → proposal draft` يسجّل «الممول وافق» (موجود، يحتاج فقط التسمية) | 1 يوم |
| **T45** دمج On Hold | وفق D-59: إزالة الانتقالات إلى `on hold` من `ProposalTransitions`، إضافة `hold_reason` على `submitted`، migration للصفوف الحالية، `OnHold` تصبح `@deprecated`، تحديث `ProposalStatusWriteGuardTest` والـ pickers (`live()`) | 1.5 يوم |
| **T46** Negotiation log | في مرحلة `submitted`: جدول `proposal_negotiations` (date, requested_by donor/us, change, decision) يظهر قبل Approve/Reject؛ warning إذا Approve بلا توثيق | 1 يوم |
| **T47** Extension date | `proposals.extension_date` + `extension_note`؛ action `ExtendProposal` (writer جديد في §4) يحتاج نفس evidence الاعتماد؛ `ApproveProjectFromProposal` يعرض التمديد كـ extension لا كـ end_date صامت؛ يبث `ProjectPeriodChanged` (موجود) | 1.5 يوم |
| **T48** Auto internal number | وفق D-57: `NumberSequence` table + توليد تحت lock في `SaveProposal`؛ الحقل read-only في الفورم؛ backfill للـ `TEMP-` | 1 يوم |
| **T49** Per-stage tasks | `tasks.proposal_stage` (enum `ProposalStage`: concept_note / proposal / review / submission / implementation / closure)؛ tab Plan يجمّع حسب المرحلة؛ قوالب افتراضية للمراجعة (Review tasks من الأقسام → Donor submission) | 1.5 يوم |

### المرحلة C: Risks داخل الـ Logframe (يوم 8–11) — Team B بعد T45

| المهمة | الوصف | حجم |
|---|---|---|
| **T50** Risks & Assumptions على العقد | `results.assumptions` text + `risks.result_id` (nullable FK)؛ عند إنشاء عقدة من المكتبة تُنسَخ risks المكتبة (`indicator_library` → `library_risks`)؛ UI داخل `components/ui/logframe-node.blade.php` | 2 يوم |
| **T51** إزالة Risks المستقلة | حذف إدخال Risks من sidebar وtab المشروع، الإبقاء على `/risks` كـ read-only index مفلتر؛ migration للـ risks القديمة وفق D-63 | 1 يوم |

### المرحلة D: Workspace والديسك (يوم 10–14)

| المهمة | الوصف | حجم |
|---|---|---|
| **T52** Dashboard panels per role | جدول `dashboard_panels` (user_id, panel_key, position)؛ `config/dashboard.php` يحدد الـ panels والصلاحية لكل واحد؛ زر **Add panel** يظهر فقط إذا بقي panel مسموح؛ الإخفاء التلقائي لما لا صلاحية له | 2 يوم |
| **T53** To-do & Notes cards | نسخة من HR: `user_todos` + `user_notes` (شخصية، لا activity log)؛ panels في T52 | 1 يوم |
| **T54** Tasks scoping | استبدال `myTasksOnly` بثلاثة filters: **Assigned to me** · **Assigned by me** · **My department** (الأخير فقط إذا المستخدم مدير القسم عبر `SupervisionMap`/HR) ؛ تعديل `TaskVisibilityScope` | 1.5 يوم |
| **T55** Report task | وفق D-62: listener على `ProjectActivated`/توليد الشهر ينشئ task نوع `report` لصاحب التقرير، يُغلق تلقائياً عند submit؛ يظهر في «My work» | 1.5 يوم |
| **T56** Indicator من داخل المشروع | زر «Add indicator» في `_show-targets-tab` يفتح نفس modal الـ library (`AttachLibraryIndicatorToResult`) مع `project_id` محدد | 1 يوم |
| **T57** Command palette | تنفيذ `docs/redesign-plan/05-command-palette.md` مع وجهة Programs = `programs.show` (المشكلة المذكورة بالاجتماع ستكون في النسخة المنفَّذة محلياً عند Rita؛ يُتحقق منها) | 2 يوم |
| **T58** Project form | إزالة اختيار Center من فورم المشروع؛ المركز يأتي من T42 (proposal) أو من HR؛ زر **Add center** يفتح HR (D-56) | 0.5 يوم |
| **T59** Donor reporting aggregation | تعريف الحقول التجميعية للتقارير النصف سنوية (Targets فقط) على `DonorReportBuilder` (T19) | 1 يوم |

### المرحلة E: مؤجَّل (بعد تأكيد HR)

| المهمة | الوصف |
|---|---|
| **T60** الموظف البديل | عند إجازة في HR، task بـ deadline داخل الإجازة تُعاد إلى `substitute_employee_id`؛ يحتاج API إجازات من HR (D-64) |

## 3. الاعتماديات والترتيب

```
A (T35–T41) ──────────────────────────────┐
D-55..D-60 ─▶ T42 ─▶ T58                  │
              T43, T44, T48 (مستقلة)      ├─▶ T27 rehearsal 2 ─▶ deploy
              T45 ─▶ T46 ─▶ T47           │
              T49 ─▶ T55                  │
T45 ─▶ T50 ─▶ T51                         │
T52 ─▶ T53 ; T54 ; T56 ; T57 ; T59 ───────┘
```

- **Team A** (Rita + Dev 2): T42–T49، T58.
- **Team B**: T35–T41، T50–T57، T59.
- الحجم الإجمالي ≈ 34 يوم عمل؛ بفريقين متوازيين ≈ 3 أسابيع (3–21 نوفمبر).

## 4. قواعد ثابتة لكل المهام
- كل مهمة: prompt بنمط `prompts/_TEMPLATE`، branch `lifecycle/TNN-…`، handoff في النهاية.
- أي writer جديد لـ `proposals.status` أو `projects.status` يُضاف إلى `contracts.md §4` وإلى guard tests.
- لا حذف enum case: `@deprecated` + migration + `live()`.
- UI: `x-ui.enum-badge` للحالات، `detail-header` للعناوين، لا أصناف لون يدوية جديدة.
- اختبار لكل تغيير (Pest)، `pint` قبل الـ PR.
