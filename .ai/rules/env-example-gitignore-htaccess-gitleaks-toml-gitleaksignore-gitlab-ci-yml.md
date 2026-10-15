---
paths:
  - '{.env.example,.gitignore,.htaccess,.gitleaks.toml,.gitleaksignore}'
---

# Env Example Gitignore Htaccess Gitleaks Toml Gitleaksignore Gitlab Ci Yml

## No real secrets in committed files; gitleaks guards history
.env.example is a template: every *KEY/*SECRET/*PASSWORD/*TOKEN stays empty, null or a ${REFERENCE}, and HR_ADMIN_EMAILS stays empty (a real APP_KEY once leaked here, commit 3ebfc62, now retired). tests/Feature/SecretHygieneTest.php enforces this and that git ignores every .env.* except the template. The team does not use CI pipelines (.gitlab-ci.yml was removed on 2026-10-15); run gitleaks locally with .gitleaks.toml on full history before pushing; never silence a finding by widening an allowlist — pin only a reviewed dead/rotated finding by fingerprint in .gitleaksignore. The root .htaccess (deny everything; public/.htaccess overrides it) stops XAMPP serving .env/.git/storage; do not remove it. Local broadcasting uses Reverb; the live Pusher keys never belong on a dev machine.
