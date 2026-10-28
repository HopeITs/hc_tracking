-- =============================================================================
-- T27 · Launch read-only checks
-- DRAFT (pre-CP5). Written 2026-10-21 on lifecycle/T27-rehearsal from GitHub
-- main 21b3187a (HopeITs/hc_tracking), with Dev 1's approval to draft before
-- T27's start state passes. CP5 = day 12, Tue 27 Oct (README). Update at CP5.
-- NOT a rehearsal result and NOT evidence that production is ready.
-- =============================================================================
--
-- How to use
--   * Every statement is SELECT only (including information_schema). No DML,
--     no DDL, no INTO OUTFILE, no procedures. MariaDB 10.4+ and MySQL 8.0.16+.
--   * Run as a read-only database user (recommended: SELECT on <db> and
--     <hr_db> only).
--   * PRE-xx run on the restored copy BEFORE runbook step 1. They read only
--     columns that exist at the baseline 0a21efc5 (verified against the
--     baseline's own migration files and config/permission.php), because the
--     release migrations have not run yet. POST-xx run after the step they
--     name.
--   * Results are counts and ids only. Never paste names, emails, amounts,
--     account numbers, file names or hosts into the report.
--   * <db> = the tracking schema, <hr_db> = the HR schema (read-only). HR
--     table and column names follow the app's HR read model
--     (app/Models/Hr*.php on the `hr` connection, table prefix `hchr_`);
--     confirm them on the HR snapshot. When HR is on another server, run the
--     HR half of a check there; each check says so.
--   * BLOCKING: a result other than the expected one stops the rehearsal
--     until the owner fixes it or management records a decision.
--     INFORMATIONAL: goes into the report with a management note.
--
-- Each check: id · purpose · owner (task) · blocking/informational ·
-- expected · "If not:" what a different result means.
-- "Reference" = a value from a prompt or another team's dev DB; not verified here.
-- "local, 2026-10-21" = the developer's local DB (hc_tracking): seed data,
-- 39 of the 57 release migrations still pending, HR = a SQLite stand-in
-- with no centers and invented program codes. Not representative.
-- "test DB" = hc_tracking_test, migrated from empty by the test suite (all 57
-- applied, no data): proves a POST query is valid on the post-release
-- schema, nothing about results.
--
-- T01 did not deliver docs/lifecycle/prechecks.md. PRE-01 … PRE-10 follow
-- T01's own specification (prompts/T01.md, slice 1, step 3), in its order
-- (decision D2 in the runbook).
-- =============================================================================


-- =============================================================================
-- PRE checks (before runbook step 1)
-- =============================================================================

-- PRE-00 · Which migrations the copy has already applied
--   The baseline has 74 migrations; its last is
--   2026_10_06_100000_restrict_project_creation_to_pr_manager_and_general_manager.
--   The release is the 57 migrations after it (GitHub main 21b3187a).
--   Owner T27 · BLOCKING · Expected: up_to_baseline = 74, release_already_applied = 0
--   If not: up_to_baseline < 74 = production is older than the baseline:
--   name the missing migrations (migrate:status), stop, decision D1.
--   release_already_applied > 0 = a partial earlier deploy: stop, Dev 1 decides.
--   local, 2026-10-21: applied 92 = 74 baseline + 18 release (partly migrated
--   on purpose; on a copy this would block)
SELECT COUNT(*) AS applied,
       COALESCE(SUM(migration <= '2026_10_06_100000_restrict_project_creation_to_pr_manager_and_general_manager'), 0) AS up_to_baseline,
       COALESCE(SUM(migration > '2026_10_06_100000_restrict_project_creation_to_pr_manager_and_general_manager'), 0) AS release_already_applied,
       MAX(batch) AS last_batch
FROM migrations;

-- PRE-01 · donor_proposal duplicates per proposal_id
--   Owner T03 · BLOCKING · Expected: 0
--   If not: migration 2026_10_13_130052 throws "Proposals with more than one
--   funding agreement (ids: …)". Finance names the donor; rerun from a fresh copy.
--   local, 2026-10-21: 0
SELECT COUNT(*) AS duplicate_groups,
       GROUP_CONCAT(proposal_id ORDER BY proposal_id) AS proposal_ids
FROM (SELECT proposal_id FROM donor_proposal GROUP BY proposal_id HAVING COUNT(*) > 1) d;

-- PRE-02 · Proposals without an agreement (T03 backfills donor_id from it)
--   Owner T03 · BLOCKING · Expected: 0
--   If not: 2026_10_13_130052 throws "Proposals with no funding agreement
--   (ids: …)". Finance names the donor; rerun from a fresh copy.
--   local, 2026-10-21: 0
SELECT COUNT(*) AS proposals_without_agreement,
       GROUP_CONCAT(p.id ORDER BY p.id) AS proposal_ids
FROM proposals p
WHERE NOT EXISTS (SELECT 1 FROM donor_proposal dp WHERE dp.proposal_id = p.id);

-- PRE-03 · Agreements whose donor differs from the proposal-level donor.
--   At the baseline a proposal has no donor column; its donor comes from its
--   agreement, so a mismatch can only come from agreements of one proposal
--   carrying different donors. Such a proposal also has several agreements,
--   so PRE-03 is a subset of PRE-01. Kept because T01 lists it: it tells
--   Finance which duplicates also disagree on the donor.
--   Owner T03 · BLOCKING (same as PRE-01) · Expected: 0
--   If not: Finance picks the donor per id before PRE-01 can be cleared.
--   local, 2026-10-21: 0
SELECT COUNT(*) AS proposals_with_mixed_donors,
       GROUP_CONCAT(proposal_id ORDER BY proposal_id) AS proposal_ids
FROM (SELECT proposal_id FROM donor_proposal GROUP BY proposal_id HAVING COUNT(DISTINCT donor_id) > 1) m;

-- PRE-04 · Status distribution of proposals and projects
--   Owner T04 (proposals), T05 (projects) · INFORMATIONAL · Expected: record
--   local, 2026-10-21: proposals approved 29, active 5; projects active 26, closed 2
SELECT 'proposals' AS subject, status, COUNT(*) AS n FROM proposals GROUP BY status
UNION ALL
SELECT 'projects', status, COUNT(*) FROM projects GROUP BY status
ORDER BY subject, status;

-- PRE-05 · Draft projects carrying dates
--   Owner T05 · BLOCKING (T27 rule) · Expected: 0
--   If not: T05's migration does not abort; it prints these ids and leaves
--   them. A draft must hold no dates (contracts §3). Management decides per
--   id (clear the dates through the app, or record an exception). No DML by T27.
--   local, 2026-10-21: 0
SELECT COUNT(*) AS drafts_with_dates,
       GROUP_CONCAT(id ORDER BY id) AS project_ids
FROM projects
WHERE status = 'draft' AND (start_date IS NOT NULL OR end_date IS NOT NULL);

-- PRE-06 · Proposals in `final reports` (T04's data migration moves them to `active`)
--   Owner T04 · INFORMATIONAL · Expected: record (POST-05 must match it)
--   local, 2026-10-21: 0
SELECT COUNT(*) AS final_reports_proposals,
       GROUP_CONCAT(id ORDER BY id) AS proposal_ids
FROM proposals
WHERE status = 'final reports';

-- PRE-07 · More than one open project per (city_id, program_id): an EARLY
--   WARNING, not the rule itself.
--   The rule (D-30a, contracts §3) is one open project per
--   (center_id, program_id). D-30: a center hosts several projects, and a
--   city can have several centers (T30's dev run lists five in Aleppo). Two
--   open projects in one city and program are legitimate when they sit in
--   different centers. projects.center_id does not exist before the release,
--   so this check can only group by city and lists candidates.
--   Open at the baseline = draft, submitted, approved, active.
--   The BLOCKING check is runbook step 4: hr:map-project-centers --dry-run,
--   section "Conflicts (open projects on one center and program)". The
--   command never applies a conflict. Then POST-12.
--   Owner T30 · INFORMATIONAL (blocking at step 4) · Expected: record
--   If not 0: compare each group with the step-4 Conflicts list. A group that
--   is also a conflict is a release blocker until management decides per
--   pair (D-30c): another center in HR, close a finished one, or an
--   accepted exception.
--   local, 2026-10-21: 2 groups. city 2 / program 7: projects 9, 25;
--   city 3 / program 7: projects 11, 26. These are the local copies of "DISC
--   Aleppo / Science Zone Aleppo" and "DISC Homs / Science Zone Homs", the
--   same pairs T30's dev dry run reports as center conflicts (#17/#33 at
--   ADISC, #19/#34 at HDISC). Production is unknown until PRE-07 and step 4
--   run on the copy. Locally 11, 25 and 26 are still `active` after their end
--   dates (2026-06-30, 2026-07-31; PRE-27b): closing a finished project
--   through the app would remove that pair. A management decision, not T27's.
--   real HR dump, 2026-10-22: hr:map-project-centers --dry-run confirms the
--   same pairs as center conflicts: 9 and 25 at ADISC, 11 and 26 at HDISC (G6).
SELECT COUNT(*) AS groups,
       GROUP_CONCAT(CONCAT('city ', city_id, ' / program ', program_id, ': ', ids) ORDER BY city_id, program_id SEPARATOR '; ') AS detail
FROM (SELECT city_id, program_id, GROUP_CONCAT(id ORDER BY id) AS ids
      FROM projects
      WHERE status IN ('draft', 'submitted', 'approved', 'active')
      GROUP BY city_id, program_id
      HAVING COUNT(*) > 1) o;

-- PRE-08 · Projects whose city has no active HR center (D-30c)
--   D-30: HR adds centers for cities 7, 8, 12, 13, 14, 17 before the
--   rehearsal; otherwise their projects keep center_id NULL with a warning.
--   Owner T30 (HR team adds centers) · INFORMATIONAL (management reviews
--   T30's matching) · Expected: record
--   If not 0: those projects cannot be mapped at step 4; they must be in the
--   unmapped list management accepted (POST-12a).
--   Same server: run the first query (<hr_db> qualified). Otherwise run
--   PRE-08a on <hr_db> and PRE-08b on <db>, then compare.
--   local, 2026-10-21: project cities 2, 3, 4, 5, 6, 14, 17; the local HR
--   stand-in has no centers, so all 7 lack one (not representative)
--   real HR dump, 2026-10-22 (same server, one query): city 14 (project 6) and
--   city 17 (projects 5, 18, 24). City 17 does not exist in the real HR (cities
--   1-16): the local tracking data was seeded against the stand-in.
--   PRE-08a: active-center cities 2, 3, 4, 5, 6, 16.
SELECT p.city_id, COUNT(*) AS projects, GROUP_CONCAT(p.id ORDER BY p.id) AS project_ids
FROM projects p
WHERE NOT EXISTS (SELECT 1 FROM <hr_db>.hchr_centers c WHERE c.city_id = p.city_id AND c.is_active = 1)
GROUP BY p.city_id
ORDER BY p.city_id;
-- PRE-08a (on <hr_db>): city ids that have an active center
SELECT GROUP_CONCAT(DISTINCT city_id ORDER BY city_id) AS city_ids_with_active_center
FROM hchr_centers WHERE is_active = 1;
-- PRE-08b (on <db>): city ids of projects
SELECT GROUP_CONCAT(DISTINCT city_id ORDER BY city_id) AS project_city_ids FROM projects;

-- PRE-09 · Duplicated HR programs.code on a program with indicators (D-10a)
--   Owner T11 (HR team fixes the codes) · BLOCKING when a duplicated code
--   belongs to a program with indicators · Expected: 0
--   If not: meal:seed-indicator-library (step 6) stops ("HR program code …
--   is used by more than one program"). HR fixes the codes before the rehearsal.
--   PRE-09a on <hr_db>; PRE-09b on <db>, then intersect the ids.
--   Reference (handoffs/T11.md, T30.md, their dev DB): 003, 004 and 009 duplicated.
--   local, 2026-10-21: no duplicated codes in the local HR stand-in (not
--   representative); programs with indicators: 1, 2, 3, 4, 6, 7, 8, 10, 13
-- PRE-09a (on <hr_db>)
--   real HR dump, 2026-10-22: PRE-09a = 003 (programs 3, 4), 004 (5, 6), 009 (11, 12,
--   14), matching the reference; programs 3, 4 and 6 have indicators, so this
--   BLOCKS, and meal:seed-indicator-library --dry-run stops on 003 and 004 (G7).
SELECT code, COUNT(*) AS programs, GROUP_CONCAT(id ORDER BY id) AS program_ids
FROM hchr_programs
GROUP BY code
HAVING COUNT(*) > 1;
-- PRE-09b (on <db>)
SELECT GROUP_CONCAT(program_id ORDER BY program_id) AS program_ids_with_indicators
FROM (SELECT r.program_id FROM indicators i JOIN results r ON r.id = i.result_id WHERE r.program_id IS NOT NULL
      UNION
      SELECT pr.program_id FROM indicators i JOIN projects pr ON pr.id = i.project_id) x;

-- PRE-10 · Row counts of indicators, plan_indicators, reports
--   Owner T01 · INFORMATIONAL · Expected: record
--   Reference (prompts/T01.md, its dev DB): 61 / 61 / 33.
--   local, 2026-10-21: 51 / 45 / 9 (a different seeded copy; not a failure)
SELECT (SELECT COUNT(*) FROM indicators) AS indicators,
       (SELECT COUNT(*) FROM plan_indicators) AS plan_indicators,
       (SELECT COUNT(*) FROM reports) AS reports;

-- PRE-11 · Duplicate plan_indicators (source_type, source_id, indicator_id, project_id)
--   Owner T13 · BLOCKING · Expected: 0
--   If not: 2026_10_15_130213 throws "Duplicate targets on (source_type,
--   source_id, indicator_id, project_id), plan_indicators ids: …". MEAL
--   resolves them; rerun from a fresh copy.
--   local, 2026-10-21: 0
SELECT COUNT(*) AS duplicate_groups,
       GROUP_CONCAT(ids ORDER BY ids SEPARATOR '; ') AS plan_indicator_ids
FROM (SELECT GROUP_CONCAT(id ORDER BY id) AS ids
      FROM plan_indicators
      GROUP BY source_type, source_id, indicator_id, project_id
      HAVING COUNT(*) > 1) d;

-- PRE-12 · Projects that will be `active` after step 3c without a
--   donor-approved proposal. T05's migration turns legacy `submitted` and
--   `approved` projects holding both dates into `active`, so they count too.
--   Donor-approved at the baseline = approved, active, final reports, completed.
--   Owner T05/T08 · INFORMATIONAL here; the same rows fail POST-06, which is
--   BLOCKING · Expected: record
--   If not 0: management decides per id before the rehearsal (link the
--   donor-approved proposal through the app, or record an exception).
--   local, 2026-10-21: 0
SELECT COUNT(*) AS projects, GROUP_CONCAT(pr.id ORDER BY pr.id) AS project_ids
FROM projects pr
WHERE (pr.status = 'active'
       OR (pr.status IN ('submitted', 'approved') AND pr.start_date IS NOT NULL AND pr.end_date IS NOT NULL))
  AND NOT EXISTS (SELECT 1 FROM project_proposal pp JOIN proposals p ON p.id = pp.proposal_id
                  WHERE pp.project_id = pr.id AND p.status IN ('approved', 'active', 'final reports', 'completed'));

-- PRE-13 · Proposals without any project_proposal row
--   Owner T06 · INFORMATIONAL · Expected: record
--   local, 2026-10-21: 1 (proposal 5)
SELECT COUNT(*) AS proposals, GROUP_CONCAT(p.id ORDER BY p.id) AS proposal_ids
FROM proposals p
WHERE NOT EXISTS (SELECT 1 FROM project_proposal pp WHERE pp.proposal_id = p.id);

-- PRE-14 · Donor-approved proposals missing actual dates
--   Owner T08 · INFORMATIONAL · Expected: record
--   local, 2026-10-21: 34 (proposals 1-34; the seed sets no actual dates)
SELECT COUNT(*) AS proposals, GROUP_CONCAT(id ORDER BY id) AS proposal_ids
FROM proposals
WHERE status IN ('approved', 'active', 'final reports', 'completed')
  AND (actual_date_begin IS NULL OR actual_date_end IS NULL);

-- PRE-15 · Temporary internal project numbers
--   Owner T06 · INFORMATIONAL · Expected: record
--   local, 2026-10-21: 0
SELECT COUNT(*) AS proposals, GROUP_CONCAT(id ORDER BY id) AS proposal_ids
FROM proposals
WHERE internal_project_number LIKE 'TEMP-%';

-- PRE-16 · Indicator names used with more than one measure (ids only)
--   Owner T11 · INFORMATIONAL · Expected: record
--   local, 2026-10-21: 0
SELECT COUNT(*) AS name_groups, GROUP_CONCAT(ids SEPARATOR '; ') AS indicator_ids
FROM (SELECT GROUP_CONCAT(id ORDER BY id) AS ids FROM indicators GROUP BY name HAVING COUNT(DISTINCT measure) > 1) m;

-- PRE-17 · File rows per table: total, stored, stored bytes.
--   Stored = every media row; original_name IS NOT NULL in the other tables
--   (rows without it are reference-only). Bytes size the disk for step 10:
--   free disk at least 1.5 x storage/app/private (decision D4).
--   Owner T22 · INFORMATIONAL · Expected: record
--   local, 2026-10-21: media 0/0/0, report_files 0/0/0, task_attachments 0/0/0
SELECT 'media' AS file_table, COUNT(*) AS total, COUNT(*) AS stored, COALESCE(SUM(size), 0) AS stored_bytes FROM media
UNION ALL
SELECT 'report_files', COUNT(*), COALESCE(SUM(original_name IS NOT NULL), 0),
       COALESCE(SUM(CASE WHEN original_name IS NOT NULL THEN size END), 0) FROM report_files
UNION ALL
SELECT 'task_attachments', COUNT(*), COALESCE(SUM(original_name IS NOT NULL), 0),
       COALESCE(SUM(CASE WHEN original_name IS NOT NULL THEN size END), 0) FROM task_attachments;

-- PRE-18 · Role -> permission snapshot and users per role.
--   PRE-18a per-role summary; PRE-18b full pair list to diff in POST-11.
--   Role and permission names are not personal data.
--   Owner T02 · INFORMATIONAL · Expected: record
--   local, 2026-10-21 (already synced locally, not a baseline): Super Admin
--   87 / 1 user, General Manager 76 / 0, MEAL 40 / 0, Financial 39 / 0,
--   Managers 39 / 0, Public Relations Manager 33 / 0, Media Team 19 / 0,
--   Public Relations 19 / 0, Team Member 15 / 0; 367 pairs
-- PRE-18a
SELECT r.name AS role,
       COUNT(DISTINCT rp.permission_id) AS permissions,
       (SELECT COUNT(*) FROM model_has_roles m WHERE m.role_id = r.id) AS users
FROM roles r
LEFT JOIN role_has_permissions rp ON rp.role_id = r.id
GROUP BY r.id, r.name
ORDER BY r.name;
-- PRE-18b
SELECT r.name AS role, p.name AS permission
FROM role_has_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions p ON p.id = rp.permission_id
ORDER BY r.name, p.name;

-- PRE-19 · Super Admin holder ids (D-20a: management confirms none is operational staff)
--   Owner T02 · INFORMATIONAL · Expected: record, confirmed by management
--   local, 2026-10-21: 1 (user 1)
SELECT COUNT(*) AS holders, GROUP_CONCAT(m.model_id ORDER BY m.model_id) AS user_ids
FROM model_has_roles m
JOIN roles r ON r.id = m.role_id
WHERE r.name = 'Super Admin';

-- PRE-20 · Counts of touched tables, activities, notifications, jobs,
--   failed_jobs, annual_plans. Re-run after step 3c, before and after step 11
--   (POST-19), and at the end.
--   Owner T27 · INFORMATIONAL · Expected: record
--   Reference: the T27 prompt gives annual_plans = 8 (the planning team's dev
--   DB; prompts/later/T24.md: "the plan expected 8"). Not verified; local is
--   0 and production is unknown until this runs.
--   Which fill steps write activities or notifications (read in the code,
--   2026-10-21):
--     * steps 4, 5, 6, 9: none. hr:map-project-centers writes through the
--       query builder; meal:import-framework, strategy:import-pillars,
--       meal:seed-indicator-library and MonthlyReportGenerator run under
--       Activity::withoutRecording.
--     * step 8: yes, by design. ReportTemplatePrefiller::prefillProject()
--       records `report_template.created` (notify: false) per new template
--       and sends `report_template.pending` to MEAL after commit. Its command
--       does not exist yet (T33 slice 4, B4): expected behaviour, not verified.
--   local, 2026-10-21: proposals 34, donor_proposal 34, donors 64, projects 28,
--   project_proposal 43, programs 9, milestones 37, tasks 20, results 51,
--   indicators 51, plan_indicators 45, reports 9, proposal_status_notes 34,
--   transfers_tracking 0, annual_plans 0, users 1, role_has_permissions 367,
--   activities 1, notifications 12, jobs 0, failed_jobs 0
SELECT 'proposals' AS table_name, COUNT(*) AS n FROM proposals
UNION ALL SELECT 'donor_proposal', COUNT(*) FROM donor_proposal
UNION ALL SELECT 'donors', COUNT(*) FROM donors
UNION ALL SELECT 'projects', COUNT(*) FROM projects
UNION ALL SELECT 'project_proposal', COUNT(*) FROM project_proposal
UNION ALL SELECT 'programs', COUNT(*) FROM programs
UNION ALL SELECT 'milestones', COUNT(*) FROM milestones
UNION ALL SELECT 'tasks', COUNT(*) FROM tasks
UNION ALL SELECT 'results', COUNT(*) FROM results
UNION ALL SELECT 'indicators', COUNT(*) FROM indicators
UNION ALL SELECT 'plan_indicators', COUNT(*) FROM plan_indicators
UNION ALL SELECT 'reports', COUNT(*) FROM reports
UNION ALL SELECT 'proposal_status_notes', COUNT(*) FROM proposal_status_notes
UNION ALL SELECT 'transfers_tracking', COUNT(*) FROM transfers_tracking
UNION ALL SELECT 'annual_plans', COUNT(*) FROM annual_plans
UNION ALL SELECT 'users', COUNT(*) FROM users
UNION ALL SELECT 'role_has_permissions', COUNT(*) FROM role_has_permissions
UNION ALL SELECT 'activities', COUNT(*) FROM activities
UNION ALL SELECT 'notifications', COUNT(*) FROM notifications
UNION ALL SELECT 'jobs', COUNT(*) FROM jobs
UNION ALL SELECT 'failed_jobs', COUNT(*) FROM failed_jobs;

-- PRE-20b · The two listener-driven alerts and their queued jobs (read in
--   the code, 2026-10-21):
--     * `notifications.data` is a JSON object written by NotificationGrouper:
--       {"key": "notifications.events.<event key>", "params", "count",
--       "activity_ids", "link", "actions"}. `notifications.events.<key>` is
--       a value of data.key, not a table or a column.
--     * Duplicates: the grouper merges a repeat into the latest unread row
--       with the same group_key (5-minute window) and raises data.count.
--       Both alerts are sent with no group_key
--       (GenerateMonthlyReportsForProject::notifyGenerated,
--       ReportTemplatePrefiller::notifyPending), so each delivery is its own
--       row; data.count is summed anyway.
--     * Both listeners implement ShouldQueue. A queued listener's job has
--       payload.displayName = the listener class
--       (App\Listeners\GenerateMonthlyReportsForProject). The class is
--       compared after the last backslash (CHAR(92)), independent of the
--       NO_BACKSLASH_ESCAPES sql_mode.
--   Before the release neither listener exists, so PRE expects 0 everywhere.
--   It matters during the rehearsal: with no queue worker on the copy a
--   queued listener stays in `jobs` and never writes its notification.
--   Re-run after step 8, after step 12 (the test approval queues
--   PrefillReportTemplates) and in POST-19.
--   Owner T27 (T17A, T33 emit them) · INFORMATIONAL · Expected PRE: 0 / 0 / 0 / 0 / 0 / 0
--   If not 0 before the release: the copy is not the baseline (PRE-00).
--   local, 2026-10-21: 0 / 0 / 0 / 0 / 0 / 0. Positive test through PHP PDO
--   on MariaDB (values built with json_encode, as Laravel stores them; a
--   notification with count 3 and a job payload with displayName
--   App\Listeners\GenerateMonthlyReportsForProject): key matched, count read
--   3, class extracted. The query itself contains no backslash; the mysql
--   command-line client strips backslashes from typed literals, so test
--   literals there are not reliable.
SELECT COUNT(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(data, '$.key')) = 'notifications.events.monthly_reports.generated' THEN 1 END) AS generated_rows,
       COALESCE(SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(data, '$.key')) = 'notifications.events.monthly_reports.generated' THEN CAST(JSON_EXTRACT(data, '$.count') AS UNSIGNED) END), 0) AS generated_deliveries,
       COUNT(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(data, '$.key')) = 'notifications.events.report_template.pending' THEN 1 END) AS pending_rows,
       COALESCE(SUM(CASE WHEN JSON_UNQUOTE(JSON_EXTRACT(data, '$.key')) = 'notifications.events.report_template.pending' THEN CAST(JSON_EXTRACT(data, '$.count') AS UNSIGNED) END), 0) AS pending_deliveries,
       (SELECT COUNT(*) FROM jobs WHERE SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.displayName')), CHAR(92), -1) = 'GenerateMonthlyReportsForProject') AS queued_generation_jobs,
       (SELECT COUNT(*) FROM jobs WHERE SUBSTRING_INDEX(JSON_UNQUOTE(JSON_EXTRACT(payload, '$.displayName')), CHAR(92), -1) = 'PrefillReportTemplates') AS queued_prefill_jobs
FROM notifications;

-- PRE-21 · HR cities without an active HR center
--   Reference (T27 prompt, D-30): cities 7, 8, 12, 13, 14, 17 on dev; D-30
--   asks HR to add their centers before the rehearsal. Projects without
--   center_id can only be counted once T30's migration adds the column
--   (POST-12a).
--   Owner T30 (HR team) · INFORMATIONAL · Expected: record; ideally none of
--   the project cities (PRE-08b)
--   If a project city is listed: its projects stay unmapped at step 4.
--   Run on <hr_db>.
--   local, 2026-10-21: 8 (cities 1, 2, 3, 4, 5, 6, 14, 17: no centers in the stand-in)
--   real HR dump, 2026-10-22: 10 cities without an active center: 1, 7-15 (G7).
SELECT COUNT(*) AS cities, GROUP_CONCAT(c.id ORDER BY c.id) AS city_ids
FROM hchr_cities c
WHERE NOT EXISTS (SELECT 1 FROM hchr_centers h WHERE h.city_id = c.id AND h.is_active = 1);

-- PRE-22 · Results breaking "parent exactly one level up" (outcome under
--   impact, output under outcome; activity under output after T12). Listed
--   for MEAL, never auto-fixed. Reference (T27 prompt, dev): 32, 40 impact
--   under output; 31, 43 output under output.
--   Owner T12 · INFORMATIONAL · Expected: record
--   local, 2026-10-21: 16 breaks: 21, 25, 29, 33, 37, 46, 50, 51 output under
--   output; 22, 26, 30, 34, 38, 43, 47 impact under output; 41 outcome under
--   output. PRE-22b: 7 impacts with a parent, 17 non-impact roots
-- PRE-22a
SELECT COUNT(*) AS breaks,
       GROUP_CONCAT(CONCAT(c.id, ' ', c.type, ' under ', p.type) ORDER BY c.id SEPARATOR '; ') AS detail
FROM results c
JOIN results p ON p.id = c.parent_id
WHERE NOT ((c.type = 'outcome' AND p.type = 'impact')
        OR (c.type = 'output' AND p.type = 'outcome')
        OR (c.type = 'activity' AND p.type = 'output'));
-- PRE-22b
SELECT COALESCE(SUM(type = 'impact' AND parent_id IS NOT NULL), 0) AS impacts_with_a_parent,
       COALESCE(SUM(type NOT IN ('impact', 'cross-cutting') AND parent_id IS NULL), 0) AS non_impact_roots
FROM results;

-- PRE-23 · Percentage reports. Before the release `reports` has no
--   numerator/denominator, so each of them lacks both and is excluded from
--   pooling (counted). POST-22 counts the ones still missing them.
--   Owner T17A · INFORMATIONAL · Expected: record
--   local, 2026-10-21: 0
SELECT COUNT(*) AS percentage_reports
FROM reports r
JOIN plan_indicators pi ON pi.id = r.plan_indicator_id
JOIN indicators i ON i.id = pi.indicator_id
WHERE i.measure = 'percentage';

-- PRE-24 · Agreements on proposals that are not donor-approved
--   Owner T09 · INFORMATIONAL · Expected: record
--   local, 2026-10-21: 0
SELECT COUNT(*) AS agreements, GROUP_CONCAT(dp.id ORDER BY dp.id) AS donor_proposal_ids
FROM donor_proposal dp
JOIN proposals p ON p.id = dp.proposal_id
WHERE p.status NOT IN ('approved', 'active', 'final reports', 'completed');

-- PRE-25 · Project links on donor-approved proposals. target_beneficiaries
--   is added by T03, so before the release every one is NULL: the size of
--   the D-25 work list for the PR Manager. POST-21 counts what is left.
--   Owner T03/T06 (PR Manager completes) · INFORMATIONAL · Expected: record
--   local, 2026-10-21: 43
SELECT COUNT(*) AS links
FROM project_proposal pp
JOIN proposals p ON p.id = pp.proposal_id
WHERE p.status IN ('approved', 'active', 'final reports', 'completed');

-- PRE-26 · Projects without any proposal: candidates for the self-funded
--   relinking to «تمويل ذاتي — Hope» (D-27a, runbook 12a)
--   Owner T03 (decision: Dev 1) · INFORMATIONAL · Expected: record
--   local, 2026-10-21: 2 (projects 12, 28)
SELECT COUNT(*) AS projects, GROUP_CONCAT(pr.id ORDER BY pr.id) AS project_ids
FROM projects pr
WHERE NOT EXISTS (SELECT 1 FROM project_proposal pp WHERE pp.project_id = pr.id);

-- PRE-27 · The months monthly-reports:backfill --from=2026-10 should create.
--   Rule read in BackfillMonthlyReports and MonthlyReportGenerator::
--   monthsBetween (2026-10-21): every project `active` at step 9 with both
--   dates; one month per calendar month from max(start month, 2026-10) to
--   the end month, both ends included. `active` at step 9 = `active` today,
--   plus legacy `submitted`/`approved` holding both dates (T05, step 3c).
--   PRE-27b: projects that will be active but get no month (no dates, or an
--   end before 2026-10).
--   Owner T17A · INFORMATIONAL · Expected: record (POST-17c must equal expected_months)
--   local, 2026-10-21: 20 projects, 74 months; PRE-27b: 6 (projects 11, 20, 23, 25, 26, 27)
-- PRE-27a
SELECT COUNT(*) AS projects_to_backfill,
       COALESCE(SUM(PERIOD_DIFF(DATE_FORMAT(end_date, '%Y%m'), DATE_FORMAT(GREATEST(start_date, '2026-10-01'), '%Y%m')) + 1), 0) AS expected_months
FROM projects
WHERE status IN ('active', 'submitted', 'approved')
  AND start_date IS NOT NULL AND end_date IS NOT NULL AND end_date >= '2026-10-01';
-- PRE-27b
SELECT COUNT(*) AS projects, GROUP_CONCAT(id ORDER BY id) AS project_ids
FROM projects
WHERE (status = 'active' AND (start_date IS NULL OR end_date IS NULL OR end_date < '2026-10-01'))
   OR (status IN ('submitted', 'approved') AND start_date IS NOT NULL AND end_date IS NOT NULL AND end_date < '2026-10-01');


-- =============================================================================
-- POST checks (after the step each one names)
-- "local, 2026-10-21" only where the local DB already has the columns;
-- "not runnable locally" = its migration is still pending there. Every POST
-- query was executed on the test DB (0 errors).
-- =============================================================================

-- POST-01 · Every proposal has a donor (after step 3a)
--   Owner T03 · BLOCKING · Expected: 0
--   If not: T03's backfill left a proposal without a donor (the migration
--   should have thrown): stop, defect for T03.
--   local, 2026-10-21: 0
SELECT COUNT(*) AS proposals_without_donor FROM proposals WHERE donor_id IS NULL;

