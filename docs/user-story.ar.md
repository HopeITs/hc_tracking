# قصة المستخدم: رحلة كل دور في التطبيق

> مختصر مبني على خطة الـ Lifecycle (أكتوبر) وخطة Wave 2، وعلى ما هو موجود اليوم على `main`.
> العلامة ⏳ تعني أن الميزة مخطط لها ولم تكتمل بعد.

---

## البداية: نفس الخطوة للجميع

1. **تسجيل الدخول** بحساب الموارد البشرية (HR). الدور والصلاحيات تأتي من الحساب.
2. **الـ Desk** هي أول شاشة تظهر. فيها لوحات (panels) يحددها دورك: Tasks و Projects و Targets و Reporting duties و To-do. اللوحة التي لا تملك صلاحيتها لا تظهر.
3. **الـ Sidebar** فيها كل الصفحات المسموحة لك، والإشعارات توصلك بالمهام الجديدة.

---

## القصة باختصار (8 خطوات)

```
Project (Draft) → Concept note → Proposal → Donor approval → Report template → Monthly reports → Agreement & transfers → Donor reports
```

1. **Project**: تبدأ بمدينة و Program، مع مركز أو مركز مستضيف (host center). يكون Draft بلا تواريخ.
2. **Concept note**: مذكرة قصيرة لـ Donor واحد.
3. **Proposal**: هنا التخطيط كله: Logframe و Indicators و Targets و Milestones والميزانية.
4. **Donor approval**: تثبيت التواريخ الحقيقية. تتحول الـ Projects إلى `approved`.
5. **Report template**: يحددها MEAL، وتأكيدها يجعل الـ Project `active`.
6. **Monthly reports**: يرسلها مدير المركز ويعتمدها MEAL. الأرقام المعتمدة فقط هي التي تُحسب.
7. **Agreement & transfers**: اتفاقية واحدة لكل Proposal معتمد، ودفعة متوقعة لكل قسط.
8. **Donor reports**: تُجمَّع عند الطلب من الـ Monthly reports المعتمدة.

---

## حسب الدور

### 1) Public Relations (موظف جمع التمويل)
**يبدأ من:** Proposals → «New proposal».

- يكتب الاسم ويختار الـ **Donor**. إذا لم يكن موجوداً يضيفه من النموذج نفسه، فيصبح «طلب Donor معلّق» ولا يُحفظ الـ Proposal حتى يوافق عليه الـ General Manager.
- يضيف **صفوف المواقع (Location rows)**: المدينة إلزامية، أما الـ Program والمسؤول والمراكز فاختيارية حتى الاعتماد. ويعلّم `is_new` إذا كان البرنامج أو المركز غير موجود بعد.
- يكتب نص الـ **Concept note** أو يرفع ملفها، ثم التواريخ المخططة، والملاحظات في آخر النموذج.
- ينقل الحالة: `concept note` → `review` → `submitted`، ثم `proposal draft` → `review` → `submitted`.
- بعد الإرسال للـ Donor يضيف **Negotiation notes** (نص وملف) عند الحاجة.

### 2) Public Relations Manager (مدير المشاريع وجمع التمويل)
**يبدأ من:** Desk → Proposals بانتظار المراجعة.

- يراجع الـ Concept note والـ Proposal ثم يعيده بسبب أو يرسله.
- يدير المراجعين (Reviewers) ويصحّح الحالات النهائية (Correct status) عند الخطأ.
- يسجّل الـ **Funding agreement** والأقساط، وتتولد الـ Transfers المتوقعة تلقائياً.
- يقبل الـ **Final report** (التاريخ، من قبِله، ملف) لإغلاق الـ Proposal على `completed`.
- يسجّل **Extension** للـ Proposal النشط: تاريخ نهاية جديد وسبب وملف تعديل.
- يضيف المركز من «Add center»: المدينة، والـ Program، والمركز الفعلي أو المضيف، والمسؤول، والقطاعات.

### 3) General Manager (المدير العام)
**يبدأ من:** Desk → طلبات الـ Donors المعلّقة والملخصات.

- يوافق على الـ Donors الجدد في صفحة Donors.
- يرى كل شيء، ويصحّح الحالات النهائية، ويعتمد الـ Proposal (`approved`).
- عند الاعتماد يُكمل النظام صفوف المواقع وينشئ الـ Projects الناقصة تلقائياً (Program + مركز + مسؤول).
- يضيف المركز من «Add center» مثل الـ Public Relations Manager. لا يملك هذا غيرهما (قد يُضاف Logistics لاحقاً مع الـ HUB).

### 4) Financial (المالية)
**يبدأ من:** Proposals في مرحلة `proposal draft`.

- يُدخل الميزانية والعملة (`requested_budget`)، وهي شرط للانتقال إلى المراجعة.
- يتابع الـ Funding agreements والـ Transfers: المتوقع مقابل الفعلي.
- ينفّذ مهام القسم (Finance tasks) المرتبطة بالـ Proposal.

### 5) MEAL (المتابعة والتقييم)
**يبدأ من:** Indicator library و Risk library.

- يدير مكتبة المؤشرات ومكتبة المخاطر. المخاطر تُختار من المكتبة فقط داخل الـ Logframe.
- يبني الـ **Logframe** للـ Proposal: Impact → Outcome → Output → Activity، ويحدد **Targets** لكل مركز.
- يؤكد **Report template** لكل Project، فيصبح الـ Project `active` وتتولد الـ Monthly reports.
- **يعتمد (Validate)** الـ Monthly reports، ويقرر طلبات إعادة الفتح (MEAL Manager).
- يجمّع **Donor reports** (شهري أو نصف سنوي) من الأرقام المعتمدة.

### 6) Managers (مدير مركز / برنامج)
**يبدأ من:** Desk → Monthly Reports و Tasks.

- لا يضيف مراكز: يطلبها من الـ Public Relations Manager أو الـ General Manager.
- يقرّ (acknowledge) بملكية الـ Project بعد اعتماد الـ Donor، وهذا شرط ليصبح `approved`.
- يعبّئ ويرسل الـ **Monthly report** لكل شهر (الأرقام والسرد والأدلة).
- يطلب إعادة فتح تقرير معتمد، ويرى القرار في صفحة Monthly Reports وفي الإشعارات.
- يرى مهام قسمه إذا كان مدير قسم (Grade 5).

### 7) Media Team (الإعلام)
**يبدأ من:** Desk → Tasks.

- يرى مهام القسم المرتبطة بالـ Proposal والـ Milestones، بدون أي مبالغ مالية.
- ينفّذ المهمة ويرفع الملفات ويغلقها.

### 8) Team Member (عضو فريق)
**يبدأ من:** Desk → Tasks + To-do.

- يرى المهام المسندة إليه، بما فيها مهام التقارير (Report tasks)، وينفذها.
- يستخدم To-do الشخصية لتنظيم عمله.

---

## ⏳ قادم (مخطط ولم يكتمل)

| الميزة | الأثر على المستخدم |
|---|---|
| W-06 (الواجهة) | أزرار «Put on hold / Take off hold» على الـ Proposal المرسل |
| W-03 | رقم داخلي تلقائي للـ Proposal (`HC-YYYY-NNN`) |
| W-12 | مهام تُنشأ تلقائياً لكل مرحلة (Media وFinance وMEAL) |
| W-34a (الجزء 3) | طلبات البرامج الجديدة لموظف ارتباط الـ HR |
| W-32 | نوع Proposal للرواتب (Payroll) |
| W-33 / W-35 | تنبيه التمديد عند بقاء أموال، وتنبيه عند تقاعد المسؤول |
