---
paths:
  - '{database/migrations/**,tests/Feature/DatabaseEncodingTest.php}'
---

# Feature

## MariaDB json columns are utf8mb4_bin on purpose
passkeys.credential is utf8mb4_bin, not utf8mb4_unicode_ci. That is not drift and must not be "fixed": MariaDB implements Laravel's $table->json() as `longtext ... COLLATE utf8mb4_bin CHECK (json_valid(col))`. Any future $table->json() column will look the same.

The encoding test detects these columns via information_schema.CHECK_CONSTRAINTS (CHECK_CLAUSE LIKE 'json_valid(%') rather than by data type, because MariaDB reports them as longtext.