-- POST-02 · Agreement donor differs from proposal donor (after step 3a)
--   Owner T03 · BLOCKING · Expected: 0
--   If not: the composite FK should make this impossible: stop, defect for T03.
--   local, 2026-10-21: 0
SELECT COUNT(*) AS mismatches, GROUP_CONCAT(dp.id ORDER BY dp.id) AS donor_proposal_ids
FROM donor_proposal dp
JOIN proposals p ON p.id = dp.proposal_id
WHERE p.donor_id <> dp.donor_id;

-- POST-03 · T03's keys exist under their handoff names, on the right tables (after step 3a)
--   Owner T03 · BLOCKING · Expected: 4 (donor_proposal_proposal_id_unique,
--   proposals_id_donor_id_unique, donor_proposal_proposal_donor_foreign,
--   proposals_donor_id_foreign)
--   If not 4: a key is missing or misnamed: stop, defect for T03.
--   local, 2026-10-21: 4
SELECT COUNT(DISTINCT key_name) AS keys_present, GROUP_CONCAT(DISTINCT key_name ORDER BY key_name) AS key_names
FROM (SELECT index_name AS key_name FROM information_schema.statistics
      WHERE table_schema = DATABASE()
        AND ((table_name = 'donor_proposal' AND index_name = 'donor_proposal_proposal_id_unique')
          OR (table_name = 'proposals' AND index_name = 'proposals_id_donor_id_unique'))
      UNION ALL
      SELECT constraint_name FROM information_schema.referential_constraints
      WHERE constraint_schema = DATABASE()
        AND ((table_name = 'donor_proposal' AND constraint_name = 'donor_proposal_proposal_donor_foreign' AND referenced_table_name = 'proposals')
          OR (table_name = 'proposals' AND constraint_name = 'proposals_donor_id_foreign' AND referenced_table_name = 'donors'))) k;

