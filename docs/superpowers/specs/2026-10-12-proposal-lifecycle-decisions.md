# سجل القرارات: تصحيح مسار المقترح والمشروع (Task 01)

> **التاريخ:** 2026-10-12 (اليوم 1) · **يُغلق في:** CP0، نهاية اليوم 2 (الثلاثاء 2026-10-13)
> **المرجع:** `docs/superpowers/plans/2026-10-08-proposal-lifecycle-correction.ar.md` حتى **المراجعة 9**. عند أي تعارض بين هذا السجل والخطة، فالخطة هي المرجع، ويُرفع التعارض إلى Dev 1.
> **المشاركون (بالدور):** الإدارة العامة (General Manager / الرئيس التنفيذي)، مدير المشاريع والتمويل (Public Relations Manager)، مدير MEAL، مدير المالية (Financial)، Dev 1، Dev 2، Dev 3 (تدوين)، قادة الفريق B (الجزء الثاني).
> **الحالات:** `مقرر` · `مقرر (الافتراضي)` أي اعتُمد الافتراضي الموصى به في الورشة · `افتراضي مطبّق (قابل للمراجعة)` · `مؤجَّل (خارج نافذة أكتوبر)` · `مستبدل` · `مفتوح`.

**ملاحظة:** برومبت Task 01 يطلب 34 صفًا (D-01…D-34). الخطة بعد المراجعات 3–9 تحوي D-01…D-51 مع قرارات فرعية، ولا يوجد D-52. لذلك يغطي هذا السجل كل القرارات الواردة في القسم 9 من الخطة.

---

## 1. الملخص

| الحالة | العدد | القرارات |
|---|---|---|
| مقرر | 31 | D-02، D-04، D-06، D-09، D-10، D-12، D-20، D-23، D-25، D-26، D-27، D-30، D-31، D-34، D-35، D-38…D-51، D-53، D-54. القرارات الفرعية (D-02c، D-10b، D-25b، D-27a، D-50a، D-50b، D-51a…) مدمجة في صفوف قراراتها الأم |
| مقرر (الافتراضي) | 19 | D-01، D-03، D-05، D-07، D-08، D-11، D-13، D-14، D-15، D-16، D-19، D-21، D-22، D-24، D-28، D-29، D-32، D-33، D-37 |
| مؤجَّل (خارج نافذة أكتوبر) | 2 | D-17، D-18 |
| مستبدل | 1 | D-36 (حلّت محله D-38) |
| مفتوح | 0 | — (لكن انظر «تعارضات تحتاج حسمًا في CP0» في القسم 9) |

**يجب أن يؤكد Dev 1 مع الإدارة في CP0** أن القرارات الموسومة «مقرر (الافتراضي)» اعتُمدت فعلًا في الورشة. مصدرها نص الخطة (عمود «الافتراضي الموصى به»: «الافتراضي (ورشة 2026-10-12)»)، وليس محضرًا موقّعًا. إن لم يُؤكَّد أحدها، يتحول إلى `افتراضي مطبّق (قابل للمراجعة)` ويُعاد عرضه في CP1.

---

## 2. جدول أعمال الورشة

**الجزء الأول (Dev 1 وDev 2 مع أصحاب القرار):**
1. حقائق المجال للتوقيع، قبل أي قرار (القسم 1.0 من الخطة، الحقائق 1–20)، في القسم 3 أدناه.
2. القصة المصححة: دورة الحياة (1.1) وجانب MEAL (1.2).
3. مخطط حالات المقترح وحالات المشروع (1.3).
4. جدول الفجوات (القسم 2 من الخطة).
5. القرارات بندًا بندًا (القسم 4 أدناه)، بترتيب «يُحسم قبل».
6. مسودة مصفوفة الصلاحيات ومن يرى المال (القسم 5).
7. المدخلات الخارجية ومواعيدها (القسم 6).

**الجزء الثاني (بحضور قادة الفريق B):**
1. عرض القسمين 0 و1 من الخطة.
2. العقود بين الفريقين:
   - الجداول التي لا يغيّرها الفريق B: `proposals`، `projects`، `donor_proposal`، `proposal_status_notes`، وآلة الحالات.
   - الأحداث بعد الالتزام (`afterCommit`): `ProposalStatusChanged`، `ProjectActivated`، `ProjectPeriodChanged`، `FundingAgreementRecorded`. الأسماء والحمولة تُجمَّد في CP1، والأصناف يكتبها Dev 1 في اليوم 6 (CP2).
3. نقاط التكامل CP0…CP6.
4. الإطارات الأولية النصية (القسم 8).

---

## 3. حقائق المجال للتوقيع (القسم 1.0 من الخطة)

| # | الحقيقة (مختصرة) | المهام المتأثرة | التوقيع / الاعتراض |
|---|---|---|---|
| 1 | المشروع داخل مركز HR (`projects.center_id`). المركز مبنى قد يضم عدة مشاريع، ومشروع مفتوح واحد لكل **مركز وبرنامج**. المنحة الجديدة للمشروع نفسه = مقترح جديد عليه (D-30) | 03، 05، 10، 30 | ☐ |
| 2 | التمويل متعدد-متعدد عبر المقترحات: المقترح = مانح واحد + مشروع واحد أو أكثر | 03، 06 | ☐ |
| 3 | التمويل الرسمي = اعتماد المانح (`submitted` ← `approved`). المشروع يصبح `approved` ثم `active` بعد تأكيد MEAL لنموذج التقرير (D-28، D-39) | 05، 08، 33 | ☐ |
| 4 | المقترح حاوية التخطيط منذ إنشائه: الإطار المنطقي والمؤشرات والمراحل ومهام الإعلام والمالية، وتُزاح التواريخ عند الاعتماد | 06، 12، 13، 31 | ☐ |
| 5 | التقرير الشهري كل شهر بغض النظر عن موعد المانح | 17 | ☐ |
| 6 | تقارير المانح تجميعية وديناميكية حسب الفترة | 19 | ☐ |
| 7 | التوليد الآلي: التقارير الشهرية عند التفعيل، وسجل التحويل عند تسجيل الاتفاقية | 09، 17 | ☐ |
| 8 | كل حركة تصل إلى MEAL والإدارة العامة | 21 | ☐ |
| 9 | HR قراءة فقط، ولا تُنسخ بياناته | 30 | ☐ |
| 10 | الاستراتيجية بثلاثة محاور، والبرامج مربوطة بها (D-35) | 32 | ☐ |
| 11 | إطار البرنامج المعياري (مثل Micro بمساريه) نقطة بداية المقترح، مع مستوى **Cross-Cutting** | 12، 32 | ☐ |
| 12 | طبيعة المؤشرات: نسب ببسط ومقام، وأعداد، ومتوسطات، ولكل منها تواتر ومسؤول جمع | 11، 17 | ☐ |
| 13 | نموذج التقرير الشهري بوابة التفعيل، وبنوده حصرًا من **Indicator Library** | 33 | ☐ |
| 14 | المستهدف يوضع عند تأكيد النموذج. المؤشر المكرر بمستهدفات مختلفة = بند واحد (D-40، D-46) | 13، 33 | ☐ |
| 15 | لكل بند جدول جمع خاص، والافتراضي من تواتر المكتبة (D-38) | 33، 17 | ☐ |
| 16 | مؤشرات مقترح جديد لمشروع نشط تُعبّأ خلال مدته فقط (D-41) | 17، 33 | ☐ |
| 17 | التقرير ثلاثة أقسام: أسئلة سردية، وقيمة لكل بند، وملفات داعمة مصنفة (D-42، D-43) | 17، 33 | ☐ |
| 18 | نمط التمويل المشترك صفة للبرنامج: `shared` أو `split` (D-46) | 13، 17، 18، 19 | ☐ |
| 19 | بعض البنود يُدخلها MEAL حصرًا (`entered_by`، D-48) | 17، 33 | ☐ |
| 20 | رزنامة الأنشطة في التقرير الشهري، وتأكيد MEAL يحدّث حالة النشاط (D-49) | 12، 17، 31 | ☐ |

