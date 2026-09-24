---
paths:
  - 'app/Notifications/Alerts/**'
---

# Alerts

## Scheduled alerts: one delivery path, working days, admin thresholds
Every scheduled alert goes through AlertDispatcher (RecipientFilter → AlertLedger::claim → notify). Stage keys embed the date they depend on (due date, stall start, ISO week, year) so a moved date or status change re-arms naturally. Day counts always use WorkingCalendar; thresholds always come from NotificationSettings (admin page /admin/notification-settings, defaults in config/notifications.php), never literals. Scanner tests must assert on the records they create, not on "nothing else exists" — factories create related projects/milestones/proposals with random dates that land in the scan window.

## Escalation catch-up window, no stalled tranches, ISO weeks
User decisions 2026-09-23. Escalations fire only while days overdue is in [threshold, threshold + escalation_catch_up_days) (admin setting, default 3), so items overdue long before the first scan keep overdue reminders but are never escalated. Late tranches get the overdue/escalation chain only; StalledScanner has no transfers. Every scanner (off-track and data-gap included) skips non-working days. Weeks are ISO (Monday start): always startOfWeek(CarbonInterface::MONDAY) / WorkingCalendar::startOfIsoWeek, never bare startOfWeek() — Carbon starts an Arabic week on Saturday.