-- POST-04 · Exactly one internal donor (D-27a, after step 3a)
--   Owner T03 · BLOCKING · Expected: 1
--   If not: 0 = not created (it is skipped only under tests); > 1 = created
--   twice: stop, defect for T03.
--   local, 2026-10-21: 1 (donor 64)
SELECT COUNT(*) AS internal_donors, GROUP_CONCAT(id) AS donor_ids FROM donors WHERE is_internal = 1;

-- POST-05 · `final reports` retired (after step 3b)
--   The migration writes the TRANSLATED sentence into `context`
--   (__('proposals.final_reports_status_retired') in the server's
--   APP_LOCALE), not the key, so both language versions are matched (lang/en
--   and lang/ar proposals.php, 2026-10-21).
--   Owner T04 · BLOCKING · Expected: final_reports_left = 0; retire_notes = PRE-06
--   If not: fewer notes = rows moved without their note; more = the
--   migration ran on a copy that already held notes (PRE-00): stop, defect for T04.
--   local, 2026-10-21: 0 / 0
SELECT (SELECT COUNT(*) FROM proposals WHERE status = 'final reports') AS final_reports_left,
       (SELECT COUNT(*) FROM proposal_status_notes
        WHERE context IN ('Final reports status retired; moved to Active.', 'أُلغيت حالة التقارير النهائية؛ نُقل إلى نشط')) AS retire_notes;