**الموقّعون:** الإدارة العامة ☐ · مدير المشاريع والتمويل ☐ · مدير MEAL ☐ · المالية ☐

---

## 4. جدول القرارات

**مفتاح الأعمدة:**
- **صاحب القرار:** GM = الإدارة العامة (الرئيس التنفيذي)، PFM = مدير المشاريع والتمويل، MEALM = مدير MEAL، FIN = المالية.
- **ق.م** = قابل للمراجعة.
- **التاريخ** 2026-10-12 لكل القرارات إلا إن ذُكر غيره.

### 4.1 قرارات تُحسم قبل CP0 والمسار الحرج (Task 03، 04، 05، 30)

| # | السؤال | القرار | الأثر | المهام | قاعدة `.ai/rules` | الحالة | صاحب القرار | ق.م | يُحسم قبل |
|---|---|---|---|---|---|---|---|---|---|
| D-02 | الإبقاء على حالة المقترح `final reports`؟ | تُلغى الحالة. التقرير الختامي معلَم يُضاف يدويًا من قالب (D-02a). يُمنع `completed` قبل اكتمال المعلَم ورفع ملف التقرير الختامي وتسجيل PR لموافقة الممول (D-02b، D-02c) | `ProposalStatus::FinalReports` يُزال من المسار (محليًا: 0 مقترحات فيه) | 04، 28 | `proposals.md` | مقرر | GM + PFM | لا | Task 04 |
| D-03 | تخطي المذكرة المفاهيمية لمانح لا يطلبها؟ | التخطي مسموح بسبب مكتوب، مع سبب جاهز «المقترح موجود مسبقًا» وحقل نص اختياري | نقطة البداية `proposal draft` تتطلب سببًا في أول `proposal_status_notes` | 04، 06 | `proposals.md` | مقرر (الافتراضي) | PFM | نعم | Task 04 |
| D-04 | الاعتماد يفعّل المشروع فورًا؟ | البدء = تاريخ بدء المقترح، وينزاح إلى تاريخ الاعتماد إن تأخر، أو لتاريخ لاحق بسبب (D-04a). المشروع `active` عند تأكيد MEAL للنموذج، والتقارير من شهر البدء (D-04b) | العدسات والتنبيهات | 08، 21، 33 | — | مقرر | GM + PFM | لا | Task 04 |
| D-05 | مشروع بعدة مقترحات معتمدة: تواريخه؟ | البداية = أقدم بداية، والنهاية = أبعد نهاية، ولا تُقصَّر تواريخ مشروع نشط أبدًا. جانت لكل مقترح وجانت كلي. في الفجوة يبقى المشروع `active` والتقارير الشهرية مستمرة (بلا أسطر مانحين) | تواريخ المشروع والجانت | 08، 17، 31 | — | مقرر (الافتراضي) | GM + PFM | نعم | Task 04 |
| D-20 | من يصحح الحالات النهائية (`rejected`، `completed`)؟ | GM (الرئيس التنفيذي) وPublic Relations Manager (مدير المشاريع والتمويل)، بسبب إلزامي وعلامة «تصحيح». **Super Admin للمطوّرين فقط**، ولا يُذكر في القوائم التجارية (D-20a) | الصلاحية `proposals.correct-status` | 02، 04 | `policies.md` | مقرر | GM | لا | Task 04 |
| D-24 | مقترح لمشاريع في برامج مختلفة؟ والممول المشارك؟ | مقترح متعدد البرامج مسموح (`program_id` للإطار = NULL مع شارة «متعدد البرامج»). الممول المشارك = مقترح منفصل | برنامج الإطار المنطقي | 03، 12، 13 | — | مقرر (الافتراضي) | PFM | نعم | Task 03 |
| D-28 | متى يصبح المشروع «ممولًا رسميًا»؟ | عند اعتماد المانح (`approved`). المقترح `submitted` يظهر «بانتظار التمويل» | التفعيل والتوليد الآلي | 05، 08، 17 | — | مقرر (الافتراضي) | GM | نعم | Task 03، Task 30 |
| D-30 | المشروع = المركز؟ | المركز مبنى قد يضم عدة مشاريع. مشروع مفتوح واحد لكل **مركز وبرنامج** (D-30a). التقرير الشهري لكل مشروع يعدّه مالكه (D-30b). المراكز الناقصة يضيفها HR قبل البروفة (D-30c) | ربط البيانات وكشف التكرار | 03، 05، 10، 17، 30 | `concerns-factories.md` (HR قراءة فقط، دون تغيير) | مقرر | GM | لا | Task 03، Task 30 |
| D-06 | مشروع رُفض مقترحه الوحيد؟ | المقترح وحده `rejected` في الأرشيف. المشروع مسودة بشارة «لا يوجد مقترح فعّال»، والأرشفة يدوية | المحفظة المفتوحة | 05 | — | مقرر | PFM | لا | Task 05 |
| D-27 | استعادة مشروع من الأرشيف إلى أي حالة؟ | `closed` إن كانت له تواريخ، وإلا `draft`، بشرط ألا يكون للمركز والبرنامج مشروع مفتوح آخر. لكل مشروع مقترح دائمًا. **D-27a:** مانح داخلي واحد «تمويل ذاتي — Hope»، والاتفاقية له اختيارية، ولا يظهر في تقارير المانحين | `RestoreProject` اليوم يعيد كل مشروع إلى `draft` | 03، 05، 27 | لا قاعدة اليوم، تُضاف (القسم 7) | مقرر | GM | لا | Task 05 |

### 4.2 قرارات الاعتماد والمراجعة والاتفاقية (Task 07، 08، 09)

