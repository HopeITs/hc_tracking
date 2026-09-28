---
paths:
  - '{app/Actions/Fortify/**,app/Console/Commands/**,config/fortify.php}'
---

# Commands

## Login uses the HR password; users.password is never checked
SUPERSEDES the user:create options in enums.md. AuthenticateWithHrAccount checks the password against hchr_users and requires a tracking user with the same email (holds role, 2FA, passkeys); it records hr_user_id/employee_id and refreshes name each login. Password confirmation also checks HR (throttled: 5/min per user+IP, 10/15min per user). Login failures are capped at 30/15min per IP and 10/15min per email on top of Fortify's 5/min; User::getAuthPassword() returns the HR hash so an HR password change ends sessions and remember cookies. Self-service account deletion and email verification are removed. Password reset and Settings "update password" are removed; profile name/email are read-only. Accounts come from an HR account: `php artisan user:create --email= --role=` or Admin > Users. Registration stays disabled.