-- POST-06 · Project statuses settled (after step 3c)
--   Donor-approved after the release = approved, active, completed.
--   Owner T05 (T08 for the approval rule) · BLOCKING · Expected: 0 / 0 / 0
--   If not: a status the release must settle is left, or an active project
--   lacks a donor-approved proposal or its dates: stop, defect for T05/T08.
--   Ids already decided under PRE-12 follow their recorded decision.
--   local, 2026-10-21: 0 / 0 / 0
SELECT (SELECT COUNT(*) FROM projects WHERE status IN ('submitted', 'approved')) AS submitted_or_approved,
       (SELECT COUNT(*) FROM projects WHERE status = 'draft' AND (start_date IS NOT NULL OR end_date IS NOT NULL)) AS drafts_with_dates,
       (SELECT COUNT(*) FROM projects pr
        WHERE pr.status = 'active'
          AND (pr.start_date IS NULL OR pr.end_date IS NULL
               OR NOT EXISTS (SELECT 1 FROM project_proposal pp JOIN proposals p ON p.id = pp.proposal_id
                              WHERE pp.project_id = pr.id AND p.status IN ('approved', 'active', 'completed')))) AS active_without_approval_or_dates;

-- POST-07 · General Manager and Managers no longer hold targets.create/update (D-23, after step 3d)
--   Owner T02 · BLOCKING · Expected: 0
--   If not 0: T02's revoke did not apply: stop, defect for T02.
--   local, 2026-10-21: 0
SELECT COUNT(*) AS grants, GROUP_CONCAT(CONCAT(r.name, ': ', pm.name) ORDER BY r.name, pm.name SEPARATOR '; ') AS detail
FROM role_has_permissions rp
JOIN roles r ON r.id = rp.role_id
JOIN permissions pm ON pm.id = rp.permission_id
WHERE r.name IN ('General Manager', 'Managers') AND pm.name IN ('targets.create', 'targets.update');

