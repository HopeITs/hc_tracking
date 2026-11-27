---
paths:
  - '{app/Models/User.php,app/Models/Program.php,app/Models/Hr*.php,app/Models/Concerns/RemembersHrLookup.php,database/factories/{UserFactory,ProgramFactory}.php}'
---

# Concerns Factories

## No copies of HR data: users are thin rows read through HR (programs are NOT, since W-34b)
SUPERSEDES the note that login/sync refresh users' name/email. User decision 2026-09-23: tracking keeps no copy of HR data. users has no name/email/password (hr_user_id is required and is the identity; employee_id is the refreshed HR employee link). User::name/email are accessors backed by HrUser::directory() (RemembersHrLookup: one whole-table load per app instance, reloaded after 60s or on an unknown id). Never select/where/orderBy those columns: use User::whereNameOrEmailLike()/orderByName() and eager-load 'user:id,hr_user_id'. UserFactory moves name/email into the in-memory HR DB (reusing an HR account with the same email), so create the HR row before, never after, a factory user with that email.

**Programs are the exception again.** D-68 (5 Nov 2026, W-34b) made `programs` tracking-owned: it has name_en/name_ar/code/slug/is_active of its own, ProgramFactory writes them locally and touches no HR table, and `Program::factory()->create(['name_en' => ...])` is how a named program is made (there is no `name` column — `name` is a locale-aware accessor). See `models.md` for the full rule.
