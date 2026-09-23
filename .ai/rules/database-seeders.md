---
paths:
  - 'database/seeders/**'
---

# Database Seeders

## Seeders and HR syncs run under Activity::withoutRecording
DatabaseSeeder, Program::syncFromHr, User::syncFromHrAccounts and User::pruneInactiveHrAccounts wrap their writes in Activity::withoutRecording() so they produce no activity or notifications. Any new bulk import/sync must do the same. QuietWritesTest enforces it.