-- POST-08 · Seeded lookups (after step 3d)
--   Owner T34 (sectors), T33 (evidence types, narrative questions) · BLOCKING
--   Expected: sectors 6, evidence_types 5, narrative_questions 6 (counted from
--   DefaultSectors / DefaultEvidenceTypes / DefaultNarrativeQuestions::all(),
--   2026-10-21), unless rows already existed: the seeds never duplicate.
--   If not: a seed migration did not run, or the table already held rows;
--   compare with the step-3 output: defect for the owner.
--   local, 2026-10-21: sectors 6; the other two not runnable locally
SELECT (SELECT COUNT(*) FROM sectors) AS sectors,
       (SELECT COUNT(*) FROM evidence_types) AS evidence_types,
       (SELECT COUNT(*) FROM narrative_questions) AS narrative_questions;

-- POST-09 · Legacy transfers are manual; no installments or generated transfers (D-31, after step 3d)
--   Owner T09 · BLOCKING · Expected: 0 / 0 / 0
--   If not 0: stop, defect for T09.
--   not runnable locally (transfers_tracking.source pending)
SELECT (SELECT COUNT(*) FROM transfers_tracking WHERE source <> 'manual') AS not_manual,
       (SELECT COUNT(*) FROM transfers_tracking WHERE installment_id IS NOT NULL) AS linked_to_installment,
       (SELECT COUNT(*) FROM funding_agreement_installments) AS installments;

