---
paths:
  - '{app/Models/User.php,app/Models/Program.php,app/Models/Hr*.php,app/Models/Concerns/RemembersHrLookup.php,database/factories/{UserFactory,ProgramFactory}.php}'
---

# Concerns Factories

## No copies of HR data: users/programs are thin rows read through HR
SUPERSEDES "Programs mirror HR programs" (name copy) and the earlier note that login/sync refresh users' name/email. User decision 2026-09-23: tracking keeps no copy of HR data. users has no name/email/password (hr_user_id is required and is the identity; employee_id is the refreshed HR employee link); programs has no name. User::name/email and Program::name are accessors backed by HrUser::directory() / HrProgram::namesById() (RemembersHrLookup: one whole-table load per app instance, reloaded after 60s or on an unknown id). Never select/where/orderBy those columns: use User::whereNameOrEmailLike()/orderByName(), Program::whereNameLike()/orderByName()/options(), and eager-load 'user:id,hr_user_id' / 'program:id'. UserFactory/ProgramFactory move name/email into the in-memory HR DB (reusing an HR account with the same email), so create the HR row before, never after, a factory user with that email.
