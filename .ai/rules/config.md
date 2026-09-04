---
paths:
  - '{config/database.php,.env.example}'
---

# Config

## The app's DB user is least-privilege; do not widen its grants
The app connects as the hc_tracking MariaDB user, never root. Its grants cover only hc_tracking and hc_tracking_test.

Consequence: `php artisan db:show` fails with "SELECT command denied ... performance_schema.session_status". That is the least-privilege design working, not a misconfiguration. Do not grant performance_schema or any wider privilege to silence it — use information_schema queries or the encoding test instead.