-- POST-10 · Encoding contract: utf8mb4_unicode_ci on InnoDB DYNAMIC (after step 3)
--   POST-10a excludes JSON columns: MariaDB stores $table->json() as
--   longtext utf8mb4_bin with CHECK (json_valid(`col`)), by design
--   (.ai/rules/feature.md). They are found through TABLE_CONSTRAINTS joined
--   to CHECK_CONSTRAINTS (MariaDB and MySQL 8.0.16+). On MySQL native JSON
--   has no collation and is never listed.
--   Owner T27 (DatabaseEncodingTest) · BLOCKING · Expected: 0 / 0
--   If not 0: a table or column drifted: defect for the migration's owner.
--   local, 2026-10-21: 10a = 0 (the 6 JSON columns are recognised and
--   excluded; the inverse query lists exactly them); 10b = 0.
--   test DB (all 57 migrations): 10a = 0 with 10 utf8mb4_bin columns, all of
--   them JSON.
-- POST-10a
SELECT COUNT(*) AS columns,
       GROUP_CONCAT(CONCAT(c.table_name, '.', c.column_name, ' ', c.collation_name) ORDER BY c.table_name, c.column_name SEPARATOR '; ') AS detail
FROM information_schema.columns c
WHERE c.table_schema = DATABASE()
  AND c.collation_name IS NOT NULL AND c.collation_name <> 'utf8mb4_unicode_ci'
  AND NOT (c.collation_name = 'utf8mb4_bin'
           AND EXISTS (SELECT 1
                       FROM information_schema.table_constraints tc
                       JOIN information_schema.check_constraints cc
                         ON cc.constraint_schema = tc.constraint_schema AND cc.constraint_name = tc.constraint_name
                       WHERE tc.constraint_schema = c.table_schema AND tc.table_name = c.table_name
                         AND tc.constraint_type = 'CHECK'
                         AND cc.check_clause LIKE CONCAT('%json_valid(`', c.column_name, '`)%')));
