---
paths:
  - '{config/database.php,.env.example}'
---

# Config

## The app's DB user is least-privilege; do not widen its grants
The app connects as the hc_tracking MariaDB user, never root. Its grants cover only hc_tracking and hc_tracking_test.

Consequence: `php artisan db:show` fails with "SELECT command denied ... performance_schema.session_status". That is the least-privilege design working, not a misconfiguration. Do not grant performance_schema or any wider privilege to silence it — use information_schema queries or the encoding test instead.

## Local development connects as root; production does not
SUPERSEDES "the app connects as the hc_tracking user, never root" for LOCAL development only. User decision 2026-09-14: the local .env uses root with an empty password for both the tracking (DB_*) and HR (HR_DB_*) connections. .env.example still shows the hc_tracking user. Production will use a different, least-privilege setup, planned separately; never deploy root credentials.