| # | السؤال | القرار | الأثر | المهام | قاعدة `.ai/rules` | الحالة | صاحب القرار | ق.م | يُحسم قبل |
|---|---|---|---|---|---|---|---|---|---|
| D-01 | اعتماد داخلي قبل الإرسال؟ ومن يملك **Approve Proposal**؟ | المراجعة الداخلية كافية بلا بوابة مجلس. الرئيس التنفيذي ومدير المشاريع والتمويل يراجعان قبل الإرسال، فتُضاف خانة **CEO** إلى **Default Proposal Reviewers** | خانة مراجِع جديدة. **من يضغط Approve لم يُسمَّ صراحة**، انظر التعارض T-07 | 04، 07، 08 | — | مقرر (الافتراضي) | GM | نعم | Task 04 |
| D-14 | المعنيون الافتراضيون، ومن يضيف معنيين؟ | خانات لا أسماء: MEAL Manager، Finance Manager، Projects & Fundraising Manager، CEO (D-01)، ومالك كل مشروع مرتبط ومدير برنامجه. الإضافة والإزالة لحاملي `proposals.manage-reviewers` | الإشعارات والصلاحية | 02، 07 | — | مقرر (الافتراضي) | GM | نعم | Task 07 |
| D-15 | هل يجب اطّلاع كل المعنيين قبل الإرسال للمانح؟ | لا. الانتقال إلى `concept note submitted` أو `submitted` يعرض تحذيرًا بأسماء من لم يطّلع، ولا يمنع | قد يعطّل الإرسال | 07 | — | مقرر (الافتراضي) | PFM | نعم | Task 07 |
| D-26 | هل يتطلب `approved → active` اتفاقية؟ | لا. الاعتماد يطلب دليل موافقة المانح: ملف بريد **أو** ملاحظة توضيحية. التفعيل بلا اتفاقية يُظهر تحذيرًا حتى تُسجَّل | `ManagesAgreementForm` اليوم يحذّر فقط عند اتفاقية لمقترح غير معتمد، وTask 09 يجعله منعًا | 04، 08، 09 | `funding.md` | مقرر | GM + PFM | لا | Task 04 |
| D-31 | سجل التحويل المولَّد: دفعة واحدة أم جدول دفعات؟ وهل العمولة = `fees`؟ | جدول دفعات يُدخله **PR والمالية** (D-31b)، والمجموع = الميزانية (تحذير). صف تحويل لكل دفعة. أربعة تواريخ: مجدول، وإرسال فعلي، ووصول، واستلام. العمولة = `fees` | دقة التتبع المالي، وPR Manager يحصل على `funding-agreements.view/create/update` | 02، 09 | `pages.md` (PR Manager يصبح حامل مال) | مقرر | FIN + PFM | لا | Task 09 |

### 4.3 قرارات MEAL والتقرير الشهري (الفريق B: Task 11، 12، 13، 15، 17، 33)

| # | السؤال | القرار | الأثر | المهام | قاعدة `.ai/rules` | الحالة | صاحب القرار | ق.م | يُحسم قبل |
|---|---|---|---|---|---|---|---|---|---|
| D-07 | متى يُحتسب التقرير الشهري في الإنجاز؟ | عند **Confirm** من MEAL. بعد الإرسال يُقفل التقرير على المدير، وMEAL إما يؤكد أو يعيد (**Return**) بملاحظة إلزامية | تعريف الإنجاز | 17، 18، 19 | `resultsindicatorstargetsreports.md` (يبقى «المعتمد فقط»: التأكيد = اعتماد) | مقرر (الافتراضي) | MEALM | نعم | Task 17 |
| D-08 | التسلسل الشهري: منع الفتح أم منع الإرسال؟ | منع **الإرسال** فقط. لا يُرسَل الشهر M قبل إرسال M-1 (داخل فترة المشروع وله بنود). الفتح مسموح لأن الأشهر مولّدة مسبقًا | مرونة مدير المركز | 17 | — | مقرر (الافتراضي) | MEALM | نعم | Task 17 |
| D-09 | من يوافق على إعادة فتح تقرير متحقق منه؟ | شاغل خانة **MEAL Manager**. عند شغورها أو عدم فعالية شاغلها في HR: **Public Relations Manager** (D-09a). مُعدّ التقرير لا يقرر في طلبه. إن لم يوجد حامل فعّال يُنبَّه GM. D-09b مغلق | الصلاحية `reports.reopen` | 02، 17 | — | مقرر | MEALM + GM | لا | Task 17 |
| D-29 | التوليد: كل أشهر المدة أم شهرًا بشهر؟ والمشاريع النشطة القائمة؟ | كل الأشهر عند التفعيل (`ProjectActivated`)، و`ProjectPeriodChanged` يضيف الأشهر الجديدة فقط. المشاريع النشطة القائمة: من شهر النشر، لا رجعيًا (`monthly-reports:backfill`) | حجم البيانات والتنبيهات | 17، 27 | — | مقرر (الافتراضي) | MEALM | نعم | Task 17 |
| D-10 | رمز المؤشر في المكتبة | الرمز `رمز_البرنامج.المستوى.تسلسل` (مثل `002.OP1.2.3`) يولّده الخادم، فريد على مستوى المؤسسة (D-10a). المستويات `G`، `OC`، `OP`، `AC`، `CC`، والبادئة `HC` للمؤشرات المؤسسية (D-10b). رموز البرامج المكررة في HR تُصحَّح قبل التعبئة | استيراد الجداول القائمة | 11، 13، 27، 32 | — | مقرر | MEALM | لا | Task 11 |
| D-11 | تعبئة المكتبة ونسخ المستهدفات عند التجديد | المكتبة تُعبَّأ من المؤشرات القائمة. المستهدفات لا تُنسخ كقيم ملتزم بها: قيم السنة السابقة ومستهدف الإطار المعياري تُعرض اقتراحًا يؤكده MEAL | جودة المكتبة | 10، 11، 13 | — | مقرر (الافتراضي) | MEALM | نعم | Task 11 |
| D-12 | مسؤول MEAL لكل مدينة أم لكل مشروع؟ | لكل محافظة مسؤول، ولكل مشروع مسؤول خاص اختياري (قد يكون من محافظة أخرى ويتغلب على المحافظة)، والاحتياطي مدير MEAL. واحد لكل نطاق (D-12a) | توجيه التقارير | 15، 17، 33 | — | مقرر | MEALM | لا | Task 15 |
| D-23 | من يُدخل المستهدفات؟ | **MEAL فقط**. تُسحب `targets.create/update` من General Manager وManagers | الصلاحيات | 02، 13، 27 | `policies.md` | مقرر | GM + MEALM | لا | Task 02 |
| D-25 | التجميع عبر الأشهر والمراكز والمانحين | مدير المشروع يُدخل **العدد فقط** حتى لمؤشر النسبة، والنسبة = العدد ÷ `project_proposal.target_beneficiaries`. مؤشرات النسبة التي يقيسها MEAL ببسط ومقام مُدخلين (D-25a). التجميع Σبسط ÷ Σمقام، ولا متوسط للنسب أبدًا. لمستهدف واحد: آخر قيمة معتمدة. **D-25b:** العدد الفريد للجدد فقط كل شهر، والإجمالي مجموع الأشهر | تعريف الإنجاز | 03، 06، 11، 13، 17، 18، 19، 33 | `resultsindicatorstargetsreports.md` | مقرر | MEALM | لا | Task 11، Task 17 |
| D-36 | من يُدخل قيمة كل مؤشر ومتى؟ | **استُبدل بـ D-38** في المراجعة 4، ولا أثر للإجابة على الشيفرة | — | — | — | مستبدل | — | — | — |
| D-38 | ما الذي يحدد أسطر التقرير الشهري؟ | اختيار MEAL في نموذج التقرير، حصرًا من المكتبة. الأشهر من تواتر المكتبة، ويمكن لـ MEAL تجاوزها لكل بند | — | 17، 18، 33 | — | مقرر | MEALM | لا | Task 33 |
| D-39 | متى يصبح المشروع `active`؟ | بعد الاعتماد وتأكيد المالك يصبح `approved`، ثم `active` عند تأكيد MEAL لنموذج التقرير، وعندها التوليد الآلي | — | 05، 08، 17، 33 | — | مقرر | GM + MEALM | لا | Task 05 |
| D-40 | المؤشر نفسه لمانحين بمستهدفين مختلفين | بند واحد، مستهدفه الأعلى أو رقم يغطي الاثنين، والقيمة كاملة لكل مانح (مرايا `reports`). **عدّلتها D-46:** للبرامج `shared` فقط | — | 13، 17، 18، 19، 33 | — | مقرر | MEALM | لا | Task 33 |
| D-41 | من أي شهر تظهر مؤشرات مقترح جديد لمشروع نشط؟ | خلال مدة المقترح الجديد فقط، ولا يُعاد فتح أي تقرير مُرسَل | — | 17، 33 | — | مقرر | MEALM | لا | Task 33 |
| D-42 | الملفات الداعمة | لائحة أنواع ثابتة يديرها MEAL (`evidence_types`)، والأنواع الإلزامية لكل بند، والإرسال يُرفض بدونها | — | 17، 33 | — | مقرر | MEALM | لا | Task 33 |
| D-43 | شكل الأسئلة السردية | التقرير شهري دائمًا. بنك أسئلة موحّد (شهرية، وربعية في آخر شهر من الربع) يُنسخ إلى نموذج كل مشروع، ويعدّله MEAL ويضيف عليه لكل مشروع (D-43a) | — | 17، 19، 33 | — | مقرر | MEALM | لا | Task 33 |
| D-44 | المؤشرات المؤسسية | مستهدف سنوي لكل مركز يضعه MEAL (`plan_indicators` من الخطة السنوية للبرنامج). الخطة الآلية لعام 2026 فقط، ومن 2027 يُدخلها مدير البرنامج (D-44a) | — | 24، 33 | — | مقرر | MEALM + GM | لا | Task 33 |
| D-45 | المشاريع النشطة القائمة عند النشر | تبقى `active`، ونموذج معبّأ مسبقًا ينتظر تأكيد MEAL. أسطرها لا تظهر حتى التأكيد | — | 27، 33 | — | مقرر | MEALM | لا | Task 27 |
| D-46 | مؤشر مشترك بين مانحين: لكل ممول أم مقسَّم؟ | النمط صفة للبرنامج (`programs.donor_attribution`: `shared` أو `split`، الافتراضي `shared`). في `split` يُدخل مدير المركز حصة كل مانح شهريًا، وإنجاز المركز = مجموع الحصص. المستفيد له ممول واحد | — | 13، 17، 18، 19، 27، 33 | — | مقرر | MEALM | لا | Task 13 |
| D-48 | مؤشرات يحتسبها MEAL حصرًا | `entered_by` لكل بند (`project_team` أو `meal`)، والافتراضي من المكتبة. بند MEAL للقراءة عند المدير ولا يمنع الإرسال، ويُدخله MEAL قبل التأكيد أو يعلّمه «لم يُقس هذا الشهر» (D-48a) | — | 17، 27، 33 | — | مقرر | MEALM | لا | Task 17 |
| D-49 | متابعة رزنامة الأنشطة | أنشطة الإطار المنطقي فقط، بحالات Excel (D-51)، وتأكيد MEAL يحدّث حالة النشاط. رزنامة واحدة للمقترح مع تعديل لمركز (D-49a). الدقة الشهر والأسبوع، وتاريخ التنفيذ الفعلي في التقرير (D-49b). يضعها MEAL ومحرر المقترح قبل الاعتماد، وMEAL وحده بعده (D-49c) | — | 12، 17، 27، 31، 33 | — | مقرر | MEALM | لا | Task 12 |
| D-50 | تفصيل المؤشرات | المؤشرات عن الأشخاص (`is_disaggregated`) تُفصَّل حسب الجنس، ومنهم ذوو الإعاقة لكل جنس، في العدد والبسط والمقام. المجموع على الخادم. المستهدف إجمالي (D-50a)، وMEAL يحدد التفصيل في المكتبة (D-50b). لا فئات عمرية | — | 11، 13، 17، 18، 19، 27، 33 | — | مقرر | MEALM | لا | Task 11 |
| D-51 | حالات النشاط | Not Started، Started، Ongoing، Suspended، Cancelled، Completed، وConducted للأحداث. نوع النشاط ممتد أو حدث، يحدده MEAL والافتراضي «ممتد» (D-51a). ترحيل آلي إلى الشهر التالي بلا إعادة جدولة | — | 12، 17، 18، 31 | — | مقرر | MEALM | لا | Task 12 |
| D-37 | اعتماد `micro logic.xlsx` إطارًا معياريًا، ومعاني المستهدف الافتراضي | يُعتمد الإطار بمساريه. «80%» قيمة تُقترح كما هي. «-» مؤشر رصد بلا مستهدف (لا صف في `plan_indicators`). «**» يُحدد لكل مشروع (إلزامي قبل `submitted`). «+20%» تغيّر عن خط الأساس كنسبة مع `indicators.baseline`. الأسماء الإنجليزية الناقصة اختيارية | — | 11، 13، 32 | — | مقرر (الافتراضي) | MEALM | نعم | Task 32 |
| D-35 | ربط برامج HR بالمحاور الثلاثة | يضيف HR البرامج الناقصة. DISC = Development Impact and Support Center. المحور 1: Micro وRelief وF2F والآبار والطاقة البديلة. المحور 2: Study Zone وLEAP وEntrepreneurship وDISC. المحور 3: Children University وPSS وإدارة الحالة والتربية الوالدية (D-35a) | — | 32، 32b | — | مقرر | GM | لا | Task 32 |
| D-47 | وحدة القطاعات (Sectors) | قائمة يديرها MEAL وGM. القائمة الأولية (S-1): الصحة، الأمن الغذائي وسبل العيش، WASH، المأوى وNFIs، الحماية، التعليم. قطاع واحد على الأقل للمشاريع الجديدة (S-2). الربط بالمشروع فقط (S-3) | — | 02، 05، 27، 34 | — | مقرر | GM + MEALM | لا | Task 34 |