-- POST-10b
SELECT COUNT(*) AS tables,
       GROUP_CONCAT(CONCAT(table_name, ' ', engine, ' ', row_format, ' ', table_collation) SEPARATOR '; ') AS detail
FROM information_schema.tables
WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'
  AND (engine <> 'InnoDB' OR row_format <> 'Dynamic' OR table_collation <> 'utf8mb4_unicode_ci');

-- POST-11 · Role grants after step 2 and T02's migration (after step 3d)
--   Re-run PRE-18a and PRE-18b and diff with the PRE result.
--   Owner T02 · BLOCKING · Expected: no PRE pair removed except General
--   Manager and Managers x targets.create/update (D-23); new pairs =
--   contracts §8 (incl. Public Relations Manager x
--   funding-agreements.view/create/update, D-31b)
--   If not: another pair was removed, or a §8 pair is missing: stop, defect for T02.

-- POST-12 · Center mapping (after step 4): the authoritative D-30a check
--   hr:map-project-centers never applies a conflict, so conflicting
--   projects stay center_id NULL and appear in POST-12a, not POST-12b.
--   Owner T30 · BLOCKING · Expected: POST-12a = the unmapped list management
--   accepted (D-30c), with every step-4 conflict and its decision; POST-12b = 0
--   If not: 12b > 0 = two open projects share a (center, program): stop.
--   12a differs from the accepted list: stop.
--   local, 2026-10-21: 12a = 28 (all; local HR has no centers, step 4 not run); 12b = 0
-- POST-12a
SELECT COUNT(*) AS projects_without_center, GROUP_CONCAT(id ORDER BY id) AS project_ids
FROM projects WHERE center_id IS NULL;
-- POST-12b: open = draft, approved, active (after T05)
SELECT COUNT(*) AS groups, GROUP_CONCAT(ids ORDER BY ids SEPARATOR '; ') AS project_ids
FROM (SELECT GROUP_CONCAT(id ORDER BY id) AS ids
      FROM projects
      WHERE center_id IS NOT NULL AND status IN ('draft', 'approved', 'active')
      GROUP BY center_id, program_id
      HAVING COUNT(*) > 1) o;

-- POST-13 · Strategy pillars and the Micro framework (after step 5, BEFORE step 6)
--   meal:seed-indicator-library (step 6) also creates 002.* entries; only
--   `imported_at IS NOT NULL` marks what meal:import-framework wrote (it sets
--   it; SeedIndicatorLibrary never touches it, read 2026-10-21).
--   Owner T32 · BLOCKING when it differs from step 5's real-run output
--   Expected: pillars and programs_with_pillar = what strategy:import-pillars
--   printed; imported_micro_entries = the urban + agri "created" counts;
--   imported_outside_002 = 0.
--   Reference (handoffs/T32.md, dry runs of each file alone on its dev DB):
--   3 pillars, 8 programs assigned (5 missing in HR), 16 urban and 21 agri entries.
--   If not: stop, defect for T32.
--   not runnable locally (strategic_pillars pending)
SELECT (SELECT COUNT(*) FROM strategic_pillars) AS pillars,
       (SELECT COUNT(*) FROM programs WHERE strategic_pillar_id IS NOT NULL) AS programs_with_pillar,
       (SELECT COUNT(*) FROM library_indicators WHERE imported_at IS NOT NULL AND code LIKE '002.%') AS imported_micro_entries,
       (SELECT COUNT(*) FROM library_indicators WHERE imported_at IS NOT NULL AND code NOT LIKE '002.%') AS imported_outside_002;

-- POST-14 · Every indicator is in the Indicator Library (after step 6)
--   Owner T11 · BLOCKING · Expected: 0, and no new activities (PRE-20)
--   If not 0: step 6 left unlinked indicators (it stops on a duplicated HR
--   code, PRE-09): stop until the codes are fixed.
--   local, 2026-10-21: 51 (step 6 not run locally)
SELECT COUNT(*) AS indicators_without_library_entry FROM indicators WHERE library_indicator_id IS NULL;

-- POST-15 · One 2026 annual plan per program (after step 7)
--   Owner T33 · BLOCKING · Expected: 0 / 0
--   If not: stop, defect for T33.
--   PENDING: annual-plans:ensure-2026 is registered on no branch (checked
--   2026-10-21; T33 slice 4, B4). Which programs it covers is defined by
--   that slice: adjust programs_without_2026_plan to its handoff before use.
SELECT (SELECT COUNT(*) FROM programs pg WHERE NOT EXISTS (SELECT 1 FROM annual_plans ap WHERE ap.program_id = pg.id AND ap.year = 2026)) AS programs_without_2026_plan,
       (SELECT COUNT(*) FROM (SELECT program_id FROM annual_plans WHERE year = 2026 GROUP BY program_id HAVING COUNT(*) > 1) d) AS programs_with_several_2026_plans;

