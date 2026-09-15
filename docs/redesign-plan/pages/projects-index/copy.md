# Projects index · copy (English + Arabic)

Add these keys to `lang/en/projects.php` and `lang/ar/projects.php`, keeping each file's keys in **alphabetical order**. Arabic plurals use explicit ranges (00-conventions §4). Placeholders must match exactly in both languages (`LocalizationTest` checks).

**Existing keys this page keeps using** (don't rename): `add_project`, `add_the_first_project_and_place_it_in_a_city_and_program`, `all_cities`, `all_owners`, `beneficiary_targets`, `boys`, `breakdown_count`, `count_matching_project`, `create_project`, `define_delivery_ownership_lifecycle_schedule_and_beneficiary`, `edit_project`, `end_date`, `enter_a_project_name`, `girls`, `headline_total`, `men`, `no_matching_projects`, `owner`, `project_city_program_or_owner`, `project_created`, `project_name`, `project_updated`, `search_projects`, `select_a_city`, `select_a_city_2`, `select_a_program`, `select_a_program_2`, `select_a_project_owner`, `select_an_owner`, `the_end_date_must_be_on_or_after_the_start_date`, `the_headline_commitment_and_demographic_breakdown_are_record`, `the_selected_city_is_no_longer_available`, `the_selected_owner_is_no_longer_available`, `the_selected_program_is_no_longer_available`, `track_delivery_ownership_lifecycle_schedule_and_beneficiary`, `women`.

**Existing keys no longer used by this page:** `headline_count` (the headline now renders as a bare number under the Beneficiaries column). Keep the key if `projects-show` still uses it (check with `grep -rn "projects.headline_count" resources`); otherwise remove it from **both** files.

**Shared keys used from other files** (read-only here): `common.projects`, `common.city`, `common.program`, `common.status`, `common.actions`, `common.all_programs`, `common.unknown_city`, `common.no_projects_yet`, `common.change_the_search_or_filters_and_try_again`, `common.reset_filters`, `common.cancel`, `common.save_changes`, `common.description`, `common.start_date`, `common.edit_name`, `common.count_milestone`, `common.count_target`, `common.count_risk`, `common.not_assigned`, `formats.date`, `ui.view_table`, `ui.view_cards`, `ui.sort_by`. If any `common.*` / `ui.*` key listed here doesn't exist yet, ask the Foundation owner to add it; don't add it yourself.

## New keys

| Key | English | Arabic |
| --- | --- | --- |
| `breakdown_adds_up` | `Breakdown adds up to :breakdown · headline :headline` | `مجموع التفصيل :breakdown · المستهدف الرئيسي :headline` |
| `col_beneficiaries` | `Beneficiaries` | `المستفيدون` |
| `col_progress` | `Progress` | `التقدم` |
| `col_schedule` | `Schedule` | `الجدول الزمني` |
| `col_scheduled` | `Scheduled` | `المخطط` |
| `col_signals` | `Signals` | `المؤشرات السريعة` |
| `count_active_past_end_date` | `{1} :count active project is past its end date\|[2,*] :count active projects are past their end date` | `{1} مشروع نشط واحد تجاوز تاريخ انتهائه\|{2} مشروعان نشطان تجاوزا تاريخ انتهائهما\|[3,10] :count مشاريع نشطة تجاوزت تاريخ انتهائها\|[11,*] :count مشروعًا نشطًا تجاوز تاريخ انتهائه` |
| `count_risks_materialised` | `{1} :count risk, :materialised materialised\|[2,*] :count risks, :materialised materialised` | `{1} خطر واحد، تحقق منها :materialised\|{2} خطران، تحقق منها :materialised\|[3,10] :count مخاطر، تحقق منها :materialised\|[11,*] :count خطرًا، تحقق منها :materialised` |
| `duration_days` | `{1} :count day\|[2,*] :count days` | `{1} يوم واحد\|{2} يومان\|[3,10] :count أيام\|[11,*] :count يومًا` |
| `lifecycle` | `Project lifecycle` | `دورة حياة المشروع` |
| `milestone_progress_for` | `Milestone progress for :name` | `تقدم المراحل الرئيسية لمشروع :name` |
| `milestones_reached` | `:reached of :total milestones reached` | `تم بلوغ :reached من :total مراحل رئيسية` |
| `no_weighted_milestones` | `No weighted milestones` | `لا توجد مراحل رئيسية موزونة` |
| `project_directory` | `Project directory` | `دليل المشاريع` |
| `section_identity` | `Identity` | `التعريف` |
| `section_placement` | `Placement` | `الموقع والمسؤولية` |
| `section_schedule` | `Schedule` | `الجدول الزمني` |
| `show_all_projects` | `Show all projects` | `عرض كل المشاريع` |
| `show_them` | `Show them` | `اعرضها` |
| `sort_end_date` | `End date (soonest first)` | `تاريخ الانتهاء (الأقرب أولًا)` |
| `sort_name` | `Name (A–Z)` | `الاسم (أ–ي)` |
| `sort_recently_updated` | `Recently updated` | `المحدثة مؤخرًا` |
| `sort_start_date` | `Start date (earliest first)` | `تاريخ البدء (الأقدم أولًا)` |

Notes for the translator/reviewer:
- `count_risks_materialised` is only used as an `aria-label`; the `:materialised` number is always ≥ 1 when it is used.
- `{0}` isn't needed for `count_active_past_end_date` because the line only renders when the count is > 0; don't add a `{0}` range to English only (keep en/ar range sets consistent with how `trans_choice` is called).
- Enum labels (Draft, Submitted, Approved, Active, Closed, Archived) come from `lang/*/enums.php` via `ProjectStatus::label()`; don't duplicate them here.
