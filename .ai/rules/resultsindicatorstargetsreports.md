---
paths:
  - '{app/Models/PlanIndicator.php,resources/views/pages/{results,indicators,targets,reports}/**}'
---

# Resultsindicatorstargetsreports

## Target achievement has one definition: PlanIndicator::achievedValue()
Decided with the user (2026-09-21): achievement against a target counts APPROVED reports only; numeric indicators sum them, percentage indicators take the latest approved level by planned_date. Draft/reviewed values are "pending" (pendingValue(), always 0 for percentage). Every page must read a target through achievedValue()/percentAchieved()/achievementStatus(), or their SQL twins achievedValueSql()/pendingValueSql()/achievementStatusSql() when filtering or counting in the query — never a local formula. A test in tests/Feature/Models/PlanIndicatorTest.php asserts the PHP and SQL forms agree. This supersedes the older indicators note about showing sum(reports.value): one report divided by the target is never shown as "achievement".