-- POST-16 · One pending report template per active project, no status change (after step 8)
--   Owner T33 · BLOCKING · Expected: 0 / 0; projects by status = the counts
--   recorded after step 3c (not PRE-04: step 3c changes statuses on purpose)
--   If not: stop, defect for T33.
--   PENDING: report-templates:prefill-active is registered on no branch
--   (checked 2026-10-21; T33 slice 4, B4). Its helper
--   ReportTemplatePrefiller::prefillProject() exists on main.
SELECT (SELECT COUNT(*) FROM projects pr WHERE pr.status = 'active'
        AND NOT EXISTS (SELECT 1 FROM report_templates rt WHERE rt.project_id = pr.id)) AS active_without_template,
       (SELECT COUNT(*) FROM report_templates rt JOIN projects pr ON pr.id = rt.project_id
        WHERE pr.status = 'active' AND rt.status <> 'pending') AS active_templates_not_pending;

-- POST-17 · Monthly report backfill (after step 9)
--   Rule as in PRE-27. The scheduler does not run on the copy, so
--   monthly-reports:prepare adds nothing.
--   Owner T17A · BLOCKING · Expected: a = 0, b = 0, c = PRE-27a
--   expected_months, d = 0
--   If not: a > 0 duplicates, b > 0 months outside a project window: stop,
--   defect for T17A. c differs: d names how many projects disagree.
--   not runnable locally (monthly_reports pending)
SELECT (SELECT COUNT(*) FROM (SELECT project_id, period FROM monthly_reports GROUP BY project_id, period HAVING COUNT(*) > 1) dup) AS a_duplicate_months,
       (SELECT COUNT(*) FROM monthly_reports mr JOIN projects pr ON pr.id = mr.project_id
        WHERE mr.period < '2026-10-01'
           OR mr.period < DATE_FORMAT(pr.start_date, '%Y-%m-01')
           OR mr.period > DATE_FORMAT(pr.end_date, '%Y-%m-01')) AS b_months_outside_window,
       (SELECT COUNT(*) FROM monthly_reports) AS c_months,
       (SELECT COUNT(*) FROM projects pr
        WHERE pr.status = 'active' AND pr.start_date IS NOT NULL AND pr.end_date IS NOT NULL AND pr.end_date >= '2026-10-01'
          AND (SELECT COUNT(*) FROM monthly_reports mr WHERE mr.project_id = pr.id)
              <> PERIOD_DIFF(DATE_FORMAT(pr.end_date, '%Y%m'), DATE_FORMAT(GREATEST(pr.start_date, '2026-10-01'), '%Y%m')) + 1) AS d_projects_with_wrong_month_count;

-- POST-18 · Stored files are encrypted (after step 10)
--   Owner T22 · BLOCKING unless decision D4 defers encryption after the
--   window · Expected: 0 except rows the command reported missing;
--   reference-only rows (original_name IS NULL) are counted, never encrypted
--   If not: stop, defect for T22.
--   local, 2026-10-21: not runnable as a whole (monthly_report_files pending locally); media, report_files and task_attachments hold no files there
SELECT 'media' AS file_table, COALESCE(SUM(encryption_key_id IS NULL), 0) AS not_encrypted, 0 AS reference_only FROM media
UNION ALL SELECT 'report_files', COALESCE(SUM(original_name IS NOT NULL AND encryption_key_id IS NULL), 0), COALESCE(SUM(original_name IS NULL), 0) FROM report_files
UNION ALL SELECT 'task_attachments', COALESCE(SUM(original_name IS NOT NULL AND encryption_key_id IS NULL), 0), COALESCE(SUM(original_name IS NULL), 0) FROM task_attachments
UNION ALL SELECT 'monthly_report_files', COALESCE(SUM(encryption_key_id IS NULL), 0), 0 FROM monthly_report_files;

-- POST-19 · The second runs (step 11) change nothing
--   Run PRE-20 and PRE-20b right BEFORE step 11 and again AFTER it.
--   Owner T27 · BLOCKING · Expected: every count identical before and after step 11.
--   The FIRST runs (steps 4–10) may add rows: step 8 adds
--   `report_template.created` activities and `report_template.pending`
--   notifications by design (PRE-20). Record them; they are not a failure.
--   Untouched tables (proposals, donor_proposal, projects, project_proposal,
--   milestones, tasks, plan_indicators, reports, transfers_tracking, users)
--   equal PRE-20 throughout.
--   If not: a second run wrote: stop, defect for that command's owner.

-- POST-20 · Projects without any proposal: each needs a recorded decision (D-27a, step 12a)
--   Re-run PRE-26.
--   Owner T03 (decision: Dev 1) · BLOCKING until every id has a decision · Expected: record
--   If not: an id without a decision: stop until Dev 1 decides.

-- POST-21 · Links on donor-approved proposals still missing target_beneficiaries (D-25)
--   The D-25 work list for the PR Manager. The BLOCKING rule of step 8a is
--   narrower: the template lines with a `fixed` denominator basis and no
--   fixed_denominator, listed by report-templates:prefill-active --dry-run
--   (PENDING, T33 slice 4).
--   Owner T06 (PR Manager enters them) · INFORMATIONAL · Expected: record; ideally 0
--   local, 2026-10-21: 43 (no data entry locally)
SELECT COUNT(*) AS links, GROUP_CONCAT(CONCAT(pp.proposal_id, '/', pp.project_id) ORDER BY pp.proposal_id, pp.project_id SEPARATOR '; ') AS proposal_project_ids
FROM project_proposal pp
JOIN proposals p ON p.id = pp.proposal_id
WHERE p.status IN ('approved', 'active', 'completed') AND pp.target_beneficiaries IS NULL;

-- POST-22 · Percentage reports without numerator or denominator (excluded from pooling, counted)
--   Owner T17A · INFORMATIONAL · Expected: record
--   local, 2026-10-21: not runnable (reports.numerator pending); local rehearsal copy, 2026-10-22: 0
SELECT COUNT(*) AS percentage_reports_without_ratio
FROM reports r
JOIN plan_indicators pi ON pi.id = r.plan_indicator_id
JOIN indicators i ON i.id = pi.indicator_id
WHERE i.measure = 'percentage' AND (r.numerator IS NULL OR r.denominator IS NULL);

-- POST-23 · Result shapes after the release
--   Re-run PRE-22a and PRE-22b: the release must not add a break.
--   Owner T12 · INFORMATIONAL · Expected: the same ids as PRE-22