### 4.4 قرارات التقارير التجميعية والإشعارات والأقسام والقرارات العابرة

| # | السؤال | القرار | الأثر | المهام | قاعدة `.ai/rules` | الحالة | صاحب القرار | ق.م | يُحسم قبل |
|---|---|---|---|---|---|---|---|---|---|
| D-32 | جداول دورية ثابتة لكل اتفاقية أم تجميع عند الطلب؟ | تجميع عند الطلب. `due_on` يُدخل يدويًا لكل تقرير حسب طلب المانح، مع تنبيه قبل الموعد وعند التأخر. لا مولّد جداول دورية | يوم ونصف من الجدول | 02، 19 | — | مقرر (الافتراضي) | PFM | نعم | Task 19 |
| D-21 | تسليم تقرير المانح يتطلب ملفًا؟ وشكل الملخص الشهري؟ | الملف النهائي إلزامي عند **Mark as Submitted** لتقارير المانح. الملخص الشهري إشعار داخلي وبريد **بلا مبالغ وبلا مرفقات** (مصفوفة الأمن، القسم 5 من الخطة) | جودة الأثر وأمن البريد | 19، 21 | `alerts.md` | مقرر (الافتراضي) | PFM + GM | نعم | Task 19 |
| D-22 | أسبوع العمل الفعلي | الاثنين–الجمعة في جدول الخطة (إعداد النظام الافتراضي). إضافة أيام عمل خاصة بكل مشروع (`projects.working_days`) تتجاوز إعداد المؤسسة، عبر `WorkingCalendar::forProject` | تواريخ الجدول والتنبيهات | 05، 19، 21، 31 | `alerts.md` | مقرر (الافتراضي) | GM | نعم | Task 21 |
| D-33 | مهام الأقسام: للقسم ثم للشخص؟ ومن يراها؟ | تُسند إلى **قسم** أولًا (إلزامي)، ثم يحدد رئيس القسم أو حامل `tasks.update` الشخص (اختياري، مع تنبيه قبل الموعد بأسبوع). يراها: حامل `tasks.view` في القسم نفسه حسب منصبه الحالي في HR، أو المسند إليه، أو حامل صلاحية المقترح. المهام المالية للمالية فقط، بلا مبالغ | الرؤية والتنبيهات | 31 | `tasks.md` (`TaskVisibilityScope`) | مقرر (الافتراضي) | GM | نعم | Task 31 |
| D-34 | مراحل المقترح: مشتركة أم لكل مركز؟ | مشتركة على مستوى المقترح، ومرحلة خاصة بمركز تحمل `project_id`. تُزاح مرة واحدة بفارق البداية الفعلية عن المخططة | الجانت والتقارير | 31 | — | مقرر | PFM | لا | Task 31 |
| D-16 | ماذا يرى فريق الإعلام من المقترح؟ | اسم المقترح ووصفه ومهامه ومراحله، بلا مال وبلا `proposals.view` عامة | الخصوصية المالية | 31 | `pages.md` | مقرر (الافتراضي) | PFM | نعم | Task 31 |
| D-13 | مصدر «من يتابع من» | HR: `hchr_positions.direct_manager_id` لمنصب الموظف الحالي. جدول `supervisions` للاستثناءات والإضافات فقط | الصيانة المزدوجة | 23 | — | مقرر (الافتراضي) | GM | نعم | Task 23 |
| D-19 | نطاق التشفير ومفتاحه | كل ملف يُرفع يُشفَّر بـ AES-256-GCM بمفتاح مستقل `FILE_ENCRYPTION_KEY`، بنسخة احتياطية للمفتاح خارج الخادم، وأمر تدوير وأمر فك طارئ. الملفات القديمة تُشفَّر بأمر على دفعات | كل الملفات وخطر فقدان المفتاح | 22، 29 | — | مقرر (الافتراضي) | GM | نعم | Task 22 |
| D-17 | بنود الميزانية حسب الفئة | خطة نوفمبر | — | 25 | — | مؤجَّل (خارج نافذة أكتوبر) | FIN | — | — |
| D-18 | الخطة السنوية: مسودة واعتماد وأنشطة | بداية 2027. الخطط القائمة تبقى كما هي | — | 24 | — | مؤجَّل (خارج نافذة أكتوبر) | GM | — | — |
| D-53 | الدفعات (Cohorts) | لا حاجة لها، لا الآن ولا بعد الإطلاق | — | — | — | مقرر | MEALM | لا | — |
| D-54 | ملفا Syria Cross وChildren University في البروفة | لا يُستعملان في البروفة. البروفة ببيانات البذور، والملفان مرجع للتصميم | — | 27، 28، 32 | — | مقرر | MEALM | لا | Task 27 |

