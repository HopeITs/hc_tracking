---
paths:
  - '{app/Actions/Fortify/**,app/Console/Commands/**,config/auth.php,database/seeders/**}'
---

# Commands Seeders

## Every HR account is a tracking user (Officer by default)
SUPERSEDES "access needs an existing tracking user": any HR account may log in. User::forHrAccount() creates the tracking user on first login as Officer, or Admin when the email is in auth.hr_admin_emails (env HR_ADMIN_EMAILS, comma separated). The role is set only at creation, so later role changes stick. `php artisan hr:sync-users` creates all missing users in bulk, and CoreDataSeeder does the same; there are no fake demo users any more. HR is only read.
