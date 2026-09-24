---
paths:
  - '{app/Models/User.php,app/Console/Commands/**,app/Http/Middleware/**,app/Actions/Fortify/**}'
---

# Middleware Actions Fortify

## HR user sync: identity is hr_user_id, access follows HR is_active
SUPERSEDES "Officer by default": new users get no role (Super Admin for auth.hr_admin_emails). Tracking users match HR by users.hr_user_id first, email only before the first link; login and `hr:sync-users` refresh name/email/employee_id (roles never touched). User::delete() runs in a transaction so Spatie's role detach rolls back when a restricting FK keeps the user. EnsureHrAccountIsActive (web group) signs out users whose HR account is inactive/removed (cached 60s, fails open if HR is down); passkey login checks the same via Passkeys::authorizeLoginUsing. Never whereHas() across the hr connection — use User::withActiveHrAccount()/withoutActiveHrAccount() or pluck HR ids first.