### 4.5 تسليم القرارات حسب المهمة

| المهمة | القرارات | الحالة |
|---|---|---|
| Task 02 (CP0) | D-09، D-12، D-14، D-20، D-23، D-31b، D-47، القسم 5 | كلها محسومة، مع تعارضات T-01…T-04 |
| Task 03 + Task 30 | D-24، D-28، D-30، D-27a، وفحص تكرار `donor_proposal` (فارغ محليًا) | محسومة |
| Task 04 | D-02، D-03، D-04، D-05، D-20، D-26 | محسومة |
| Task 05 | D-06، D-27، D-30، D-39 | محسومة |
| Task 07 | D-01، D-14، D-15 | محسومة (خانة CEO جديدة في `ReviewerSlot`) |
| Task 09 | D-31 | محسومة |
| Task 17 (B1) | D-07، D-08، D-09، D-29، D-25، D-48، D-49، D-50، D-51 | محسومة |
| Task 19 | D-21، D-32 | محسومة |
| Task 31 (B3) | D-16، D-33، D-34 | محسومة |
| Task 23 (B3) | D-13 | محسومة |
| Task 32 | D-35، D-37 | محسومة |

---

## 5. مسودة مصفوفة الصلاحيات (دور × وحدة × إجراء)

الأساس: `config/access-control.php` الحالي + جدول Task 02 + تعديلات المراجعات 6 و8 و9. **Super Admin** يملك كل شيء تقنيًا (`Role::booted()`)، لكنه للمطوّرين فقط (D-20a)، فلا يظهر في الأعمدة.

**الاختصارات:**
- GM = General Manager
- Mgr = Managers
- MEAL
- FIN = Financial
- PRM = Public Relations Manager
- PR = Public Relations
- Media = Media Team
- TM = Team Member
- ✔ = في `role_baselines`
- ✔ᵖ = في الكتالوج، والسياسة تضيّقه (خانة أو نطاق)
- **جديد** = صلاحية أو منح جديد

### 5.1 الصلاحيات الجديدة (تُثبَّت أسماؤها في CP0)

| الصلاحية | GM | Mgr | MEAL | FIN | PRM | PR | Media | TM | المصدر |
|---|---|---|---|---|---|---|---|---|---|
| `indicator-library.view` **جديد** | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | `$viewEverything` |
| `indicator-library.create/update/delete` **جديد** | ✔ | | ✔ | | | | | | Task 02 |
| `proposals.manage-reviewers` **جديد** | ✔ | | | ✔ | ✔ | | | | Task 02، D-14 |
| `proposals.correct-status` **جديد** | ✔ | | | | ✔ | | | | D-20 |
| `reports.reopen` **جديد** | ⚠ T-01 | | ✔ᵖ (شاغل خانة MEAL Manager) | | ✔ᵖ (عند شغور الخانة) | | | | D-09، D-09a |
| `reports.aggregate` **جديد** | ✔ | | ✔ | | ✔ | | | | Task 02، Task 19 |
| `meal-coverage.manage` **جديد** | ⚠ T-01 | | ✔ᵖ (شاغل خانة MEAL Manager) | | | | | | D-12 |
| `supervisions.manage` **جديد** | ✔ | | | | | | | | Task 02، D-13 |
| `sectors.view` **جديد** | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | ✔ | `$viewEverything` |
| `sectors.manage` **جديد** | ✔ | | ✔ | | | | | | S-4 |
| `report-templates.view` **جديد** | ✔ | ⚠ T-02 | ✔ | ⚠ | ⚠ | ⚠ | ⚠ | ⚠ | Task 33 |
| `report-templates.manage` **جديد** | ✔ | | ✔ | | | | | | D-20a |
| `report-templates.confirm` **جديد** | ✔ | | ✔ᵖ (مدير MEAL، أو مسؤول MEAL للمشروع، D-12a) | | | | | | Task 33 |
| `evidence-types.manage` **جديد** | ⚠ T-01 | | ✔ᵖ (مدير MEAL) | | | | | | D-20a |
| `narrative-questions.manage` **جديد** | ⚠ T-01 | | ✔ᵖ (مدير MEAL) | | | | | | D-20a |

### 5.2 تغييرات على صلاحيات قائمة

