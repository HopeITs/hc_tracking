---
paths:
  - '{config/access-control.php,app/Policies/UserPolicy.php}'
---

# Policies

## Built-in roles cannot be renamed or deleted
The 9 role names in role_baselines + administrator_roles are built-in: UserPolicy::isBuiltIn blocks rename/delete because task scoping, default_role and alert routing find them by name. Their permissions stay editable.
