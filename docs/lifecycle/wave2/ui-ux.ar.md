# خطة تبسيط وتحسين الواجهة · من اجتماع 23/10 وردود 29/10

النسخة الإنكليزية: [ui-ux.md](ui-ux.md). الخطة الكاملة (كل البنود) على فرع `sub-main` في `docs/lifecycle/wave2/README.ar.md`.

هذه البنود لا تغيّر العقد (`contracts.md`) ولا الحالات، لذلك تُنفَّذ على `main` مباشرة بلا انتظار القرارات.

## منجز أصلاً على main (لا عمل)

| البند | الدليل |
|---|---|
| W-15 "اذهب إلى البرامج" يفتح المشاريع | صفحة البرنامج صارت صفحة مستقلة وزر الرجوع يعود إلى `programs.index` (commit 2a4b49ca). |
| W-26 صفحة المشروع أبسط | إعادة تصميم صفحة المشروع (commits 750ed0a9 → 100e46b2). |
| إزالة بطاقة المخاطر من المكتب، وإخفاء "أضف لوحة" عندما لا يوجد ما يُضاف | منجز في موجة المكتب. |

## الدفعة 1 · تُنفَّذ الآن

| # | البند | ماذا يتغيّر للمستخدم | الملفات |
|---|---|---|---|
| W-17 | زر رجوع | كل صفحة تفاصيل (Proposal، مشروع، مانح، تقرير...) فيها زر "رجوع" أعلى العنوان يعود إلى الصفحة السابقة داخل التطبيق، وإلا إلى سجلّها. | `components/ui/detail-header.blade.php`، `projects/_story-header.blade.php` |
| W-18 | صفوف لوحة الأهداف قابلة للنقر | في لوحة Targets على المكتب، اسم المؤشر يفتح المؤشر واسم المشروع يفتح المشروع. | `desk/panels/⚡targets.blade.php` |
| W-19 | بطاقة المشروع: التواريخ أولاً | لوحة المشاريع على المكتب تعرض البداية ← النهاية تحت اسم كل مشروع في كل العدسات، والعدسة الافتراضية "Dates". | `desk/panels/⚡projects.blade.php` |
| W-11 | ورقة التخصيص بلا لوحات مقفلة | قائمة "أضف" تعرض فقط اللوحات المسموحة؛ لا قائمة "مقفل". | `pages/desk/_customize-sheet.blade.php` |
| W-21 | إضافة مؤشر من صفحة المشروع | زر "مؤشر جديد" في تبويب Targets للمشروع يفتح فورم المؤشر والمشروع محدد مسبقاً. | `projects/_show-targets-tab.blade.php`، `indicators/⚡index.blade.php` |
| W-05 | قبول التقرير النهائي | زر "تسجيل قبول التقرير النهائي" (التاريخ، من قَبِل، ملف اختياري) في صفحة الـ Proposal. بدونه لا يمكن إغلاق Proposal مموّل (active ← completed). | `proposals/_show-actions.blade.php`، `_show-modals.blade.php` |
| W-16 | مدخل "التقارير" | "Donor Report Builder" يصبح "Reports"، وصفحة قائمة لكل تقارير المانحين (فلاتر: مانح، Proposal، الحالة) في الـ sidebar تحت MEAL. | `routes/web.php`، `Desk/Destinations.php`، `pages/donor-reports/⚡index.blade.php` جديد |
| W-28 | ألوان حالة موحّدة | لون واحد لكل معنى في كل الشارات: good / warn / critical / active / neutral. Critical بلون خاص لا أحمر العلامة. تصحيح التناقضات (TaskStatus، MilestoneStatus، RagStatus، ProjectStatus Approved). | `Enums/StatusTone.php` جديد، `Enums/Concerns/HasStatusTone.php`، `ui/enum-badge.blade.php`، `FluxMarkup::statusPill` |
| W-20 | فخ التمرير في المكتب | التحقق في المتصفح أن عجلة الفأرة فوق اللوحات تمرّر الصفحة. | `resources/css/desk.css`، `desk-shell.css` |
| W-22 | توثيق قديم | تصحيح `.ai/rules/desk.md` و`docs/redesign-plan/tracking-desk.md`. | docs |

## الدفعة 2 · بعد مراجعة الدفعة 1

| # | البند | لماذا لاحقاً |
|---|---|---|
| W-29 | وصفة واحدة للأقسام والحدود (`x-ui.panel` / `x-ui.section`، 43 div) | كبير ويلمس معظم الصفحات؛ يُنفَّذ بعد W-28 كي لا تتضارب الألوان. |
| W-27 | لوحتا To-do وNotes على المكتب | يحتاج جدولين جديدين؛ صغير لكن ليس "تبسيطاً". |
| W-30 | "التبويبات الرئيسية ليست في الـ sidebar" | ينتظر أن تعرض مي الحالة. |

## قواعد التنفيذ

- كل بند = تعديل + اختبار Pest يغطي السلوك وحالة الفشل المهمة.
- لا تغيير في `contracts.md`، ولا كتابة حالة خارج كتّاب §4.
- `vendor/bin/pint --dirty` قبل الـ commit؛ commit واحد لكل بند.