| الصلاحية | التغيير | القرار |
|---|---|---|
| `targets.create`، `targets.update` | تُسحب من Mgr وGM، فتبقى لـ MEAL فقط. `permissions:sync` لا يسحب، فيلزم خيار `--revoke` يُشغَّل مرة في البروفة بـ `--dry-run` أولًا. **وGM يأخذها تلقائيًا** من «كل شيء عدا»، فيجب إضافتها إلى قائمة الاستثناء (T-01) | D-23 |
| `funding-agreements.view/create/update` | تُضاف إلى PRM (لا `delete`) | D-31b |
| `reports.reopen` | يُضاف إلى PRM | D-09a |
| `proposals.approve` | غير موجودة في الكتالوج. اليوم `ProposalPolicy::approve` تستعمل `proposals.update` (FIN، PRM، GM). انظر T-07 | D-01 |

### 5.3 من يرى المال في صفحات **Proposal** الجديدة («Hide money, keep names»)

- **الميزانية المطلوبة (`requested_budget`)** وجدول الدفعات والقسم المالي في التقرير التجميعي تُستعلم وتُعرض فقط لحاملي `funding-agreements.view`:
  - بعد D-31b: Financial، وPublic Relations Manager، وGeneral Manager (وSuper Admin تقنيًا).
  - غيرهم يرى نموذجًا بلا حقل الميزانية، والخادم يتجاهل القيمة. هذا يحلّ ملاحظة README رقم 6، فـ PRM يستطيع إدخال الميزانية قبل `proposal review`.
- **المعنيون في حلقة المراجعة** (MEAL Manager، CEO، مالك المشروع، مدير البرنامج، الإضافيون) لا يحصلون على `proposals.view` ولا على المال بسبب إضافتهم. يرون تبويبات المقترح المسموحة لهم، والمال فقط إن كانوا يحملون صلاحيته أصلًا.
- **فريق الإعلام وصفحة Department Tasks:** بلا مال (D-16).
- **الإشعارات والبريد والملخص الشهري:** بلا مبالغ (D-21).
- **مُبلَّغ عنه للمراجعة:** لا طلب في هذه الورشة لمنح `proposals.correct-status` لغير GM وPRM، ولا لمنح المعنيين `proposals.view` أو المال.

**توقيع الإدارة على المصفوفة:** ______________________ · التاريخ: ________ (الموعد الأقصى 2026-10-16)

---

## 6. المدخلات الخارجية

| # | المدخل | من (الدور) | الموعد المتوقع | يغذي | الحالة |
|---|---|---|---|---|---|
| 1 | قالب التقرير الشهري: الحقول، والسرد لكل بند، والأسئلة الشهرية والربعية، وأنواع الملفات الداعمة (`evidence_types`)، وأمثلة | مدير MEAL | 2026-10-16 (اليوم 5) | Task 17، 33 (مطابقة في CP3) | ☐ |
| 2 | مصفوفة الصلاحيات الموقّعة (القسم 5) | الإدارة العامة | 2026-10-16 (اليوم 5). إن تأخرت، يحدّث Task 02 `role_baselines` ويعيد `permissions:sync` | Task 02 | ☐ |
| 3 | خانات المعنيين الافتراضيين (MEAL Manager، Finance Manager، Projects & Fundraising Manager، CEO) بالمناصب أو الأدوار لا بالأسماء | الإدارة العامة | 2026-10-13 (CP0) | Task 07 | ☐ |
| 4 | مصدر «من يتابع من»: تأكيد أن `direct_manager_id` محدَّث (محليًا: 161 من 163 منصبًا فيها مدير) | فريق HR | 2026-10-14 | Task 23 (D-13) | ☐ |
| 5 | إضافة المراكز الناقصة في HR: مدن المشاريع بلا مركز (محليًا: المدن 1 و7 و8 و12 و13 و14 و17، و9 مشاريع) | فريق HR | 2026-10-16، وقبل البروفة إلزاميًا (D-30c) | Task 30، 27 | ☐ |
| 6 | تصحيح رموز البرامج المكررة في HR (محليًا: `003` ×2، و`004` ×2، و`009` ×3) | فريق HR | 2026-10-16، وقبل تعبئة المكتبة | Task 11، 32 (D-10a) | ☐ |
| 7 | إضافة البرامج الناقصة في HR (الآبار، الطاقة البديلة، PSS، إدارة الحالة، التربية الوالدية) | فريق HR | 2026-10-16 | Task 32 (D-35) | ☐ |
| 8 | مراجعة ملفات JSON لإطار Micro | مدير MEAL | 2026-10-16 (CP2 يحتاجها 10-19) | Task 32 | ☐ |
| 9 | أطر معيارية لبرامج أخرى (Study Zone، LEAP، DISC، Children University) | مدير MEAL | بعد الإطلاق | Task 32 (لاحقًا) | ☐ |
| 10 | نمط التمويل المشترك لكل برنامج (`shared` أو `split`) | مدير MEAL | قبل بروفة Task 27 (2026-10-27) | Task 13، 27 (D-46) | ☐ |
| 11 | تشغيل استعلامات القسم 7 على نسخة الإنتاج وتسجيل النتائج | الإدارة / Dev 1 بمستخدم قراءة فقط | 2026-10-13 (قبل القيود الفريدة في Task 03) | Task 03، 05، 08، 27 | ☐ |

---

## 7. ملحق: استعلامات فحص للقراءة فقط

**قواعد التشغيل:**
- `SELECT` فقط، على نسخة من الإنتاج أو replica، بمستخدم قراءة فقط.
- تُسجَّل الأعداد والمعرّفات فقط، بلا أسماء أو مبالغ أو بيانات شخصية.
- جداول HR على الاتصال `hr` بالبادئة `hchr_`.

```sql
-- C1: مقترح بأكثر من اتفاقية (يجب أن يكون فارغًا، Task 03 قيد فريد)
SELECT proposal_id, COUNT(*) FROM donor_proposal GROUP BY proposal_id HAVING COUNT(*) > 1;

-- C2: مقترحات بلا اتفاقية (Task 03 لا يجعل proposals.donor_id NOT NULL لها)
SELECT p.id, p.internal_project_number FROM proposals p
LEFT JOIN donor_proposal dp ON dp.proposal_id = p.id WHERE dp.id IS NULL;

-- C3: توزيع الحالات
SELECT status, COUNT(*) FROM proposals GROUP BY status;
SELECT status, COUNT(*) FROM projects GROUP BY status;

-- C4: مشاريع submitted/approved (ترحيل Task 05 الدفاعي)، ومسودات بتواريخ
SELECT id, status FROM projects WHERE status IN ('submitted', 'approved');
SELECT id FROM projects WHERE status = 'draft' AND (start_date IS NOT NULL OR end_date IS NOT NULL);

-- C5: روابط المشروع بالمقترح
SELECT project_id, COUNT(*) FROM project_proposal GROUP BY project_id HAVING COUNT(*) > 1;
SELECT proposal_id, COUNT(*) FROM project_proposal GROUP BY proposal_id HAVING COUNT(*) > 1;
SELECT p.id FROM proposals p LEFT JOIN project_proposal pp ON pp.proposal_id = p.id WHERE pp.proposal_id IS NULL;

-- C6: أرقام مؤقتة
SELECT COUNT(*) FROM proposals WHERE internal_project_number LIKE 'TEMP-%';

-- C7: أحجام MEAL (الافتراض 4: 61 / 61 / 33)
SELECT (SELECT COUNT(*) FROM indicators) AS indicators,
       (SELECT COUNT(*) FROM plan_indicators) AS plan_indicators,
       (SELECT COUNT(*) FROM reports) AS reports;

-- C8 (اتصال hr): المراكز ومدنها
SELECT COUNT(*) AS centers, GROUP_CONCAT(DISTINCT city_id ORDER BY city_id) AS city_ids FROM hchr_centers;
-- C8b (قاعدة التتبع): المشاريع حسب المدينة، ثم تُطابق يدويًا مع city_ids من C8
SELECT city_id, COUNT(*) FROM projects GROUP BY city_id;

-- C9 (اتصال hr): رموز البرامج المكررة (D-10a)
SELECT code, COUNT(*), GROUP_CONCAT(id) FROM hchr_programs GROUP BY code HAVING COUNT(*) > 1;

-- C10 (اتصال hr): تغطية المدير المباشر (D-13)
SELECT COUNT(*) AS positions, SUM(direct_manager_id IS NOT NULL) AS with_manager FROM hchr_positions;
```

