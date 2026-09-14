---
paths:
  - '{app/Actions/Fortify/**,app/Console/Commands/**,config/fortify.php}'
---

# Commands

## Login uses the HR password; users.password is never checked
SUPERSEDES the user:create options in enums.md. AuthenticateWithHrAccount checks the password against hchr_users and requires a tracking user with the same email (holds role, 2FA, passkeys); it records hr_user_id/employee_id and refreshes name each login. Password confirmation and currentPasswordRules() also check HR. Password reset and Settings "update password" are removed; profile name/email are read-only. Accounts come from an HR account: `php artisan user:create --email= --role=` or Admin > Users. Registration stays disabled.