### 7.1 النتائج

| الفحص | محلي، 2026-10-12 (Boost `database-query`) | الإنتاج (شغّله / التاريخ / النتيجة) |
|---|---|---|
| C1 | فارغ ✔ | |
| C2 | فارغ (كل المقترحات الـ 38 لها اتفاقية) | |
| C3 مقترحات | `approved` 33، `active` 5 (المجموع 38). لا `final reports` | |
| C3 مشاريع | `active` 30، `closed` 6 (المجموع 36). لا مسودة | |
| C4 | لا `submitted`/`approved`، ولا مسودة بتواريخ | |
| C5 | 13 مشروعًا بأكثر من مقترح، و6 مقترحات بأكثر من مشروع، و**مقترح واحد بلا مشروع (id 5)** ⚠ | |
| C6 | **38 من 38 بأرقام `TEMP-`** ⚠ | |
| C7 | 61 / 61 / 33 ✔ | |
| C8 | 26 مركزًا في 6 مدن (2، 3، 4، 5، 6، 16). مشاريع في مدن بلا مركز: 1، 7، 8، 12، 13، 14، 17 (9 مشاريع) ⚠ تزيد المدينة 1 على الافتراض 4 | |
| C9 | `003` (3، 4)، `004` (5، 6)، `009` (11، 12، 14) ⚠ | |
| C10 | 163 منصبًا، منها 161 بمدير مباشر | |

**ما يجب أن تقرره الإدارة إن تكررت هذه النتائج على الإنتاج** (قرارات إدارة، لا «إصلاح» في الشيفرة):
- **C5، مقترح بلا مشروع:** يوقف Task 08 (الاعتماد يتطلب مشروعًا)، وقيد «≥ 1 مشروع» في Task 03 و06. يُربط يدويًا قبل البروفة.
- **C6، أرقام `TEMP-`:** قاعدة Task 06 «بلا `TEMP-`» ستجعل كل مقترح قائم يفشل عند التعديل. يلزم إدخال الأرقام الحقيقية قبل النشر، أو تطبيق القاعدة على الإنشاء فقط. يُحسم في Task 03/06/27.
- **C8 وC9:** طلبا HR في القسم 6 (البندان 5 و6).

---

## 8. إطارات أولية نصية

### 8.1 **Create Proposal** (Task 06)

```
┌─ Create Proposal ─────────────────────────────────────────────┐
│ Starting point:  (•) Concept note   ( ) Proposal draft        │
│   └ if Proposal draft: Skip reason [Proposal already exists ▾]│
│                        Note [__________________] (optional)   │
│ Name*                     [______________________________]    │
│ Internal project number   [__________]  (unique, no TEMP-)    │
│ Donor*                    [Select one donor ▾]                │
│ Projects / centres*       [Multi-select: draft/active/closed] │
│   per project: Target beneficiaries [____] (D-25)             │
│ Requested budget + Currency   [______] [USD ▾]  ← money holders only │
│ Planned start / end       [____-__-__] [____-__-__]           │
│ Notes                     [______________________________]    │
│                               [Cancel]  [Save concept note]   │
└───────────────────────────────────────────────────────────────┘
```

- **الترتيب:** نقطة البداية أولًا لأنها تغيّر الحقول الإلزامية.
- **من صفحة Project:** زر **New Concept Note** يفتح النموذج والمشروع محدَّد مسبقًا.
- **بلا مشاريع مسموحة:** رسالة «لا مشاريع متاحة للربط»، وزر الحفظ معطّل.
- **أخطاء:** «أدخل اسم المقترح»، «اختر المانح»، «اختر مشروعًا واحدًا على الأقل»، «اكتب سبب تخطي المذكرة».
- **مستخدم بلا صلاحيات مال:** لا يُعرض حقل الميزانية، والخادم يتجاهل القيمة.
- **بعد الحفظ:** صفحة **Proposal** بتبويبات **Logical Framework** و**Indicators & Targets** و**Plan**، بحالة «قيد الإنشاء».

### 8.2 لوحة **Reviewers** (Task 07)

```
┌─ Reviewers · Stage: Concept note review ──────── [+ Add reviewer] ┐
│ Name            Slot                  Status            Note        │
│ (employee)      MEAL Manager          Seen 2026-10-20   "…" [Edit]  │
│ (employee)      CEO                   Not seen          —           │
│ (employee)      Finance Manager       Not seen          —           │
│ (employee)      Projects & Fundraising Seen 2026-10-21  —           │
│ (employee)      Project owner         Not seen          —           │
│ (employee)      Additional            Not seen          — [Remove]  │
│                                     [Mark as Seen + optional note]  │
└─────────────────────────────────────────────────────────────────────┘
```

- **إضافة وإزالة:** فقط لحاملي `proposals.manage-reviewers`. الإزالة للخانة `additional` فقط، وقبل أي علامة أو ملاحظة.
- **Mark as Seen:** لا تُلغى. الملاحظة تُعدَّل حتى خروج المقترح من المرحلة.
- **فارغة:** «لا معنيين بعد. يُضاف الافتراضيون عند الانتقال إلى المراجعة».
- **معنيّ بلا حساب:** تحذير بجانب اسمه.
- **عند الإرسال للمانح** ومَن لم يطّلع بعد: تحذير بأسمائهم، والإرسال غير ممنوع (D-15).
- **مرحلة المقترح** لها سجلات مستقلة عن مرحلة المذكرة.

### 8.3 صفحة **Monthly Report** (Task 17 + 33)

```
┌─ Monthly Report · <Project> · 2026-11 · Status: Draft ───────────────┐
│ ⚠ Previous month not submitted: you can edit, but not submit (D-08)  │
│ 1. Narrative questions (monthly; + quarterly in the last month)      │
│    Q1 [_______________________]   Q2 [_______________________]       │
│ 2. Indicators due this month (template lines only)                   │
│    Code        Indicator          Male  Female  of which PwD M/F  Total│
│    002.OP1.2.3 Beneficiaries…     [ ]   [ ]     [ ] [ ]          (auto)│
│    split program → one row per donor share (D-46)                    │
│    MEAL-entered line → read only "Entered by MEAL" (D-48)            │
│    Ratio line → count only; % = count ÷ target beneficiaries (D-25)  │
│ 3. Activities this month (calendar)                                  │
│    Activity   Status [Not Started|Started|Ongoing|Suspended|        │
│               Cancelled|Completed|Conducted]  Actual date [____]     │
│ 4. Supporting files   [Upload] type [Attendance sheet ▾]             │
│    Required for line X: Distribution list ✖ missing                  │
│                                   [Save draft]  [Submit]             │
└──────────────────────────────────────────────────────────────────────┘
```

- **حالات المدير:**
  - `draft`: قابل للتعديل.
  - `submitted`: مقفل على المدير.
  - بعد التأكيد: زر **Request reopen** بسبب.
- **أزرار MEAL:**
  - **Confirm**: يُحتسب في الإنجاز (D-07)، ويحدّث حالة الأنشطة (D-49).
  - **Return**: ملاحظة إلزامية.
  - إدخال بنود MEAL أو تعليم «لم يُقس هذا الشهر».
- **فارغ:** «بانتظار تأكيد نموذج التقرير من MEAL» (D-45)، أو «لا بنود مستحقة هذا الشهر، أجب عن الأسئلة السردية فقط».
- **أخطاء الإرسال:**
  - ملف إلزامي ناقص لبند.
  - الشهر السابق غير مُرسَل.
  - قيمة سالبة (الصفر مسموح).

---

## 9. مسودات قواعد `record-rule` (لا تُسجَّل قبل تأكيد بشري في المحادثة)

| # | glob | title | note (مسودة) | يحلّ محل / يعدّل | القرار |
|---|---|---|---|---|---|
| R1 | `{app/Models/Result.php,resources/views/pages/results/**,resources/views/components/ui/framework-node.blade.php}` | Activity under Output; Cross-Cutting is its own level | Results chain is Impact→Outcome→Output→Activity; Activity's parent is exactly one Output and usually has no indicators. Cross-Cutting is a separate root level. Proposal frameworks (results.proposal_id) are excluded from Program::results() via a programFramework() scope. | `views-components-ui.md` «nothing under an output» | Task 12، الحقيقة 11 |
| R2 | `{app/Livewire/Concerns/ManagesAgreementForm.php,app/Actions/FundingAgreements/**,resources/views/pages/funding-agreements/**}` | One agreement, approved proposals only | An agreement is recorded only for an approved proposal, at most one per proposal (unique index), and its donor is written server-side from the proposal — never from the request. Recording it generates one transfers_tracking row per tranche of the payment schedule (D-31). | `funding.md` «allowed but warned» | D-26، D-31، Task 09 |
| R3 | `{app/Actions/Proposals/**,resources/views/pages/proposals/**}` | Proposal starts at concept note; moves only via TransitionProposal | A new proposal starts at `concept note`, or at `proposal draft` only with a written skip reason stored in its first proposal_status_notes row (D-03). Every later move goes through TransitionProposal's server-side transition table; `final reports` is gone (D-02); `rejected`/`completed` are corrected only via Correct Status with a reason (D-20). | `proposals.md` «starting status chosen on create» | D-02، D-03، D-20 |
| R4 | `{app/Models/PlanIndicator.php,resources/views/pages/{results,indicators,targets,reports}/**}` | Ratios pool numerator and denominator | Counts sum. Percentage/average achievement across months, centres or donors is Σnumerator ÷ Σdenominator (pooledRatioSql()), never an average of percentages; a single target still takes its latest approved value. Monthly lines count only after MEAL Confirm (D-07). | يضيف إلى `resultsindicatorstargetsreports.md` | D-07، D-25 |
| R5 | `{config/access-control.php,app/Policies/**}` | Targets are MEAL-only; Super Admin is developer-only | targets.create/update belong to MEAL only (D-23) — General Manager's "everything except" list must exclude them. Super Admin keeps every permission technically but is never listed in business defaults nor used as a fallback decider (D-20a). | يضيف إلى `policies.md` | D-20a، D-23 |
| R6 | `resources/views/pages/**` | Requested budget is money | proposals.requested_budget and the agreement payment schedule are money: queried and rendered only for funding-agreements.view holders (Financial, Public Relations Manager after D-31b, General Manager). Reviewers and Media Team never gain money or proposals.view through their role in a proposal. | يضيف إلى `pages.md` «Hide money, keep names» | D-16، D-31b |
| R7 | `{app/Actions/Projects/**,app/Models/Project.php}` | Project restore and one open project per centre+program | Restoring an archived project returns it to `closed` if it has dates, else `draft`, and is refused if its centre+program already has an open project (D-27, D-30). Projects leave draft only through proposal approval (approved) and MEAL template confirmation (active). | قاعدة جديدة | D-27، D-30، D-39 |

---

## 10. تعارضات تحتاج حسمًا في CP0

**T-01 — General Manager يأخذ كل صلاحية جديدة تلقائيًا.** قائمة GM مبنية بصيغة «كل شيء عدا قائمة». فيحصل تلقائيًا على:
- `reports.reopen` و`meal-coverage.manage` و`evidence-types.manage` و`narrative-questions.manage`، والخطة تحصرها في MEAL/مدير MEAL.
- ويبقى معه `targets.create/update` بعد D-23.

**المقترح:** تُضاف هذه الست إلى قائمة الاستثناء في Task 02. (ملاحظة README رقم 4.)

**T-02 — `report-templates.view` ينتهي بـ `.view`.** لذلك يدخل `$viewEverything` لكل الأدوار، بينما Task 33 يقصره على MEAL وGM ومالك المشروع ومدير برنامجه. يُختار أحد حلين:
- إضافته إلى `$restrictedViews` ومنحه صراحة؛
- أو قبول العرض للجميع، مع بقاء الكتابة والتأكيد مقيدَين.

**T-03 — نصوص قديمة لم تُحدَّث في Task 33 وTask 34.**
- السطر 2329 يذكر Super Admin و«مسؤول MEAL لمدينة المشروع».
- السطر 2227 يذكر Super Admin في `sectors.manage`.

هذه النصوص نسختها المراجعتان 8 و9 (D-20a، D-12a)، والمراجعات اللاحقة هي المعتمدة.

**T-04 — `proposals.approve`.** تظهر في قائمة D-20a (السطر 419)، لكنها غير معرَّفة في كتالوج Task 02. واليوم `ProposalPolicy::approve` تعتمد على `proposals.update`، فيضغط Financial أيضًا **Approve**. القرار المطلوب: إما صلاحية جديدة، أو الإبقاء على `proposals.update`.

**T-05 — عدد صفوف السجل.** البرومبت يطلب 34 صفًا، والخطة الحالية حتى D-54 بلا D-52. اعتُمدت الخطة.

**T-06 — بيانات محلية تخالف الافتراض 4:**
- المدينة 1 بلا مركز.
- مقترح بلا مشروع (id 5).
- كل الأرقام الداخلية `TEMP-`.

انظر القسم 7.1.

**T-07 — D-01 لا يسمّي من يضغط Approve Proposal.** يحدد D-01 من يراجع قبل الإرسال (CEO وPFM)، ولا يحدد من يسجّل اعتماد المانح. وهو مرتبط بـ T-04.

**T-08 — `ReviewerSlot` في Task 07 بلا خانة CEO.** القائمة اليوم: meal_manager، finance_manager، fundraising_manager، project_owner، program_manager، additional. D-01 يضيف خانة **CEO**، فيلزم `ceo` في الـ enum وفي إعداد **Default Proposal Reviewers**.

---

## 11. سجل المراجعة

| التاريخ | من (الدور) | ما تغيّر |
|---|---|---|
| 2026-10-12 | Dev 1 (مسودة بمساعدة Claude) | إنشاء السجل من الخطة حتى المراجعة 9، مع الفحوص المحلية |
| | Dev 1 + صاحب القرار | مراجعة CP0 |
